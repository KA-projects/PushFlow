<?php

namespace App\Services\Push\Exceptions;

/**
 * Устройство отписано либо token более недействителен:
 * notification → failed, subscription → is_active = false.
 */
class DeviceNotRegisteredException extends PermanentPushException
{
    public static function create(string $errorCode = 'DeviceNotRegistered', string $message = 'The device is not registered for push notifications.'): self
    {
        return new self($errorCode, $message);
    }
}
