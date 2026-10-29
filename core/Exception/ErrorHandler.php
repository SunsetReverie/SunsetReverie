<?php

declare(strict_types=1);

namespace SunsetReverie\Exception;

use Throwable;
use SunsetReverie\Http\Envelope;
use SunsetReverie\Http\Request;
use SunsetReverie\Http\Response;
use SunsetReverie\Routing\Exception\MethodNotAllowedException;

/**
 * 统一异常渲染：把任意 Throwable 转为符合约定的 JSON 响应。
 *
 * 安全规则（见规划文档 11.6）：
 * - 生产环境只输出通用消息，不包含文件路径、SQL、堆栈；
 * - debug 模式才附带 debug 字段，便于本地排障；
 * - 405 需保留 Allow 头。
 */
final class ErrorHandler
{
    /**
     * @param bool $debug 是否输出调试信息
     * @param DebugRenderer|null $renderer 调试渲染器
     */
    public function __construct(
        private readonly bool $debug = false,
        private readonly ?DebugRenderer $renderer = null,
    ) {
    }

    /**
     * 渲染异常。
     *
     * @param Throwable $e 异常
     * @param Request|null $request 当前请求（用于 request_id）
     * @return Response 响应
     */
    public function render(Throwable $e, ?Request $request = null): Response
    {
        $requestId = $request?->requestId;

        if ($e instanceof MethodNotAllowedException) {
            $payload = Envelope::error($e->errorCode ?? 40003, $e->getMessage(), $requestId);

            return Response::json($payload, 405)
                ->withHeader('Allow', implode(', ', $e->allowed));
        }

        if ($e instanceof HttpException) {
            $payload = Envelope::error(
                $e->errorCode ?? $e->status,
                $e->getMessage(),
                $requestId,
                $e->errors,
            );

            return $this->decorateDebug(Response::json($payload, $e->status), $e);
        }

        $this->log($e);

        $payload = Envelope::error(
            ErrorCode::InternalError->value,
            $this->debug ? $e->getMessage() : ErrorCode::InternalError->message(),
            $requestId,
        );

        if ($this->debug) {
            $payload['debug'] = ($this->renderer ?? new DebugRenderer())->render($e);
        }

        return Response::json($payload, 500);
    }

    /**
     * 为 HttpException 响应附加调试字段（仅 debug 模式）。
     *
     * @param Response $response 响应
     * @param Throwable $e 异常
     * @return Response 响应
     */
    private function decorateDebug(Response $response, Throwable $e): Response
    {
        if (!$this->debug) {
            return $response;
        }

        return $response->withHeader('X-Debug-Exception', rawurlencode($e::class));
    }

    /**
     * 记录异常（写系统错误日志，绝不输出到响应）。
     *
     * @param Throwable $e 异常
     * @return void
     */
    private function log(Throwable $e): void
    {
        error_log(sprintf(
            '[SunsetReverie] %s: %s in %s:%d',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }
}
