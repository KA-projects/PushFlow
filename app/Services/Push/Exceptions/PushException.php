<?php

namespace App\Services\Push\Exceptions;

use RuntimeException;
use Throwable;

abstract class PushException extends RuntimeException
{
    /**
     * @param  string  $errorCode  Машинный код ошибки (например, HTTP_503, DeviceNotRegistered).
     * @param  string  $message  Человекочитаемое описание ошибки.
     */
    public function __construct(
        protected string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
