<?php

namespace App\Apps\CmsproSso\Controllers\Admin;

use App\Apps\CmsproSso\Models\Client;
use App\Apps\CmsproSso\Services\ShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 快捷模式客户端管理控制器（服务端）
 *
 * 管理接入统一登录中转的客户端站点：创建后发放 client_id + client_secret
 * （明文仅创建/重置时展示一次），支持启停、重置密钥与删除。
 */
class ClientController extends Controller
{
    /**
     * 渲染客户端管理页面
     */
    public function index()
    {
        return view('cmspro.sso::Admin.clients.index', [
            'authorize_base' => rtrim(ShareService::frontUrl(''), '/'),
            'is_server'      => app(\App\Apps\CmsproSso\Services\SettingService::class)->isRelayServer(),
        ]);
    }

    /**
     * 客户端列表（API）
     */
    public function list(Request $request): JsonResponse
    {
        $keyword = trim((string) $request->query('keyword', ''));
        $status  = $request->query('status', '');
        $page    = max(1, (int) $request->query('page', 1));
        $limit   = min(100, max(1, (int) $request->query('limit', 15)));

        $query = Client::query();

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', '%' . $keyword . '%')
                  ->orWhere('client_id', 'like', '%' . $keyword . '%');
            });
        }
        if ($status !== '' && $status !== null) {
            $query->where('status', (int) $status);
        }

        $total = (clone $query)->count();
        $items = $query->orderByDesc('id')
            ->forPage($page, $limit)
            ->get();

        return response()->json([
            'code'    => 0,
            'message' => 'ok',
            'data'    => [
                'items'      => $items,
                'pagination' => [
                    'total' => $total,
                    'page'  => $page,
                    'limit' => $limit,
                ],
            ],
        ]);
    }

    /**
     * 新建客户端（API）：返回 client_id 与一次性明文密钥
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:100',
            'redirect_uris' => 'required|string|max:5000',
            'remark'        => 'nullable|string|max:255',
        ], [
            'name.required'          => '请填写应用名称',
            'redirect_uris.required' => '请填写回调地址白名单',
        ]);

        $uris = $this->parseRedirectUris($validated['redirect_uris']);
        if ($uris === []) {
            return response()->json(['code' => 400, 'message' => '回调地址白名单不能为空，且每行须为合法 URL'], 400);
        }

        $secret = Client::generateSecret();

        $client = Client::create([
            'client_id'    => Client::generateClientId(),
            'secret_hash'  => password_hash($secret, PASSWORD_DEFAULT),
            'name'         => $validated['name'],
            'redirect_uris' => implode("\n", $uris),
            'status'       => Client::STATUS_ENABLED,
            'remark'       => $validated['remark'] ?? '',
        ]);

        return response()->json([
            'code'    => 0,
            'message' => '创建成功，请立即保存客户端密钥（仅展示一次）',
            'data'    => [
                'id'            => $client->id,
                'client_id'     => $client->client_id,
                'client_secret' => $secret,
            ],
        ]);
    }

    /**
     * 更新客户端基础信息（API，不含密钥）
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $client = Client::find($id);
        if (!$client) {
            return response()->json(['code' => 404, 'message' => '客户端不存在'], 404);
        }

        $validated = $request->validate([
            'name'          => 'required|string|max:100',
            'redirect_uris' => 'required|string|max:5000',
            'remark'        => 'nullable|string|max:255',
        ], [
            'name.required'          => '请填写应用名称',
            'redirect_uris.required' => '请填写回调地址白名单',
        ]);

        $uris = $this->parseRedirectUris($validated['redirect_uris']);
        if ($uris === []) {
            return response()->json(['code' => 400, 'message' => '回调地址白名单不能为空，且每行须为合法 URL'], 400);
        }

        $client->update([
            'name'          => $validated['name'],
            'redirect_uris' => implode("\n", $uris),
            'remark'        => $validated['remark'] ?? '',
        ]);

        return response()->json(['code' => 0, 'message' => '更新成功', 'data' => null]);
    }

    /**
     * 启用/禁用客户端（API）
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $status = (int) $request->input('status', -1);
        if (!in_array($status, [Client::STATUS_DISABLED, Client::STATUS_ENABLED], true)) {
            return response()->json(['code' => 400, 'message' => '状态值不合法'], 400);
        }

        $client = Client::find($id);
        if (!$client) {
            return response()->json(['code' => 404, 'message' => '客户端不存在'], 404);
        }

        $client->update(['status' => $status]);

        return response()->json(['code' => 0, 'message' => $status === Client::STATUS_ENABLED ? '已启用' : '已禁用', 'data' => null]);
    }

    /**
     * 重置客户端密钥（API）：返回一次性新明文密钥，旧密钥立即失效
     */
    public function resetSecret(int $id): JsonResponse
    {
        $client = Client::find($id);
        if (!$client) {
            return response()->json(['code' => 404, 'message' => '客户端不存在'], 404);
        }

        $secret = Client::generateSecret();
        $client->update(['secret_hash' => password_hash($secret, PASSWORD_DEFAULT)]);

        return response()->json([
            'code'    => 0,
            'message' => '密钥已重置，请立即保存（仅展示一次）',
            'data'    => ['client_secret' => $secret],
        ]);
    }

    /**
     * 删除客户端（API）：同步清理其授权码与访问令牌
     */
    public function destroy(int $id): JsonResponse
    {
        $client = Client::find($id);
        if (!$client) {
            return response()->json(['code' => 404, 'message' => '客户端不存在'], 404);
        }

        $client->deleteWithRelations();

        return response()->json(['code' => 0, 'message' => '删除成功', 'data' => null]);
    }

    /**
     * 解析回调地址白名单（换行分隔，逐行校验合法性）
     *
     * @return string[] 全部合法时返回去除空行的数组，存在非法行返回空数组
     */
    protected function parseRedirectUris(string $raw): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $raw))));
        foreach ($lines as $line) {
            if (filter_var($line, FILTER_VALIDATE_URL) === false) {
                return [];
            }
        }

        return $lines;
    }
}
