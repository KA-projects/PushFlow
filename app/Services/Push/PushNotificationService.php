<?php

namespace App\Services\Push;

use App\Jobs\SendPushNotification;
use App\Models\PushSubscription;
use Illuminate\Support\Collection;

class PushNotificationService
{
    /**
     * Постановка push-уведомления в очередь для одной подписки (по endpoint) или всех.
     *
     * @param  array<string, mixed>  $extra
     */
    public function queueForEndpoint(string $title, string $body, ?string $endpoint, array $extra = []): Collection
    {
        $subscriptions = $endpoint !== null
            ? PushSubscription::where('endpoint', $endpoint)->get()
            : PushSubscription::all();

        foreach ($subscriptions as $subscription) {
            SendPushNotification::dispatch($subscription->id, $title, $body, $extra);
        }

        return $subscriptions;
    }
}
