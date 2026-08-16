<?php

namespace App\Services\Push\Providers;

use App\Contracts\PushProviderInterface;
use App\Dto\DeliveryReceipt;
use App\Dto\SendResult;
use App\Models\PushSubscription;
use App\Services\Push\Exceptions\DeviceNotRegisteredException;
use App\Services\Push\Exceptions\PermanentPushException;
use App\Services\Push\Exceptions\TemporaryPushException;
use Illuminate\Support\Str;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushProvider implements PushProviderInterface
{
    public function __construct(private ?WebPush $webPush = null) {}

    public function send(PushSubscription $subscription, string $title, string $body, array $extra = [], ?string $idempotencyKey = null): SendResult
    {
        $webPush = $this->webPush ?? new WebPush([
            'VAPID' => [
                'subject' => config('webpush.vapid.subject'),
                'publicKey' => config('webpush.vapid.public_key'),
                'privateKey' => config('webpush.vapid.private_key'),
            ],
        ]);

        $webPushSubscription = Subscription::create([
            'endpoint' => $subscription->endpoint,
            'keys' => [
                'p256dh' => $subscription->public_key,
                'auth' => $subscription->auth_token,
            ],
        ]);

        $webPush->queueNotification(
            $webPushSubscription,
            json_encode(array_merge([
                'title' => $title,
                'body' => $body,
            ], $extra))
        );

        foreach ($webPush->flush() as $report) {
            if (! $report->isSuccess()) {
                throw $this->exceptionFromReport($report);
            }
        }

        return new SendResult(ticketId: 'webpush-'.$subscription->id.'-'.Str::random(8));
    }

    public function checkDelivery(PushSubscription $subscription, string $ticketId): DeliveryReceipt
    {
        // Web Push не предоставляет delivery receipts — считаем доставку подтверждённой.
        return new DeliveryReceipt(status: 'delivered');
    }

    /**
     * Классификация неудачного отчёта WebPush на временные/постоянные/устройство.
     */
    protected function exceptionFromReport(MessageSentReport $report): \Exception
    {
        $reason = $report->getReason();
        $response = $report->getResponse();
        $status = $response?->getStatusCode();

        if ($status >= 429 && $status < 500) {
            return TemporaryPushException::http($status);
        }

        if (in_array($status, [500, 502, 503, 504], true)) {
            return TemporaryPushException::http($status);
        }

        if (in_array($status, [404, 410], true)) {
            return DeviceNotRegisteredException::create(message: 'WebPush failed: '.$reason);
        }

        return PermanentPushException::unknown('WebPush failed: '.$reason);
    }
}
