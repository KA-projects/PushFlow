<?php

namespace App\Contracts;

use App\Models\PushSubscription;

interface PushProviderInterface
{
    /**
     * Отправка push-уведомления конкретному подписчику.
     *
     * @param  array  $extra  Дополнительные параметры (иконка, ссылка, data payload)
     *
     * @throws \Exception Если отправка завершилась неудачей
     */
    public function send(PushSubscription $subscription, string $title, string $body, array $extra = []): void;
}
