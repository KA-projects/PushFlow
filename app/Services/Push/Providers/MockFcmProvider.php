<?php

namespace App\Services\Push\Providers;

use App\Models\PushSubscription;
use App\Services\Push\PushProviderInterface;
use Illuminate\Support\Facades\Log;

class MockFcmProvider implements PushProviderInterface
{
    public function send(PushSubscription $subscription, string $title, string $body, array $extra = []): void
    {
        // Эмулируем отправку через Firebase Cloud Messaging
        Log::info('Sending FCM notification via Google API...', [
            'endpoint' => $subscription->endpoint,
            'title' => $title,
            'body' => $body,
        ]);
    }
}
