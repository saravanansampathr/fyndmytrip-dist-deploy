<?php

namespace App\Domains\Suppliers\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Per-supplier circuit breaker — Master Brief §6: "Circuit breaker + rate
 * limiting per supplier". A supplier that fails `failure_threshold` times
 * in a row is skipped by the orchestrator for `cooldown_seconds`, so a
 * timing-out supplier cannot slow down or fail every search/booking on the
 * platform.
 */
class CircuitBreaker
{
    protected function failureCountKey(string $supplierCode): string
    {
        return "supplier_cb:{$supplierCode}:failures";
    }

    protected function openKey(string $supplierCode): string
    {
        return "supplier_cb:{$supplierCode}:open";
    }

    public function isOpen(string $supplierCode): bool
    {
        return (bool) Cache::get($this->openKey($supplierCode), false);
    }

    public function recordFailure(string $supplierCode): void
    {
        $threshold = (int) config('suppliers.circuit_breaker.failure_threshold');
        $cooldown = (int) config('suppliers.circuit_breaker.cooldown_seconds');

        $failures = (int) Cache::get($this->failureCountKey($supplierCode), 0) + 1;
        Cache::put($this->failureCountKey($supplierCode), $failures, now()->addMinutes(10));

        if ($failures >= $threshold) {
            Cache::put($this->openKey($supplierCode), true, now()->addSeconds($cooldown));
        }
    }

    public function recordSuccess(string $supplierCode): void
    {
        Cache::forget($this->failureCountKey($supplierCode));
        Cache::forget($this->openKey($supplierCode));
    }
}
