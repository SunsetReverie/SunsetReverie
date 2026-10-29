<?php

declare(strict_types=1);

namespace SunsetReverie\Validation;

use Closure;
use InvalidArgumentException;

/**
 * 规则式校验器。
 *
 * 用法：
 *   $data = $validator->validate($request->json(), [
 *       'title'  => 'required|string|max:200',
 *       'status' => 'in:draft,published',
 *   ]);
 *
 * 设计要点：
 * - 规则用字符串表达（`required|string|max:200`），易读易改；
 * - 校验通过后只返回**规则中声明的字段**，未声明字段不会进入业务逻辑；
 * - 失败抛 ValidationException（422 + errors），由统一异常处理渲染；
 * - 支持 extend() 注册项目自定义规则。
 *
 * v0.4 范围：顶层字段 + 常用规则；嵌套字段（`user.name`）留待后续。
 */
final class Validator
{
    /** @var array<string, Closure> 自定义规则 */
    private array $extensions = [];

    /** @var array<string, string> 自定义消息（键形如 `title.required`） */
    private array $messages = [];

    /**
     * @param array<string, string> $messages 自定义消息
     */
    public function __construct(array $messages = [])
    {
        $this->messages = $messages;
    }

    /**
     * 校验并返回通过的数据。
     *
     * @param array<string, mixed> $data 待校验数据
     * @param array<string, string> $rules 字段 => 规则串
     * @return array<string, mixed> 仅包含声明字段的数据
     * @throws ValidationException 校验失败时抛出
     */
    public function validate(array $data, array $rules): array
    {
        $errors = $this->errors($data, $rules);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $validated = [];

        foreach (array_keys($rules) as $field) {
            if (array_key_exists($field, $data)) {
                $validated[$field] = $data[$field];
            }
        }

        return $validated;
    }

    /**
     * 获取全部错误（不抛异常）。
     *
     * @param array<string, mixed> $data 数据
     * @param array<string, string> $rules 规则
     * @return array<string, list<string>> 字段 => 错误列表
     */
    public function errors(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $ruleList = array_values(array_filter(explode('|', $ruleString), static fn (string $r): bool => $r !== ''));
            $value = $data[$field] ?? null;
            $present = array_key_exists($field, $data);
            $nullable = in_array('nullable', $ruleList, true);

            if ($nullable && ($value === null || $value === '')) {
                continue;
            }

            foreach ($ruleList as $rule) {
                if ($rule === 'nullable') {
                    continue;
                }

                [$name, $parameter] = $this->parseRule($rule);
                $message = $this->checkRule($name, $value, $parameter, $field, $data, $present);

                if ($message !== null) {
                    $errors[$field][] = $message;

                    // 必填失败后不再继续后续规则，避免输出一串连带错误
                    if ($name === 'required') {
                        break;
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * 注册自定义规则。
     *
     * @param string $name 规则名
     * @param Closure(mixed, ?string, array<string, mixed>): bool $callback 返回值 true 表示通过
     * @return self 当前实例
     * @throws InvalidArgumentException 与内置规则同名时抛出
     */
    public function extend(string $name, Closure $callback): self
    {
        if (method_exists($this, 'check' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name))))) {
            throw new InvalidArgumentException(sprintf('规则 [%s] 与内置规则同名，请更换', $name));
        }

        $this->extensions[$name] = $callback;

        return $this;
    }

    /**
     * 追加自定义消息。
     *
     * @param array<string, string> $messages 形如 ['title.required' => '标题不能为空']
     * @return self 当前实例
     */
    public function withMessages(array $messages): self
    {
        $this->messages = array_merge($this->messages, $messages);

        return $this;
    }

    /**
     * 解析单条规则。
     *
     * @param string $rule 规则串片段
     * @return array{0: string, 1: string|null} 规则名与参数
     */
    private function parseRule(string $rule): array
    {
        $position = strpos($rule, ':');

        if ($position === false) {
            return [$rule, null];
        }

        return [substr($rule, 0, $position), substr($rule, $position + 1)];
    }

    /**
     * 执行单条规则。
     *
     * @param string $name 规则名
     * @param mixed $value 值
     * @param string|null $parameter 参数
     * @param string $field 字段名
     * @param array<string, mixed> $data 全部数据
     * @param bool $present 字段是否存在
     * @return string|null 错误消息，通过返回 null
     */
    private function checkRule(
        string $name,
        mixed $value,
        ?string $parameter,
        string $field,
        array $data,
        bool $present,
    ): ?string {
        if (isset($this->extensions[$name])) {
            $passed = (bool) ($this->extensions[$name])($value, $parameter, $data);

            return $passed ? null : $this->message($field, $name, $parameter, '校验未通过');
        }

        $method = 'check' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));

        if (!method_exists($this, $method)) {
            throw new InvalidArgumentException(sprintf('未知校验规则 [%s]', $name));
        }

        return $this->{$method}($value, $parameter, $field, $data, $present);
    }

    /**
     * 取消息（自定义优先）。
     *
     * @param string $field 字段
     * @param string $rule 规则
     * @param string|null $parameter 参数
     * @param string $default 默认消息模板
     * @return string 最终消息
     */
    private function message(string $field, string $rule, ?string $parameter, string $default): string
    {
        $template = $this->messages[$field . '.' . $rule]
            ?? $this->messages[$rule]
            ?? $default;

        return str_replace(
            ['{field}', '{param}'],
            [$field, (string) $parameter],
            $template
        );
    }

    /**
     * 判断是否为空值。
     *
     * @param mixed $value 值
     * @return bool 为空返回 true
     */
    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    // ── 内置规则 ──────────────────────────────────────────────────────

    /**
     * 必填。
     *
     * @param mixed $value 值
     * @param string|null $parameter 参数
     * @param string $field 字段
     * @param array<string, mixed> $data 数据
     * @param bool $present 是否存在
     * @return string|null 错误消息
     */
    private function checkRequired(mixed $value, ?string $parameter, string $field, array $data, bool $present): ?string
    {
        if (!$present || $this->isEmpty($value)) {
            return $this->message($field, 'required', null, '{field} 不能为空');
        }

        return null;
    }

    /**
     * 字符串。
     *
     * @param mixed $value 值
     * @param string|null $parameter 参数
     * @param string $field 字段
     * @param array<string, mixed> $data 数据
     * @param bool $present 是否存在
     * @return string|null 错误消息
     */
    private function checkString(mixed $value, ?string $parameter, string $field, array $data, bool $present): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        return is_string($value) ? null : $this->message($field, 'string', null, '{field} 必须是字符串');
    }

    /**
     * 整数。
     *
     * @param mixed $value 值
     * @param string|null $parameter 参数
     * @param string $field 字段
     * @param array<string, mixed> $data 数据
     * @param bool $present 是否存在
     * @return string|null 错误消息
     */
    private function checkInteger(mixed $value, ?string $parameter, string $field, array $data, bool $present): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        $passed = is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1);

        return $passed ? null : $this->message($field, 'integer', null, '{field} 必须是整数');
    }

    /**
     * 数值。
     *
     * @param mixed $value 值
     * @param string|null $parameter 参数
     * @param string $field 字段
     * @param array<string, mixed> $data 数据
     * @param bool $present 是否存在
     * @return string|null 错误消息
     */
    private function checkNumeric(mixed $value, ?string $parameter, string $field, array $data, bool $present): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        return is_numeric($value) ? null : $this->message($field, 'numeric', null, '{field} 必须是数字');
    }

    /**
     * 布尔。
     *
     * @param mixed $value 值
     * @param string|null $parameter 参数
     * @param string $field 字段
     * @param array<string, mixed> $data 数据
     * @param bool $present 是否存在
     * @return string|null 错误消息
     */
    private function checkBoolean(mixed $value, ?string $parameter, string $field, array $data, bool $present): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        return is_bool($value) ? null : $this->message($field, 'boolean', null, '{field} 必须是布尔值');
    }

    /**
     * 数组。
     *
     * @param mixed $value 值
     * @param string|null $parameter 参数
     * @param string $field 字段
     * @param array<string, mixed> $data 数据
     * @param bool $present 是否存在
     * @return string|null 错误消息
     */
    private function checkArray(mixed $value, ?string $parameter, string $field, array $data, bool $present): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        return is_array($value) ? null : $this->message($field, 'array', null, '{field} 必须是数组');
    }

    /**
     * 邮箱。
     *
     * @param mixed $value 值
     * @param string|null $parameter 参数
     * @param string $field 字段
     * @param array<string, mixed> $data 数据
     * @param bool $present 是否存在
     * @return string|null 错误消息
     */
    private function checkEmail(mixed $value, ?string $parameter, string $field, array $data, bool $present): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        $passed = is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;

        return $passed ? null : $this->message($field, 'email', null, '{field} 不是合法的邮箱地址');
    }

    /**
     * 日期（Y-m-d 或 ISO 8601）。
     *
     * @param mixed $value 值
     * @param string|null $parameter 参数
     * @param string $field 字段
     * @param array<string, mixed> $data 数据
     * @param bool $present 是否存在
     * @return string|null 错误消息
     */
    private function checkDate(mixed $value, ?string $parameter, string $field, array $data, bool $present): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        $passed = is_string($value) && strtotime($value) !== false;

        return $passed ? null : $this->message($field, 'date', null, '{field} 不是合法日期');
    }

    /**
     * 仅字母数字下划线短横线。
     *
     * @param mixed $value 值
     * @param string|null $parameter 参数
     * @param string $field 字段
     * @param array<string, mixed> $data 数据
     * @param bool $present 是否存在
     * @return string|null 错误消息
     */
    private function checkAlphaDash(mixed $value, ?string $parameter, string $field, array $data, bool $present): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        $passed = is_string($value) && preg_match('/^[A-Za-z0-9_-]+$/', $value) === 1;

        return $passed ? null : $this->message($field, 'alpha_dash', null, '{field} 只能包含字母、数字、下划线与短横线');
    }

    /**
     * 最小值（字符串长度 / 数值 / 数组元素数）。
     *
     * @param mixed $value 值
     * @param string|null $parameter 参数
     * @param string $field 字段
     * @param array<string, mixed> $data 数据
     * @param bool $present 是否存在
     * @return string|null 错误消息
     */
    private function checkMin(mixed $value, ?string $parameter, string $field, array $data, bool $present): ?string
    {
        if ($this->isEmpty($value) || $parameter === null) {
            return null;
        }

        $limit = (float) $parameter;
        $actual = $this->sizeOf($value);

        return $actual >= $limit ? null : $this->message($field, 'min', $parameter, sprintf('{field} 不能小于 %s', $parameter));
    }

    /**
     * 最大值（字符串长度 / 数值 / 数组元素数）。
     *
     * @param mixed $value 值
     * @param string|null $parameter 参数
     * @param string $field 字段
     * @param array<string, mixed> $data 数据
     * @param bool $present 是否存在
     * @return string|null 错误消息
     */
    private function checkMax(mixed $value, ?string $parameter, string $field, array $data, bool $present): ?string
    {
        if ($this->isEmpty($value) || $parameter === null) {
            return null;
        }

        $limit = (float) $parameter;
        $actual = $this->sizeOf($value);

        return $actual <= $limit ? null : $this->message($field, 'max', $parameter, sprintf('{field} 不能大于 %s', $parameter));
    }

    /**
     * 区间。
     *
     * @param mixed $value 值
     * @param string|null $parameter 形如 "1,10"
     * @param string $field 字段
     * @param array<string, mixed> $data 数据
     * @param bool $present 是否存在
     * @return string|null 错误消息
     * @throws InvalidArgumentException 参数格式错误时抛出
     */
    private function checkBetween(mixed $value, ?string $parameter, string $field, array $data, bool $present): ?string
    {
        if ($this->isEmpty($value) || $parameter === null) {
            return null;
        }

        $parts = explode(',', $parameter);

        if (count($parts) !== 2) {
            throw new InvalidArgumentException(sprintf('between 参数格式应为 "min,max"，收到 [%s]', $parameter));
        }

        $actual = $this->sizeOf($value);
        $passed = $actual >= (float) $parts[0] && $actual <= (float) $parts[1];

        return $passed ? null : $this->message(
            $field,
            'between',
            $parameter,
            sprintf('{field} 必须在 %s 到 %s 之间', $parts[0], $parts[1])
        );
    }

    /**
     * 枚举。
     *
     * @param mixed $value 值
     * @param string|null $parameter 形如 "draft,published"
     * @param string $field 字段
     * @param array<string, mixed> $data 数据
     * @param bool $present 是否存在
     * @return string|null 错误消息
     */
    private function checkIn(mixed $value, ?string $parameter, string $field, array $data, bool $present): ?string
    {
        if ($this->isEmpty($value) || $parameter === null) {
            return null;
        }

        $allowed = explode(',', $parameter);
        $passed = is_scalar($value) && in_array((string) $value, $allowed, true);

        return $passed ? null : $this->message(
            $field,
            'in',
            $parameter,
            sprintf('{field} 只能是 %s 之一', implode('、', $allowed))
        );
    }

    /**
     * 不在枚举内。
     *
     * @param mixed $value 值
     * @param string|null $parameter 形如 "admin,root"
     * @param string $field 字段
     * @param array<string, mixed> $data 数据
     * @param bool $present 是否存在
     * @return string|null 错误消息
     */
    private function checkNotIn(mixed $value, ?string $parameter, string $field, array $data, bool $present): ?string
    {
        if ($this->isEmpty($value) || $parameter === null) {
            return null;
        }

        $forbidden = explode(',', $parameter);
        $passed = !(is_scalar($value) && in_array((string) $value, $forbidden, true));

        return $passed ? null : $this->message($field, 'not_in', $parameter, '{field} 取值不被允许');
    }

    /**
     * 二次确认（需存在 `{field}_confirmation`）。
     *
     * @param mixed $value 值
     * @param string|null $parameter 参数
     * @param string $field 字段
     * @param array<string, mixed> $data 数据
     * @param bool $present 是否存在
     * @return string|null 错误消息
     */
    private function checkConfirmed(mixed $value, ?string $parameter, string $field, array $data, bool $present): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        $confirmation = $data[$field . '_confirmation'] ?? null;
        $passed = $confirmation === $value;

        return $passed ? null : $this->message($field, 'confirmed', null, '{field} 两次输入不一致');
    }

    /**
     * 计算"尺寸"：数组元素数 / 字符串长度 / 数值本身。
     *
     * @param mixed $value 值
     * @return float 尺寸
     */
    private function sizeOf(mixed $value): float
    {
        if (is_array($value)) {
            return (float) count($value);
        }

        if (is_string($value)) {
            return (float) mb_strlen($value);
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
