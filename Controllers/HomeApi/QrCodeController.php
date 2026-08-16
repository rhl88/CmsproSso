<?php

namespace App\Apps\CmsproSso\Controllers\HomeApi;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 二维码图片生成
 *
 * 后端用 endroid/qr-code 生成真实 PNG 图片流返回，
 * 供前端 <img src> 直接加载（PC 扫码登录弹窗）。
 */
class QrCodeController extends Controller
{
    /**
     * 生成二维码图片
     *
     * 参数：
     *   url   二维码内容（必填，如微信授权跳转 URL）
     *   size  图片尺寸（可选，默认 240）
     *
     * 返回：image/png 图片流
     */
    public function generate(Request $request)
    {
        // 依赖未安装时直接 500 提示
        if (!class_exists(Builder::class)) {
            abort(500, '二维码依赖未安装，请在后台查看设置提示');
        }

        $url = trim((string) $request->input('url', ''));
        if ($url === '') {
            abort(400, '缺少二维码内容');
        }

        $size = (int) $request->input('size', 240);
        if ($size < 100 || $size > 600) {
            $size = 240;
        }

        // 用 endroid/qr-code 生成 PNG 图片
        $result = (new Builder(
            writer: new PngWriter(),
            data: $url,
            encoding: new Encoding('UTF-8'),
            // 容错等级 Medium：保证局部污损仍可识别
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: 20,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
            foregroundColor: new Color(0, 0, 0),
            backgroundColor: new Color(255, 255, 255),
        ))->build();

        // 返回图片流，设置较短缓存（二维码内容含一次性 state，不宜长缓存）
        return response($result->getString(), 200, [
            'Content-Type'        => $result->getMimeType(),
            'Cache-Control'       => 'no-store',
            'Content-Disposition' => 'inline; filename="qrcode.png"',
        ]);
    }
}
