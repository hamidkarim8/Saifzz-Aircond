<?php

namespace Tests\Feature\Portal;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalAuthTest extends TestCase
{
    use RefreshDatabase;

    private function client(): Client
    {
        return Client::create(['name' => 'Zainab', 'phone' => '012-345 6789', 'address' => 'No. 5, KL']);
    }

    public function test_correct_serial_and_phone4_authenticates(): void
    {
        $client = $this->client();

        $res = $this->post(route('portal.authenticate'), [
            'serial' => $client->serial_no,
            'phone4' => '6789',
        ]);

        $res->assertRedirect(route('portal.account'));
        $this->assertEquals($client->id, session('portal_client_id'));
    }

    public function test_wrong_phone4_is_rejected_without_session(): void
    {
        $client = $this->client();

        $res = $this->from(route('portal.login'))->post(route('portal.authenticate'), [
            'serial' => $client->serial_no,
            'phone4' => '0000',
        ]);

        $res->assertRedirect(route('portal.login'));
        $res->assertSessionHasErrors('serial');
        $this->assertNull(session('portal_client_id'));
    }

    public function test_unknown_serial_gives_same_generic_error(): void
    {
        $this->client();

        $res = $this->from(route('portal.login'))->post(route('portal.authenticate'), [
            'serial' => '999999',
            'phone4' => '6789',
        ]);

        $res->assertSessionHasErrors('serial');
        $this->assertNull(session('portal_client_id'));
    }

    public function test_validation_rejects_malformed_input(): void
    {
        $res = $this->from(route('portal.login'))->post(route('portal.authenticate'), [
            'serial' => '12',
            'phone4' => 'abcd',
        ]);

        $res->assertSessionHasErrors(['serial', 'phone4']);
    }

    public function test_authentication_is_rate_limited(): void
    {
        $client = $this->client();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('portal.authenticate'), ['serial' => $client->serial_no, 'phone4' => '0000']);
        }

        $res = $this->post(route('portal.authenticate'), ['serial' => $client->serial_no, 'phone4' => '0000']);

        $res->assertStatus(429);
    }

    public function test_already_authed_visiting_login_redirects_to_account(): void
    {
        $client = $this->client();

        $res = $this->withSession(['portal_client_id' => $client->id])->get(route('portal.login'));

        $res->assertRedirect(route('portal.account'));
    }
}
