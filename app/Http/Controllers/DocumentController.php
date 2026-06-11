<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\Documents\DocumentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function invoice(Transaction $transaction): Response
    {
        return response(view('documents.invoice', $this->invoiceData($transaction)));
    }

    public function invoicePdf(Transaction $transaction): Response
    {
        $data = $this->invoiceData($transaction);

        return Pdf::loadView('documents.invoice', $data)->download($data['number'].'.pdf');
    }

    public function receipt(Transaction $transaction): Response
    {
        return response(view('documents.receipt', $this->receiptData($transaction)));
    }

    public function receiptPdf(Transaction $transaction): Response
    {
        $data = $this->receiptData($transaction);

        return Pdf::loadView('documents.receipt', $data)->download($data['number'].'.pdf');
    }

    /** Invoice view-model — mints the Invoice lazily; renders from its frozen snapshot. */
    private function invoiceData(Transaction $transaction): array
    {
        $invoice = $this->documents->invoiceFor($transaction);
        $snapshot = $invoice->snapshot;

        return [
            'snapshot' => $snapshot,
            'number' => $invoice->number,
            'issuedAt' => $invoice->created_at,
            'dueDate' => Carbon::parse($snapshot['visit_date'])->addDays((int) config('business.invoice_due_days')),
            'status' => $transaction->status,
        ];
    }

    /** Receipt view-model — reads the existing Receipt; 404 if the txn is unpaid. */
    private function receiptData(Transaction $transaction): array
    {
        $receipt = $transaction->receipt;
        abort_if($receipt === null, 404);

        return [
            'snapshot' => $receipt->snapshot,
            'number' => $receipt->number,
            'issuedAt' => $receipt->created_at,
        ];
    }
}
