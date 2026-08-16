<?php

namespace App\Jobs\Concerns;

use App\Enums\PushNotificationStatus;
use App\Models\PushAttempt;
use App\Models\PushNotification;

trait RecordsPushAttempts
{
    /**
     * Сохранение записи в push_attempts для диагностики.
     */
    protected function recordAttempt(
        PushNotification $notification,
        PushNotificationStatus|string $status,
        ?string $ticketId = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        ?int $attempt = null,
    ): void {
        PushAttempt::create([
            'notification_id' => $notification->id,
            'attempt' => $attempt ?? $this->attempts(),
            'status' => $status instanceof PushNotificationStatus ? $status->value : $status,
            'ticket_id' => $ticketId,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'created_at' => now(),
        ]);
    }
}
