<?php

namespace App\Services\Push;

use App\Dto\PushSubscriptionData;
use App\Models\PushSubscription;

class PushSubscriptionService
{
    /**
     * Сохранение/обновление подписки клиента на push-уведомления.
     */
    public function upsert(PushSubscriptionData $data): PushSubscription
    {
        return PushSubscription::updateOrCreate(
            ['endpoint' => $data->endpoint],
            [
                'provider' => 'webpush',
                'public_key' => $data->p256dh,
                'auth_token' => $data->auth,
                'user_id' => auth()->id(),
            ]
        );
    }
}
