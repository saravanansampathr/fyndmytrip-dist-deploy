<?php

namespace App\Domains\Suppliers\Adapters;

use App\Domains\Suppliers\Contracts\SupplierAdapterInterface;
use App\Domains\Suppliers\DTOs\BookingConfirmResult;
use App\Domains\Suppliers\DTOs\BookingHoldResult;
use App\Domains\Suppliers\DTOs\FareRuleResult;
use App\Domains\Suppliers\DTOs\SearchRequest;
use App\Domains\Suppliers\DTOs\SupplierBalance;
use App\Domains\Suppliers\Exceptions\SupplierException;

/**
 * Placeholder adapter for Akbar Travels' Flight API.
 *
 * UNLIKE TBOFlightSupplierAdapter and TripJackFlightSupplierAdapter (each
 * built from a genuinely sourced request/response contract — TBO's public
 * API docs, TripJack's browsed Postman workspace), no equivalent source for
 * Akbar Travels' own B2B/API contract was available to build against in
 * this environment. Rather than invent endpoint paths, field names, or
 * response shapes that would only *look* like a real integration, every
 * method below throws until Akbar Travels' actual API documentation (or a
 * sandbox account) is obtained and this adapter is built against it for
 * real — same honesty bar as the other two adapters' docblocks.
 *
 * What IS real and functional here: this class registers as a first-class
 * supplier (see config/suppliers.php's 'adapters' map and the `suppliers`
 * table row seeded for code AKBAR), so a tenant can already connect it from
 * the CRM's Supplier Connections screen — entering an API key/secret via
 * SupplierConnectionController, stored encrypted per-tenant in
 * tenant_supplier_configs — and SupplierOrchestrator already resolves and
 * would pass those credentials into this adapter. That plumbing does not
 * need to change when the real integration is built: only the method
 * bodies below do.
 */
class AkbarTravelsFlightSupplierAdapter implements SupplierAdapterInterface
{
    public function code(): string
    {
        return 'AKBAR';
    }

    public function searchAvailability(SearchRequest $request, array $credentials): array
    {
        throw new SupplierException($this->code(), $this->notIntegratedMessage());
    }

    public function priceCheck(string $externalId, array $credentials): FareRuleResult
    {
        throw new SupplierException($this->code(), $this->notIntegratedMessage());
    }

    public function holdBooking(string $externalId, array $passengers, array $credentials): BookingHoldResult
    {
        throw new SupplierException($this->code(), $this->notIntegratedMessage());
    }

    public function confirmBooking(string $supplierBookingRef, array $credentials): BookingConfirmResult
    {
        throw new SupplierException($this->code(), $this->notIntegratedMessage());
    }

    public function retrieveBooking(string $supplierBookingRef, array $credentials): array
    {
        throw new SupplierException($this->code(), $this->notIntegratedMessage());
    }

    public function cancelBooking(string $supplierBookingRef, array $credentials): bool
    {
        throw new SupplierException($this->code(), $this->notIntegratedMessage());
    }

    public function getRefundStatus(string $supplierBookingRef, array $credentials): string
    {
        throw new SupplierException($this->code(), $this->notIntegratedMessage());
    }

    public function getSupplierAccountBalance(array $credentials): SupplierBalance
    {
        throw new SupplierException($this->code(), $this->notIntegratedMessage());
    }

    protected function notIntegratedMessage(): string
    {
        return 'Akbar Travels API credentials are saved, but the real request/response contract has not been '
            .'confirmed against Akbar Travels\' own API documentation yet — this adapter intentionally throws '
            .'rather than guess at endpoint shapes. Contact Akbar Travels\' API/B2B integration team for '
            .'documentation or sandbox access, then implement this adapter\'s methods against the confirmed '
            .'contract (see TBOFlightSupplierAdapter/TripJackFlightSupplierAdapter for the pattern).';
    }
}
