<?php

namespace App\Providers;

use App\Domains\Suppliers\Services\CircuitBreaker;
use Illuminate\Support\ServiceProvider;

class SupplierServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CircuitBreaker::class);
    }

    public function boot(): void
    {
        //
    }
}
