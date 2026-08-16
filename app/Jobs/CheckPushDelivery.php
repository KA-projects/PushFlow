<?php

namespace App\Jobs;

use App\Dto\DeliveryReceipt;
use App\Enums\PushNotificationStatus;
use App\Jobs\Concerns\RecordsPushAttempts;
use App\Models\PushNotification;
use App\Models\PushSubscription;
use App\Services\Push\Exceptions\TemporaryPushException;
use App\Services\Push\PushNotificationManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class CheckPushDelivery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RecordsPushAttempts, SerializesModels;

    public int $timeout = 30;

    /**
     * @param  int  $notificationId  ID уведомления, для которого проверяется доставка.
     * @param  int  $attempt  Номер проверки receipt (начинается с 1).
     */
    public function __construct(
        public int $notificationId,
        public int $attempt = 1,
    ) {}

    /**
     * Проверка результата доставки по сохранённому ticket_id.
     */
    public function handle(PushNotificationManager $manager): void
    {
        $notification = PushNotification::find($this->notificationId);

        if ($notification === null || $notification->status->isFinal()) {
            return;
        }

        if ($notification->ticket_id === null) {
            return;
        }

        $subscription = PushSubscription::find($notification->push_subscription_id);

        if ($subscription === null) {
            return;
        }

        try {
            $receipt = $manager->driver($notification->provider)->checkDelivery($subscription, $notification->ticket_id);
        } catch (TemporaryPushException|Throwable $exception) {
            $this->scheduleNextCheck($notification, $exception->getMessage());

            return;
        }

        match ($receipt->status) {
            'delivered' => $this->markDelivered($notification),
            'failed' => $this->markFailedFromReceipt($notification, $subscription, $receipt),
            default => $this->scheduleNextCheck($notification),
        };
    }

    protected function markDelivered(PushNotification $notification): void
    {
        $notification->status = PushNotificationStatus::Delivered;
        $notification->delivered_at = now();
        $notification->save();

        $this->recordAttempt($notification, PushNotificationStatus::Delivered, attempt: $this->attempt);
    }

    protected function markFailedFromReceipt(PushNotification $notification, PushSubscription $subscription, DeliveryReceipt $receipt): void
    {
        if (in_array($receipt->errorCode, ['DeviceNotRegistered', 'InvalidToken'], true) && $subscription->is_active) {
            $subscription->is_active = false;
            $subscription->save();
        }

        $notification->status = PushNotificationStatus::Failed;
        $notification->error_code = $receipt->errorCode ?? 'DELIVERY_FAILED';
        $notification->error_message = $receipt->errorMessage ?? 'Delivery failed according to the push provider.';
        $notification->failed_at = now();
        $notification->save();

        $this->recordAttempt(
            $notification,
            PushNotificationStatus::Failed,
            errorCode: $notification->error_code,
            errorMessage: $notification->error_message,
            attempt: $this->attempt,
        );
    }

    /**
     * Receipt ещё не готов — повторяем проверку позже (с ограничением количества попыток).
     */
    protected function scheduleNextCheck(PushNotification $notification, ?string $message = null): void
    {
        $maxAttempts = (int) config('push.receipt.max_attempts', 5);

        if ($this->attempt >= $maxAttempts) {
            $notification->status = PushNotificationStatus::Failed;
            $notification->error_code = 'DELIVERY_STATUS_UNKNOWN';
            $notification->error_message = 'Delivery status could not be determined after multiple checks.';
            $notification->failed_at = now();
            $notification->save();

            $this->recordAttempt(
                $notification,
                PushNotificationStatus::Failed,
                errorCode: 'DELIVERY_STATUS_UNKNOWN',
                errorMessage: $notification->error_message,
                attempt: $this->attempt,
            );

            return;
        }

        $this->recordAttempt($notification, PushNotificationStatus::Pending, errorMessage: $message, attempt: $this->attempt);

        CheckPushDelivery::dispatch($this->notificationId, $this->attempt + 1)
            ->delay(now()->addSeconds($this->nextDelay()));
    }

    /**
     * Задержка перед следующей проверкой (эскалация: 30s, 60s, 120s, ...).
     */
    protected function nextDelay(): int
    {
        $delays = (array) config('push.receipt.reschedule_delays', [30, 60, 120, 300, 600]);

        return $delays[$this->attempt - 1] ?? (int) end($delays);
    }
}
