<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Client;
use App\Models\ServiceVisit;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Documents\SnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BusinessSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_for_tenant_returns_row_when_present(): void
    {
        $boss = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $boss->update(['tenant_id' => $boss->id]);
        BusinessSetting::create([
            'tenant_id' => $boss->id,
            'business_name' => 'Acme Cooling',
            'ssm_no' => '202603093151 (003839732-K)',
        ]);

        $resolved = BusinessSetting::forTenant($boss->id);

        $this->assertSame('Acme Cooling', $resolved['name']);
        $this->assertSame('202603093151 (003839732-K)', $resolved['ssm_no']);
    }

    public function test_for_tenant_falls_back_to_config_when_absent(): void
    {
        $resolved = BusinessSetting::forTenant(null);

        $this->assertSame(config('business.name'), $resolved['name']);
        $this->assertNull($resolved['ssm_no']);
    }

    public function test_snapshot_freezes_per_tenant_business_identity(): void
    {
        $boss = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $boss->update(['tenant_id' => $boss->id]);
        BusinessSetting::create([
            'tenant_id' => $boss->id,
            'business_name' => 'Tenant Cooling Co',
            'ssm_no' => 'SSM-123',
        ]);

        $client = Client::create([
            'tenant_id' => $boss->id,
            'name' => 'Test Client',
            'phone' => '0123456789',
            'address' => '123 Test Street',
        ]);
        $visit = ServiceVisit::create([
            'tenant_id' => $boss->id,
            'client_id' => $client->id,
            'visit_date' => now()->toDateString(),
            'created_by' => $boss->id,
            'technician_id' => $boss->id,
        ]);
        $txn = Transaction::create([
            'visit_id' => $visit->id,
            'txn_id' => 'TXN-'.now()->format('Ymd').'-001',
            'amount' => '0.00',
            'method' => 'Cash',
            'status' => 'pending',
        ]);

        $snap = app(SnapshotBuilder::class)->forTransaction($txn);

        $this->assertSame('Tenant Cooling Co', $snap['business']['name']);
        $this->assertSame('SSM-123', $snap['business']['ssm_no']);
    }

    private function bossAdmin(): User
    {
        $boss = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $boss->update(['tenant_id' => $boss->id]);

        return $boss;
    }

    public function test_admin_can_view_business_settings(): void
    {
        $this->actingAs($this->bossAdmin())
            ->get(route('business-settings.show'))
            ->assertOk();
    }

    public function test_admin_can_save_identity(): void
    {
        $boss = $this->bossAdmin();
        $this->actingAs($boss)->put(route('business-settings.update'), [
            'business_name' => 'New Name Sdn Bhd',
            'ssm_no' => '202603093151 (003839732-K)',
        ])->assertRedirect();

        $this->assertDatabaseHas('business_settings', [
            'tenant_id' => $boss->id,
            'business_name' => 'New Name Sdn Bhd',
            'ssm_no' => '202603093151 (003839732-K)',
        ]);
    }

    public function test_qr_upload_stores_file_and_path(): void
    {
        Storage::fake('public');
        $boss = $this->bossAdmin();

        $this->actingAs($boss)->put(route('business-settings.update'), [
            'google_review_qr' => UploadedFile::fake()->image('qr.png', 200, 200),
        ])->assertRedirect();

        $this->assertDatabaseHas('business_settings', [
            'tenant_id' => $boss->id,
            'google_review_qr_path' => "qr/tenant-{$boss->id}.png",
        ]);
        Storage::disk('public')->assertExists("qr/tenant-{$boss->id}.png");
    }

    public function test_tenant_id_not_honored_from_input(): void
    {
        $boss = $this->bossAdmin();
        $this->actingAs($boss)->put(route('business-settings.update'), [
            'tenant_id' => 99999,
            'business_name' => 'X',
        ])->assertRedirect();

        $this->assertDatabaseHas('business_settings', ['tenant_id' => $boss->id]);
        $this->assertDatabaseMissing('business_settings', ['tenant_id' => 99999]);
    }

    public function test_preview_renders_real_invoice_template_with_live_identity(): void
    {
        $boss = $this->bossAdmin();

        $res = $this->actingAs($boss)
            ->get(route('business-settings.preview', ['type' => 'invoice']).'?'.http_build_query([
                'name' => 'Live Typed Co',
                'phone' => '011-2223334',
            ]));

        $res->assertOk();
        $res->assertSee('INVOICE');                 // real template, kind label
        $res->assertSee('Live Typed Co');           // live identity from query
        $res->assertSee('011-2223334');
        $res->assertSee('Ahmad bin Ismail');        // sample snapshot client
    }

    public function test_preview_renders_receipt_template(): void
    {
        $boss = $this->bossAdmin();

        $this->actingAs($boss)
            ->get(route('business-settings.preview', ['type' => 'receipt']))
            ->assertOk()
            ->assertSee('RECEIPT')
            ->assertDontSee('OFFICIAL RECEIPT')
            ->assertSee('TOTAL PAID');
    }

    public function test_preview_rejects_unknown_type(): void
    {
        $this->actingAs($this->bossAdmin())
            ->get(route('business-settings.preview', ['type' => 'quote']))
            ->assertNotFound();
    }

    public function test_preview_falls_back_to_saved_identity_when_no_query(): void
    {
        $boss = $this->bossAdmin();
        BusinessSetting::create([
            'tenant_id' => $boss->id,
            'business_name' => 'Saved Identity Sdn Bhd',
        ]);

        $this->actingAs($boss)
            ->get(route('business-settings.preview', ['type' => 'invoice']))
            ->assertOk()
            ->assertSee('Saved Identity Sdn Bhd');
    }

    public function test_non_admin_cannot_view_preview(): void
    {
        $tech = User::factory()->create(['role' => User::ROLE_TECHNICIAN]);
        $this->actingAs($tech)
            ->get(route('business-settings.preview', ['type' => 'invoice']))
            ->assertForbidden();
    }

    public function test_non_admin_cannot_update(): void
    {
        $tech = User::factory()->create(['role' => User::ROLE_TECHNICIAN]);
        $this->actingAs($tech)->put(route('business-settings.update'), [
            'business_name' => 'Hax',
        ])->assertForbidden();
    }

    public function test_show_passes_google_review_qr_url(): void
    {
        Storage::fake('public');
        $boss = $this->bossAdmin();
        BusinessSetting::create([
            'tenant_id' => $boss->id,
            'google_review_qr_path' => 'qr/sample.png',
            'google_review_url' => 'https://g.page/r/test',
        ]);
        Storage::disk('public')->put('qr/sample.png', 'x');

        $client = Client::create([
            'name' => 'QR Client', 'phone' => '0123', 'address' => 'Addr',
            'tenant_id' => $boss->id,
        ]);
        $visit = ServiceVisit::create([
            'client_id' => $client->id, 'visit_date' => now()->toDateString(),
            'tenant_id' => $boss->id, 'technician_id' => $boss->id, 'created_by' => $boss->id,
        ]);

        $this->actingAs($boss)
            ->get(route('service-records.show', $visit->id))
            ->assertInertia(fn ($page) => $page
                ->where('googleReview.url', 'https://g.page/r/test')
                ->whereNot('googleReview.qrUrl', null));
    }

    public function test_admin_uploads_payment_qr_and_show_exposes_url(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->put(route('business-settings.update'), [
                'payment_qr' => UploadedFile::fake()->image('myqr.png', 300, 300),
            ])
            ->assertRedirect();

        $row = BusinessSetting::where('tenant_id', $admin->id)->first();
        $this->assertSame("payment-qr/tenant-{$admin->id}.png", $row->payment_qr_path);
        Storage::disk('public')->assertExists($row->payment_qr_path);

        $this->actingAs($admin)
            ->get(route('business-settings.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('paymentQrUrl', fn ($url) => $url !== null));
    }

    /**
     * The browser CANNOT send a QR as a real PUT — PHP does not parse a
     * multipart body on PUT, so $_FILES arrives empty and the upload silently
     * no-ops behind a success flash. Both upload forms therefore POST with
     * `_method: 'put'`; this pins the route down to what they actually send.
     */
    public function test_qr_uploads_arrive_via_method_spoofed_post(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->post(route('business-settings.update'), [
                '_method' => 'put',
                'payment_qr' => UploadedFile::fake()->image('manual.png', 300, 300),
                'google_review_qr' => UploadedFile::fake()->image('review.png', 300, 300),
            ])
            ->assertRedirect();

        $row = BusinessSetting::where('tenant_id', $admin->id)->first();
        $this->assertSame("payment-qr/tenant-{$admin->id}.png", $row->payment_qr_path);
        $this->assertSame("qr/tenant-{$admin->id}.png", $row->google_review_qr_path);
        Storage::disk('public')->assertExists($row->payment_qr_path);
        Storage::disk('public')->assertExists($row->google_review_qr_path);
    }

    /**
     * Re-uploading overwrites a fixed filename, so the DB path never changes.
     * The exposed URL must still change, or the browser keeps the old image —
     * which is exactly how a working upload looks broken to the user.
     */
    public function test_replacing_a_qr_changes_the_exposed_url(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $upload = fn (int $size) => $this->actingAs($admin)
            ->post(route('business-settings.update'), [
                '_method' => 'put',
                'payment_qr' => UploadedFile::fake()->image('qr.png', $size, $size),
            ])
            ->assertRedirect();

        $urlAfterUpload = fn () => $this->actingAs($admin)
            ->get(route('business-settings.show'))
            ->viewData('page')['props']['paymentQrUrl'];

        $path = "payment-qr/tenant-{$admin->id}.png";

        $upload(300);
        $first = $urlAfterUpload();
        $firstBytes = Storage::disk('public')->get($path);

        $upload(400);
        // Two uploads a fraction of a second apart share an mtime, so nudge it
        // forward to stand in for the real-world gap between replacements.
        touch(Storage::disk('public')->path($path), time() + 10);
        $second = $urlAfterUpload();

        // Same path, different bytes — the only thing that can move the URL is
        // the cache-bust version, which is keyed on the file's mtime.
        $this->assertNotSame($firstBytes, Storage::disk('public')->get($path));
        $this->assertStringContainsString('?v=', $first);
        $this->assertNotSame($first, $second);
    }

    public function test_payment_page_qr_url_is_cache_busted(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $admin->update(['tenant_id' => $admin->id]);

        $this->actingAs($admin)
            ->post(route('business-settings.update'), [
                '_method' => 'put',
                'payment_qr' => UploadedFile::fake()->image('qr.png', 300, 300),
            ])
            ->assertRedirect();

        $client = Client::create([
            'tenant_id' => $admin->id,
            'name' => 'QR Client',
            'phone' => '0123456789',
            'address' => '1 Jalan Test',
        ]);
        $visit = ServiceVisit::create([
            'tenant_id' => $admin->id,
            'client_id' => $client->id,
            'visit_date' => now()->toDateString(),
            'created_by' => $admin->id,
            'technician_id' => $admin->id,
        ]);
        $txn = Transaction::create([
            'visit_id' => $visit->id,
            'txn_id' => 'TXN-'.now()->format('Ymd').'-900',
            'amount' => '120.00',
            'method' => 'Cash',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->get(route('payments.show', $txn))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'manualQrUrl',
                fn ($url) => $url !== null && str_contains($url, '?v='),
            ));
    }
}
