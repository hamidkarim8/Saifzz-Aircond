<?php

namespace App\Http\Controllers;

use App\Services\Portal\PortalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PortalController extends Controller
{
    public function __construct(private readonly PortalService $portal) {}

    /** Login form (or bounce to the account if already authed). */
    public function showLogin(Request $request): InertiaResponse|RedirectResponse
    {
        if ($request->session()->has('portal_client_id')) {
            return redirect()->route('portal.account');
        }

        return Inertia::render('Portal/Login', ['business' => $this->business()]);
    }

    /** Serial + phone-last-4 lookup. Generic failure — never reveals serial existence. */
    public function authenticate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'serial' => ['required', 'digits:6'],
            'phone4' => ['required', 'digits:4'],
        ]);

        $client = $this->portal->authenticate($data['serial'], $data['phone4']);

        if ($client === null) {
            throw ValidationException::withMessages([
                'serial' => 'No matching record. Check your serial and phone number.',
            ]);
        }

        // Elevating an anonymous session to an authenticated one — regenerate the
        // session id to defend against session fixation (mirrors Laravel Auth login).
        $request->session()->regenerate();
        $request->session()->put('portal_client_id', $client->id);

        return redirect()->route('portal.account');
    }

    /** Read-only account page for the session client. */
    public function account(Request $request): InertiaResponse
    {
        $client = $request->attributes->get('portal_client');

        return Inertia::render('Portal/Show', [
            ...$this->portal->accountFor($client),
            'business' => $this->business(),
        ]);
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('portal_client_id');

        return redirect()->route('portal.login');
    }

    /** Business identity + WhatsApp number (MY: drop leading 0, prefix 60). */
    protected function business(): array
    {
        $digits = preg_replace('/\D/', '', (string) config('business.phone'));

        return [
            'name' => config('business.name'),
            'wa' => '60'.ltrim($digits, '0'),
        ];
    }
}
