<?php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Gateway resolved per-tenant via TenantGateway::resolveGateway().
    }
}
