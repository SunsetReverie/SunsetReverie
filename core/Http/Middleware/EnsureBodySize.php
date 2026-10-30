<?php

declare(strict_types=1);

namespace SunsetReverie\Http\Middleware;

use Closure;
use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Exception\HttpException;
use SunsetReverie\Http\Request;
use SunsetReverie\Http\Response;

/**
 * 限制请求体大小。
 *
 * 防的是「超大请求拖垮进程」：100MB 的 POST 会让 PHP 在读 php://input 时
 * 占满内存。Nginx 的 client_max_body_size 只在经过 Nginx 时生效——
 * FPM 直连、内置服务器、容器内互调都没有这层保护。
 *
 * 只看 Content-Length：它由客户端声明，超限直接拒绝，
 * 不用读完整个 body 再判断（那正是要避免的）。
 *
 * 边界：客户端可以不发 Content-Length（分块传输），这种情况放行，
 * 由 SAPI 的 post_max_size 兜底。两层防护都要有。
 */
final class EnsureBodySize implements MiddlewareInterface, HasParameters
{
    /** @var int 上限（字节），0 表示读配置 */
    private int $maxBytes = 0;

    /**
     * {@inheritDoc}
     */
    public function withParameters(array $parameters): static
    {
        $clone = clone $this;
        $clone->maxBytes = (int) ($parameters[0] ?? 0);

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function handle(Request $request, Closure $next): Response
    {
        $max = $this->maxBytes > 0
            ? $this->maxBytes
            : (int) config('http.max_body_size', 8388608);

        $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        if ($max > 0 && $declared > $max) {
            throw new HttpException(
                413,
                ErrorCode::PayloadTooLarge->value,
                sprintf(
                    '请求体过大（%.1f MB），上限 %.1f MB',
                    $declared / 1048576,
                    $max / 1048576
                )
            );
        }

        return $next($request);
    }
}
