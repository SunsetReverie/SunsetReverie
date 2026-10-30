<?php

declare(strict_types=1);

namespace SunsetReverie\Mail;

/**
 * 一封邮件。
 *
 * 只用标量属性：进队列时要 JSON 序列化，不能带对象或资源。
 */
final class Message
{
    /**
     * @param string $to 收件人邮箱
     * @param string $subject 主题
     * @param string $html HTML 正文
     * @param string $text 纯文本正文（留空时从 HTML 粗略剥离）
     * @param string $toName 收件人显示名
     */
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text = '',
        public readonly string $toName = '',
    ) {
    }

    /**
     * 纯文本正文（未提供时从 HTML 剥离标签）。
     *
     * @return string 纯文本
     */
    public function plainText(): string
    {
        if ($this->text !== '') {
            return $this->text;
        }

        $stripped = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', '', $this->html) ?? $this->html;
        // 块级标签统一换成换行，否则剥离后段落会粘成一行
        $stripped = str_replace(
            ['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>', '</blockquote>', '</h1>', '</h2>', '</h3>', '</tr>'],
            "\n",
            $stripped
        );
        $stripped = strip_tags($stripped);

        return trim(html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * 序列化为数组（进队列用）。
     *
     * @return array<string, string> 数据
     */
    public function toArray(): array
    {
        return [
            'to' => $this->to,
            'subject' => $this->subject,
            'html' => $this->html,
            'text' => $this->text,
            'to_name' => $this->toName,
        ];
    }

    /**
     * 从数组恢复。
     *
     * @param array<string, mixed> $data 数据
     * @return self 邮件
     */
    public static function fromArray(array $data): self
    {
        return new self(
            to: (string) ($data['to'] ?? ''),
            subject: (string) ($data['subject'] ?? ''),
            html: (string) ($data['html'] ?? ''),
            text: (string) ($data['text'] ?? ''),
            toName: (string) ($data['to_name'] ?? ''),
        );
    }
}
