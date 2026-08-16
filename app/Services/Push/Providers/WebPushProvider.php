<?php

namespace App\Services\Push\Providers;

use App\Models\PushSubscription;
use App\Services\Push\PushProviderInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use RuntimeException;

class WebPushProvider implements PushProviderInterface
{
    public function __construct(private ?WebPush $webPush = null) {}

    public function send(PushSubscription $subscription, string $title, string $body, array $extra = []): void
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
                throw new RuntimeException('WebPush failed: '.$report->getReason());
            }
        }
    }
}
