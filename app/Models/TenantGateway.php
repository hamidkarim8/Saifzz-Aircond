<?php
namespace App\Models;

use App\Services\Payments\BayarCashGateway;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\FakeBayarCashGateway;
use Illuminate\Database\Eloquent\Model;

class TenantGateway extends Model
{
    protected $fillable = ['tenant_id', 'api_token', 'portal_key', 'api_secret'];

    protected $casts = [
        'api_token' => 'encrypted',
        'portal_key' => 'encrypted',
        'api_secret' => 'encrypted',
    ];

    public static function resolveGateway(?int $tenantId): PaymentGateway
    {
        if ($tenantId !== null) {
            $row = static::where('tenant_id', $tenantId)->first();
            if ($row) {
                return new BayarCashGateway([
                    'api_token' => $row->api_token,
                    'portal_key' => $row->portal_key,
                    'api_secret' => $row->api_secret,
                    'channel' => 5,
                    'base_url' => config('services.bayarcash.base_url'),
                    'driver' => 'live',
                ]);
            }
        }
        $config = config('services.bayarcash');
        return ($config['driver'] ?? 'fake') === 'live'
            ? new BayarCashGateway($config)
            : new FakeBayarCashGateway();
    }
}
