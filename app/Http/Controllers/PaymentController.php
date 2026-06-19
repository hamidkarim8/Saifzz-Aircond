<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\Payments\PaymentService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PaymentController extends Controller
{
    private function authorizeVisitScope(\App\Models\Transaction $transaction): void
    {
        abort_unless(
            \App\Models\ServiceVisit::whereKey($transaction->visit_id)
                ->visibleTo(request()->user())->exists(),
            403,
        );
    }

    public function show(Transaction $transaction): Response|RedirectResponse
    {
        $this->authorizeVisitScope($transaction);

        if ($transaction->status === 'paid') {
            return redirect()->route('payments.return', $transaction);
        }

        $transaction->load('visit.client');

        $biz = \App\Models\BusinessSetting::forTenant($transaction->visit->tenant_id);
        $manualQrUrl = $biz['payment_qr_path']
            ? \Illuminate\Support\Facades\Storage::disk('public')->url($biz['payment_qr_path'])
            : null;

        return Inertia::render('Payments/Show', [
            'transaction' => [
                'id' => $transaction->id,
                'txn_id' => $transaction->txn_id,
                'amount' => $transaction->amount,
                'status' => $transaction->status,
                'method' => $transaction->method,
                'visit_id' => $transaction->visit_id,
                'client' => [
                    'name' => $transaction->visit->client->name,
                    'serial_no' => $transaction->visit->client->serial_no,
                ],
            ],
            'manualQrUrl' => $manualQrUrl,
            'isAdmin' => request()->user()->isAdmin(),
        ]);
    }

    public function cash(Transaction $transaction, PaymentService $payments): RedirectResponse
    {
        $this->authorizeVisitScope($transaction);

        $payments->confirmCash($transaction);

        return redirect()->route('payments.return', $transaction)
            ->with('success', 'Cash payment recorded.');
    }

    public function manualQr(Transaction $transaction, PaymentService $payments): RedirectResponse
    {
        $this->authorizeVisitScope($transaction);
        abort_unless(request()->user()->isAdmin(), 403);

        $payments->confirmManualQr($transaction);

        return redirect()->route('payments.return', $transaction)
            ->with('success', 'Manual QR payment recorded.');
    }

    public function pay(Transaction $transaction, PaymentService $payments): HttpResponse
    {
        $this->authorizeVisitScope($transaction);

        if ($transaction->status === 'paid') {
            return redirect()->route('payments.return', $transaction);
        }

        return Inertia::location($payments->startGateway($transaction));
    }

    public function return(Transaction $transaction): Response
    {
        $this->authorizeVisitScope($transaction);

        $transaction->load('visit.client', 'receipt');

        return Inertia::render('Payments/Return', [
            'transaction' => [
                'id' => $transaction->id,
                'txn_id' => $transaction->txn_id,
                'amount' => $transaction->amount,
                'status' => $transaction->status,
                'method' => $transaction->method,
                'visit_id' => $transaction->visit_id,
                'client' => [
                    'name' => $transaction->visit->client->name,
                    'serial_no' => $transaction->visit->client->serial_no,
                ],
                'receipt' => $transaction->receipt
                    ? ['number' => $transaction->receipt->number]
                    : null,
            ],
        ]);
    }
}
