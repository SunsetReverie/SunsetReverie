<?php

declare(strict_types=1);

namespace SunsetReverie\Upload\Controller;

use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Exception\HttpException;
use SunsetReverie\Http\Request;
use SunsetReverie\Http\Response;
use SunsetReverie\Upload\UploadManager;

/**
 * 私有文件访问控制器（签名 URL 的验证端）。
 *
 * 为什么必须有这个控制器：
 * `/uploads/*` 由 Web 服务器直接 serve，**不经过任何 PHP 代码**，
 * 因此放在那里的 URL 无法鉴权。私有文件必须走本控制器：
 * 校验签名 → 校验时效 → 读文件，缺一不可。
 */
final class FileController
{
    /**
     * 输出私有文件。
     *
     * @param Request $request 当前请求（path / expires / sign 三个查询参数）
     * @param UploadManager $upload 上传管理器
     * @return Response 文件响应
     * @throws HttpException 签名无效、过期或文件不存在时抛出
     */
    public function show(Request $request, UploadManager $upload): Response
    {
        $path = (string) $request->query('path', '');
        $expires = (int) $request->query('expires', 0);
        $signature = (string) $request->query('sign', '');

        if ($path === '' || $signature === '') {
            throw HttpException::fromCode(ErrorCode::SignedUrlExpired);
        }

        if (!$upload->verifySignature($path, $expires, $signature)) {
            throw HttpException::fromCode(ErrorCode::SignedUrlExpired);
        }

        $absolute = $upload->storage()->path($path);

        if ($absolute === '' || !is_file($absolute)) {
            throw HttpException::fromCode(ErrorCode::NotFound);
        }

        return Response::file($absolute, basename($path));
    }
}
