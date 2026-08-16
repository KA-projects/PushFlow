<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Services\Push\PushNotificationManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  int  $subscriptionId  ID подписки в таблице push_subscriptions
     * @param  string  $title  Заголовок уведомления
     * @param  string  $body  Текст уведомления
     * @param  array<string, mixed>  $extra  Дополнительные параметры (иконка, ссылка, data payload)
     */
    public function __construct(
        protected int $subscriptionId,
        protected string $title,
        protected string $body,
        protected array $extra = [],
    ) {}

    /**
     * Отправка push-уведомления через драйвер, выбранный по полю provider подписки.
     */
    public function handle(PushNotificationManager $manager): void
    {
        $subscription = PushSubscription::findOrFail($this->subscriptionId);

        $driver = $manager->driver($subscription->provider);

        $driver->send($subscription, $this->title, $this->body, $this->extra);
    }
}
