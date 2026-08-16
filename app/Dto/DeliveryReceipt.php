<?php

namespace App\Dto;

final readonly class DeliveryReceipt
{
    /**
     * @param  string  $status  Статус доставки: delivered | pending | failed
     */
    public function __construct(
        public string $status,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}
}
