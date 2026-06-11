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
    public function show(Transaction $transaction): Response|RedirectResponse
    {
        if ($transaction->status === 'paid') {
            return redirect()->route('payments.return', $transaction);
        }

        $transaction->load('visit.client');

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
        ]);
    }

    public function cash(Transaction $transaction, PaymentService $payments): RedirectResponse
    {
        $payments->confirmCash($transaction);

        return redirect()->route('payments.return', $transaction)
            ->with('success', 'Cash payment recorded.');
    }

    public function pay(Transaction $transaction, PaymentService $payments): HttpResponse
    {
        if ($transaction->status === 'paid') {
            return redirect()->route('payments.return', $transaction);
        }

        return Inertia::location($payments->startGateway($transaction));
    }

    public function return(Transaction $transaction): Response
    {
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
