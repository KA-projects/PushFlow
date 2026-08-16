<?php

namespace App\Services\Push;

use App\Models\PushSubscription;

class PushSubscriptionService
{
    /**
     * Сохранение/обновление подписки клиента на push-уведомления.
     *
     * @param  array{endpoint: string, keys: array{p256dh: string, auth: string}}  $validated
     */
    public function upsert(array $validated): PushSubscription
    {
        return PushSubscription::updateOrCreate(
            ['endpoint' => $validated['endpoint']],
            [
                'provider' => 'webpush',
                'public_key' => $validated['keys']['p256dh'],
                'auth_token' => $validated['keys']['auth'],
                'user_id' => auth()->id(),
            ]
        );
    }
}
