<?php

namespace App\Domains\Suppliers\DTOs;

final class BookingHoldResult
{
    public function __construct(
        public readonly string $supplierBookingRef,
        public readonly \DateTimeImmutable $holdExpiresAt,
        public readonly string $netAmount,
    ) {}
}
