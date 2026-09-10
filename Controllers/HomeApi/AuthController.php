<?php

namespace App\Apps\CmsproSso\Controllers\HomeApi;

use App\Apps\CmsproSso\Models\InviteCode;
use App\Apps\CmsproSso\Models\LoginLog;
use App\Apps\CmsproSso\Services\SettingService;
use App\Apps\CmsproSso\Services\ShareService;
use App\Apps\CmsproSso\Services\SmsService;
use App\Apps\CmsproSso\Services\RelayClientService;
use App\Apps\CmsproSso\Services\OAuthService;
use App\Apps\CmsproSso\Services\WechatOauthService;
use App\Apps\CmsproSso\Services\QqOauthService;
use App\Apps\CmsproSso\Services\AlipayOauthService;
use App\Apps\CmsproSso\Services\DouyinOauthService;
use App\Apps\CmsproSso\Services\WeiboOauthService;
use App\Apps\CmsproSso\Services\GithubOauthService;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\UserThirdParty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    // 账号维度登录失败锁定配置
    protected const MAX_FAILURES = 5;      // 最大连续失败次数
    protected const LOCK_MINUTES = 15;     // 锁定分钟数

    // Session 键：社交授权 state（CSRF 防护）与登录跳转地址，按平台区分
    // 实际键名：cmspro_sso_{platform}_oauth_state / cmspro_sso_{platform}_oauth_redirect
    // 微信固定使用 wechat，保证既有会话/测试向后兼容
    protected const SESSION_OAUTH_PREFIX = 'cmspro_sso_';
    protected const SESSION_OAUTH_STATE_SUFFIX = '_oauth_state';
    protected const SESSION_OAUTH_REDIRECT_SUFFIX = '_oauth_redirect';

    // Cache 键前缀：PC 扫码登录 state + 登录结果（跨设备扫码场景）
    protected const CACHE_LOGIN_STATE_PREFIX = 'cmspro_sso_login_state:';
    protected const CACHE_LOGIN_RESULT_PREFIX = 'cmspro_sso_login_result:';
    // Cache 键前缀：PC 扫码绑定 state（含目标用户）+ 绑定结果（跨设备扫码绑定场景）
    protected const CACHE_BIND_STATE_PREFIX = 'cmspro_sso_bind_state:';
    protected const CACHE_BIND_RESULT_PREFIX = 'cmspro_sso_bind_result:';
    // Cache 键前缀：待建号新用户的微信授权数据（邀请码收集期间暂存，凭 token 一次性取回）
    protected const CACHE_OAUTH_DATA_PREFIX = 'cmspro_sso_oauth_data:';
    protected const LOGIN_STATE_TTL = 600;  // 10 分钟过期

    // Cache 键前缀：手机验证码
    protected const CACHE_PHONE_CODE_PREFIX = 'cmspro_sso_phone_code:';
    protected const PHONE_CODE_TTL = 10;          // 验证码有效期（分钟）
    protected const PHONE_CODE_MAX_ATTEMPTS = 3;  // 每分钟内单个手机号最多发送次数

    // Cache 键前缀：邮箱验证码（与系统注册验证码共用缓存键，校验规则一致）
    protected const CACHE_EMAIL_CODE_PREFIX = 'user_register_code:';
    protected const EMAIL_CODE_TTL = 10;          // 验证码有效期（分钟）
    protected const EMAIL_CODE_MAX_ATTEMPTS = 3;  // 每分钟内单个邮箱最多发送次数

    public function __construct(
        protected SettingService $settingService,
        protected SmsService $smsService,
        protected OAuthService $oauthService
    ) {
    }

    /**
     * 会话键：社交授权 state（CSRF 防护），按平台区分
     *
     * 微信为 cmspro_sso_wechat_oauth_state，QQ 为 cmspro_sso_qq_oauth_state，
     * 既有的微信会话/测试键名保持不变，向后兼容。
     */
    protected function sessionStateKey(string $platform): string
    {
        return self::SESSION_OAUTH_PREFIX . $platform . self::SESSION_OAUTH_STATE_SUFFIX;
    }

    /**
     * 会话键：登录跳转地址（授权前暂存，回调后取回继续透传），按平台区分
     */
    protected function sessionRedirectKey(string $platform): string
    {
        return self::SESSION_OAUTH_PREFIX . $platform . self::SESSION_OAUTH_REDIRECT_SUFFIX;
    }

    /**
     * 会话键：社交绑定授权 state（绑定模式专用，与登录态区分，防止误登录）
     *
     * 键名：cmspro_sso_{platform}_bind_oauth_state
     */
    protected function sessionBindStateKey(string $platform): string
    {
        return self::SESSION_OAUTH_PREFIX . $platform . '_bind_oauth_state';
    }

    /**
     * 登录逻辑：支持用户名/邮箱/手机号 + 密码
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account'  => 'required|string',
            'password' => 'required|string|min:6',
        ], [
            'account.required'  => '请输入账号',
            'password.required' => '请输入密码',
            'password.min'      => '密码至少6个字符',
        ]);

        $account = $validated['account'];
        $lockKey = 'cmspro_sso_login_lock:' . md5(strtolower($account));

        // 账号已被锁定
        if (Cache::has($lockKey)) {
            $remaining = (int) ceil(Cache::get($lockKey) / 60);
            return response()->json([
                'code'    => 429,
                'message' => '登录失败次数过多，请 ' . $remaining . ' 分钟后再试',
            ], 429);
        }

        $credentials = $this->resolveCredentials($account, $validated['password']);

        if (Auth::guard('web')->attempt($credentials, $request->filled('remember'))) {
            $request->session()->regenerate();

            // 登录成功清除失败计数
            Cache::forget($lockKey);

            // 更新最后登录信息
            $user = Auth::guard('web')->user();
            $user->incrementLoginCount($request->ip());

            $this->writeLoginLog($user->id, 'password', $request, 1, '登录成功');

            return response()->json([
                'code'    => 0,
                'message' => '登录成功',
                'data'    => ['redirect' => $this->resolveRedirect($request)],
            ]);
        }

        // 记录失败次数，达到阈值则锁定
        $failKey = 'cmspro_sso_login_fail:' . md5(strtolower($account));
        $failures = (int) Cache::get($failKey, 0) + 1;
        if ($failures >= self::MAX_FAILURES) {
            Cache::put($lockKey, self::LOCK_MINUTES * 60, self::LOCK_MINUTES * 60);
            Cache::forget($failKey);
            return response()->json([
                'code'    => 429,
                'message' => '登录失败次数过多，账号已锁定 ' . self::LOCK_MINUTES . ' 分钟',
            ], 429);
        }
        Cache::put($failKey, $failures, self::LOCK_MINUTES * 60);

        return response()->json(['code' => 400, 'message' => '账号或密码错误'], 400);
    }

    /**
     * 注册逻辑：创建 User 并自动登录
     *
     * 后台已配置邮箱时强制邮箱验证码校验（与系统注册逻辑一致）
     */
    public function register(Request $request): JsonResponse
    {
        $requireEmailCode = mail_configured();
        $inviteRequired = $this->settingService->get('invite_enabled', '0') === '1';

        $validated = $request->validate([
            'username' => 'required|string|min:3|max:30|unique:users,username',
            'password' => 'required|string|min:6|confirmed',
            'nickname' => 'nullable|string|max:50',
            'email' => ($requireEmailCode ? 'required' : 'nullable') . '|string|max:100|email|unique:users,email',
            'email_code' => ($requireEmailCode ? 'required' : 'nullable') . '|string|size:6',
            'invite_code' => ($inviteRequired ? 'required' : 'nullable') . '|string|max:32',
        ], [
            'username.required'  => '用户名不能为空',
            'username.min'       => '用户名至少3个字符',
            'username.max'       => '用户名不能超过30个字符',
            'username.unique'    => '该用户名已被注册',
            'password.required'  => '密码不能为空',
            'password.min'       => '密码至少6个字符',
            'password.confirmed' => '两次输入的密码不一致',
            'nickname.max'       => '昵称不能超过50个字符',
            'email.required'     => '邮箱地址不能为空',
            'email.unique'       => '该邮箱已被注册',
            'email_code.required' => '邮箱验证码不能为空',
            'email_code.size'    => '邮箱验证码必须是6位',
            'invite_code.required' => '请输入邀请码',
        ]);

        // 注册用户名/昵称屏蔽词校验：命中后台配置的违禁词则拒绝注册（昵称未填写时由用户名兜底，无需重复校验）
        $usernameBanned = $this->settingService->isUsernameBanned($validated['username']);
        $nicknameBanned = ($validated['nickname'] ?? '') !== ''
            && $this->settingService->isUsernameBanned($validated['nickname']);
        if ($usernameBanned || $nicknameBanned) {
            $message = $usernameBanned ? '用户名包含被屏蔽的词语，请更换用户名' : '昵称包含被屏蔽的词语，请更换昵称';

            return response()->json(['code' => 422, 'message' => $message], 422);
        }

        // 校验注册邮箱验证码：不正确或未输入不予注册（后台未配置邮箱时跳过）
        if ($requireEmailCode) {
            $cachedCode = Cache::get('user_register_code:' . $validated['email']);
            if (! $cachedCode || ! hash_equals((string) $cachedCode, (string) $validated['email_code'])) {
                return response()->json(['code' => 422, 'message' => '邮箱验证码不正确或已过期'], 422);
            }
        }

        $defaultGroup = UserGroup::default()->enabled()->first();

        // 事务保证：用户创建与邀请码消费原子生效，消费失败整体回滚
        try {
            $user = DB::transaction(function () use ($validated, $defaultGroup, $inviteRequired) {
                $user = User::create([
                    'username' => $validated['username'],
                    'password' => Hash::make($validated['password']),
                    'nickname' => $validated['nickname'] ?? $validated['username'],
                    'email'    => $validated['email'] ?? null,
                    'group_id' => $defaultGroup?->id,
                    'status'   => 1,
                ]);

                if ($inviteRequired) {
                    $error = $this->consumeInviteCode($validated['invite_code'], $user->id);
                    if ($error !== null) {
                        throw new \RuntimeException($error);
                    }
                }

                return $user;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['code' => 422, 'message' => $e->getMessage()], 422);
        }

        // 注册成功后验证码一次性失效
        if ($requireEmailCode) {
            Cache::forget('user_register_code:' . $validated['email']);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $this->writeLoginLog($user->id, 'password', $request, 1, '注册成功');

        return response()->json([
            'code'    => 0,
            'message' => '注册成功',
            'data'    => ['redirect' => $this->resolveRedirect($request)],
        ]);
    }

    /**
     * 发送手机验证码短信
     */
    public function sendPhoneCode(Request $request): JsonResponse
    {
        if (! $this->settingService->isPhoneLoginEnabled()) {
            return response()->json(['code' => 400, 'message' => '手机验证码登录未启用'], 400);
        }

        if (! $this->smsService->isConfigured()) {
            return response()->json(['code' => 400, 'message' => '短信服务未配置，请联系管理员'], 400);
        }

        $validated = $request->validate([
            'phone' => 'required|string|regex:/^1\d{10}$/',
            'invite_code' => 'nullable|string|max:32',
        ], [
            'phone.required' => '请输入手机号',
            'phone.regex'    => '手机号格式不正确',
        ]);

        $phone = $validated['phone'];

        // 邀请注册开启时，获取验证码前先确认手机号账号是否存在：
        // 已存在 → 正常发送验证码登录；不存在 → 需凭有效邀请码获取验证码走注册步骤
        if ($this->settingService->get('invite_enabled', '0') === '1'
            && ! User::where('phone', $phone)->exists()) {
            $inviteCode = strtoupper(trim((string) $request->input('invite_code', '')));

            if ($inviteCode === '') {
                return response()->json([
                    'code'    => 422,
                    'message' => '该手机号未注册，注册新账号需要邀请码',
                    'data'    => ['need_invite' => true],
                ], 422);
            }

            // 预校验邀请码可用性（不消费，建号时才消费）
            $error = $this->checkInviteCodeUsable($inviteCode);
            if ($error !== null) {
                return response()->json(['code' => 422, 'message' => $error], 422);
            }
        }

        $throttleKey = 'cmspro_sso_sms_throttle:' . $phone;

        if (RateLimiter::tooManyAttempts($throttleKey, self::PHONE_CODE_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json(['code' => 429, 'message' => "发送过于频繁，请 {$seconds} 秒后再试"], 429);
        }
        RateLimiter::hit($throttleKey, 60);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put(self::CACHE_PHONE_CODE_PREFIX . $phone, $code, now()->addMinutes(self::PHONE_CODE_TTL));

        $result = $this->smsService->sendVerificationCode($phone, $code);

        if (! $result['success']) {
            // 发送失败清除验证码缓存，避免残留
            Cache::forget(self::CACHE_PHONE_CODE_PREFIX . $phone);
            return response()->json(['code' => 500, 'message' => '短信发送失败：' . $result['message']], 500);
        }

        return response()->json(['code' => 0, 'message' => '验证码已发送']);
    }

    /**
     * 手机验证码登录
     *
     * 手机号已存在则自动登录；不存在则自动创建用户并跳转完善资料页。
     */
    public function phoneLogin(Request $request): JsonResponse
    {
        if (! $this->settingService->isPhoneLoginEnabled()) {
            return response()->json(['code' => 400, 'message' => '手机验证码登录未启用'], 400);
        }

        $validated = $request->validate([
            'phone' => 'required|string|regex:/^1\d{10}$/',
            'code'  => 'required|string|size:6',
            'invite_code' => 'nullable|string|max:32',
        ], [
            'phone.required' => '请输入手机号',
            'phone.regex'    => '手机号格式不正确',
            'code.required'  => '请输入验证码',
            'code.size'      => '验证码必须是6位',
        ]);

        $phone = $validated['phone'];
        $cachedCode = Cache::get(self::CACHE_PHONE_CODE_PREFIX . $phone);

        if (! $cachedCode || ! hash_equals((string) $cachedCode, (string) $validated['code'])) {
            return response()->json(['code' => 422, 'message' => '验证码不正确或已过期'], 422);
        }

        // 验证码一次性失效
        Cache::forget(self::CACHE_PHONE_CODE_PREFIX . $phone);

        $user = User::where('phone', $phone)->first();
        $isNew = $user === null;
        $inviteRequired = $this->settingService->get('invite_enabled', '0') === '1';

        if ($isNew) {
            // 邀请注册开启时，新手机号自动建号也需邀请码（need_invite 供前端动态显示邀请码输入框）
            if ($inviteRequired && empty($validated['invite_code'])) {
                return response()->json([
                    'code'    => 422,
                    'message' => '注册新账号需要邀请码',
                    'data'    => ['need_invite' => true],
                ], 422);
            }

            try {
                $user = DB::transaction(function () use ($phone, $validated, $inviteRequired) {
                    $user = $this->createPhoneUser($phone);

                    if ($inviteRequired) {
                        $error = $this->consumeInviteCode($validated['invite_code'], $user->id);
                        if ($error !== null) {
                            throw new \RuntimeException($error);
                        }
                    }

                    return $user;
                });
            } catch (\RuntimeException $e) {
                return response()->json(['code' => 422, 'message' => $e->getMessage()], 422);
            }
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->incrementLoginCount($request->ip());

        $this->writeLoginLog($user->id, 'phone', $request, 1, $isNew ? '手机验证码登录成功（首次创建）' : '手机验证码登录成功');

        return response()->json([
            'code'    => 0,
            'message' => '登录成功',
            'data'    => [
                'redirect' => $isNew
                    ? $this->completeUrl((string) $request->input('redirect', ''))
                    : $this->resolveRedirect($request),
                'is_new'   => $isNew,
            ],
        ]);
    }

    /**
     * 首次手机号登录：创建用户（用户名优先取手机号本身，密码随机不可登录）
     */
    protected function createPhoneUser(string $phone): User
    {
        $defaultGroup = UserGroup::default()->enabled()->first();

        $username = $phone;
        while (User::where('username', $username)->exists()) {
            $username = $phone . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
        }

        $randomPassword = 'phone_' . bin2hex(random_bytes(16));

        return User::create([
            'username' => $username,
            'password' => Hash::make($randomPassword),
            'nickname' => $phone,
            'phone'    => $phone,
            'group_id' => $defaultGroup?->id,
            'status'   => 1,
        ]);
    }

    /**
     * 发送邮箱验证码邮件
     */
    public function sendEmailCode(Request $request): JsonResponse
    {
        if (! $this->settingService->isEmailLoginEnabled()) {
            return response()->json(['code' => 400, 'message' => '邮箱验证码登录未启用'], 400);
        }

        $validated = $request->validate([
            'email' => 'required|string|email|max:100',
            'invite_code' => 'nullable|string|max:32',
        ], [
            'email.required' => '请输入邮箱地址',
            'email.email'    => '邮箱格式不正确',
        ]);

        $email = strtolower(trim($validated['email']));

        // 邀请注册开启时，获取验证码前先确认邮箱账号是否存在：
        // 已存在 → 正常发送验证码登录；不存在 → 需凭有效邀请码获取验证码走注册步骤
        if ($this->settingService->get('invite_enabled', '0') === '1'
            && ! User::where('email', $email)->exists()) {
            $inviteCode = strtoupper(trim((string) $request->input('invite_code', '')));

            if ($inviteCode === '') {
                return response()->json([
                    'code'    => 422,
                    'message' => '该邮箱未注册，注册新账号需要邀请码',
                    'data'    => ['need_invite' => true],
                ], 422);
            }

            // 预校验邀请码可用性（不消费，建号时才消费）
            $error = $this->checkInviteCodeUsable($inviteCode);
            if ($error !== null) {
                return response()->json(['code' => 422, 'message' => $error], 422);
            }
        }

        $throttleKey = 'cmspro_sso_email_throttle:' . $email;

        if (RateLimiter::tooManyAttempts($throttleKey, self::EMAIL_CODE_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json(['code' => 429, 'message' => "发送过于频繁，请 {$seconds} 秒后再试"], 429);
        }
        RateLimiter::hit($throttleKey, 60);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put(self::CACHE_EMAIL_CODE_PREFIX . $email, $code, now()->addMinutes(self::EMAIL_CODE_TTL));

        try {
            Mail::send('emails.register-code', ['code' => $code, 'minutes' => self::EMAIL_CODE_TTL], function ($message) use ($email) {
                $message->to($email)->subject('【用户中心】登录验证码');
            });
        } catch (\Throwable $e) {
            // 发送失败清除验证码缓存，避免残留
            Cache::forget(self::CACHE_EMAIL_CODE_PREFIX . $email);
            Log::error('邮箱验证码发送失败', ['email' => $email, 'error' => $e->getMessage()]);

            return response()->json(['code' => 500, 'message' => '邮件发送失败，请稍后重试'], 500);
        }

        return response()->json(['code' => 0, 'message' => '验证码已发送，请查收邮件']);
    }

    /**
     * 邮箱验证码登录
     *
     * 邮箱已存在则自动登录；不存在则自动创建用户并跳转完善资料页。
     */
    public function emailLogin(Request $request): JsonResponse
    {
        if (! $this->settingService->isEmailLoginEnabled()) {
            return response()->json(['code' => 400, 'message' => '邮箱验证码登录未启用'], 400);
        }

        $validated = $request->validate([
            'email' => 'required|string|email|max:100',
            'code'  => 'required|string|size:6',
            'invite_code' => 'nullable|string|max:32',
        ], [
            'email.required' => '请输入邮箱地址',
            'email.email'    => '邮箱格式不正确',
            'code.required'  => '请输入验证码',
            'code.size'      => '验证码必须是6位',
        ]);

        $email = strtolower(trim($validated['email']));
        $cachedCode = Cache::get(self::CACHE_EMAIL_CODE_PREFIX . $email);

        if (! $cachedCode || ! hash_equals((string) $cachedCode, (string) $validated['code'])) {
            return response()->json(['code' => 422, 'message' => '验证码不正确或已过期'], 422);
        }

        // 验证码一次性失效
        Cache::forget(self::CACHE_EMAIL_CODE_PREFIX . $email);

        $user = User::where('email', $email)->first();
        $isNew = $user === null;
        $inviteRequired = $this->settingService->get('invite_enabled', '0') === '1';

        if ($isNew) {
            // 邀请注册开启时，新邮箱自动建号也需邀请码（need_invite 供前端动态显示邀请码输入框）
            if ($inviteRequired && empty($validated['invite_code'])) {
                return response()->json([
                    'code'    => 422,
                    'message' => '注册新账号需要邀请码',
                    'data'    => ['need_invite' => true],
                ], 422);
            }

            try {
                $user = DB::transaction(function () use ($email, $validated, $inviteRequired) {
                    $user = $this->createEmailUser($email);

                    if ($inviteRequired) {
                        $error = $this->consumeInviteCode($validated['invite_code'], $user->id);
                        if ($error !== null) {
                            throw new \RuntimeException($error);
                        }
                    }

                    return $user;
                });
            } catch (\RuntimeException $e) {
                return response()->json(['code' => 422, 'message' => $e->getMessage()], 422);
            }
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->incrementLoginCount($request->ip());

        $this->writeLoginLog($user->id, 'email', $request, 1, $isNew ? '邮箱验证码登录成功（首次创建）' : '邮箱验证码登录成功');

        return response()->json([
            'code'    => 0,
            'message' => '登录成功',
            'data'    => [
                'redirect' => $this->resolveRedirect($request),
                'is_new'   => $isNew,
            ],
        ]);
    }

    /**
     * 首次邮箱登录：创建用户（用户名优先取邮箱 @ 前缀，密码随机不可登录）
     */
    protected function createEmailUser(string $email): User
    {
        $defaultGroup = UserGroup::default()->enabled()->first();

        // 用户名：邮箱 @ 前缀，冲突时追加随机后缀保证唯一
        $baseUsername = strstr($email, '@', true) ?: $email;
        $username = $baseUsername;
        while (User::where('username', $username)->exists()) {
            $username = $baseUsername . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
        }

        $randomPassword = 'email_' . bin2hex(random_bytes(16));

        return User::create([
            'username' => $username,
            'password' => Hash::make($randomPassword),
            'nickname' => $baseUsername,
            'email'    => $email,
            'group_id' => $defaultGroup?->id,
            'status'   => 1,
        ]);
    }

    /**
     * 退出登录
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'code'    => 0,
            'message' => '已退出登录',
            'data'    => ['redirect' => ShareService::frontUrl('/login')],
        ]);
    }

    /**
     * 获取当前登录用户信息
     */
    public function userInfo(Request $request): JsonResponse
    {
        $user = auth('web')->user();
        if (!$user) {
            return response()->json(['code' => 401, 'message' => '未登录'], 401);
        }

        return response()->json([
            'code'    => 0,
            'message' => 'ok',
            'data'    => [
                'id'       => $user->id,
                'username' => $user->username,
                'nickname' => $user->nickname,
                'avatar'   => $user->avatar,
                'phone'    => $user->phone,
                'email'    => $user->email,
            ],
        ]);
    }

    /**
     * 查询当前用户第三方/手机绑定状态（绑定中心）
     */
    public function bindings(Request $request): JsonResponse
    {
        $user = auth('web')->user();
        if (!$user) {
            return response()->json(['code' => 401, 'message' => '未登录'], 401);
        }

        // 仅展示后台开启的快捷登录方式（与登录页入口开关一致）
        $platforms = ['phone', 'email', 'wechat', 'qq', 'alipay', 'douyin', 'weibo', 'github'];
        $enabledPlatforms = array_values(array_filter($platforms, function ($platform) {
            if ($platform === 'phone') {
                return $this->settingService->isPhoneLoginEnabled();
            }
            if ($platform === 'email') {
                return $this->settingService->isEmailLoginEnabled();
            }

            return $this->settingService->get($platform . '_enabled', '0') === '1';
        }));

        $thirdParty = UserThirdParty::where('user_id', $user->id)->get()->keyBy('platform');
        $ways = $this->countLoginWays($user);

        // 当前可登录方式总数（含密码、手机、邮箱、微信、QQ）：仅剩 1 种时该方式不可解绑（防锁死）
        $locked = $ways <= 1;

        $data = [];
        foreach ($enabledPlatforms as $platform) {
            if ($platform === 'phone') {
                $data['phone'] = [
                    'bound' => (bool) $user->phone,
                    'phone' => $user->phone ? $this->maskPhone($user->phone) : '',
                    'locked' => $locked && $user->phone,
                ];
                continue;
            }
            if ($platform === 'email') {
                $data['email'] = [
                    'bound' => (bool) $user->email,
                    'email' => $user->email ? $this->maskEmail($user->email) : '',
                    'locked' => $locked && $user->email,
                ];
                continue;
            }
            $data[$platform] = [
                'bound' => $thirdParty->has($platform),
                'nickname' => (string) ($thirdParty->get($platform)?->nickname ?? ''),
                'avatar' => (string) ($thirdParty->get($platform)?->avatar ?? ''),
                'locked' => $locked && $thirdParty->has($platform),
            ];
        }

        $data['platforms'] = $enabledPlatforms;

        return response()->json(['code' => 0, 'message' => 'ok', 'data' => $data]);
    }

    /**
     * 解绑第三方/手机（绑定中心）
     *
     * @param string $platform 目标登录方式：phone / wechat / qq
     */
    public function unbind(Request $request, string $platform): JsonResponse
    {
        $user = auth('web')->user();
        if (!$user) {
            return response()->json(['code' => 401, 'message' => '未登录'], 401);
        }

        if (!in_array($platform, ['phone', 'email', 'wechat', 'qq'], true)) {
            return response()->json(['code' => 400, 'message' => '不支持的解绑类型'], 400);
        }

        // 解绑保护：解绑后仍须保留至少一种可登录方式（防账号锁死）
        $remains = $this->countLoginWays($user);
        if ($platform === 'phone') {
            $remains -= $user->phone ? 1 : 0;
        } elseif ($platform === 'email') {
            $remains -= $user->email ? 1 : 0;
        } else {
            $remains -= $this->hasBinding($user, $platform) ? 1 : 0;
        }
        if ($remains < 1) {
            return response()->json(['code' => 422, 'message' => '当前仅剩此一种登录方式，不可解绑'], 422);
        }

        if ($platform === 'phone') {
            $user->update(['phone' => null, 'phone_verified_at' => null]);
            return response()->json(['code' => 0, 'message' => '手机号已解绑']);
        }

        // 邮箱：清空用户邮箱（邮箱验证码登录凭据，全局唯一）
        if ($platform === 'email') {
            if (!$user->email) {
                return response()->json(['code' => 400, 'message' => '尚未绑定邮箱'], 400);
            }
            $user->update(['email' => null]);
            return response()->json(['code' => 0, 'message' => '邮箱已解绑']);
        }

        // 第三方平台：删除绑定记录
        $binding = UserThirdParty::where('user_id', $user->id)
            ->where('platform', $platform)
            ->first();
        if (!$binding) {
            return response()->json(['code' => 400, 'message' => '该平台尚未绑定'], 400);
        }
        $binding->delete();

        return response()->json(['code' => 0, 'message' => $this->platformUserLabel($platform) . '已解绑']);
    }

    /**
     * 统计当前用户可登录方式总数（密码、手机号、微信、QQ、邮箱）
     */
    protected function countLoginWays(User $user): int
    {
        $count = 0;
        if ($this->userCanPasswordLogin($user)) {
            $count++;
        }
        if ($user->phone) {
            $count++;
        }
        if ($user->email) {
            $count++;
        }
        foreach (['wechat', 'qq'] as $platform) {
            if ($this->hasBinding($user, $platform)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 判断用户是否设置过可登录密码
     *
     * 手机号注册/社交首次登录/邮箱注册生成的平台随机密码（phone_/wx_/qq_/email_ 前缀）不可用于登录。
     */
    protected function userCanPasswordLogin(User $user): bool
    {
        $password = (string) $user->getRawOriginal('password');
        if ($password === '') {
            return false;
        }
        foreach (['phone_', 'wx_', 'qq_', 'email_'] as $prefix) {
            if (str_starts_with($password, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 判断用户是否已绑定指定第三方平台
     */
    protected function hasBinding(User $user, string $platform): bool
    {
        return UserThirdParty::where('user_id', $user->id)
            ->where('platform', $platform)
            ->exists();
    }

    /**
     * 手机号脱敏（138****1234）
     */
    protected function maskPhone(string $phone): string
    {
        return strlen($phone) >= 7
            ? substr($phone, 0, 3) . '****' . substr($phone, -4)
            : $phone;
    }

    /**
     * 邮箱脱敏（ab****@domain.com）
     */
    protected function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at < 2) {
            return $email;
        }
        return substr($email, 0, 2) . '****' . substr($email, $at);
    }

    /**
     * 生成公众号网页授权登录链接
     *
     * 支持三种设备场景：
     *   1. 微信内：直接跳转授权，state 存 session，回调后直接 login 并 redirect
     *   2. PC 端（device=pc）：state 存 Cache，PC 端显示二维码扫码，回调后将结果存 Cache，PC 端轮询建立登录态
     *   3. 手机浏览器：前端提示在微信内打开
     *
     * 支持两种授权模式：
     *   1. 原生模式（默认）：直接跳转微信授权页，redirect_uri 指向本应用回调
     *   2. 外挂模式（开启 wechat_oauth_enabled）：跳转到外挂脚本，外挂完成授权后跳回本应用回调
     */
    public function wechatLoginUrl(Request $request): JsonResponse
    {
        return $this->oauthLoginUrl($request, 'wechat');
    }

    /**
     * QQ 授权登录 URL（PC 扫码 / 手机 / QQ 内等场景走标准 OAuth2）
     */
    public function qqLoginUrl(Request $request): JsonResponse
    {
        return $this->oauthLoginUrl($request, 'qq');
    }

    /**
     * 微信绑定授权 URL（绑定中心：已登录用户绑定微信）
     */
    public function wechatBindUrl(Request $request): JsonResponse
    {
        return $this->oauthBindUrl($request, 'wechat');
    }

    /**
     * QQ 绑定授权 URL（绑定中心：已登录用户绑定 QQ）
     */
    public function qqBindUrl(Request $request): JsonResponse
    {
        return $this->oauthBindUrl($request, 'qq');
    }

    /**
     * 支付宝授权登录 URL（网页授权跳转）
     */
    public function alipayLoginUrl(Request $request): JsonResponse
    {
        return $this->oauthLoginUrl($request, 'alipay');
    }

    /**
     * 抖音授权登录 URL（网页授权跳转）
     */
    public function douyinLoginUrl(Request $request): JsonResponse
    {
        return $this->oauthLoginUrl($request, 'douyin');
    }

    /**
     * 微博授权登录 URL（网页授权跳转）
     */
    public function weiboLoginUrl(Request $request): JsonResponse
    {
        return $this->oauthLoginUrl($request, 'weibo');
    }

    /**
     * GitHub 授权登录 URL（网页授权跳转）
     */
    public function githubLoginUrl(Request $request): JsonResponse
    {
        return $this->oauthLoginUrl($request, 'github');
    }

    /**
     * 支付宝绑定授权 URL（绑定中心：已登录用户绑定支付宝）
     */
    public function alipayBindUrl(Request $request): JsonResponse
    {
        return $this->oauthBindUrl($request, 'alipay');
    }

    /**
     * 抖音绑定授权 URL（绑定中心：已登录用户绑定抖音）
     */
    public function douyinBindUrl(Request $request): JsonResponse
    {
        return $this->oauthBindUrl($request, 'douyin');
    }

    /**
     * 微博绑定授权 URL（绑定中心：已登录用户绑定微博）
     */
    public function weiboBindUrl(Request $request): JsonResponse
    {
        return $this->oauthBindUrl($request, 'weibo');
    }

    /**
     * GitHub 绑定授权 URL（绑定中心：已登录用户绑定 GitHub）
     */
    public function githubBindUrl(Request $request): JsonResponse
    {
        return $this->oauthBindUrl($request, 'github');
    }

    /**
     * 生成社交账号绑定授权链接（微信/QQ 通用，绑定中心）
     *
     * 与登录授权的差异：
     *   1. state 存 session 绑定专用键（cmspro_sso_{platform}_bind_oauth_state），与登录态隔离；
     *   2. 仅登录用户可发起（路由受 cmspro.sso.auth 中间件保护）；
     *   3. 回调后走绑定落库流程，不建立新登录态、不改变当前用户。
     *
     * 支持两种设备场景：
     *   1. 跳转绑定（默认）：浏览器整体跳转授权页，state 存 session 绑定专用键，回调后可回显；
     *   2. PC 扫码绑定（device=pc）：PC 端弹二维码扫码，state 存 Cache（含目标用户），跨设备回调后落库，PC 端轮询绑定结果。
     *
     * @param string $platform 平台标识（wechat/qq）
     */
    protected function oauthBindUrl(Request $request, string $platform): JsonResponse
    {
        $label = $this->platformUserLabel($platform);

        // 平台未启用或未配置
        if ($this->settingService->get($platform . '_enabled', '0') !== '1') {
            return response()->json(['code' => 400, 'message' => $label . '登录未启用'], 400);
        }
        if (!$this->settingService->isSocialConfigured($platform)) {
            return response()->json(['code' => 400, 'message' => $label . '登录未配置，请联系管理员'], 400);
        }

        // 已绑定该平台时拒绝重复绑定
        $user = auth('web')->user();
        if ($user && $this->hasBinding($user, $platform)) {
            return response()->json(['code' => 400, 'message' => '已绑定' . $label . '，请先解绑'], 400);
        }

        $device = (string) $request->input('device', '');
        // 授权回调地址（与登录共用，回调按 state 存储位置区分登录/绑定模式）
        $callbackUrl = ShareService::frontUrl('/auth/' . $platform . '/callback');
        $state = md5(uniqid((string) mt_rand(), true));

        if ($device === 'pc') {
            // PC 扫码绑定：state 存 Cache 绑定专用键（跨设备，回调时 PC 端 session 不可用），
            // 绑定目标取当前登录用户，回调时据此落库到该用户
            Cache::put(self::CACHE_BIND_STATE_PREFIX . $state, ['user_id' => $user->id], self::LOGIN_STATE_TTL);
        } else {
            // 跳转绑定：state 存 session 绑定专用键（与登录 state 键隔离，防止误登录）
            $request->session()->put($this->sessionBindStateKey($platform), $state);
        }

        // 展示形态由后端统一决策（前端按 display 渲染，避免各视图重复实现 qq_mode 判断）：
        //   移动端一律跳转授权；PC 端微信固定内嵌扫码（与登录页一致）；
        //   PC 端 QQ 依后台 qq_mode 配置（qrcode=内嵌二维码，web/默认=跳转QQ授权页）。
        $display = 'redirect';
        if ($device === 'pc') {
            if ($platform === 'wechat' || $this->settingService->get('qq_mode', 'web') === 'qrcode') {
                $display = 'qrcode';
            }
        }

        // 微信外挂模式：构建跳转到外挂脚本的 URL（QQ 无外挂模式）
        if ($platform === 'wechat' && $this->settingService->isWechatOauthProxyEnabled()) {
            $proxyUrl = $this->settingService->getWechatOauthUrl();
            $separator = strpos($proxyUrl, '?') === false ? '?' : '&';
            $url = $proxyUrl . $separator . http_build_query([
                'action'     => 'authorize',
                'type'       => 'userinfo',
                'return_url' => $callbackUrl,
                'state'      => $state,
            ]);

            return response()->json([
                'code'    => 0,
                'message' => 'ok',
                'data'    => ['url' => $url, 'mode' => 'proxy', 'state' => $state, 'device' => $device, 'display' => $display],
            ]);
        }

        // 原生模式：直接调用对应平台网页授权
        $oauthService = $this->socialService($platform);
        $result = $oauthService->buildOauthRedirectUrl($callbackUrl, $state);

        if (!$result['success']) {
            return response()->json(['code' => 400, 'message' => $result['message']], 400);
        }

        return response()->json([
            'code'    => 0,
            'message' => 'ok',
            'data'    => ['url' => $result['url'], 'mode' => 'native', 'state' => $state, 'device' => $device, 'display' => $display],
        ]);
    }

    /**
     * 生成社交化授权登录链接（微信/QQ 通用）
     *
     * 支持两种设备场景：
     *   1. PC 端（device=pc）：state 存 Cache，PC 端显示二维码扫码，回调后将结果存 Cache，PC 端轮询建立登录态
     *   2. 普通模式（微信内/QQ 内/手机浏览器）：state 存 session，回调后直接 login 并 redirect
     *
     * 微信额外支持外挂授权模式（wechat_oauth_enabled），QQ 仅走标准 OAuth2。
     *
     * @param string $platform 平台标识（wechat/qq）
     */
    protected function oauthLoginUrl(Request $request, string $platform): JsonResponse
    {
        $label = $this->platformUserLabel($platform);

        // 快捷模式客户端：本地社交配置失效，微信/QQ 登录统一委托服务端中转
        if ($this->settingService->isRelayClient()) {
            return $this->doRelayLoginUrl($request, $platform);
        }

        // 平台未启用或未配置
        if ($this->settingService->get($platform . '_enabled', '0') !== '1') {
            return response()->json(['code' => 400, 'message' => $label . '登录未启用'], 400);
        }
        if (!$this->settingService->isSocialConfigured($platform)) {
            return response()->json(['code' => 400, 'message' => $label . '登录未配置，请联系管理员'], 400);
        }

        $device = (string) $request->input('device', '');
        // 授权回调地址（固定为统一登录对应平台回调，外挂模式下作为 return_url 回传给外挂）
        $callbackUrl = ShareService::frontUrl('/auth/' . $platform . '/callback');

        // state 防 CSRF：随机串
        $state = md5(uniqid((string) mt_rand(), true));

        // 说明：邀请注册模式下不在授权前收集邀请码——先完成授权确认账号是否已存在，
        // 已绑定老用户直接登录，仅新用户在回调后（PC 弹窗/端内页面）提示填写邀请码。

        if ($device === 'pc') {
            // PC 扫码模式：state 存 Cache（跨设备，回调时 PC 端 session 不可用）
            Cache::put(self::CACHE_LOGIN_STATE_PREFIX . $state, true, self::LOGIN_STATE_TTL);
        } else {
            // 普通模式：state 存 session，跳转地址暂存 session（回调后使用）
            $request->session()->put($this->sessionStateKey($platform), $state);
            $request->session()->put($this->sessionRedirectKey($platform), trim((string) $request->input('redirect', '')));
        }

        // 微信外挂模式：构建跳转到外挂脚本的 URL（QQ 无外挂模式）
        if ($platform === 'wechat' && $this->settingService->isWechatOauthProxyEnabled()) {
            $proxyUrl = $this->settingService->getWechatOauthUrl();
            $separator = strpos($proxyUrl, '?') === false ? '?' : '&';
            $url = $proxyUrl . $separator . http_build_query([
                'action'     => 'authorize',
                'type'       => 'userinfo',  // 弹窗授权，获取昵称头像用于首次登录创建用户
                'return_url' => $callbackUrl,
                'state'      => $state,
            ]);

            return response()->json([
                'code'    => 0,
                'message' => 'ok',
                'data'    => ['url' => $url, 'mode' => 'proxy', 'state' => $state, 'device' => $device],
            ]);
        }

        // 原生模式：直接调用对应平台网页授权
        $oauthService = $this->socialService($platform);
        $result = $oauthService->buildOauthRedirectUrl($callbackUrl, $state);

        if (!$result['success']) {
            return response()->json(['code' => 400, 'message' => $result['message']], 400);
        }

        return response()->json([
            'code'    => 0,
            'message' => 'ok',
            'data'    => ['url' => $result['url'], 'mode' => 'native', 'state' => $state, 'device' => $device],
        ]);
    }

    /**
     * 公众号网页授权回调
     *
     * 支持两种授权模式：
     *   1. 外挂模式：外挂脚本完成 code 换 openid 后跳回，附带 openid+state+可选 userinfo
     *   2. 原生模式：微信直接回调，附带 code+state，由本应用用 code 换 openid
     *
     * 支持两种设备场景（通过 state 存储位置区分）：
     *   1. PC 扫码模式：state 在 Cache 中，回调后将登录结果存 Cache，redirect 到提示页，PC 端轮询建立登录态
     *   2. 普通模式：state 在 session 中，回调后直接 login 并 redirect
     */
    public function wechatCallback(Request $request)
    {
        return $this->oauthCallback($request, 'wechat');
    }

    /**
     * QQ 授权回调（页面转发入口）
     */
    public function qqCallback(Request $request)
    {
        return $this->oauthCallback($request, 'qq');
    }

    /**
     * 支付宝授权回调
     */
    public function alipayCallback(Request $request)
    {
        return $this->oauthCallback($request, 'alipay');
    }

    /**
     * 抖音授权回调
     */
    public function douyinCallback(Request $request)
    {
        return $this->oauthCallback($request, 'douyin');
    }

    /**
     * 微博授权回调
     */
    public function weiboCallback(Request $request)
    {
        return $this->oauthCallback($request, 'weibo');
    }

    /**
     * GitHub 授权回调
     */
    public function githubCallback(Request $request)
    {
        return $this->oauthCallback($request, 'github');
    }

    /**
     * 社交化授权回调（各平台通用）
     *
     * 支持两种授权模式：
     *   1. 外挂模式（仅微信）：外挂脚本完成 code 换 openid 后跳回，附带 openid+state+可选 userinfo
     *   2. 原生模式：平台直接回调，附带 code+state，由本应用用 code 换 openid
     *
     * 支持两种设备场景（通过 state 存储位置区分）：
     *   1. PC 扫码模式：state 在 Cache 中，回调后将登录结果存 Cache，redirect 到提示页，PC 端轮询建立登录态
     *   2. 普通模式：state 在 session 中，回调后直接 login 并 redirect
     *
     * @param string $platform 平台标识（wechat/qq/alipay/douyin/weibo/github）
     */
    public function oauthCallback(Request $request, string $platform)
    {
        $label = $this->platformUserLabel($platform);
        $stateKey = $this->sessionStateKey($platform);
        $redirectKey = $this->sessionRedirectKey($platform);

        $code = (string) $request->query('code', '');
        // 支付宝网页授权回调使用 auth_code 参数名（平台独有），其他平台均为 code，需兼容
        if ($code === '' && $platform === 'alipay') {
            $code = (string) $request->query('auth_code', '');
        }
        $state = (string) $request->query('state', '');

        // 绑定模式（已登录用户在首页发起绑定）：state 存 session 单独键，
        // 校验通过 → 解析授权数据后走绑定落库流程（不建立新登录态、不改变当前用户）
        $bindStateKey = $this->sessionBindStateKey($platform);
        $expectedBindState = (string) $request->session()->get($bindStateKey, '');
        $isBindMode = $expectedBindState !== '' && $state !== ''
            && hash_equals($expectedBindState, $state);
        // 绑定 PC 扫码模式：state 存 Cache 绑定专用键（跨设备扫码，回调时在 bindPcOauthCallback 中消费取回目标用户）
        $isPcBindMode = false;
        $isPcMode = false;
        // 中转模式识别（服务端纯跳板透传）：
        //   1. PC 扫码中转：state = relay_qr:{session_id}（二维码直达社交授权页时携带）
        //   2. 整页跳转中转：state 为 Base64 编码的 {ctx, uri}（authorize 端点构建，ctx 以 relay: 开头）
        $decodedState = $this->decodeRelayOauthState($state);
        $isRelayQrMode = str_starts_with($state, 'relay_qr:')
            || (string) ($decodedState['ctx'] ?? '') !== '' && str_starts_with((string) $decodedState['ctx'], 'relay_qr:');
        $isRelayMode = $isRelayQrMode
            || ((string) ($decodedState['ctx'] ?? '') !== '' && str_starts_with((string) $decodedState['ctx'], 'relay:'));

        if ($isRelayMode) {
            // 中转回调不做会话 state 校验（state 即 authorize 端点构建的中转上下文，防伪靠白名单校验）
        } elseif ($state !== '' && Cache::has(self::CACHE_BIND_STATE_PREFIX . $state)) {
            $isBindMode = true;
            $isPcBindMode = true;
        } elseif ($isBindMode) {
            // 跳转绑定模式：state 使用绑定会话键，消费后即失效
            $request->session()->forget($bindStateKey);
        } elseif ($state !== '' && Cache::has(self::CACHE_LOGIN_STATE_PREFIX . $state)) {
            // 校验 state：PC 扫码登录（state 存 Cache，跨设备）
            $isPcMode = true;
            Cache::pull(self::CACHE_LOGIN_STATE_PREFIX . $state);
        } else {
            $expectedState = (string) $request->session()->get($stateKey, '');
            if ($expectedState === '' || $state === '' || !hash_equals($expectedState, $state)) {

                // 失败态回登录页提示（而非 abort：浏览器场景会被全局异常兜底渲染为 500）
                $redirect = (string) $request->session()->pull($redirectKey, '');
                $loginUrl = ShareService::frontUrl('/login');
                if ($redirect !== '') {
                    $loginUrl .= '?redirect=' . urlencode($redirect);
                }

                return redirect($loginUrl)->with('error', '授权状态校验失败，请重新登录');
            }
            $request->session()->forget($stateKey);
        }

        // 外挂模式错误回传（仅微信外挂会带 error 参数）
        $error = (string) $request->query('error', '');
        if ($error !== '') {
            Log::warning($label . '授权外挂回调错误', ['error' => $error, 'errmsg' => $request->query('errmsg')]);
            abort(400, $label . '授权失败：' . $error);
        }

        // 获取 openid + 用户信息（原生模式用 code 换，外挂模式直接读）
        if ($code !== '') {
            $data = $this->resolveNativeOauthData($code, $platform);
        } else {
            $data = $this->resolveProxyOauthData($request);
        }

        if ($data === null) {
            abort(400, $label . '授权失败，未获取到 openid');
        }

        // 中转模式（客户端站点中转，服务端纯跳板透传）：社交授权成功后不建服务端账号、
        // 不建服务端登录态，直接将社交真实 openid 与授权数据透传给客户端（签发不关联
        // 服务端用户的授权码），手机端跳提示页、客户端凭授权码走透传流程
        if ($isRelayMode) {
            return $this->relayPassthroughCallback($data, $platform, $state, $decodedState, $isRelayQrMode);
        }

        // 绑定模式：已登录用户发起绑定，校验通过 → 走绑定落库流程（不建立新登录态、不改变当前用户）
        if ($isBindMode) {
            if ($isPcBindMode) {
                return $this->bindPcOauthCallback($request, $data, $platform, $state);
            }
            return $this->bindOauthCallback($request, $data, $platform);
        }

        // 邀请注册模式下：先检查账号是否已存在——老用户直接登录，仅新用户需要邀请码
        $existingUser = $this->findOauthUser((string) $data['openid'], $platform);
        $needInvite = $existingUser === null
            && $this->settingService->get('invite_enabled', '0') === '1';

        // PC 扫码模式：解析/创建用户，将结果存 Cache，redirect 到提示页
        if ($isPcMode) {
            // 回调路由即平台真相源，统一覆写授权数据中的平台标识（与普通模式一致，
            // 防御外挂模式硬编码 wechat 导致 PC 分流页绑定校验平台不匹配）
            $data['platform'] = $platform;

            if ($needInvite) {
                // 新用户且开启邀请注册：授权数据暂存 Cache，PC 端轮询到 need_invite 后弹窗收集邀请码
                $token = bin2hex(random_bytes(16));
                Cache::put(self::CACHE_OAUTH_DATA_PREFIX . $token, $data, self::LOGIN_STATE_TTL);
                Cache::put(self::CACHE_LOGIN_RESULT_PREFIX . $state, [
                    'need_invite'  => true,
                    'invite_token' => $token,
                ], self::LOGIN_STATE_TTL);

                return redirect(ShareService::frontUrl('/auth/' . $platform . '/pc-success?result=invite'));
            }

            // 新用户（未开启邀请注册）：不建号，结果存 Cache，PC 端轮询到 need_setup 后跳分流页
            if ($existingUser === null) {
                $token = bin2hex(random_bytes(16));
                Cache::put(self::CACHE_OAUTH_DATA_PREFIX . $token, $data, self::LOGIN_STATE_TTL);
                Log::info('SSO分流: PC扫码新用户授权数据暂存', [
                    'token'    => $token,
                    'platform' => $platform,
                    'openid'   => (string) $data['openid'],
                ]);
                Cache::put(self::CACHE_LOGIN_RESULT_PREFIX . $state, [
                    'need_setup'  => true,
                    'setup_token' => $token,
                ], self::LOGIN_STATE_TTL);

                return redirect(ShareService::frontUrl('/auth/' . $platform . '/pc-success?result=setup'));
            }

            // 老用户：结果存 Cache 供 PC 端轮询建立登录态
            Cache::put(self::CACHE_LOGIN_RESULT_PREFIX . $state, [
                'user_id' => $existingUser->id,
            ], self::LOGIN_STATE_TTL);

            return redirect(ShareService::frontUrl('/auth/' . $platform . '/pc-success'));
        }

        // 普通模式（平台内/手机浏览器）：新用户且开启邀请注册时，redirect 到邀请码填写页
        // 登录页携带的跳转地址已在授权前暂存 session，此处取回并继续透传
        $redirect = (string) $request->session()->pull($redirectKey, '');

        // 回调路由即平台真相源，统一覆写授权数据中的平台标识（外挂模式硬编码 wechat 的防御性统一）
        $data['platform'] = $platform;

        if ($needInvite) {
            $token = bin2hex(random_bytes(16));
            Cache::put(self::CACHE_OAUTH_DATA_PREFIX . $token, $data, self::LOGIN_STATE_TTL);

            $inviteUrl = ShareService::frontUrl('/auth/' . $platform . '/complete-invite?token=' . $token);
            if ($redirect !== '') {
                $inviteUrl .= '&redirect=' . urlencode($redirect);
            }

            return redirect($inviteUrl);
        }

        // 新用户（未开启邀请注册）：不再自动建号，授权数据暂存 Cache，跳分流页（完善资料 / 账号绑定）
        if ($existingUser === null) {
            $token = bin2hex(random_bytes(16));
            Cache::put(self::CACHE_OAUTH_DATA_PREFIX . $token, $data, self::LOGIN_STATE_TTL);
            Log::info('SSO分流: 社交新用户授权数据暂存', [
                'token'    => $token,
                'platform' => $platform,
                'openid'   => (string) $data['openid'],
            ]);

            return redirect($this->pendingCompleteUrl($token, $redirect, $platform));
        }

        // 老用户：login 并 redirect
        try {
            return $this->loginOrCreateUser($request, $data, '', $redirect, $platform);
        } catch (\RuntimeException $e) {
            abort(400, $e->getMessage() . '，请返回登录页重试');
        }
    }

    /**
     * 中转社交回调（客户端站点中转，服务端纯跳板透传）
     *
     * 社交平台授权确认后进入此处：服务端不建账号、不绑定用户、不落任何用户数据，
     * 仅将社交真实 openid 与授权数据暂存，并向客户端签发不关联服务端用户的授权码：
     *   1. PC 扫码中转：授权码写入扫码会话（手机端跳提示页，客户端 PC 端轮询取回）；
     *   2. 整页跳转中转：浏览器 302 回客户端回调地址（code + state）。
     *
     * 防伪：中转上下文由服务端 authorize 端点构建（客户端与回调地址均已过白名单校验），
     * 扫码会话由 relay-qrcode 端点登记；伪造的会话标识无法取到会话内容，直接失败。
     *
     * @param array $data 社交授权数据（openid/nickname/avatar/access_token 等，社交平台真实数据）
     * @param string $platform 平台标识（wechat/qq）
     * @param string $state 社交回调 state（明文 relay_qr:{session_id} 或 Base64 中转上下文）
     * @param array $decodedState Base64 解码后的中转上下文（{ctx, uri}）
     * @param bool $isRelayQrMode 是否为 PC 扫码中转
     */
    protected function relayPassthroughCallback(array $data, string $platform, string $state, array $decodedState, bool $isRelayQrMode)
    {
        if ($isRelayQrMode) {
            return $this->relayQrPassthroughCallback($data, $platform, $state);
        }

        // 整页跳转中转：从社交回调 state 解码中转上下文（client_id + 客户端回调地址）
        $ctx = (string) ($decodedState['ctx'] ?? '');
        $redirectUri = (string) ($decodedState['uri'] ?? '');
        $clientId = str_starts_with($ctx, 'relay:') ? substr($ctx, strlen('relay:')) : '';
        $clientId = $clientId !== '' ? explode(':', $clientId)[0] : '';

        $client = $clientId !== '' ? $this->oauthService->findEnabledClient($clientId) : null;
        if (!$client || $redirectUri === '' || !$this->oauthService->isRedirectUriAllowed($client, $redirectUri)) {
            return response('中转授权上下文无效或已过期，请重新发起登录', 400);
        }

        // 原中转 state 已随社交授权消费，回客户端时须带回客户端原始 state（authorize 端点编入的字段 s），
        // 供客户端以会话自有暂存值比对，校验通过后才建立登录态；无 s（老 state 或扫码，扫码走独立分支）时不带
        $clientState = (string) ($decodedState['s'] ?? '');

        // 暂存社交授权数据（纯透传：服务端不落库，仅 Cache 短暂暂存供 code 换取）
        $dataToken = $this->oauthService->putGrantData($data);
        $code = $this->oauthService->issueAuthCode($client, $dataToken, $platform, $redirectUri);

        $query = ['code' => $code];
        if ($clientState !== '') {
            $query['state'] = $clientState;
        }

        return redirect($redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query($query));
    }

    /**
     * 中转扫码社交回调（PC 扫码纯透传）
     *
     * 社交授权数据暂存后向扫码会话写入授权码，手机端跳提示页，客户端 PC 端轮询取回。
     *
     * @param array $data 社交授权数据（社交平台真实数据）
     * @param string $platform 平台标识（wechat/qq）
     * @param string $state 扫码会话标识（relay_qr:{session_id}）
     */
    protected function relayQrPassthroughCallback(array $data, string $platform, string $state)
    {
        $sessionId = substr($state, strlen('relay_qr:'));
        $session = $this->oauthService->getRelayQrSession($sessionId);

        // 会话无效或已过期：不写入结果，手机端展示失败态
        if ($session === null || !isset($session['client_id'], $session['redirect_uri'])) {
            return redirect(ShareService::frontUrl('/auth/relay/pc-success?result=failed'));
        }

        $client = $this->oauthService->findEnabledClient((string) $session['client_id']);
        if (!$client || !$this->oauthService->isRedirectUriAllowed($client, (string) $session['redirect_uri'])) {
            return redirect(ShareService::frontUrl('/auth/relay/pc-success?result=failed'));
        }

        // 暂存社交授权数据（纯透传：服务端不落库），签发不关联服务端用户的授权码写入会话
        $dataToken = $this->oauthService->putGrantData($data);
        $code = $this->oauthService->issueAuthCode($client, $dataToken, $platform, (string) $session['redirect_uri']);

        $this->oauthService->putRelayQrResult($sessionId, [
            'code'     => $code,
            'platform' => $platform,
        ]);

        return redirect(ShareService::frontUrl('/auth/relay/pc-success'));
    }

    /**
     * 解码社交回调 state 中的中转上下文（authorize 端点 Base64 构建）
     *
     * @return array{ctx?: string, uri?: string} 非 Base64 中转 state 时返回空数组
     */
    protected function decodeRelayOauthState(string $state): array
    {
        if ($state === '' || str_starts_with($state, 'relay_qr:')) {
            return [];
        }

        $decoded = base64_decode(strtr($state, '-_', '+/'), true);
        if ($decoded === false) {
            return [];
        }

        $payload = json_decode($decoded, true);

        return is_array($payload) && isset($payload['ctx']) ? $payload : [];
    }

    /**
     * 社交绑定回调（微信/QQ 通用，绑定中心）
     *
     * 已登录用户在首页发起绑定，授权完成后进入此处落库：
     *   - openid 已被其他账号绑定 → 回首页提示冲突；
     *   - openid 未被绑定 → 创建 UserThirdParty 记录并回首页提示成功。
     *
     * 不建立新登录态、不改变当前登录用户。
     *
     * @param array $data 授权数据（openid/nickname/avatar/access_token 等）
     * @param string $platform 平台标识（wechat/qq）
     */
    protected function bindOauthCallback(Request $request, array $data, string $platform)
    {
        $user = auth('web')->user();
        $homeUrl = ShareService::frontUrl('/home');

        // 绑定过程中登录态丢失（session 过期等）：回登录页重新登录
        if (!$user) {
            return redirect(ShareService::frontUrl('/login'))->with('error', '登录已过期，请重新登录后再绑定');
        }

        $label = $this->platformUserLabel($platform);
        $openid = (string) $data['openid'];

        // openid 冲突检测：已被其他账号绑定时拒绝（一个 openid 仅能绑定一个账号）
        $conflict = UserThirdParty::where('platform', $platform)
            ->where('open_id', $openid)
            ->first();
        if ($conflict) {
            $message = $conflict->user_id === $user->id
                ? '已绑定' . $label . '，无需重复绑定'
                : '该' . $label . '已绑定其他账号，无法重复绑定';
            return redirect($homeUrl)->with('error', $message);
        }

        // 落库绑定关系（不改变当前登录态）
        UserThirdParty::create([
            'user_id'        => $user->id,
            'platform'       => $platform,
            'open_id'        => $openid,
            'union_id'       => $data['unionid'] ?? null,
            'nickname'       => $data['nickname'] ?? null,
            'avatar'         => $data['avatar'] !== '' ? $data['avatar'] : null,
            'access_token'   => $data['access_token'] ?? null,
            'refresh_token'  => $data['refresh_token'] ?? null,
            'raw_data'       => $data['raw_data'] ?? null,
        ]);

        return redirect($homeUrl)->with('success', $label . '绑定成功');
    }

    /**
     * 社交绑定 PC 扫码回调（绑定中心）
     *
     * PC 端弹二维码，用户用手机扫码授权后进入此处落库。由于扫码跨设备，
     * 绑定目标用户不依赖回调请求自身的登录态，而是从发起时暂存 Cache 的绑定目标取回。
     *
     * 处理结果统一写入绑定结果 Cache，PC 端通过 bind-status 接口轮询获知：
     *   - 冲突/失败 → 写 error，手机端跳绑定失败提示页；
     *   - 成功 → 写 bound，手机端跳绑定成功提示页。
     *
     * 手机端跳转目标必须是无需登录的提示页：手机端扫码时无登录态，
     * 跳受保护的 /home 会被认证中间件重定向到登录页（与扫码登录流程一致）。
     *
     * @param array $data 授权数据（openid/nickname/avatar/access_token 等）
     * @param string $platform 平台标识（wechat/qq）
     * @param string $state 绑定 state（用于取回目标用户与写入结果）
     */
    protected function bindPcOauthCallback(Request $request, array $data, string $platform, string $state)
    {
        // 手机端扫码授权后跳转无需登录的绑定结果提示页（PC 端轮询 bind-status 获取结果）
        $resultPageUrl = ShareService::frontUrl('/auth/' . $platform . '/pc-success');
        // 从发起时暂存的绑定目标（PC 端当前登录用户）取回；键为一次性，消费后即失效
        $bindData = Cache::pull(self::CACHE_BIND_STATE_PREFIX . $state);
        $ownerUserId = (int) ($bindData['user_id'] ?? 0);

        $label = $this->platformUserLabel($platform);
        $openid = (string) $data['openid'];

        // 绑定目标校验：无有效目标或账号已注销，向 PC 端回传失败结果
        if ($ownerUserId <= 0 || !User::find($ownerUserId)) {
            $message = '绑定目标不存在或已注销，无法完成绑定';
            Cache::put(self::CACHE_BIND_RESULT_PREFIX . $state, ['error' => $message], self::LOGIN_STATE_TTL);
            return redirect($resultPageUrl . '?result=bind_failed')->with('error', $message);
        }

        // openid 冲突检测：已被其他账号绑定时拒绝（一个 openid 仅能绑定一个账号）
        $conflict = UserThirdParty::where('platform', $platform)
            ->where('open_id', $openid)
            ->first();
        if ($conflict) {
            $message = $conflict->user_id === $ownerUserId
                ? '已绑定' . $label . '，无需重复绑定'
                : '该' . $label . '已绑定其他账号，无法重复绑定';
            Cache::put(self::CACHE_BIND_RESULT_PREFIX . $state, ['error' => $message], self::LOGIN_STATE_TTL);
            return redirect($resultPageUrl . '?result=bind_failed')->with('error', $message);
        }

        // 落库绑定关系到绑定目标用户（不依赖回调请求自身的登录态，不改变任何登录态）
        UserThirdParty::create([
            'user_id'        => $ownerUserId,
            'platform'       => $platform,
            'open_id'        => $openid,
            'union_id'       => $data['unionid'] ?? null,
            'nickname'       => $data['nickname'] ?? null,
            'avatar'         => $data['avatar'] !== '' ? $data['avatar'] : null,
            'access_token'   => $data['access_token'] ?? null,
            'refresh_token'  => $data['refresh_token'] ?? null,
            'raw_data'       => $data['raw_data'] ?? null,
        ]);

        // 通知 PC 端：绑定成功
        Cache::put(self::CACHE_BIND_RESULT_PREFIX . $state, ['bound' => true], self::LOGIN_STATE_TTL);

        return redirect($resultPageUrl . '?result=bind_success')->with('success', $label . '绑定成功');
    }

    /**
     * 手机验证码绑定（绑定中心：已登录用户绑定手机号）
     *
     * 校验规则：
     *   - 手机验证码登录功能需开启（复用短信配置）；
     *   - 当前账号未绑定手机号；
     *   - 目标手机号未被其他账号占用（手机号是登录凭据，全局唯一）。
     */
    public function bindPhone(Request $request): JsonResponse
    {
        $user = auth('web')->user();
        if (!$user) {
            return response()->json(['code' => 401, 'message' => '未登录'], 401);
        }

        if (! $this->settingService->isPhoneLoginEnabled()) {
            return response()->json(['code' => 400, 'message' => '手机验证码登录未启用，无法绑定手机号'], 400);
        }

        if ($user->phone) {
            return response()->json(['code' => 400, 'message' => '已绑定手机号，请先解绑'], 400);
        }

        $validated = $request->validate([
            'phone' => 'required|string|regex:/^1\d{10}$/',
            'code'  => 'required|string|size:6',
        ], [
            'phone.required' => '请输入手机号',
            'phone.regex'    => '手机号格式不正确',
            'code.required'  => '请输入验证码',
            'code.size'      => '验证码必须是6位',
        ]);

        $phone = $validated['phone'];

        // 手机号是登录凭据，必须全局唯一（未注册且未被其他账号绑定）
        if (User::where('phone', $phone)->exists()) {
            return response()->json(['code' => 422, 'message' => '该手机号已被其他账号使用'], 422);
        }

        $cachedCode = Cache::get(self::CACHE_PHONE_CODE_PREFIX . $phone);
        if (! $cachedCode || ! hash_equals((string) $cachedCode, (string) $validated['code'])) {
            return response()->json(['code' => 422, 'message' => '验证码不正确或已过期'], 422);
        }

        // 验证码一次性失效
        Cache::forget(self::CACHE_PHONE_CODE_PREFIX . $phone);

        $user->update(['phone' => $phone]);

        return response()->json(['code' => 0, 'message' => '手机号绑定成功']);
    }

    /**
     * 邮箱验证码绑定（绑定中心：已登录用户绑定邮箱）
     *
     * 校验规则：
     *   - 邮箱验证码登录功能需开启（复用邮箱配置）；
     *   - 当前账号未绑定邮箱；
     *   - 目标邮箱未被其他账号占用（邮箱是登录凭据，全局唯一）。
     */
    public function bindEmail(Request $request): JsonResponse
    {
        $user = auth('web')->user();
        if (!$user) {
            return response()->json(['code' => 401, 'message' => '未登录'], 401);
        }

        if (! $this->settingService->isEmailLoginEnabled()) {
            return response()->json(['code' => 400, 'message' => '邮箱验证码登录未启用，无法绑定邮箱'], 400);
        }

        if ($user->email) {
            return response()->json(['code' => 400, 'message' => '已绑定邮箱，请先解绑'], 400);
        }

        $validated = $request->validate([
            'email' => 'required|string|email|max:100',
            'code'  => 'required|string|size:6',
        ], [
            'email.required' => '请输入邮箱地址',
            'email.email'    => '邮箱格式不正确',
            'code.required'  => '请输入验证码',
            'code.size'      => '验证码必须是6位',
        ]);

        $email = strtolower(trim($validated['email']));

        // 邮箱是登录凭据，必须全局唯一（未注册且未被其他账号绑定）
        if (User::where('email', $email)->exists()) {
            return response()->json(['code' => 422, 'message' => '该邮箱已被其他账号使用'], 422);
        }

        $cachedCode = Cache::get(self::CACHE_EMAIL_CODE_PREFIX . $email);
        if (! $cachedCode || ! hash_equals((string) $cachedCode, (string) $validated['code'])) {
            return response()->json(['code' => 422, 'message' => '验证码不正确或已过期'], 422);
        }

        // 验证码一次性失效
        Cache::forget(self::CACHE_EMAIL_CODE_PREFIX . $email);

        $user->update(['email' => $email]);

        return response()->json(['code' => 0, 'message' => '邮箱绑定成功']);
    }

    /**
     * PC 扫码登录：轮询检查微信登录状态
     */
    public function wechatLoginStatus(Request $request): JsonResponse
    {
        return $this->oauthLoginStatus($request, 'wechat');
    }

    /**
     * PC 扫码登录：轮询检查 QQ 登录状态
     */
    public function qqLoginStatus(Request $request): JsonResponse
    {
        return $this->oauthLoginStatus($request, 'qq');
    }

    /**
     * PC 扫码登录：轮询检查登录状态（微信/QQ 通用）
     *
     * PC 端弹出二维码后，前端轮询此接口。检测到登录结果后在 PC 端建立 session。
     *
     * @param string $platform 平台标识（wechat/qq）
     */
    protected function oauthLoginStatus(Request $request, string $platform): JsonResponse
    {
        $label = $this->platformUserLabel($platform);
        $state = trim((string) $request->input('state', ''));
        if ($state === '') {
            return response()->json(['code' => 400, 'message' => '缺少 state'], 400);
        }

        $result = Cache::get(self::CACHE_LOGIN_RESULT_PREFIX . $state);
        if (!$result) {
            // 尚未登录
            return response()->json([
                'code'    => 0,
                'message' => 'ok',
                'data'    => ['logged_in' => false],
            ]);
        }

        // 新用户且开启邀请注册：返回 need_invite，PC 端弹窗收集邀请码后凭 token 提交。
        // 不清理结果 Cache，PC 端提交邀请码成功后直接跳转，过期前轮询可重复获得该状态。
        if (!empty($result['need_invite'])) {
            return response()->json([
                'code'    => 0,
                'message' => 'ok',
                'data'    => [
                    'logged_in'    => false,
                    'need_invite'  => true,
                    'invite_token' => (string) ($result['invite_token'] ?? ''),
                ],
            ]);
        }

        // 新用户且未开启邀请注册：返回 need_setup，PC 端跳分流页（完善资料 / 账号绑定）。
        // 不清理结果 Cache，过期前轮询可重复获得该状态（与 need_invite 一致）。
        if (!empty($result['need_setup'])) {
            $setupUrl = $this->pendingCompleteUrl(
                (string) ($result['setup_token'] ?? ''),
                (string) $request->input('redirect', ''),
                $platform
            );

            return response()->json([
                'code'    => 0,
                'message' => 'ok',
                'data'    => ['logged_in' => false, 'need_setup' => true, 'setup_url' => $setupUrl],
            ]);
        }

        // 已登录：清理 Cache，在 PC 端建立登录态
        Cache::forget(self::CACHE_LOGIN_RESULT_PREFIX . $state);

        // 扫码确认后建号失败（如邀请码消费失败）：向 PC 端返回具体错误
        if (isset($result['error'])) {
            return response()->json(['code' => 422, 'message' => (string) $result['error']], 422);
        }

        $userId = (int) ($result['user_id'] ?? 0);
        $user = User::find($userId);
        if (!$user) {
            return response()->json(['code' => 400, 'message' => '用户不存在'], 400);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->incrementLoginCount($request->ip());

        $this->writeLoginLog($user->id, $platform, $request, 1, $platform === 'qq' ? 'QQ扫码登录成功' : '微信扫码登录成功');

        return response()->json([
            'code'    => 0,
            'message' => '登录成功',
            'data'    => [
                'logged_in' => true,
                'redirect'  => $this->resolveRedirect($request),
            ],
        ]);
    }

    /**
     * PC 扫码绑定：轮询检查微信绑定状态（绑定中心）
     */
    public function wechatBindStatus(Request $request): JsonResponse
    {
        return $this->oauthBindStatus($request, 'wechat');
    }

    /**
     * PC 扫码绑定：轮询检查 QQ 绑定状态（绑定中心）
     */
    public function qqBindStatus(Request $request): JsonResponse
    {
        return $this->oauthBindStatus($request, 'qq');
    }

    /**
     * PC 扫码绑定：轮询检查绑定状态（微信/QQ 通用，绑定中心）
     *
     * PC 端弹出二维码后，前端轮询此接口获知扫码授权结果：
     *   - 结果为空 → 尚未扫码授权，返回 bound=false；
     *   - 有结果 → 一次性消费，成功返回 bound=true，失败返回具体错误。
     *
     * @param string $platform 平台标识（wechat/qq）
     */
    protected function oauthBindStatus(Request $request, string $platform): JsonResponse
    {
        $state = trim((string) $request->input('state', ''));
        if ($state === '') {
            return response()->json(['code' => 400, 'message' => '缺少 state'], 400);
        }

        $result = Cache::get(self::CACHE_BIND_RESULT_PREFIX . $state);
        if (!$result) {
            // 尚未扫码授权
            return response()->json([
                'code'    => 0,
                'message' => 'ok',
                'data'    => ['bound' => false],
            ]);
        }

        // 一次性结果：消费后即清理，避免重复绑定提示
        Cache::forget(self::CACHE_BIND_RESULT_PREFIX . $state);

        // 扫码授权确认后绑定失败（如 openid 冲突）：向 PC 端返回具体错误
        if (isset($result['error'])) {
            return response()->json(['code' => 422, 'message' => (string) $result['error']], 422);
        }

        return response()->json([
            'code'    => 0,
            'message' => '绑定成功',
            'data'    => ['bound' => true],
        ]);
    }

    /**
     * 快捷模式客户端：生成中转授权跳转地址（社交登录委托服务端）
     *
     * 登录页中转社交入口点击后调用，返回服务端 authorize 跳转地址。
     */
    public function relayLoginUrl(Request $request): JsonResponse
    {
        return $this->doRelayLoginUrl($request, (string) $request->input('platform', ''));
    }

    /**
     * 生成中转授权跳转地址（登录页中转入口与微信/QQ 登录入口客户端模式共用）
     *
     * state 存 session（复用社交授权会话键，platform=relay），回调时校验；回调地址固定为
     * /auth/relay/callback，与服务端客户端白名单中配置的地址一致。
     *
     * PC 端（device=pc）不整页跳转，改为扫码中转：调服务端 relay-qrcode 创建扫码会话，
     * 返回服务端授权页地址（客户端渲染为二维码，手机扫码在服务端完成登录授权），
     * PC 端凭 session_id 轮询 relay-status 取回授权码建立登录态。
     */
    protected function doRelayLoginUrl(Request $request, string $platform): JsonResponse
    {
        $relay = app(RelayClientService::class);
        if (!$relay->isConfigured()) {
            return response()->json(['code' => 400, 'message' => '快捷模式客户端未配置，请联系管理员'], 400);
        }

        $platform = trim($platform);
        if ($platform !== '' && !in_array($platform, array_column($relay->platforms(), 'platform'), true)) {
            return response()->json(['code' => 400, 'message' => '该登录方式未在服务端启用'], 400);
        }

        // PC 端扫码中转：创建服务端扫码会话，返回授权页地址（二维码内容）与轮询句柄
        if ((string) $request->input('device', '') === 'pc') {
            return $this->doRelayQrcodeLogin($request, $platform);
        }

        $state = md5(uniqid((string) mt_rand(), true));
        $request->session()->put($this->sessionStateKey('relay'), $state);
        $request->session()->put($this->sessionRedirectKey('relay'), trim((string) $request->input('redirect', '')));

        return response()->json([
            'code'    => 0,
            'message' => 'ok',
            'data'    => ['url' => $relay->authorizeUrl($platform, $state), 'mode' => 'relay'],
        ]);
    }

    /**
     * 快捷模式客户端：创建 PC 扫码中转会话
     *
     * 调服务端 relay-qrcode 创建扫码会话，返回服务端授权页地址（客户端渲染为二维码，
     * 手机扫码后在服务端完成登录授权），PC 端凭 session_id 轮询 relayLoginStatus。
     */
    protected function doRelayQrcodeLogin(Request $request, string $platform): JsonResponse
    {
        $relay = app(RelayClientService::class);

        $session = $relay->createQrSession($platform);
        if ($session === null) {
            return response()->json(['code' => 400, 'message' => '创建扫码会话失败，请联系管理员'], 400);
        }

        return response()->json([
            'code'    => 0,
            'message' => 'ok',
            'data'    => array_merge(['mode' => 'relay_qrcode'], $session),
        ]);
    }

    /**
     * 快捷模式客户端：轮询 PC 扫码中转结果
     *
     * PC 端弹出二维码后前端轮询此接口：调服务端 relay-status 查询扫码会话结果，
     * confirmed 时凭授权码换取访问令牌并取社交授权信息，按既有 relay 回调
     * 同样的分流逻辑处理（新用户跳完善资料/绑定页，老用户直接登录）。
     */
    public function relayLoginStatus(Request $request): JsonResponse
    {
        $relay = app(RelayClientService::class);
        if (!$relay->isConfigured()) {
            return response()->json(['code' => 400, 'message' => '快捷模式客户端未配置，请联系管理员'], 400);
        }

        $sessionId = trim((string) $request->input('session_id', ''));
        if ($sessionId === '') {
            return response()->json(['code' => 400, 'message' => '缺少 session_id'], 400);
        }

        $result = $relay->pullQrResult($sessionId);
        if ($result === null) {
            return response()->json([
                'code'    => 0,
                'message' => 'ok',
                'data'    => ['logged_in' => false],
            ]);
        }

        // 扫码确认后授权失败（如用户拒绝）：向 PC 端返回具体错误
        if (isset($result['error'])) {
            return response()->json(['code' => 422, 'message' => (string) $result['error']], 422);
        }

        // 授权码换取访问令牌 → 凭令牌取服务端用户信息
        $exchange = $relay->exchangeCode((string) $result['code']);
        if (!$exchange['success']) {
            Log::warning('快捷模式扫码中转登录失败', ['step' => 'exchange', 'message' => $exchange['message']]);

            return response()->json(['code' => 422, 'message' => $exchange['message']], 422);
        }

        $info = $relay->userinfo($exchange['access_token']);
        if ($info === null) {
            Log::warning('快捷模式扫码中转登录失败', ['step' => 'userinfo']);

            return response()->json(['code' => 422, 'message' => '获取社交授权信息失败，请重新扫码'], 422);
        }

        // 身份锚点：社交平台真实 openid（服务端纯跳板透传，不做任何数据保存）
        $data = [
            'openid'        => (string) $info['openid'],
            'unionid'       => $info['unionid'] ?? null,
            'nickname'      => (string) ($info['nickname'] ?? ''),
            'avatar'        => (string) ($info['avatar'] ?? ''),
            'access_token'  => (string) ($info['access_token'] ?? ''),
            'refresh_token' => (string) ($info['refresh_token'] ?? ''),
            'platform'      => 'relay',
            'raw_data'      => $info,
        ];

        // 新用户分流：与 relayCallback 一致——授权数据暂存 Cache，PC 端跳分流页
        // （邀请注册开启时跳邀请码页，否则跳完善资料/绑定页）
        $existingUser = $this->findOauthUser($data['openid'], 'relay');
        if ($existingUser === null) {
            $token = bin2hex(random_bytes(16));
            Cache::put(self::CACHE_OAUTH_DATA_PREFIX . $token, $data, self::LOGIN_STATE_TTL);

            return response()->json([
                'code'    => 0,
                'message' => 'ok',
                'data'    => [
                    'logged_in' => false,
                    'need_setup' => true,
                    'setup_url' => $this->relayCompleteUrl() . '&token=' . $token,
                ],
            ]);
        }

        // 老用户：建立 PC 端登录态
        Auth::guard('web')->login($existingUser);
        $request->session()->regenerate();
        $existingUser->incrementLoginCount($request->ip());

        $this->writeLoginLog($existingUser->id, 'relay', $request, 1, '快捷登录扫码成功');

        return response()->json([
            'code'    => 0,
            'message' => '登录成功',
            'data'    => [
                'logged_in' => true,
                'is_new'    => false,
                'redirect'  => $this->resolveRedirect($request),
            ],
        ]);
    }

    /**
     * 快捷模式新用户分流页地址（邀请注册开启时跳邀请码页，否则跳完善资料/绑定页）
     *
     * @param string $redirect 登录成功后的跳转地址（继续透传）
     */
    protected function relayCompleteUrl(string $redirect = ''): string
    {
        $targetUrl = $this->settingService->get('invite_enabled', '0') === '1'
            ? '/auth/relay/complete-invite'
            : '/auth/relay/complete';

        return ShareService::frontUrl($targetUrl . '?redirect=' . urlencode($redirect));
    }

    /**
     * 快捷模式客户端：服务端授权回调（浏览器跳转）
     *
     * 流程：校验 state（防 CSRF）→ 授权码换访问令牌 → 取社交授权信息 →
     * 以「社交平台真实 openid」为身份锚点查找/创建本地账号并登录。
     * 新用户跳分流页（完善资料 / 账号绑定）；邀请注册开启时先跳邀请码填写页。
     */
    public function relayCallback(Request $request)
    {
        if (!$this->settingService->isRelayClient()) {
            abort(404);
        }

        $relay = app(RelayClientService::class);

        // state 校验（防 CSRF）：与会话中暂存值比对，一次性消费；跳转地址随会话取回继续透传
        $expectedState = (string) $request->session()->get($this->sessionStateKey('relay'), '');
        $state = (string) $request->query('state', '');
        $redirect = (string) $request->session()->pull($this->sessionRedirectKey('relay'), '');

        if ($expectedState === '' || $state === '' || !hash_equals($expectedState, $state)) {
            return redirect(ShareService::frontUrl('/login'))->with('error', '授权状态校验失败，请重新登录');
        }
        $request->session()->forget($this->sessionStateKey('relay'));

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return redirect(ShareService::frontUrl('/login'))->with('error', '未获取到授权码，请重新登录');
        }

        // 授权码换访问令牌（服务器间调用服务端 token 端点）
        $exchange = $relay->exchangeCode($code);
        if (!$exchange['success']) {
            Log::warning('快捷模式中转登录失败', ['step' => 'exchange', 'message' => $exchange['message']]);

            return redirect(ShareService::frontUrl('/login'))->with('error', $exchange['message']);
        }

        // 凭访问令牌取社交授权信息
        $info = $relay->userinfo($exchange['access_token']);
        if ($info === null) {
            Log::warning('快捷模式中转登录失败', ['step' => 'userinfo']);

            return redirect(ShareService::frontUrl('/login'))->with('error', '获取社交授权信息失败，请重新登录');
        }

        // 身份锚点：社交平台真实 openid（服务端纯跳板透传，不做任何数据保存）
        $data = [
            'openid'        => (string) $info['openid'],
            'unionid'       => $info['unionid'] ?? null,
            'nickname'      => (string) ($info['nickname'] ?? ''),
            'avatar'        => (string) ($info['avatar'] ?? ''),
            'access_token'  => (string) ($info['access_token'] ?? ''),
            'refresh_token' => (string) ($info['refresh_token'] ?? ''),
            'platform'      => 'relay',
            'raw_data'      => $info,
        ];

        // 新用户分流：邀请注册开启时先跳邀请码填写页；否则跳完善资料页，
        // 页内提供「完善资料（新建账号）」与「账号绑定（登录已有账号）」两种选择。
        // 授权数据暂存 Cache（凭 token 一次性取回，与邀请码收集流程一致）。
        $existingUser = $this->findOauthUser($data['openid'], 'relay');
        if ($existingUser === null) {
            $token = bin2hex(random_bytes(16));
            Cache::put(self::CACHE_OAUTH_DATA_PREFIX . $token, $data, self::LOGIN_STATE_TTL);
            Log::info('SSO分流: 快捷登录新用户授权数据暂存', [
                'token'  => $token,
                'openid' => (string) $data['openid'],
            ]);

            $targetUrl = $this->settingService->get('invite_enabled', '0') === '1'
                ? '/auth/relay/complete-invite'
                : '/auth/relay/complete';
            $nextUrl = ShareService::frontUrl($targetUrl . '?token=' . $token);
            if ($redirect !== '') {
                $nextUrl .= '&redirect=' . urlencode($redirect);
            }

            return redirect($nextUrl);
        }

        try {
            return $this->loginOrCreateUser($request, $data, '', $redirect, 'relay');
        } catch (\RuntimeException $e) {
            return redirect(ShareService::frontUrl('/login'))->with('error', $e->getMessage());
        }
    }

    /**
     * 原生模式：用 code 换 openid + 用户信息（各社交平台通用，统一 Service 接口形状）
     *
     * @param string $code 授权 code
     * @param string $platform 平台标识（wechat/qq/alipay/douyin/weibo/github）
     */
    protected function resolveNativeOauthData(string $code, string $platform): ?array
    {
        $openidService = $this->socialService($platform);
        $result = $openidService->getOpenidByOauthCode($code);

        if (!$result['success'] || $result['openid'] === '') {
            return null;
        }

        $userInfo = $openidService->getUserInfoByToken($result['access_token'] ?? '', $result['openid']);
        if (!$userInfo['success']) {
            $userInfo = [];
        }

        return [
            'openid'        => $result['openid'],
            'unionid'       => $result['unionid'] ?? null,
            'nickname'      => $userInfo['nickname'] ?? '',
            'avatar'        => $userInfo['avatar'] ?? '',
            'access_token'  => $result['access_token'] ?? '',
            'refresh_token' => $result['refresh_token'] ?? '',
            'platform'      => $platform,
            'raw_data'      => $userInfo ?: null,
        ];
    }

    /**
     * 外挂模式：从回传参数读取 openid + 用户信息
     */
    protected function resolveProxyOauthData(Request $request): ?array
    {
        $openid = trim((string) $request->query('openid', ''));
        if ($openid === '') {
            return null;
        }

        $accessToken  = trim((string) $request->query('access_token', ''));
        $refreshToken = trim((string) $request->query('refresh_token', ''));
        $unionid      = trim((string) $request->query('unionid', ''));

        $userInfo = [
            'openid'   => $openid,
            'nickname' => (string) $request->query('nickname', ''),
            'avatar'   => (string) $request->query('headimgurl', ''),
        ];

        return [
            'openid'        => $openid,
            'unionid'       => $unionid !== '' ? $unionid : null,
            'nickname'      => $userInfo['nickname'],
            'avatar'        => $userInfo['avatar'],
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'platform'      => 'wechat',
            'raw_data'      => $userInfo,
        ];
    }

    /**
     * 已绑定老用户社交登录（建立登录态并跳转）
     *
     * 仅处理 findOauthUser 已命中的老用户；新用户一律经授权数据暂存 +
     * 分流页（完善资料 / 账号绑定）完成建号或绑定，不再自动建号。
     *
     * @param Request $request
     * @param array $data 必须包含 openid，可选 unionid/nickname/avatar/access_token/refresh_token/raw_data
     * @param string $inviteCode 社交登录携带的邀请码（可为空）
     * @param string $redirect 登录页携带的跳转地址（可为空，空时按请求参数解析）
     * @param string $platform 平台标识（wechat/qq），默认 wechat
     */
    protected function loginOrCreateUser(Request $request, array $data, string $inviteCode = '', string $redirect = '', string $platform = 'wechat')
    {
        try {
            [$user] = $this->resolveOrCreateUser($data, $inviteCode, $platform);
        } catch (\RuntimeException $e) {
            // 建号失败（如邀请码消费失败）时未建立登录态，异常交由调用方处理
            throw $e;
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->incrementLoginCount($request->ip());

        $this->writeLoginLog($user->id, $platform, $request, 1, $platform === 'qq' ? 'QQ登录成功' : '微信登录成功');

        return redirect($this->resolveRedirect($request, $redirect));
    }

    /**
     * 根据 openid 查找已绑定的用户（不存在返回 null）
     *
     * @param string $openid 第三方平台 openid
     * @param string $platform 平台标识（wechat/qq），默认 wechat
     */
    protected function findOauthUser(string $openid, string $platform = 'wechat'): ?User
    {
        $binding = UserThirdParty::where('platform', $platform)
            ->where('open_id', $openid)
            ->first();

        if ($binding && $binding->user_id) {
            $user = User::find($binding->user_id);
            if ($user) {
                return $user;
            }
        }

        return null;
    }

    /**
     * 提交邀请码完成社交新用户注册（微信/QQ 通用）
     *
     * 适用于两种场景（邀请注册开启 + 授权确认是新用户后）：
     *   1. PC 扫码：PC 端轮询到 need_invite 后弹窗收集邀请码提交；
     *   2. 端内授权：回调 redirect 到 complete-invite 页面填写提交。
     * 建号成功即建立登录态。
     */
    public function submitInvite(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token'       => 'required|string|size:32',
            'invite_code' => 'required|string|max:32',
        ], [
            'invite_code.required' => '请输入邀请码',
        ]);

        $data = Cache::pull(self::CACHE_OAUTH_DATA_PREFIX . $validated['token']);
        if (!$data || !is_array($data)) {
            return response()->json(['code' => 400, 'message' => '登录会话已过期，请重新扫码登录'], 400);
        }

        // 授权数据携带平台标识（resolveNativeOauthData/resolveProxyOauthData 写入）
        $platform = (string) ($data['platform'] ?? 'wechat');

        try {
            [$user] = $this->resolveOrCreateUser($data, $validated['invite_code'], $platform);
        } catch (\RuntimeException $e) {
            // 邀请码无效等：授权数据放回，允许用户重新填写
            Cache::put(self::CACHE_OAUTH_DATA_PREFIX . $validated['token'], $data, self::LOGIN_STATE_TTL);

            return response()->json(['code' => 422, 'message' => $e->getMessage()], 422);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->incrementLoginCount($request->ip());

        $this->writeLoginLog($user->id, $platform, $request, 1, $platform === 'qq' ? 'QQ登录成功（邀请码注册）' : '微信登录成功（邀请码注册）');

        return response()->json([
            'code'    => 0,
            'message' => '登录成功',
            'data'    => [
                'redirect' => $this->resolveRedirect($request),
            ],
        ]);
    }

    /**
     * 快捷模式新用户：选择「完善资料」创建新账号
     *
     * 客户端完善资料页 Tab1 提交：凭 token 取回暂存的授权数据，
     * 创建本地账号并绑定 relay 身份，随后建立登录态。
     * 邀请注册开启时本接口不可用（需先经 complete-invite 页消费邀请码）。
     */
    public function setupAccount(Request $request): JsonResponse
    {
        if (!$this->settingService->isRelayClient()) {
            return response()->json(['code' => 400, 'message' => '快捷模式客户端未开启'], 400);
        }

        return $this->createAccountFromToken($request, (string) $request->input('token', ''), 'relay', '快捷登录注册成功');
    }

    /**
     * 凭授权句柄创建账号（社交新用户分流页「完善资料」Tab 通用，relay 与独立站社交平台共用）
     *
     * 凭 token 取回暂存的授权数据，创建本地账号并绑定该平台身份，补充分流页填写的
     * 昵称/手机号后建立登录态。建号失败（如并发下绑定已存在）时授权数据放回允许重试。
     *
     * @param string $expectedPlatform 期望的授权数据平台标识（relay/wechat/qq/...）
     * @param string $logMessage 登录日志描述文案
     */
    protected function createAccountFromToken(Request $request, string $token, string $expectedPlatform, string $logMessage): JsonResponse
    {
        $validated = $request->validate([
            'token'    => 'required|string|size:32',
            'nickname' => 'nullable|string|max:50',
            'phone'    => 'nullable|string|max:20',
        ]);

        $data = Cache::pull(self::CACHE_OAUTH_DATA_PREFIX . $validated['token']);
        if (!$data || !is_array($data) || (string) ($data['platform'] ?? '') !== $expectedPlatform) {
            // 诊断日志：定位建号校验失败（token 过期/伪造/平台不一致）
            Log::warning('SSO分流: 完善资料建号校验失败', [
                'token'  => $validated['token'],
                'expect' => $expectedPlatform,
                'exists' => is_array($data),
                'actual' => is_array($data) ? (string) ($data['platform'] ?? '') : null,
            ]);

            return response()->json(['code' => 400, 'message' => '登录会话已过期，请重新登录'], 400);
        }

        try {
            [$user] = $this->resolveOrCreateUser($data, '', $expectedPlatform);
        } catch (\RuntimeException $e) {
            // 建号失败（如并发下绑定已存在等）：授权数据放回，允许用户重试
            Cache::put(self::CACHE_OAUTH_DATA_PREFIX . $validated['token'], $data, self::LOGIN_STATE_TTL);

            return response()->json(['code' => 422, 'message' => $e->getMessage()], 422);
        }

        // 建号成功后补充用户在分流页填写的昵称/手机号
        $profile = array_filter([
            'nickname' => (string) ($validated['nickname'] ?? ''),
            'phone'    => (string) ($validated['phone'] ?? ''),
        ]);
        if (!empty($profile)) {
            $user->update($profile);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->incrementLoginCount($request->ip());

        $this->writeLoginLog($user->id, $expectedPlatform, $request, 1, $logMessage);

        return response()->json([
            'code'    => 0,
            'message' => '账号创建成功',
            'data'    => ['redirect' => $this->resolveRedirect($request)],
        ]);
    }

    /**
     * 快捷模式新用户：选择「账号绑定」登录已有账号
     *
     * 客户端完善资料页 Tab2 提交：凭账号（用户名/手机号/邮箱）+ 密码校验已有账号，
     * 校验通过后将 relay 身份绑定到该账号并切换登录态。
     */
    public function bindAccount(Request $request): JsonResponse
    {
        if (!$this->settingService->isRelayClient()) {
            return response()->json(['code' => 400, 'message' => '快捷模式客户端未开启'], 400);
        }

        return $this->bindTokenToAccount($request, (string) $request->input('token', ''), 'relay', '快捷登录绑定已有账号');
    }

    /**
     * 凭授权句柄绑定既有账号（社交新用户分流页「账号绑定」Tab 通用，relay 与独立站社交平台共用）
     *
     * 凭账号（用户名/手机号/邮箱）+ 密码校验已有账号，校验通过后将该平台身份
     * 绑定到该账号并切换登录态。失败锁定策略与账号密码登录一致（连续失败 5 次锁定 15 分钟）。
     *
     * @param string $expectedPlatform 期望的授权数据平台标识（relay/wechat/qq/...）
     * @param string $logMessage 登录日志描述文案
     */
    protected function bindTokenToAccount(Request $request, string $token, string $expectedPlatform, string $logMessage): JsonResponse
    {
        $validated = $request->validate([
            'token'    => 'required|string|size:32',
            'account'  => 'required|string',
            'password' => 'required|string',
        ], [
            'account.required'  => '请输入账号',
            'password.required' => '请输入密码',
        ]);

        $data = Cache::get(self::CACHE_OAUTH_DATA_PREFIX . $validated['token']);
        if (!$data || !is_array($data) || (string) ($data['platform'] ?? '') !== $expectedPlatform) {
            // 诊断日志：定位绑定校验失败（token 过期/伪造/平台不一致）
            Log::warning('SSO分流: 账号绑定校验失败', [
                'token'  => $validated['token'],
                'expect' => $expectedPlatform,
                'exists' => is_array($data),
                'actual' => is_array($data) ? (string) ($data['platform'] ?? '') : null,
            ]);

            return response()->json(['code' => 400, 'message' => '登录会话已过期，请重新登录'], 400);
        }

        $result = $this->verifyBindCredentials($validated['account'], $validated['password']);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $target = $result;

        // 校验通过，消费授权数据（并发下绑定关系可能已被建立，openid 已被绑定时拒绝）
        $openid = (string) $data['openid'];
        if (!$this->bindOauthToUser($data, $openid, $target, $expectedPlatform)) {
            Cache::forget(self::CACHE_OAUTH_DATA_PREFIX . $validated['token']);

            return response()->json(['code' => 400, 'message' => '该平台身份已绑定其他账号，请直接登录'], 400);
        }
        Cache::forget(self::CACHE_OAUTH_DATA_PREFIX . $validated['token']);

        Auth::guard('web')->login($target);
        $request->session()->regenerate();
        $target->incrementLoginCount($request->ip());

        $this->writeLoginLog($target->id, $expectedPlatform, $request, 1, $logMessage);

        return response()->json([
            'code'    => 0,
            'message' => '绑定成功',
            'data'    => ['redirect' => $this->resolveRedirect($request)],
        ]);
    }

    /**
     * 绑定场景的账号密码校验（账号锁定检查 + 密码验证 + 失败计数）
     *
     * 校验失败时返回错误响应（429 锁定 / 400 密码错误），成功返回目标用户。
     * 失败锁定策略与账号密码登录一致（连续失败 5 次锁定 15 分钟）。
     *
     * @return User|JsonResponse
     */
    protected function verifyBindCredentials(string $account, string $password): object
    {
        $lockKey = 'cmspro_sso_login_lock:' . md5(strtolower($account));

        // 账号已被锁定
        if (Cache::has($lockKey)) {
            $remaining = (int) ceil(Cache::get($lockKey) / 60);

            return response()->json([
                'code'    => 429,
                'message' => '登录失败次数过多，请 ' . $remaining . ' 分钟后再试',
            ], 429);
        }

        // 仅取身份字段（用户名/手机号/邮箱）查询，密码单独校验
        $identity = $this->resolveCredentials($account, '');
        unset($identity['password']);

        $target = User::where($identity)
            ->where('status', 1)
            ->first();
        if (!$target || !Hash::check($password, $target->password)) {
            // 与账号密码登录共用失败计数与锁定策略
            $failKey = 'cmspro_sso_login_fail:' . md5(strtolower($account));
            $failures = (int) Cache::get($failKey, 0) + 1;
            if ($failures >= self::MAX_FAILURES) {
                Cache::put($lockKey, self::LOCK_MINUTES * 60, self::LOCK_MINUTES * 60);
                Cache::forget($failKey);

                return response()->json([
                    'code'    => 429,
                    'message' => '登录失败次数过多，账号已锁定 ' . self::LOCK_MINUTES . ' 分钟',
                ], 429);
            }
            Cache::put($failKey, $failures, self::LOCK_MINUTES * 60);

            return response()->json(['code' => 400, 'message' => '账号或密码错误'], 400);
        }

        return $target;
    }

    /**
     * 将社交身份绑定到目标账号（并发安全：openid 已被绑定时返回 false，不落库）
     */
    protected function bindOauthToUser(array $data, string $openid, User $target, string $platform): bool
    {
        $conflict = UserThirdParty::where('platform', $platform)->where('open_id', $openid)->first();
        if ($conflict) {
            return false;
        }

        DB::transaction(function () use ($data, $openid, $target, $platform) {
            UserThirdParty::create([
                'user_id'        => $target->id,
                'platform'       => $platform,
                'open_id'        => $openid,
                'union_id'       => $data['unionid'] ?? null,
                'nickname'       => $data['nickname'] ?? null,
                'avatar'         => ($data['avatar'] ?? '') !== '' ? $data['avatar'] : null,
                'access_token'   => $data['access_token'] ?? null,
                'refresh_token'  => $data['refresh_token'] ?? null,
                'raw_data'       => $data['raw_data'] ?? null,
            ]);
        });

        return true;
    }

    /**
     * 解析或创建用户（不建立登录态，由调用方决定如何 login）
     *
     * 邀请注册开启时，新用户建号需在事务内校验并消费邀请码；
     * 已绑定老用户登录不消费邀请码。
     *
     * @param array $data 必须包含 openid，可选 unionid/nickname/avatar/access_token/refresh_token/raw_data
     * @param string $inviteCode 登录携带的邀请码（可为空；邀请注册模式下新用户必须有效）
     * @param string $platform 平台标识（wechat/qq），默认 wechat
     * @return array [User, bool $isNew]
     * @throws \RuntimeException 邀请码缺失、无效或消费失败时抛出（未建号、未建立登录态）
     */
    protected function resolveOrCreateUser(array $data, string $inviteCode = '', string $platform = 'wechat'): array
    {
        $openid = $data['openid'];
        $unionid = $data['unionid'] ?? null;

        // 已绑定用户直接返回（调用方保证：邀请注册开启且为新用户时不会走到这里）
        $existing = $this->findOauthUser($openid, $platform);
        if ($existing) {
            return [$existing, false];
        }

        // 首次登录：创建用户并绑定
        $nickname = $data['nickname'] !== '' ? $data['nickname'] : ($this->platformUserLabel($platform) . substr($openid, -4));
        $avatar = $data['avatar'] !== '' ? $data['avatar'] : null;
        $defaultGroup = UserGroup::default()->enabled()->first();

        // 用户名：{platform缩写}_ + openid 后10位，若冲突追加随机后缀保证唯一
        $baseUsername = $this->platformPrefix($platform) . substr($openid, -10);
        $username = $baseUsername;
        $uniqueKey = md5($openid);
        while (User::where('username', $username)->exists()) {
            $username = $baseUsername . '_' . substr($uniqueKey, 0, 6);
            break;
        }

        // 设置一个随机的不可登录密码（保证非空，避免数据库中 password 字段无默认值报错）
        $randomPassword = $this->platformPrefix($platform) . '_' . bin2hex(random_bytes(16));

        // 邀请注册开启时，新用户建号必须在事务内消费邀请码（失败则不建号）
        $inviteRequired = $this->settingService->get('invite_enabled', '0') === '1';
        $inviteCode = strtoupper(trim($inviteCode));
        if ($inviteRequired && $inviteCode === '') {
            throw new \RuntimeException('本站开启邀请注册，' . $this->platformUserLabel($platform) . '登录需携带邀请码');
        }

        try {
            $user = DB::transaction(function () use ($data, $openid, $unionid, $nickname, $avatar, $defaultGroup, $username, $randomPassword, $inviteRequired, $inviteCode, $platform) {
                $user = User::create([
                    'username' => $username,
                    'password' => Hash::make($randomPassword),
                    'nickname' => $nickname,
                    'avatar'   => $avatar,
                    'group_id' => $defaultGroup?->id,
                    'status'   => 1,
                ]);

                UserThirdParty::create([
                    'user_id'        => $user->id,
                    'platform'       => $platform,
                    'open_id'        => $openid,
                    'union_id'       => $unionid,
                    'nickname'       => $data['nickname'] ?? null,
                    'avatar'         => $avatar,
                    'access_token'   => $data['access_token'] ?? null,
                    'refresh_token'  => $data['refresh_token'] ?? null,
                    'raw_data'       => $data['raw_data'] ?? null,
                ]);

                if ($inviteRequired) {
                    $error = $this->consumeInviteCode($inviteCode, $user->id);
                    if ($error !== null) {
                        throw new \RuntimeException($error);
                    }
                }

                return $user;
            });
        } catch (\RuntimeException $e) {
            // 事务回滚（未建号），异常交由调用方转为用户可读的错误提示
            throw $e;
        }

        return [$user, true];
    }

    /**
     * 平台用户名前缀（首次社交登录自动建号用）
     */
    protected function platformPrefix(string $platform): string
    {
        return match ($platform) {
            'qq'     => 'qq',
            'alipay' => 'ali',
            'douyin' => 'dy',
            'weibo'  => 'wb',
            'github' => 'gh',
            default  => 'wx',
        };
    }

    /**
     * 平台中文标签
     */
    protected function platformUserLabel(string $platform): string
    {
        return SettingService::platformLabel($platform) . '用户';
    }

    /**
     * 平台 → OAuth 服务实例映射（统一接口形状，新增平台在此登记）
     */
    protected function socialService(string $platform): object
    {
        return match ($platform) {
            'qq'     => app(QqOauthService::class),
            'alipay' => app(AlipayOauthService::class),
            'douyin' => app(DouyinOauthService::class),
            'weibo'  => app(WeiboOauthService::class),
            'github' => app(GithubOauthService::class),
            default  => app(WechatOauthService::class),
        };
    }

    /**
     * 独立站社交新用户分流页「完善资料」Tab：凭授权句柄创建账号并登录
     *
     * OAuth 回调发现新用户时不再自动建号，授权数据暂存 Cache 后跳分流页；
     * 用户选择「完善资料」凭 token 建号，选择「账号绑定」走 bindToExistingAccount。
     *
     * @param string $platform 平台标识（wechat/qq/alipay/douyin/weibo/github），由路由 {platform} 注入
     */
    public function completeProfile(Request $request, string $platform): JsonResponse
    {
        // 仅对独立站社交平台开放（relay 快捷模式建号沿用既有 setupAccount 流程）
        if (!in_array($platform, ['wechat', 'qq', 'alipay', 'douyin', 'weibo', 'github'], true)) {
            return response()->json(['code' => 400, 'message' => '不支持的登录平台'], 400);
        }

        return $this->createAccountFromToken(
            $request,
            (string) $request->input('token', ''),
            $platform,
            SettingService::platformLabel($platform) . '登录注册成功'
        );
    }

    /**
     * 独立站社交新用户分流页「账号绑定」Tab：凭授权句柄将社交身份绑定到既有账号并登录
     *
     * OAuth 回调发现新用户时不再自动建号，授权数据暂存 Cache 后跳分流页；
     * 用户选择「账号绑定」输入既有账号+密码，校验通过后将该平台身份绑定到目标账号并登录。
     * 失败锁定策略与账号密码登录一致（连续失败 5 次锁定 15 分钟）。
     *
     * @param string $platform 平台标识（wechat/qq/alipay/douyin/weibo/github），由路由 {platform} 注入
     */
    public function bindToExistingAccount(Request $request, string $platform): JsonResponse
    {
        // 仅对独立站社交平台开放（relay 快捷模式绑定时沿用既有 bindAccount 流程）
        if (!in_array($platform, ['wechat', 'qq', 'alipay', 'douyin', 'weibo', 'github'], true)) {
            return response()->json(['code' => 400, 'message' => '不支持的绑定平台'], 400);
        }

        return $this->bindTokenToAccount($request, (string) $request->input('token', ''), $platform, '社交登录绑定既有账号');
    }

    /**
     * 预校验邀请码可用性（不消费），用于微信登录获取授权 URL 前的提前反馈
     */
    protected function checkInviteCodeUsable(string $code): ?string
    {
        $invite = InviteCode::where('code', strtoupper(trim($code)))->first();

        if (!$invite) {
            return '邀请码不存在';
        }

        if ((int) $invite->status !== InviteCode::STATUS_UNUSED) {
            return '邀请码已被使用或已禁用';
        }

        return null;
    }

    /**
     * 消费邀请码（原子条件更新，防并发重复消费）
     *
     * @param string $code 用户输入的邀请码
     * @param int $userId 消费该邀请码的用户ID
     * @return string|null 错误信息，null 表示消费成功
     */
    protected function consumeInviteCode(string $code, int $userId): ?string
    {
        $invite = InviteCode::where('code', strtoupper(trim($code)))->first();

        if (!$invite) {
            return '邀请码不存在';
        }

        if ((int) $invite->status !== InviteCode::STATUS_UNUSED) {
            return '邀请码已被使用或已禁用';
        }

        // 条件更新保证并发下仅消费一次
        $affected = InviteCode::where('id', $invite->id)
            ->where('status', InviteCode::STATUS_UNUSED)
            ->update([
                'status'    => InviteCode::STATUS_USED,
                'used_by'   => $userId,
                'used_at'   => now(),
                'update_time' => now(),
            ]);

        return $affected ? null : '邀请码已被使用';
    }

    /**
     * 根据账号类型解析登录凭据（邮箱/手机号/用户名）
     */
    protected function resolveCredentials(string $account, string $password): array
    {
        if (filter_var($account, FILTER_VALIDATE_EMAIL)) {
            return ['email' => $account, 'password' => $password];
        }

        if (preg_match('/^1\d{10}$/', $account)) {
            return ['phone' => $account, 'password' => $password];
        }

        return ['username' => $account, 'password' => $password];
    }

    /**
     * 解析登录成功后的跳转地址
     *
     * @param string $fallback 请求参数未携带时的兜底地址（如微信授权前暂存的跳转地址）
     */
    protected function resolveRedirect(Request $request, string $fallback = ''): string
    {
        $redirect = trim((string) $request->input('redirect', ''));
        if ($redirect === '' && $fallback !== '') {
            $redirect = $fallback;
        }

        if ($redirect !== '' && $this->isSafeRedirect($redirect)) {
            return $redirect;
        }

        return $this->settingService->getDefaultRedirect();
    }

    /**
     * 首次登录完善资料页地址（携带跳转地址继续透传）
     *
     * @param string $redirect 跳转地址
     * @param string $platform 平台标识（wechat/qq），默认 wechat
     */
    protected function completeUrl(string $redirect = '', string $platform = 'wechat'): string
    {
        $url = ShareService::frontUrl('/auth/' . $platform . '/complete');
        if ($redirect !== '' && $this->isSafeRedirect($redirect)) {
            $url .= '?redirect=' . urlencode($redirect);
        }

        return $url;
    }

    /**
     * 社交新用户分流页地址（完善资料 / 账号绑定，凭 token 完成建号或绑定）
     *
     * @param string $token 授权数据暂存句柄
     * @param string $redirect 跳转地址（仅在站内安全时拼入）
     * @param string $platform 平台标识（wechat/qq/alipay/douyin/weibo/github/relay）
     */
    protected function pendingCompleteUrl(string $token, string $redirect = '', string $platform = 'wechat'): string
    {
        $url = ShareService::frontUrl('/auth/' . $platform . '/complete?token=' . $token);
        if ($redirect !== '' && $this->isSafeRedirect($redirect)) {
            $url .= '&redirect=' . urlencode($redirect);
        }

        return $url;
    }

    /**
     * 校验跳转地址是否为站内安全地址（防止开放重定向）
     */
    protected function isSafeRedirect(string $url): bool
    {
        // 相对路径（站内）
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }

        // 绝对地址需校验 host 与当前站点同主域（含兄弟子域，如 sso → bbs）
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === '') {
            return false;
        }

        return $this->sameSiteHost($host, request()->getHost());
    }

    /**
     * 判断两个 host 是否同主域（完全相同或共享注册主域）
     */
    protected function sameSiteHost(string $host1, string $host2): bool
    {
        $host1 = strtolower(trim($host1, '.'));
        $host2 = strtolower(trim($host2, '.'));

        if ($host1 === $host2) {
            return true;
        }

        $domain = $this->registrableDomain($host1);

        return $domain !== '' && $domain === $this->registrableDomain($host2);
    }

    /**
     * 取 host 的注册主域（如 bbs.cmspro.cn → cmspro.cn）
     *
     * 对常见二级后缀（com.cn/net.cn 等）取三段，其余取两段；无点号 host 返回空串
     */
    protected function registrableDomain(string $host): string
    {
        $parts = explode('.', $host);
        $count = count($parts);
        if ($count < 2) {
            return '';
        }

        $secondLevelSuffixes = ['com', 'net', 'org', 'gov', 'edu', 'ac', 'co'];
        if ($count >= 3 && in_array($parts[$count - 2], $secondLevelSuffixes, true)) {
            return implode('.', array_slice($parts, -3));
        }

        return implode('.', array_slice($parts, -2));
    }

    /**
     * 写入登录日志
     */
    protected function writeLoginLog(?int $userId, string $platform, Request $request, int $status, string $message): void
    {
        try {
            LoginLog::create([
                'user_id'    => $userId,
                'platform'   => $platform,
                'ip'         => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'status'     => $status,
                'message'    => $message,
            ]);
        } catch (\Throwable $e) {
            Log::warning('写入登录日志失败', ['error' => $e->getMessage()]);
        }
    }
}
