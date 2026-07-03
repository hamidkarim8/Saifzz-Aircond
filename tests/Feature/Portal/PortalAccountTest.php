<?php

namespace Tests\Feature\Portal;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PortalAccountTest extends TestCase
{
    use RefreshDatabase;

    private function clientWithHistory(): Client
    {
        $client = Client::create(['name' => 'Zainab', 'phone' => '012-345 6789', 'address' => 'No. 5, KL']);
        $visit = $client->visits()->create(['visit_date' => '2026-02-01', 'warranty_months' => 3, 'total_amount' => 60]);
        $visit->lines()->create(['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1, 'rate' => 60, 'discount' => 0, 'next_service_date' => $this->nextServiceDate()]);

        return $client;
    }

    private function nextServiceDate(): string
    {
        return now()->addMonth()->toDateString();
    }

    public function test_guest_without_session_is_redirected_to_login(): void
    {
        $this->get(route('portal.account'))->assertRedirect(route('portal.login'));
    }

    public function test_authed_client_sees_account_page(): void
    {
        $client = $this->clientWithHistory();

        $res = $this->withSession(['portal_client_id' => $client->id])->get(route('portal.account'));

        $res->assertOk();
        $res->assertInertia(fn (Assert $page) => $page
            ->component('Portal/Show')
            ->where('client.serial_no', $client->serial_no)
            ->where('next_service_date', $this->nextServiceDate())
            ->has('visits', 1)
            ->has('business.wa')
        );
    }

    public function test_logout_clears_session(): void
    {
        $client = $this->clientWithHistory();

        $res = $this->withSession(['portal_client_id' => $client->id])->post(route('portal.logout'));

        $res->assertRedirect(route('portal.login'));
        $this->assertNull(session('portal_client_id'));
    }
}
