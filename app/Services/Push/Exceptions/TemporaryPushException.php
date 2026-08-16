<?php

namespace App\Services\Push\Exceptions;

/**
 * Временная ошибка Push Provider'а: требует повторной отправки (retry).
 */
class TemporaryPushException extends PushException
{
    public static function http(int $status): self
    {
        return new self("HTTP_{$status}", "Push provider returned HTTP {$status}.");
    }

    public static function timeout(): self
    {
        return new self('TIMEOUT', 'Push provider request timed out.');
    }

    public static function network(): self
    {
        return new self('NETWORK_ERROR', 'Temporary network error while contacting push provider.');
    }

    public static function from(string $errorCode, string $message): self
    {
        return new self($errorCode, $message);
    }
}
