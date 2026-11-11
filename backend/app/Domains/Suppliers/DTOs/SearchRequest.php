<?php

namespace App\Domains\Suppliers\DTOs;

/**
 * Module-agnostic search request passed to every SupplierAdapterInterface
 * implementation. Module-specific parameters (e.g. hotel star rating, cab
 * vehicle type) travel in $extra rather than growing this class per module,
 * consistent with the generic booking_segments design in the Database
 * Design Document §4.3.
 */
final class SearchRequest
{
    public function __construct(
        public readonly string $module,
        public readonly string $origin,
        public readonly ?string $destination,
        public readonly string $departureDate,
        public readonly ?string $returnDate = null,
        public readonly int $passengerCount = 1,
        public readonly array $extra = [],
    ) {}
}
