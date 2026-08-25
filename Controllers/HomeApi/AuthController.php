<?php

namespace App\Apps\CmsproSso\Controllers\HomeApi;

use App\Apps\CmsproSso\Models\LoginLog;
use App\Apps\CmsproSso\Services\SettingService;
use App\Apps\CmsproSso\Services\ShareService;
use App\Apps\CmsproSso\Services\WechatOauthService;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\UserThirdParty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

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
    protected const LOGIN_STATE_TTL = 600;  // 10 分钟过期

    public function __construct(
        protected SettingService $settingService
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

        $validated = $request->validate([
            'username' => 'required|string|min:3|max:30|unique:users,username',
            'password' => 'required|string|min:6|confirmed',
            'nickname' => 'nullable|string|max:50',
            'email' => ($requireEmailCode ? 'required' : 'nullable') . '|string|max:100|email|unique:users,email',
            'email_code' => ($requireEmailCode ? 'required' : 'nullable') . '|string|size:6',
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
        ]);

        // 校验注册邮箱验证码：不正确或未输入不予注册（后台未配置邮箱时跳过）
        if ($requireEmailCode) {
            $cachedCode = Cache::get('user_register_code:' . $validated['email']);
            if (! $cachedCode || ! hash_equals((string) $cachedCode, (string) $validated['email_code'])) {
                return response()->json(['code' => 422, 'message' => '邮箱验证码不正确或已过期'], 422);
            }
        }

        $defaultGroup = UserGroup::default()->enabled()->first();

        $user = User::create([
            'username' => $validated['username'],
            'password' => Hash::make($validated['password']),
            'nickname' => $validated['nickname'] ?? $validated['username'],
            'email'    => $validated['email'] ?? null,
            'group_id' => $defaultGroup?->id,
            'status'   => 1,
        ]);

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
            Cache::forget(self::CACHE_LOGIN_STATE_PREFIX . $state);
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

        // PC 扫码模式：解析/创建用户，将结果存 Cache，redirect 到提示页
        if ($isPcMode) {
            [$user, $isNew] = $this->resolveOrCreateUser($data);
            Cache::put(self::CACHE_LOGIN_RESULT_PREFIX . $state, [
                'user_id' => $user->id,
                'is_new'  => $isNew,
            ], self::LOGIN_STATE_TTL);

            return redirect(ShareService::frontUrl('/auth/wechat/pc-success'));
        }

        // 普通模式：login 并 redirect
        return $this->loginOrCreateUser($request, $data);
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

        // 已登录：清理 Cache，在 PC 端建立登录态
        Cache::forget(self::CACHE_LOGIN_RESULT_PREFIX . $state);

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
    protected function loginOrCreateUser(Request $request, array $data)
    {
        [$user, $isNew] = $this->resolveOrCreateUser($data);

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
     * 解析或创建用户（不建立登录态，由调用方决定如何 login）
     *
     * @param array $data 必须包含 openid，可选 unionid/nickname/avatar/access_token/refresh_token/raw_data
     * @return array [User, bool $isNew]
     */
    protected function resolveOrCreateUser(array $data): array
    {
        $openid = $data['openid'];
        $unionid = $data['unionid'] ?? null;

        // 查找已绑定用户
        $binding = UserThirdParty::where('platform', 'wechat')
            ->where('open_id', $openid)
            ->first();

        if ($binding && $binding->user_id) {
            $user = User::find($binding->user_id);
            if ($user) {
                return [$user, false];
            }
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
