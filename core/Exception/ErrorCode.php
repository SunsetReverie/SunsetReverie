<?php

declare(strict_types=1);

namespace SunsetReverie\Exception;

/**
 * 框架内置错误码：错误码 ↔ HTTP 状态 ↔ 默认消息 的唯一事实来源。
 *
 * 命名遵循规划文档 4.4（枚举成员 PascalCase），码值遵循 14.1 分段规则。
 */
enum ErrorCode: int
{
    case Ok = 0;
    case InternalError = 10001;
    case ServiceUnavailable = 10002;
    case ConfigError = 10003;
    case BootstrapFailed = 10004;
    case Maintenance = 10005;

    case Unauthenticated = 20001;
    case TokenExpired = 20002;
    case Forbidden = 20003;
    case AccountDisabled = 20004;
    case TooManyRequests = 20005;
    case SignatureExpired = 20006;
    case SignatureReplayed = 20007;
    case SignatureInvalid = 20008;
    case CredentialsInvalid = 20009;
    case TokenDomainMismatch = 20010;
    case SignedUrlExpired = 20011;

    case ValidationFailed = 30001;
    case InvalidJson = 30002;
    case MissingParameter = 30003;
    case InvalidParameterType = 30004;
    case UnsupportedMediaType = 30005;
    case NotAcceptable = 30006;
    case MissingIdempotencyKey = 30007;

    case NotFound = 40001;
    case RouteNotFound = 40002;
    case MethodNotAllowed = 40003;
    case AlreadyExists = 40004;
    case StateConflict = 40005;
    case ResourceGone = 40006;
    case PayloadTooLarge = 40007;
    case DomainNotFound = 40008;
    case IdempotencyConflict = 40009;
    case VersionConflict = 40010;

    case DatabaseError = 60001;
    case UploadFailed = 60002;
    case CacheError = 60003;
    case StorageError = 60004;
    case UpstreamError = 60005;

    /**
     * 获取对应的 HTTP 状态码。
     *
     * @return int HTTP 状态码
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::Ok => 200,
            self::InternalError => 500,
            self::ServiceUnavailable => 503,
            self::ConfigError => 500,
            self::BootstrapFailed => 500,
            self::Maintenance => 503,
            self::Unauthenticated => 401,
            self::TokenExpired => 401,
            self::Forbidden => 403,
            self::TooManyRequests => 429,
            self::TokenDomainMismatch => 401,
            self::SignedUrlExpired => 403,
            self::NotFound => 404,
            self::ValidationFailed => 422,
            self::InvalidJson => 400,
            self::MissingParameter => 400,
            self::RouteNotFound => 404,
            self::MethodNotAllowed => 405,
            self::AccountDisabled => 403,
            self::SignatureExpired => 401,
            self::SignatureReplayed => 401,
            self::SignatureInvalid => 401,
            self::CredentialsInvalid => 401,
            self::InvalidParameterType => 400,
            self::UnsupportedMediaType => 415,
            self::NotAcceptable => 406,
            self::MissingIdempotencyKey => 400,
            self::AlreadyExists => 409,
            self::StateConflict => 409,
            self::ResourceGone => 410,
            self::PayloadTooLarge => 413,
            self::DomainNotFound => 404,
            self::IdempotencyConflict => 409,
            self::VersionConflict => 409,
            self::DatabaseError => 500,
            self::UploadFailed => 500,
            self::CacheError => 500,
            self::StorageError => 500,
            self::UpstreamError => 502,
        };
    }

    /**
     * 获取默认消息（可被应用侧覆盖）。
     *
     * @return string 面向用户的中文消息
     */
    public function message(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::InternalError => '服务器内部错误',
            self::ServiceUnavailable => '服务暂时不可用',
            self::ConfigError => '配置错误',
            self::BootstrapFailed => '框架启动失败',
            self::Maintenance => '系统维护中',
            self::Unauthenticated => '请先登录',
            self::TokenExpired => '登录已过期，请重新登录',
            self::Forbidden => '无权限执行此操作',
            self::TooManyRequests => '请求过于频繁，请稍后再试',
            self::TokenDomainMismatch => '凭证与当前接口域不匹配',
            self::SignedUrlExpired => '链接已失效或已过期',
            self::NotFound => '资源不存在',
            self::ValidationFailed => '参数校验失败',
            self::InvalidJson => '请求体不是合法 JSON',
            self::MissingParameter => '缺少必要参数',
            self::RouteNotFound => '接口不存在',
            self::MethodNotAllowed => '请求方法不被允许',
            self::AccountDisabled => '账户已被禁用',
            self::SignatureExpired => '请求已过期',
            self::SignatureReplayed => '重复请求',
            self::SignatureInvalid => '签名校验失败',
            self::CredentialsInvalid => '账号或密码错误',
            self::InvalidParameterType => '参数类型错误',
            self::UnsupportedMediaType => '不支持的请求内容类型',
            self::NotAcceptable => '不支持的响应格式',
            self::MissingIdempotencyKey => '缺少 Idempotency-Key 请求头',
            self::AlreadyExists => '资源已存在',
            self::StateConflict => '资源状态冲突',
            self::ResourceGone => '资源已失效',
            self::PayloadTooLarge => '请求体过大',
            self::DomainNotFound => '请求未匹配任何 API 域',
            self::IdempotencyConflict => '相同幂等键的请求正在处理中',
            self::VersionConflict => '数据已被他人修改，请刷新后重试',
            self::DatabaseError => '数据库操作失败',
            self::UploadFailed => '文件上传失败',
            self::CacheError => '缓存服务异常',
            self::StorageError => '存储服务异常',
            self::UpstreamError => '上游服务异常',
        };
    }

    /**
     * 获取符号名（仅用于日志与运维检索，不进入响应体）。
     *
     * @return string 形如 SR.ROUTE.NOT_FOUND
     */
    public function symbol(): string
    {
        return match ($this) {
            self::Ok => 'SR.OK',
            self::InternalError => 'SR.SERVER.INTERNAL_ERROR',
            self::ServiceUnavailable => 'SR.SERVER.UNAVAILABLE',
            self::ConfigError => 'SR.CONFIG.ERROR',
            self::BootstrapFailed => 'SR.BOOTSTRAP.FAILED',
            self::Maintenance => 'SR.SERVER.MAINTENANCE',
            self::Unauthenticated => 'SR.AUTH.UNAUTHENTICATED',
            self::TokenExpired => 'SR.AUTH.TOKEN_EXPIRED',
            self::Forbidden => 'SR.AUTH.FORBIDDEN',
            self::TooManyRequests => 'SR.AUTH.TOO_MANY_REQUESTS',
            self::TokenDomainMismatch => 'SR.AUTH.TOKEN_DOMAIN_MISMATCH',
            self::SignedUrlExpired => 'SR.AUTH.SIGNED_URL_EXPIRED',
            self::NotFound => 'SR.RESOURCE.NOT_FOUND',
            self::ValidationFailed => 'SR.VALIDATION.FAILED',
            self::InvalidJson => 'SR.HTTP.INVALID_JSON',
            self::MissingParameter => 'SR.HTTP.MISSING_PARAMETER',
            self::RouteNotFound => 'SR.ROUTE.NOT_FOUND',
            self::MethodNotAllowed => 'SR.ROUTE.METHOD_NOT_ALLOWED',
            self::AccountDisabled => 'SR.AUTH.ACCOUNT_DISABLED',
            self::SignatureExpired => 'SR.AUTH.SIGNATURE_EXPIRED',
            self::SignatureReplayed => 'SR.AUTH.SIGNATURE_REPLAYED',
            self::SignatureInvalid => 'SR.AUTH.SIGNATURE_INVALID',
            self::CredentialsInvalid => 'SR.AUTH.CREDENTIALS_INVALID',
            self::InvalidParameterType => 'SR.REQUEST.INVALID_PARAMETER_TYPE',
            self::UnsupportedMediaType => 'SR.REQUEST.UNSUPPORTED_MEDIA_TYPE',
            self::NotAcceptable => 'SR.REQUEST.NOT_ACCEPTABLE',
            self::MissingIdempotencyKey => 'SR.REQUEST.MISSING_IDEMPOTENCY_KEY',
            self::AlreadyExists => 'SR.RESOURCE.ALREADY_EXISTS',
            self::StateConflict => 'SR.RESOURCE.STATE_CONFLICT',
            self::ResourceGone => 'SR.RESOURCE.GONE',
            self::PayloadTooLarge => 'SR.REQUEST.PAYLOAD_TOO_LARGE',
            self::DomainNotFound => 'SR.RESOURCE.DOMAIN_NOT_FOUND',
            self::IdempotencyConflict => 'SR.RESOURCE.IDEMPOTENCY_CONFLICT',
            self::VersionConflict => 'SR.RESOURCE.VERSION_CONFLICT',
            self::DatabaseError => 'SR.DATABASE.ERROR',
            self::UploadFailed => 'SR.STORAGE.UPLOAD_FAILED',
            self::CacheError => 'SR.CACHE.ERROR',
            self::StorageError => 'SR.STORAGE.ERROR',
            self::UpstreamError => 'SR.UPSTREAM.ERROR',
        };
    }

    /**
     * 按码值查找枚举成员。
     *
     * @param int $code 错误码
     * @return self|null 命中返回枚举实例，否则 null
     */
    public static function fromCode(int $code): ?self
    {
        return self::tryFrom($code);
    }
}
