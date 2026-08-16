<?php

namespace App\Services\Push\Providers;

use App\Contracts\PushProviderInterface;
use App\Dto\DeliveryReceipt;
use App\Dto\SendResult;
use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MockFcmProvider implements PushProviderInterface
{
    public function send(PushSubscription $subscription, string $title, string $body, array $extra = [], ?string $idempotencyKey = null): SendResult
    {
        // Эмулируем отправку через Firebase Cloud Messaging
        Log::info('Sending FCM notification via Google API...', [
            'endpoint' => $subscription->endpoint,
            'title' => $title,
            'body' => $body,
            'idempotency_key' => $idempotencyKey,
        ]);

        return new SendResult(ticketId: 'ticket-'.Str::uuid());
    }

    public function checkDelivery(PushSubscription $subscription, string $ticketId): DeliveryReceipt
    {
        return new DeliveryReceipt(status: 'delivered');
    }
}
