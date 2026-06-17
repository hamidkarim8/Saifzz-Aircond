<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\Documents\DocumentService;
use App\Services\Notifications\WhatsApp;
use App\Services\Portal\PortalService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class PortalController extends Controller
{
    public function __construct(
        private readonly PortalService $portal,
        private readonly DocumentService $documents,
        private readonly WhatsApp $whatsapp,
    ) {}

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
        // Full teardown on sign-out — invalidate the session and rotate the CSRF token.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }

    public function receipt(Request $request, Transaction $transaction): Response
    {
        $this->authorizeReceipt($request, $transaction);

        return response(view('documents.receipt', $this->documents->receiptViewModel($transaction)
            + ['logo' => \App\Support\BrandAssets::logoDataUri()]));
    }

    public function receiptPdf(Request $request, Transaction $transaction): Response
    {
        $this->authorizeReceipt($request, $transaction);
        $data = $this->documents->receiptViewModel($transaction)
            + ['logo' => \App\Support\BrandAssets::logoDataUri()];

        return Pdf::loadView('documents.receipt', $data)->download($data['number'].'.pdf');
    }

    /**
     * The receipt must belong to the session client (cross-client isolation) and
     * be paid (receiptViewModel 404s when unpaid). 404 — not 403 — so the portal
     * never confirms that another client's transaction exists.
     */
    private function authorizeReceipt(Request $request, Transaction $transaction): void
    {
        $client = $request->attributes->get('portal_client');

        abort_unless($transaction->visit->client_id === $client->id, 404);
    }

    /** Business identity + WhatsApp number (normalized by the module-11 service). */
    protected function business(): array
    {
        return [
            'name' => config('business.name'),
            'wa' => $this->whatsapp->normalize(config('business.phone')),
        ];
    }
}
