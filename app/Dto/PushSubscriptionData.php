<?php

namespace App\Dto;

final readonly class PushSubscriptionData
{
    public function __construct(
        /** Endpoint подписки клиента на push-уведомления */
        public string $endpoint,
        /** P256DH-ключ шифрования сообщений */
        public string $p256dh,
        /** Auth-токен для шифрования сообщений */
        public string $auth,
    ) {}

    public function toArray(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'keys' => [
                'p256dh' => $this->p256dh,
                'auth' => $this->auth,
            ],
        ];
    }
}
