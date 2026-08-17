<?php

namespace App\Jobs;

use App\Enums\PushNotificationStatus;
use App\Jobs\Concerns\RecordsPushAttempts;
use App\Models\PushNotification;
use App\Models\PushSubscription;
use App\Services\Push\Exceptions\DeviceNotRegisteredException;
use App\Services\Push\Exceptions\PermanentPushException;
use App\Services\Push\Exceptions\TemporaryPushException;
use App\Services\Push\PushNotificationManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RecordsPushAttempts, SerializesModels;

    /**
     * Количество попыток отправки, после исчерпания которых уведомление помечается failed.
     */
    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(
        protected int $notificationId,
    ) {}

    /**
     * Отправка push-уведомления через драйвер, выбранный по полю provider подписки.
     */
    public function handle(PushNotificationManager $manager): void
    {
        $notification = PushNotification::find($this->notificationId);

        if ($notification === null || $notification->status->isFinal()) {
            return;
        }

        // Атомарный захват: только один worker обработает уведомление одновременно.
        if (! $this->claim($notification)) {
            return;
        }

        $notification->refresh();
        $notification->increment('attempts');

        $subscription = PushSubscription::find($notification->push_subscription_id);

        if ($subscription === null || ! $subscription->is_active) {
            $this->failPermanently($notification, 'DEVICE_INACTIVE', 'Push subscription is missing or inactive.');

            return;
        }

        $driver = $manager->driver($notification->provider);

        try {
            $result = $driver->send(
                $subscription,
                $notification->title,
                $notification->body,
                $notification->payload ?? [],
                $this->idempotencyKey($notification),
            );

            $notification->status = PushNotificationStatus::Accepted;
            $notification->ticket_id = $result->ticketId;
            $notification->sent_at = now();
            $notification->save();

            $this->recordAttempt(
                $notification,
                PushNotificationStatus::Accepted,
                ticketId: $result->ticketId,
            );

            // Доставку проверяем отдельной Job — не в рамках HTTP-запроса к провайдеру.
            CheckPushDelivery::dispatch($notification->id)
                ->delay(now()->addSeconds((int) config('push.receipt.delay', 10)));
        } catch (DeviceNotRegisteredException $exception) {
            $this->deactivateSubscription($subscription);
            $this->failPermanently($notification, $exception->getErrorCode(), $exception->getMessage());
        } catch (PermanentPushException $exception) {
            $this->failPermanently($notification, $exception->getErrorCode(), $exception->getMessage());
        } catch (TemporaryPushException $exception) {
            $this->releaseForRetry($notification, $exception);
        } catch (Throwable $exception) {
            $this->failPermanently($notification, 'UNKNOWN_ERROR', $exception->getMessage());
        }
    }

    /**
     * Временные ошибки: 10s, 30s, 60s, 300s, 600s.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [
            10,
            30,
            60,
            300,
            600,
        ];
    }

    /**
     * Вызывается после исчерпания всех попыток.
     */
    public function failed(?Throwable $exception = null): void
    {
        $notification = PushNotification::find($this->notificationId);

        if ($notification === null || $notification->status->isFinal()) {
            return;
        }

        $this->failPermanently(
            $notification,
            'MAX_ATTEMPTS',
            $exception?->getMessage() ?? 'Max attempts reached without successful delivery.',
        );
    }

    /**
     * Ключ идемпотентности для повторных попыток.
     */
    protected function idempotencyKey(PushNotification $notification): string
    {
        $prefix = (string) config('push.idempotency_prefix', 'notification-');

        return $prefix.$notification->id;
    }

    /**
     * Атомарный переход pending/processing → processing.
     */
    protected function claim(PushNotification $notification): bool
    {
        return PushNotification::query()
            ->whereKey($notification->getKey())
            ->whereIn('status', [
                PushNotificationStatus::Pending->value,
                PushNotificationStatus::Processing->value,
            ])
            ->update(['status' => PushNotificationStatus::Processing->value]) === 1;
    }

    /**
     * Фиксация окончательной ошибки без дальнейших retry.
     */
    protected function failPermanently(PushNotification $notification, string $errorCode, string $errorMessage): void
    {
        $notification->status = PushNotificationStatus::Failed;
        $notification->error_code = $errorCode;
        $notification->error_message = $errorMessage;
        $notification->failed_at = now();
        $notification->save();

        $this->recordAttempt(
            $notification,
            PushNotificationStatus::Failed,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
        );
    }

    protected function deactivateSubscription(PushSubscription $subscription): void
    {
        if ($subscription->is_active) {
            $subscription->is_active = false;
            $subscription->save();
        }
    }

    /**
     * Возврат уведомления в pending и проброс исключения, чтобы очередь выполнила retry.
     */
    protected function releaseForRetry(PushNotification $notification, Throwable $exception): void
    {
        $this->recordAttempt(
            $notification,
            'error',
            errorCode: $exception instanceof TemporaryPushException ? $exception->getErrorCode() : 'UNKNOWN_ERROR',
            errorMessage: $exception->getMessage(),
        );

        // Прямой UPDATE в обход dirty-tracking: значение status в памяти модели может
        // отличаться от БД (claim уже перевёл строку в processing).
        PushNotification::query()
            ->whereKey($notification->getKey())
            ->update(['status' => PushNotificationStatus::Pending->value]);

        throw $exception;
    }
}
