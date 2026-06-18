<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBusinessSettingRequest;
use App\Models\BusinessSetting;
use App\Models\TenantGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class BusinessSettingController extends Controller
{
    public function show(): Response
    {
        $tenantId = request()->user()->id;
        $row = BusinessSetting::where('tenant_id', $tenantId)->first();
        $gateway = TenantGateway::where('tenant_id', $tenantId)->first();

        return Inertia::render('BusinessSettings/Index', [
            'settings' => [
                'business_name' => $row?->business_name,
                'address' => $row?->address,
                'phone' => $row?->phone,
                'ssm_no' => $row?->ssm_no,
                'google_review_url' => $row?->google_review_url,
            ],
            'qrUrl' => $row?->google_review_qr_path
                ? Storage::disk('public')->url($row->google_review_qr_path)
                : null,
            'paymentQrUrl' => $row?->payment_qr_path
                ? Storage::disk('public')->url($row->payment_qr_path)
                : null,
            'payment' => [
                'isConfigured' => $gateway !== null,
                'portalKeyHint' => $gateway ? substr($gateway->portal_key, -4) : null,
            ],
        ]);
    }

    public function update(UpdateBusinessSettingRequest $request): RedirectResponse
    {
        $tenantId = $request->user()->id;

        $data = $request->only(['business_name', 'address', 'phone', 'ssm_no', 'google_review_url']);

        if ($request->hasFile('google_review_qr')) {
            $path = "qr/tenant-{$tenantId}.png";
            Storage::disk('public')->put($path, file_get_contents($request->file('google_review_qr')->getRealPath()));
            $data['google_review_qr_path'] = $path;
        }

        if ($request->hasFile('payment_qr')) {
            $path = "payment-qr/tenant-{$tenantId}.png";
            Storage::disk('public')->put($path, file_get_contents($request->file('payment_qr')->getRealPath()));
            $data['payment_qr_path'] = $path;
        }

        BusinessSetting::updateOrCreate(['tenant_id' => $tenantId], $data);

        return back()->with('success', 'Business settings saved.');
    }
}
