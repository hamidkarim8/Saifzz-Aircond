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
            // Cache-bust on the FILE's mtime: the QR filename is fixed
            // (tenant-{id}.png), so the DB path never changes on re-upload and
            // updated_at won't bump — but the file bytes do. Versioning by
            // lastModified forces the browser to refetch the new image.
            'qrUrl' => $this->qrUrl($row?->google_review_qr_path),
            'paymentQrUrl' => $this->qrUrl($row?->payment_qr_path),
            'payment' => [
                'isConfigured' => $gateway !== null,
                'portalKeyHint' => $gateway ? substr($gateway->portal_key, -4) : null,
            ],
        ]);
    }

    /** Public-disk URL with an mtime cache-bust query, or null when no file. */
    private function qrUrl(?string $path): ?string
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path).'?v='.Storage::disk('public')->lastModified($path);
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
