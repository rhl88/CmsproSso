<?php

namespace App\Apps\CmsproSso\Controllers\HomeApi;

use App\Apps\CmsproSso\Models\InviteCode;
use App\Apps\CmsproSso\Models\LoginLog;
use App\Apps\CmsproSso\Services\SettingService;
use App\Apps\CmsproSso\Services\ShareService;
use App\Apps\CmsproSso\Services\SmsService;
use App\Apps\CmsproSso\Services\WechatOauthService;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    // 账号维度登录失败锁定配置
    protected const MAX_FAILURES = 5;      // 最大连续失败次数
    protected const LOCK_MINUTES = 15;     // 锁定分钟数

    // Session 键：微信授权 state（CSRF 防护）
    protected const SESSION_OAUTH_STATE = 'cmspro_sso_wechat_oauth_state';

    // Cache 键前缀：PC 扫码登录 state + 登录结果（跨设备扫码场景）
    protected const CACHE_LOGIN_STATE_PREFIX = 'cmspro_sso_login_state:';
    protected const CACHE_LOGIN_RESULT_PREFIX = 'cmspro_sso_login_result:';
    // Cache 键前缀：待建号新用户的微信授权数据（邀请码收集期间暂存，凭 token 一次性取回）
    protected const CACHE_OAUTH_DATA_PREFIX = 'cmspro_sso_oauth_data:';
    protected const LOGIN_STATE_TTL = 600;  // 10 分钟过期

    // Cache 键前缀：手机验证码
    protected const CACHE_PHONE_CODE_PREFIX = 'cmspro_sso_phone_code:';
    protected const PHONE_CODE_TTL = 10;          // 验证码有效期（分钟）
    protected const PHONE_CODE_MAX_ATTEMPTS = 3;  // 每分钟内单个手机号最多发送次数

    public function __construct(
        protected SettingService $settingService,
        protected SmsService $smsService
    ) {
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
        ], [
            'phone.required' => '请输入手机号',
            'phone.regex'    => '手机号格式不正确',
        ]);

        $phone = $validated['phone'];
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
                    ? ShareService::frontUrl('/auth/wechat/complete')
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
        // 微信登录未启用或未配置
        if ($this->settingService->get('wechat_enabled', '0') !== '1') {
            return response()->json(['code' => 400, 'message' => '微信登录未启用'], 400);
        }
        if (!$this->settingService->isWechatConfigured()) {
            return response()->json(['code' => 400, 'message' => '微信登录未配置，请联系管理员'], 400);
        }

        $device = (string) $request->input('device', '');
        // 授权回调地址（固定为统一登录微信回调，外挂模式下作为 return_url 回传给外挂）
        $callbackUrl = ShareService::frontUrl('/auth/wechat/callback');

        // state 防 CSRF：随机串
        $state = md5(uniqid((string) mt_rand(), true));

        // 说明：邀请注册模式下不在授权前收集邀请码——先完成授权确认账号是否已存在，
        // 已绑定老用户直接登录，仅新用户在回调后（PC 弹窗/微信端页面）提示填写邀请码。

        if ($device === 'pc') {
            // PC 扫码模式：state 存 Cache（跨设备，回调时 PC 端 session 不可用）
            Cache::put(self::CACHE_LOGIN_STATE_PREFIX . $state, true, self::LOGIN_STATE_TTL);
        } else {
            // 普通模式（微信内）：state 存 session
            $request->session()->put(self::SESSION_OAUTH_STATE, $state);
        }

        // 外挂模式：构建跳转到外挂脚本的 URL
        if ($this->settingService->isWechatOauthProxyEnabled()) {
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

        // 原生模式：直接调用微信网页授权
        $openidService = app(WechatOauthService::class);
        $result = $openidService->buildOauthRedirectUrl($callbackUrl, $state);

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
        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');

        // 校验 state：先查 Cache（PC 扫码模式），再查 session（普通模式）
        $isPcMode = $state !== '' && Cache::has(self::CACHE_LOGIN_STATE_PREFIX . $state);
        if ($isPcMode) {
            Cache::pull(self::CACHE_LOGIN_STATE_PREFIX . $state);
        } else {
            $expectedState = (string) $request->session()->get(self::SESSION_OAUTH_STATE, '');
            if ($expectedState === '' || $state === '' || !hash_equals($expectedState, $state)) {
                abort(400, '授权状态校验失败，请重新登录');
            }
            $request->session()->forget(self::SESSION_OAUTH_STATE);
        }

        // 外挂模式错误回传（外挂处理失败时可能带 error 参数）
        $error = (string) $request->query('error', '');
        if ($error !== '') {
            Log::warning('微信授权外挂回调错误', ['error' => $error, 'errmsg' => $request->query('errmsg')]);
            abort(400, '微信授权失败：' . $error);
        }

        // 获取 openid + 用户信息（原生模式用 code 换，外挂模式直接读）
        if ($code !== '') {
            $data = $this->resolveNativeOauthData($code);
        } else {
            $data = $this->resolveProxyOauthData($request);
        }

        if ($data === null) {
            abort(400, '微信授权失败，未获取到 openid');
        }

        // 邀请注册模式下：先检查账号是否已存在——老用户直接登录，仅新用户需要邀请码
        $existingUser = $this->findWechatUser((string) $data['openid']);
        $needInvite = $existingUser === null
            && $this->settingService->get('invite_enabled', '0') === '1';

        // PC 扫码模式：解析/创建用户，将结果存 Cache，redirect 到提示页
        if ($isPcMode) {
            if ($needInvite) {
                // 新微信用户且开启邀请注册：授权数据暂存 Cache，PC 端轮询到 need_invite 后弹窗收集邀请码
                $token = bin2hex(random_bytes(16));
                Cache::put(self::CACHE_OAUTH_DATA_PREFIX . $token, $data, self::LOGIN_STATE_TTL);
                Cache::put(self::CACHE_LOGIN_RESULT_PREFIX . $state, [
                    'need_invite'  => true,
                    'invite_token' => $token,
                ], self::LOGIN_STATE_TTL);

                return redirect(ShareService::frontUrl('/auth/wechat/pc-success?result=invite'));
            }

            try {
                [$user, $isNew] = $this->resolveOrCreateUser($data);
            } catch (\RuntimeException $e) {
                // 建号错误：结果存 Cache 供 PC 端轮询展示，微信端提示页显示失败态
                Cache::put(self::CACHE_LOGIN_RESULT_PREFIX . $state, [
                    'error' => $e->getMessage(),
                ], self::LOGIN_STATE_TTL);

                return redirect(ShareService::frontUrl('/auth/wechat/pc-success?result=failed'));
            }

            Cache::put(self::CACHE_LOGIN_RESULT_PREFIX . $state, [
                'user_id' => $user->id,
                'is_new'  => $isNew,
            ], self::LOGIN_STATE_TTL);

            return redirect(ShareService::frontUrl('/auth/wechat/pc-success'));
        }

        // 普通模式（微信内）：新用户且开启邀请注册时，redirect 到微信端邀请码填写页
        if ($needInvite) {
            $token = bin2hex(random_bytes(16));
            Cache::put(self::CACHE_OAUTH_DATA_PREFIX . $token, $data, self::LOGIN_STATE_TTL);

            return redirect(ShareService::frontUrl('/auth/wechat/complete-invite?token=' . $token));
        }

        // 老用户或未开启邀请注册：login 并 redirect
        try {
            return $this->loginOrCreateUser($request, $data);
        } catch (\RuntimeException $e) {
            abort(400, $e->getMessage() . '，请返回登录页重试');
        }
    }

    /**
     * PC 扫码登录：轮询检查登录状态
     *
     * PC 端弹出二维码后，前端轮询此接口。检测到登录结果后在 PC 端建立 session。
     */
    public function wechatLoginStatus(Request $request): JsonResponse
    {
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

        // 新微信用户且开启邀请注册：返回 need_invite，PC 端弹窗收集邀请码后凭 token 提交。
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

        // 已登录：清理 Cache，在 PC 端建立登录态
        Cache::forget(self::CACHE_LOGIN_RESULT_PREFIX . $state);

        // 扫码确认后建号失败（如邀请码消费失败）：向 PC 端返回具体错误
        if (isset($result['error'])) {
            return response()->json(['code' => 422, 'message' => (string) $result['error']], 422);
        }

        $userId = (int) ($result['user_id'] ?? 0);
        $isNew = (bool) ($result['is_new'] ?? false);
        $user = User::find($userId);
        if (!$user) {
            return response()->json(['code' => 400, 'message' => '用户不存在'], 400);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->incrementLoginCount($request->ip());

        $this->writeLoginLog($user->id, 'wechat', $request, 1, '微信扫码登录成功');

        return response()->json([
            'code'    => 0,
            'message' => '登录成功',
            'data'    => [
                'logged_in' => true,
                'is_new'    => $isNew,
                'redirect'  => $isNew
                    ? ShareService::frontUrl('/auth/wechat/complete')
                    : $this->resolveRedirect($request),
            ],
        ]);
    }

    /**
     * 原生模式：用 code 换 openid + 用户信息
     */
    protected function resolveNativeOauthData(string $code): ?array
    {
        $openidService = app(WechatOauthService::class);
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
            'raw_data'      => $userInfo,
        ];
    }

    /**
     * 统一处理：根据 openid 查找已绑定用户登录，或首次登录创建用户并绑定
     *
     * @param Request $request
     * @param array $data 必须包含 openid，可选 unionid/nickname/avatar/access_token/refresh_token/raw_data
     */
    protected function loginOrCreateUser(Request $request, array $data, string $inviteCode = '')
    {
        try {
            [$user, $isNew] = $this->resolveOrCreateUser($data, $inviteCode);
        } catch (\RuntimeException $e) {
            // 建号失败（如邀请码消费失败）时未建立登录态，异常交由调用方处理
            throw $e;
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->incrementLoginCount($request->ip());

        $this->writeLoginLog($user->id, 'wechat', $request, 1, '微信登录成功');

        // 首次微信登录需完善资料（手机号、昵称）
        if ($isNew) {
            return redirect(ShareService::frontUrl('/auth/wechat/complete'));
        }

        return redirect($this->resolveRedirect($request));
    }

    /**
     * 根据微信 openid 查找已绑定的用户（不存在返回 null）
     */
    protected function findWechatUser(string $openid): ?User
    {
        $binding = UserThirdParty::where('platform', 'wechat')
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
     * 提交邀请码完成微信新用户注册
     *
     * 适用于两种场景（邀请注册开启 + 授权确认是新用户后）：
     *   1. PC 扫码：PC 端轮询到 need_invite 后弹窗收集邀请码提交；
     *   2. 微信内授权：回调 redirect 到 complete-invite 页面填写提交。
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

        try {
            [$user, $isNew] = $this->resolveOrCreateUser($data, $validated['invite_code']);
        } catch (\RuntimeException $e) {
            // 邀请码无效等：授权数据放回，允许用户重新填写
            Cache::put(self::CACHE_OAUTH_DATA_PREFIX . $validated['token'], $data, self::LOGIN_STATE_TTL);

            return response()->json(['code' => 422, 'message' => $e->getMessage()], 422);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->incrementLoginCount($request->ip());

        $this->writeLoginLog($user->id, 'wechat', $request, 1, '微信登录成功（邀请码注册）');

        return response()->json([
            'code'    => 0,
            'message' => '登录成功',
            'data'    => [
                'redirect' => $isNew
                    ? ShareService::frontUrl('/auth/wechat/complete')
                    : $this->resolveRedirect($request),
            ],
        ]);
    }

    /**
     * 解析或创建用户（不建立登录态，由调用方决定如何 login）
     *
     * 邀请注册开启时，新微信用户建号需在事务内校验并消费邀请码；
     * 已绑定老用户登录不消费邀请码。
     *
     * @param array $data 必须包含 openid，可选 unionid/nickname/avatar/access_token/refresh_token/raw_data
     * @param string $inviteCode 微信登录携带的邀请码（可为空；邀请注册模式下新用户必须有效）
     * @return array [User, bool $isNew]
     * @throws \RuntimeException 邀请码缺失、无效或消费失败时抛出（未建号、未建立登录态）
     */
    protected function resolveOrCreateUser(array $data, string $inviteCode = ''): array
    {
        $openid = $data['openid'];
        $unionid = $data['unionid'] ?? null;

        // 已绑定用户直接返回（调用方保证：邀请注册开启且为新用户时不会走到这里）
        $existing = $this->findWechatUser($openid);
        if ($existing) {
            return [$existing, false];
        }

        // 首次微信登录：创建用户并绑定
        $nickname = $data['nickname'] !== '' ? $data['nickname'] : ('微信用户' . substr($openid, -4));
        $avatar = $data['avatar'] !== '' ? $data['avatar'] : null;
        $defaultGroup = UserGroup::default()->enabled()->first();

        // 用户名：wx_ + openid 后10位，若冲突追加随机后缀保证唯一
        $baseUsername = 'wx_' . substr($openid, -10);
        $username = $baseUsername;
        $uniqueKey = md5($openid);
        while (User::where('username', $username)->exists()) {
            $username = $baseUsername . '_' . substr($uniqueKey, 0, 6);
            break;
        }

        // 设置一个随机的不可登录密码（保证非空，避免数据库中 password 字段无默认值报错）
        $randomPassword = 'wx_' . bin2hex(random_bytes(16));

        // 邀请注册开启时，新微信用户建号必须在事务内消费邀请码（失败则不建号）
        $inviteRequired = $this->settingService->get('invite_enabled', '0') === '1';
        $inviteCode = strtoupper(trim($inviteCode));
        if ($inviteRequired && $inviteCode === '') {
            throw new \RuntimeException('本站开启邀请注册，微信登录需携带邀请码');
        }

        try {
            $user = DB::transaction(function () use ($data, $openid, $unionid, $nickname, $avatar, $defaultGroup, $username, $randomPassword, $inviteRequired, $inviteCode) {
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
                    'platform'       => 'wechat',
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
     * 首次微信登录完善资料（手机号、昵称）
     */
    public function completeProfile(Request $request): JsonResponse
    {
        $user = auth('web')->user();
        if (!$user) {
            return response()->json(['code' => 401, 'message' => '未登录'], 401);
        }

        $validated = $request->validate([
            'phone'    => 'nullable|string|max:20',
            'nickname' => 'nullable|string|max:50',
        ]);

        $update = [];
        if (!empty($validated['phone'])) {
            $update['phone'] = $validated['phone'];
        }
        if (!empty($validated['nickname'])) {
            $update['nickname'] = $validated['nickname'];
        }

        if (!empty($update)) {
            $user->update($update);
        }

        return response()->json([
            'code'    => 0,
            'message' => '资料已完善',
            'data'    => ['redirect' => $this->resolveRedirect($request)],
        ]);
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
     */
    protected function resolveRedirect(Request $request): string
    {
        $redirect = trim((string) $request->input('redirect', ''));
        if ($redirect !== '' && $this->isSafeRedirect($redirect)) {
            return $redirect;
        }

        return $this->settingService->getDefaultRedirect();
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

        // 绝对地址需校验 host 与当前站点一致
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null) {
            return false;
        }

        $currentHost = request()->getHost();
        return $host === $currentHost || str_ends_with($host, '.' . $currentHost);
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
