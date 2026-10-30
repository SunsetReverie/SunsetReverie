<?php

declare(strict_types=1);

namespace SunsetReverie\Validation;

use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Exception\HttpException;
use SunsetReverie\Http\Request;

/**
 * 请求校验基类（对应文档 5.x 的 FormRequest）。
 *
 * 用法：
 *   public function store(StoreArticleRequest $request): array
 *   {
 *       $data = $request->validated();   // ← 在这里已经被自动校验过了
 *   }
 *
 * 与"控制器里手动调 Validator::validate()"相比，它的价值是：
 * - 规则从控制器里挪走，控制器只剩"取数据 → 调服务"两件事；
 * - 参数注入时自动校验（失败即 422，控制器体不会被执行）；
 * - `validated()` 只返回规则里声明过的字段，天然防止"客户端多塞字段"。
 */
abstract class FormRequest
{
    /** @var array<string, mixed> 已通过校验的数据 */
    private array $validatedData = [];

    /**
     * 校验规则。
     *
     * @return array<string, string> 规则表
     */
    abstract public function rules(): array;

    /**
     * 自定义错误消息（可选）。
     *
     * @return array<string, string> 字段.规则 => 消息
     */
    public function messages(): array
    {
        return [];
    }

    /**
     * 是否允许本次请求（可选，默认放行）。
     *
     * 需要在校验前做权限判断时重写它。
     *
     * @param Request $request 当前请求
     * @return bool 允许返回 true
     */
    public function authorize(Request $request): bool
    {
        return true;
    }

    /**
     * 取校验通过的数据。
     *
     * @return array<string, mixed> 安全数据（只含规则声明过的字段）
     */
    public function validated(): array
    {
        return $this->validatedData;
    }

    /**
     * 取单个校验通过的字段。
     *
     * @param string $key 字段名
     * @param mixed $default 默认值
     * @return mixed 字段值
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->validatedData[$key] ?? $default;
    }

    /**
     * 执行校验（由 Application 在参数注入时调用，业务代码不需要手动调）。
     *
     * @param Request $request 当前请求
     * @param Validator $validator 校验器
     * @return void
     * @throws HttpException 未授权时抛出
     * @throws ValidationException 校验失败时抛出
     */
    public function validateResolved(Request $request, Validator $validator): void
    {
        if (!$this->authorize($request)) {
            throw new HttpException(403, ErrorCode::Forbidden->value, '无权执行此操作');
        }

        if ($this->messages() !== []) {
            $validator = $validator->withMessages($this->messages());
        }

        $this->validatedData = $validator->validate($request->post(), $this->rules());
    }
}
