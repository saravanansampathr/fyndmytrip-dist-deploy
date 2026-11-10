<?php

namespace App\Domains\Suppliers\Contracts;

use App\Domains\Suppliers\DTOs\BookingConfirmResult;
use App\Domains\Suppliers\DTOs\BookingHoldResult;
use App\Domains\Suppliers\DTOs\FareRuleResult;
use App\Domains\Suppliers\DTOs\SearchRequest;
use App\Domains\Suppliers\DTOs\SearchResultItem;
use App\Domains\Suppliers\DTOs\SupplierBalance;

/**
 * The common internal contract every supplier integration implements,
 * translating supplier-specific XML/JSON/SOAP into this shape — see Master
 * Brief §6 and the Supplier API Integration Specification. One adapter
 * class per supplier (TBOAdapter, TripJackAdapter, AkbarAdapter, or a
 * tenant's own custom supplier via the self-service "Add Supplier" wizard).
 *
 * Every method is called with the tenant-scoped, decrypted credentials
 * array resolved by the caller (SupplierOrchestrator) from
 * tenant_supplier_configs — adapters are stateless with respect to which
 * tenant is calling them.
 */
interface SupplierAdapterInterface
{
    public function code(): string;

    /**
     * @return SearchResultItem[]
     */
    public function searchAvailability(SearchRequest $request, array $credentials): array;

    public function priceCheck(string $externalId, array $credentials): FareRuleResult;

    public function holdBooking(string $externalId, array $passengers, array $credentials): BookingHoldResult;

    public function confirmBooking(string $supplierBookingRef, array $credentials): BookingConfirmResult;

    public function retrieveBooking(string $supplierBookingRef, array $credentials): array;

    public function cancelBooking(string $supplierBookingRef, array $credentials): bool;

    public function getRefundStatus(string $supplierBookingRef, array $credentials): string;

    public function getSupplierAccountBalance(array $credentials): SupplierBalance;
}
