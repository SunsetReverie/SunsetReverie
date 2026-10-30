<?php

declare(strict_types=1);

namespace SunsetReverie\Mail;

/**
 * 邮件发送器接口。
 *
 * 实现方只需要关心「把 Message 发出去」；
 * 失败必须抛异常，不能返回 false——队列靠异常判断是否重试。
 */
interface MailerInterface
{
    /**
     * 发送邮件。
     *
     * @param Message $message 邮件
     * @return void
     * @throws Exception\MailException 发送失败时抛出
     */
    public function send(Message $message): void;
}
