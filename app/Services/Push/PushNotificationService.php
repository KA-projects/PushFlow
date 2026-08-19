<?php

namespace App\Services\Push;

use App\Dto\SendPushNotificationData;
use App\Enums\PushNotificationStatus;
use App\Jobs\SendPushNotification;
use App\Models\PushSubscription;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class PushNotificationService
{
    /**
     * Создание уведомлений и постановка Job в очередь для одной подписки (по endpoint) или всех активных.
     *
     * Уведомления для неактивных подписок не создаются и не отправляются.
     * Уведомления создаются пачкой (bulk insert) с получением ID через RETURNING,
     * чтобы не выполнять по одному INSERT с автокоммитом на каждую подписку.
     *
     * @return Collection<int, PushSubscription>
     */
    public function queueForEndpoint(SendPushNotificationData $data): Collection
    {
        $subscriptions = PushSubscription::query()
            ->when($data->endpoint !== null, fn ($query) => $query->where('endpoint', $data->endpoint))
            ->where('is_active', true)
            ->get();

        if ($subscriptions->isEmpty()) {
            return $subscriptions;
        }

        $notificationIds = DB::transaction(function () use ($subscriptions, $data): array {
            $ids = [];

            foreach ($subscriptions->chunk(500) as $chunk) {
                $ids = array_merge($ids, $this->bulkInsertNotifications($chunk, $data));
            }

            return $ids;
        });

        // Все Job ставятся одним pipeline-вызовом (Queue::bulk) — один round-trip до Redis вместо N LPUSH.
        Queue::bulk(
            array_map(fn (int $id) => new SendPushNotification($id), $notificationIds),
        );

        return $subscriptions;
    }

    /**
     * Вставка пачки уведомлений одним INSERT ... RETURNING id.
     *
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @return array<int, int>
     */
    protected function bulkInsertNotifications(Collection $subscriptions, SendPushNotificationData $data): array
    {
        $now = now();

        $rows = $subscriptions->map(fn (PushSubscription $subscription): array => [
            $subscription->user_id,
            $subscription->id,
            $data->title,
            $data->body,
            $data->extra === null ? null : json_encode($data->extra, JSON_UNESCAPED_UNICODE),
            PushNotificationStatus::Pending->value,
            $subscription->provider,
            $now,
            $now,
        ])->values()->all();

        if ($rows === []) {
            return [];
        }

        $columnCount = count($rows[0]);

        $placeholders = implode(',', array_map(
            fn (): string => '('.implode(',', array_fill(0, $columnCount, '?')).')',
            $rows,
        ));

        $columns = 'user_id, push_subscription_id, title, body, payload, status, provider, created_at, updated_at';

        $ids = collect(DB::select(
            "insert into notifications ({$columns}) values {$placeholders} returning id",
            array_merge(...$rows),
        ))->pluck('id');

        return $ids->map(fn (mixed $id): int => (int) $id)->all();
    }
}
