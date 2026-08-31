<?php

namespace App\Apps\CmsproSso\Controllers\Admin;

use App\Apps\CmsproSso\Models\InviteCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 邀请码管理控制器
 *
 * 提供邀请码列表、批量生成、启停与删除能力（仅未使用的邀请码可启停/删除）。
 */
class InviteCodeController extends Controller
{
    /**
     * 单次最大生成数量
     */
    protected const MAX_GENERATE_COUNT = 500;

    /**
     * 渲染邀请码管理页面
     */
    public function index()
    {
        return view('cmspro.sso::Admin.invite.index');
    }

    /**
     * 邀请码列表（API）
     */
    public function list(Request $request): JsonResponse
    {
        $keyword = trim((string) $request->query('keyword', ''));
        $status  = $request->query('status', '');
        $page    = max(1, (int) $request->query('page', 1));
        $limit   = min(100, max(1, (int) $request->query('limit', 15)));

        $query = InviteCode::query();

        if ($keyword !== '') {
            $query->where('code', 'like', '%' . $keyword . '%');
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
     * 批量生成邀请码（API）
     */
    public function generate(Request $request): JsonResponse
    {
        $count = (int) $request->input('count', 0);
        $price = $request->input('price', '');

        if ($count < 1 || $count > self::MAX_GENERATE_COUNT) {
            return response()->json(['code' => 400, 'message' => '生成数量须为 1-' . self::MAX_GENERATE_COUNT], 400);
        }

        $priceValue = null;
        if ($price !== '' && $price !== null) {
            if (!is_numeric($price) || (float) $price < 0) {
                return response()->json(['code' => 400, 'message' => '邀请码费用须为不小于 0 的数字'], 400);
            }
            $priceValue = round((float) $price, 2);
        }

        $codes = [];
        try {
            foreach (range(1, $count) as $_) {
                $codes[] = [
                    'code'        => InviteCode::generateUniqueCode(),
                    'status'      => InviteCode::STATUS_UNUSED,
                    'price'       => $priceValue,
                    'create_time' => now(),
                    'update_time' => now(),
                ];
            }
            InviteCode::insert($codes);
        } catch (\Throwable $e) {
            return response()->json(['code' => 500, 'message' => '邀请码生成失败：' . $e->getMessage()], 500);
        }

        return response()->json([
            'code'    => 0,
            'message' => '成功生成 ' . count($codes) . ' 个邀请码',
            'data'    => ['count' => count($codes)],
        ]);
    }

    /**
     * 更新邀请码状态（API）：仅未使用状态可启用/禁用
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $status = (int) $request->input('status', -1);
        if (!in_array($status, [InviteCode::STATUS_DISABLED, InviteCode::STATUS_UNUSED], true)) {
            return response()->json(['code' => 400, 'message' => '状态值不合法'], 400);
        }

        $code = InviteCode::find($id);
        if (!$code) {
            return response()->json(['code' => 404, 'message' => '邀请码不存在'], 404);
        }
        if ((int) $code->status === InviteCode::STATUS_USED) {
            return response()->json(['code' => 400, 'message' => '已使用的邀请码不允许修改状态'], 400);
        }

        $code->update(['status' => $status]);

        return response()->json(['code' => 0, 'message' => '状态更新成功', 'data' => null]);
    }

    /**
     * 删除邀请码（API）：已使用的邀请码保留用于追溯，不允许删除
     */
    public function destroy(int $id): JsonResponse
    {
        $code = InviteCode::find($id);
        if (!$code) {
            return response()->json(['code' => 404, 'message' => '邀请码不存在'], 404);
        }
        if ((int) $code->status === InviteCode::STATUS_USED) {
            return response()->json(['code' => 400, 'message' => '已使用的邀请码不允许删除'], 400);
        }

        $code->delete();

        return response()->json(['code' => 0, 'message' => '删除成功', 'data' => null]);
    }
}
