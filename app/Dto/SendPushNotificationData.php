<?php

namespace App\Dto;

final readonly class SendPushNotificationData
{
    public function __construct(
        /** Заголовок уведомления */
        public string $title,
        /** Текст уведомления */
        public string $body,
        /** Endpoint подписки, для которой отправляется уведомление (null — всем подпискам) */
        public ?string $endpoint = null,
        /** Дополнительные параметры (иконка, ссылка, data payload) */
        public array $extra = [],
    ) {}

    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'endpoint' => $this->endpoint,
            'extra' => $this->extra,
        ];
    }
}
