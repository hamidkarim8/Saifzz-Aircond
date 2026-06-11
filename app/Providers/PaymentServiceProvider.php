<?php

namespace App\Providers;

use App\Services\Payments\BayarCashGateway;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\FakeBayarCashGateway;
use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, function () {
            $config = config('services.bayarcash');

            return ($config['driver'] ?? 'fake') === 'live'
                ? new BayarCashGateway($config)
                : new FakeBayarCashGateway();
        });
    }
}
