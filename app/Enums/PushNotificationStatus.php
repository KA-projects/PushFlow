<?php

namespace App\Enums;

enum PushNotificationStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Accepted = 'accepted';
    case Delivered = 'delivered';
    case Failed = 'failed';

    /**
     * Финальные статусы, после которых любые повторные запуски Job должны быть проигнорированы.
     */
    public function isFinal(): bool
    {
        return $this === self::Delivered || $this === self::Failed;
    }
}
