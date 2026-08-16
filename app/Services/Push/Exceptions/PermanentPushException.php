<?php

namespace App\Services\Push\Exceptions;

/**
 * Постоянная ошибка: повторная отправка бессмысленна, уведомление помечается failed.
 */
class PermanentPushException extends PushException
{
    public static function invalidPayload(string $message): self
    {
        return new self('InvalidPayload', $message);
    }

    public static function invalidToken(): self
    {
        return new self('InvalidToken', 'The device token is invalid.');
    }

    public static function unknown(string $message): self
    {
        return new self('UNKNOWN_ERROR', $message);
    }
}
