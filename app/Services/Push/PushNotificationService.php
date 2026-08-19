<?php

namespace App\Services\Push;

use App\Dto\SendPushNotificationData;
use App\Enums\PushNotificationStatus;
use App\Jobs\SendPushNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class PushNotificationService
{
    /**
     * Создание уведомлений и постановка Job в очередь для одной подписки (по endpoint) или всех активных.
     *
     * Уведомления для неактивных подписок не создаются и не отправляются.
     * Вставка выполняется одним INSERT ... SELECT ... RETURNING id — без отдельного SELECT
     * подписок в PHP и гидрации моделей, т.е. один round-trip до БД на весь запрос.
     *
     * @return Collection<int, int> ID созданных уведомлений.
     */
    public function queueForEndpoint(SendPushNotificationData $data): Collection
    {
        $now = now()->format('Y-m-d H:i:s');
        $payload = $data->extra === null ? null : json_encode($data->extra, JSON_UNESCAPED_UNICODE);

        // SQLite (тесты) не понимает ::json, а cast(? as json) превращает '[]' в 0,
        // поэтому тип каста выбираем по драйверу: pgsql требует json, sqlite — text.
        $payloadCast = DB::connection()->getDriverName() === 'pgsql' ? 'json' : 'text';

        $ids = collect(DB::select(
            "insert into notifications
                (user_id, push_subscription_id, title, body, payload, status, provider, created_at, updated_at)
            select user_id, id, ?, ?, cast(? as {$payloadCast}), ?, provider, ?, ?
            from push_subscriptions
            where is_active = true
                and (cast(? as varchar) is null or endpoint = ?)
            returning id",
            [
                $data->title,
                $data->body,
                $payload,
                PushNotificationStatus::Pending->value,
                $now,
                $now,
                $data->endpoint,
                $data->endpoint,
            ],
        ))->pluck('id');

        $notificationIds = $ids->map(fn (mixed $id): int => (int) $id)->all();

        if ($notificationIds === []) {
            return collect();
        }

        // Все Job ставятся одним pipeline-вызовом (Queue::bulk) — один round-trip до Redis вместо N LPUSH.
        Queue::bulk(
            array_map(fn (int $id) => new SendPushNotification($id), $notificationIds),
        );

        return collect($notificationIds);
    }
}
