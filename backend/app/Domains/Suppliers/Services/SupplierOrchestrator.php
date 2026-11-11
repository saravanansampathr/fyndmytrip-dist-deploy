<?php

namespace App\Domains\Suppliers\Services;

use App\Domains\Suppliers\Contracts\SupplierAdapterInterface;
use App\Domains\Suppliers\DTOs\BookingConfirmResult;
use App\Domains\Suppliers\DTOs\BookingHoldResult;
use App\Domains\Suppliers\DTOs\FareRuleResult;
use App\Domains\Suppliers\DTOs\SearchRequest;
use App\Domains\Suppliers\DTOs\SupplierBalance;
use App\Domains\Suppliers\Exceptions\SupplierException;
use App\Domains\Suppliers\Models\Supplier;
use App\Domains\Suppliers\Models\TenantSupplierConfig;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Fans a single customer search out to every supplier enabled for the
 * tenant (in parallel in a real deployment via queued jobs; sequentially
 * here since this is the launch-stage in-house router described as Stage 1
 * of the Payments module specification's Payment Orchestration Layer
 * section — the same "simple priority-list routing + circuit-breaker
 * health checks" pattern applied to suppliers rather than gateways),
 * merges and ranks results, and resolves which supplier configuration
 * (tenant-owned, or the Master platform default) services a given booking.
 *
 * See Master Brief §6 and System Architecture Document (Supplier
 * Orchestrator, circuit breaker, Redis-cached search results).
 */
class SupplierOrchestrator
{
    public function __construct(protected CircuitBreaker $circuitBreaker) {}

    /**
     * @return array<int, array<string, mixed>> search results as arrays,
     *         price-ascending, each tagged with the supplier_code that
     *         produced it so the caller can pass it straight back into
     *         hold()/confirm() once the customer picks one.
     */
    public function search(Tenant $tenant, SearchRequest $request): array
    {
        $cacheKey = $this->searchCacheKey($tenant, $request);

        return Cache::remember($cacheKey, now()->addSeconds((int) config('suppliers.search_cache_ttl_seconds')), function () use ($tenant, $request) {
            $configs = $this->resolveActiveConfigs($tenant, $request->module);
            $merged = [];

            foreach ($configs as $config) {
                $supplierCode = $config->supplier->code;

                if ($this->circuitBreaker->isOpen($supplierCode)) {
                    Log::info("Supplier orchestrator: skipping {$supplierCode}, circuit open.");

                    continue;
                }

                try {
                    $adapter = $this->resolveAdapter($supplierCode);
                    $items = $adapter->searchAvailability($request, $config->credentials_encrypted ?? []);
                    $this->circuitBreaker->recordSuccess($supplierCode);

                    foreach ($items as $item) {
                        $row = $item->toArray();
                        $this->rememberMockOffer($supplierCode, $row);
                        $merged[] = $row;
                    }
                } catch (SupplierException $e) {
                    $this->circuitBreaker->recordFailure($supplierCode);
                    Log::warning("Supplier orchestrator: {$supplierCode} search failed: {$e->getMessage()}");
                }
            }

            usort($merged, fn ($a, $b) => $a['net_amount'] <=> $b['net_amount']);

            return $merged;
        });
    }

    public function priceCheck(Tenant $tenant, string $supplierCode, string $externalId): FareRuleResult
    {
        [$adapter, $credentials] = $this->resolveAdapterAndCredentials($tenant, $supplierCode);

        $result = $adapter->priceCheck($externalId, $credentials);
        $offer = $this->rememberedMockOffer($supplierCode, $externalId);

        if ($offer === null) {
            return $result;
        }

        return new FareRuleResult(
            isStillAvailable: $result->isStillAvailable,
            confirmedNetAmount: $offer['net_amount'],
            isRefundable: $offer['is_refundable'] ?? $result->isRefundable,
            rules: $result->rules,
            taxBreakup: $adapter instanceof \App\Domains\Suppliers\Adapters\MockFlightSupplierAdapter
                ? \App\Domains\Suppliers\Adapters\MockFlightSupplierAdapter::mockTaxes((float) $offer['net_amount'])
                : $result->taxBreakup,
        );
    }

    public function hold(Tenant $tenant, string $supplierCode, string $externalId, array $passengers): BookingHoldResult
    {
        [$adapter, $credentials] = $this->resolveAdapterAndCredentials($tenant, $supplierCode);

        $result = $adapter->holdBooking($externalId, $passengers, $credentials);
        $offer = $this->rememberedMockOffer($supplierCode, $externalId);

        return $offer === null ? $result : new BookingHoldResult(
            supplierBookingRef: $result->supplierBookingRef,
            holdExpiresAt: $result->holdExpiresAt,
            netAmount: $offer['net_amount'],
        );
    }

    /**
     * The demo MOCK* adapters are stateless: their priceCheck()/holdBooking()
     * derive a price from a hash of the offer id that has nothing to do with
     * the price the same offer showed in search, so the amount charged at
     * checkout never matched the results page (e.g. ₹4,533 shown, ₹5,664
     * charged). A real supplier re-quotes the fare it actually returned, so
     * for the mock suppliers only, remember each offer's net price and
     * refundability at search time and re-confirm exactly that.
     */
    protected function rememberMockOffer(string $supplierCode, array $row): void
    {
        if (! str_starts_with(strtoupper($supplierCode), 'MOCK') || empty($row['external_id'])) {
            return;
        }

        Cache::put($this->mockOfferKey($supplierCode, $row['external_id']), [
            'net_amount' => (string) $row['net_amount'],
            'is_refundable' => $row['is_refundable'] ?? null,
        ], now()->addHours(6));
    }

    /** @return array{net_amount: string, is_refundable: ?bool}|null */
    protected function rememberedMockOffer(string $supplierCode, string $externalId): ?array
    {
        if (! str_starts_with(strtoupper($supplierCode), 'MOCK')) {
            return null;
        }

        return Cache::get($this->mockOfferKey($supplierCode, $externalId));
    }

    protected function mockOfferKey(string $supplierCode, string $externalId): string
    {
        return 'mock-offer:'.strtoupper($supplierCode).':'.$externalId;
    }

    public function confirm(Tenant $tenant, string $supplierCode, string $supplierBookingRef): BookingConfirmResult
    {
        [$adapter, $credentials] = $this->resolveAdapterAndCredentials($tenant, $supplierCode);

        return $adapter->confirmBooking($supplierBookingRef, $credentials);
    }

    public function cancel(Tenant $tenant, string $supplierCode, string $supplierBookingRef): bool
    {
        [$adapter, $credentials] = $this->resolveAdapterAndCredentials($tenant, $supplierCode);

        return $adapter->cancelBooking($supplierBookingRef, $credentials);
    }

    public function balance(Tenant $tenant, string $supplierCode): SupplierBalance
    {
        [$adapter, $credentials] = $this->resolveAdapterAndCredentials($tenant, $supplierCode);

        return $adapter->getSupplierAccountBalance($credentials);
    }

    /**
     * @return array{0: SupplierAdapterInterface, 1: array}
     */
    protected function resolveAdapterAndCredentials(Tenant $tenant, string $supplierCode): array
    {
        $config = $this->resolveConfigForSupplier($tenant, $supplierCode);

        return [$this->resolveAdapter($supplierCode), $config?->credentials_encrypted ?? []];
    }

    /**
     * @return \Illuminate\Support\Collection<int, TenantSupplierConfig>
     */
    protected function resolveActiveConfigs(Tenant $tenant, string $module): \Illuminate\Support\Collection
    {
        $configs = TenantSupplierConfig::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->whereHas('supplier', fn ($q) => $q->whereJsonContains('supported_modules', $module))
            ->with('supplier')
            ->orderBy('priority_order')
            ->get();

        if ($configs->isNotEmpty()) {
            return $configs;
        }

        // No tenant-owned supplier contracts yet — fall back to the Master
        // platform-default configuration, per the "Add Supplier" wizard
        // being opt-in rather than mandatory (Master Brief §6).
        $master = Tenant::query()->where('type', Tenant::TYPE_MASTER)->first();

        if (! $master || $master->id === $tenant->id) {
            return $configs;
        }

        return TenantSupplierConfig::withoutTenantScope()
            ->where('tenant_id', $master->id)
            ->where('is_active', true)
            ->whereHas('supplier', fn ($q) => $q->whereJsonContains('supported_modules', $module))
            ->with('supplier')
            ->orderBy('priority_order')
            ->get();
    }

    protected function resolveConfigForSupplier(Tenant $tenant, string $supplierCode): ?TenantSupplierConfig
    {
        $supplier = Supplier::query()->where('code', $supplierCode)->first();

        if (! $supplier) {
            return null;
        }

        $config = TenantSupplierConfig::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('supplier_id', $supplier->id)
            ->where('is_active', true)
            ->first();

        if ($config) {
            return $config;
        }

        $master = Tenant::query()->where('type', Tenant::TYPE_MASTER)->first();

        if (! $master) {
            return null;
        }

        return TenantSupplierConfig::withoutTenantScope()
            ->where('tenant_id', $master->id)
            ->where('supplier_id', $supplier->id)
            ->where('is_active', true)
            ->first();
    }

    protected function resolveAdapter(string $supplierCode): SupplierAdapterInterface
    {
        $adapterClass = config("suppliers.adapters.{$supplierCode}");

        if (! $adapterClass) {
            throw new SupplierException($supplierCode, "No adapter registered for supplier code '{$supplierCode}'.");
        }

        return app($adapterClass);
    }

    protected function searchCacheKey(Tenant $tenant, SearchRequest $request): string
    {
        return implode(':', [
            'supplier_search',
            $tenant->id,
            $request->module,
            $request->origin,
            $request->destination,
            $request->departureDate,
            $request->returnDate ?? '-',
            $request->passengerCount,
        ]);
    }
}
