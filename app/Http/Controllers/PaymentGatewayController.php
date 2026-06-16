<?php
namespace App\Http\Controllers;

use App\Http\Requests\UpdatePaymentGatewayRequest;
use App\Models\TenantGateway;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PaymentGatewayController extends Controller
{
    public function index(): Response
    {
        $row = TenantGateway::where('tenant_id', request()->user()->id)->first();

        return Inertia::render('PaymentSettings/Index', [
            'isConfigured'  => $row !== null,
            'portalKeyHint' => $row ? substr($row->portal_key, -4) : null,
        ]);
    }

    public function update(UpdatePaymentGatewayRequest $request): RedirectResponse
    {
        $tenantId = $request->user()->id;
        $row      = TenantGateway::where('tenant_id', $tenantId)->first();

        $updates = [];
        if (filled($request->input('api_token')))  $updates['api_token']  = $request->input('api_token');
        if (filled($request->input('portal_key'))) $updates['portal_key'] = $request->input('portal_key');
        if (filled($request->input('api_secret'))) $updates['api_secret'] = $request->input('api_secret');

        if ($row) {
            if ($updates) $row->update($updates);
        } else {
            TenantGateway::create(['tenant_id' => $tenantId] + $updates);
        }

        return back()->with('success', 'Payment gateway settings saved.');
    }
}
