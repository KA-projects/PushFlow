<?php

namespace App\Dto;

final readonly class SendResult
{
    /**
     * @param  string  $ticketId  Идентификатор операции, возвращённый Push Provider'ом.
     *                            Наличие ticket означает только «принято», не «доставлено».
     */
    public function __construct(
        public string $ticketId,
    ) {}
}
