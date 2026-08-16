<?php

namespace App\Services\Push;

use App\Dto\SendPushNotificationData;
use App\Jobs\SendPushNotification;
use App\Models\PushSubscription;
use Illuminate\Support\Collection;

class PushNotificationService
{
    /**
     * Постановка push-уведомления в очередь для одной подписки (по endpoint) или всех.
     *
     * @return Collection<int, PushSubscription>
     */
    public function queueForEndpoint(SendPushNotificationData $data): Collection
    {
        $subscriptions = $data->endpoint !== null
            ? PushSubscription::where('endpoint', $data->endpoint)->get()
            : PushSubscription::all();

        foreach ($subscriptions as $subscription) {
            SendPushNotification::dispatch($subscription->id, $data->title, $data->body, $data->extra);
        }

        return $subscriptions;
    }
}
