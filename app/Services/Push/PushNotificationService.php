<?php

namespace App\Services\Push;

use App\Dto\SendPushNotificationData;
use App\Enums\PushNotificationStatus;
use App\Jobs\SendPushNotification;
use App\Models\PushNotification;
use App\Models\PushSubscription;
use Illuminate\Support\Collection;

class PushNotificationService
{
    /**
     * Создание уведомлений и постановка Job в очередь для одной подписки (по endpoint) или всех активных.
     *
     * Уведомления для неактивных подписок не создаются и не отправляются.
     *
     * @return Collection<int, PushSubscription>
     */
    public function queueForEndpoint(SendPushNotificationData $data): Collection
    {
        $subscriptions = PushSubscription::query()
            ->when($data->endpoint !== null, fn ($query) => $query->where('endpoint', $data->endpoint))
            ->where('is_active', true)
            ->get();

        foreach ($subscriptions as $subscription) {
            $notification = PushNotification::create([
                'user_id' => $subscription->user_id,
                'push_subscription_id' => $subscription->id,
                'title' => $data->title,
                'body' => $data->body,
                'payload' => $data->extra,
                'status' => PushNotificationStatus::Pending,
                'provider' => $subscription->provider,
            ]);

            SendPushNotification::dispatch($notification->id);
        }

        return $subscriptions;
    }
}
