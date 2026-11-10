<?php

namespace App\Domains\Suppliers\DTOs;

final class BookingConfirmResult
{
    public function __construct(
        public readonly string $supplierBookingRef,
        public readonly bool $isTicketed,
        public readonly array $ticketNumbers = [],
    ) {}
}
