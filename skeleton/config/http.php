<?php

declare(strict_types=1);

/*
 * HTTP 层限制。
 */
return [
    /*
     * 请求体大小上限（字节），默认 8MB。
     *
     * 由 `body.size` 中间件检查（根据 Content-Length 判断，不读 body）。
     * 注意：这只在请求进入框架后生效，Nginx 的 client_max_body_size
     * 更早拦截——两层都要配，且上限值应一致，否则会得到 HTML 错误页而不是 JSON。
     */
    'max_body_size' => (int) env('HTTP_MAX_BODY_SIZE', 8388608),
];
