<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBusinessSettingRequest;
use App\Models\BusinessSetting;
use App\Models\TenantGateway;
use App\Support\BrandAssets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class BusinessSettingController extends Controller
{
    public function show(): Response
    {
        $tenantId = request()->user()->id;
        // Prefill from the SAME source the documents read (forTenant), so the
        // form + live preview reflect the real current identity — including the
        // config('business.*') fallback when no row has been saved yet.
        $identity = BusinessSetting::forTenant($tenantId);
        $gateway = TenantGateway::where('tenant_id', $tenantId)->first();

        return Inertia::render('BusinessSettings/Index', [
            'settings' => [
                'business_name' => $identity['name'],
                'phone' => $identity['phone'],
                'ssm_no' => $identity['ssm_no'],
                'google_review_url' => $identity['google_review_url'],
            ],
            // Cache-bust on the FILE's mtime: the QR filename is fixed
            // (tenant-{id}.png), so the DB path never changes on re-upload and
            // updated_at won't bump — but the file bytes do. Versioning by
            // lastModified forces the browser to refetch the new image.
            'qrUrl' => $this->qrUrl($identity['google_review_qr_path']),
            'paymentQrUrl' => $this->qrUrl($identity['payment_qr_path']),
            'payment' => [
                'isConfigured' => $gateway !== null,
                'portalKeyHint' => $gateway ? substr($gateway->portal_key, -4) : null,
            ],
        ]);
    }

    /**
     * Live document preview — renders the REAL invoice/receipt Blade with a
     * fixed sample snapshot, overriding business identity with the values the
     * admin is currently typing (query params). Loaded in an <iframe> so the
     * preview is the exact template the customer receives, never a mock.
     */
    public function preview(string $type): HttpResponse
    {
        abort_unless(in_array($type, ['invoice', 'receipt'], true), 404);

        $saved = BusinessSetting::forTenant(request()->user()->id);
        $business = [
            'name' => request()->query('name') ?: $saved['name'],
            'address' => $saved['address'],
            'phone' => request()->query('phone') ?: $saved['phone'],
            'ssm_no' => request()->query('ssm') ?: $saved['ssm_no'],
        ];

        $snapshot = $this->sampleSnapshot($business);
        $logo = BrandAssets::logoDataUri();

        if ($type === 'invoice') {
            return response(view('documents.invoice', [
                'snapshot' => $snapshot,
                'number' => 'INV-'.now()->format('Ymd').'-001',
                'issuedAt' => now(),
                'dueDate' => now()->addDays((int) config('business.invoice_due_days')),
                'status' => 'pending',
                'logo' => $logo,
            ]));
        }

        return response(view('documents.receipt', [
            'snapshot' => $snapshot,
            'number' => 'RCP-'.now()->format('Ymd').'-001',
            'issuedAt' => now(),
            'logo' => $logo,
        ]));
    }

    /** Fixed sample snapshot mirroring SnapshotBuilder's shape, for previews. */
    private function sampleSnapshot(array $business): array
    {
        return [
            'business' => $business,
            'txn_id' => 'TXN-SAMPLE-0001',
            'method' => 'DuitNow QR',
            'paid_at' => now()->toIso8601String(),
            'client' => [
                'name' => 'Ahmad bin Ismail',
                'serial_no' => 'SZ-0001',
                'phone' => '012-3456789',
                'address' => 'No. 12, Jalan Mawar, 50000 Kuala Lumpur',
            ],
            'visit_date' => now()->toDateString(),
            'warranty_months' => 6,
            'warranty_end' => now()->addMonths(6)->toDateString(),
            'lines' => [[
                'service_type' => 'Aircond Service',
                'unit_type' => '1.5 HP',
                'units' => 2,
                'rate' => 60,
                'discount' => 0,
                'subtotal' => 120,
                'repair_desc' => null,
                'next_service_date' => now()->addMonths(3)->toDateString(),
            ]],
            'total_amount' => 120,
        ];
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

        $data = $request->only(['business_name', 'phone', 'ssm_no', 'google_review_url']);

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
