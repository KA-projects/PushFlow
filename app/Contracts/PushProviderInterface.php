<?php

namespace App\Contracts;

use App\Dto\DeliveryReceipt;
use App\Dto\SendResult;
use App\Models\PushSubscription;
use App\Services\Push\Exceptions\DeviceNotRegisteredException;
use App\Services\Push\Exceptions\PermanentPushException;
use App\Services\Push\Exceptions\TemporaryPushException;

interface PushProviderInterface
{
    /**
     * Отправка push-уведомления конкретному подписчику.
     *
     * @param  array  $extra  Дополнительные параметры (иконка, ссылка, data payload)
     * @param  string|null  $idempotencyKey  Ключ идемпотентности для повторных попыток (если поддерживается провайдером)
     *
     * @throws TemporaryPushException Временная ошибка — требуется retry
     * @throws DeviceNotRegisteredException Устройство недоступно — деактивировать token
     * @throws PermanentPushException Постоянная ошибка — не требует retry
     */
    public function send(
        PushSubscription $subscription,
        string $title,
        string $body,
        array $extra = [],
        ?string $idempotencyKey = null,
    ): SendResult;

    /**
     * Проверка результата доставки по идентификатору операции.
     */
    public function checkDelivery(PushSubscription $subscription, string $ticketId): DeliveryReceipt;
}
