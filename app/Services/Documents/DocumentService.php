<?php

namespace App\Services\Documents;

use App\Models\Invoice;
use App\Models\Transaction;

final class DocumentService
{
    public function __construct(private readonly SnapshotBuilder $snapshots) {}

    /**
     * Lazily mint one Invoice per transaction, freezing a snapshot at first
     * view/download. Idempotent — repeat calls return the same record.
     */
    public function invoiceFor(Transaction $transaction): Invoice
    {
        return Invoice::firstOrCreate(
            ['transaction_id' => $transaction->id],
            [
                'number' => $this->nextInvoiceNumber(),
                'amount' => $transaction->amount,
                'snapshot' => $this->snapshots->forTransaction($transaction),
            ],
        );
    }

    /**
     * Re-freeze an already-issued Invoice against the visit's current state.
     *
     * An invoice for an unpaid record is a bill still in flux, not a final
     * financial document — the meaningful freeze happens at payment, when the
     * Receipt is issued and the record is locked against editing. Without this,
     * editing a pending record whose invoice had already been viewed left that
     * invoice showing the old services and total forever, while the payment page
     * charged the new amount.
     *
     * No-op when no invoice has been minted yet: viewing stays the thing that
     * mints one. The number is deliberately preserved, so a customer already
     * holding INV-xxx sees the corrected figures under the same reference.
     */
    public function refreshInvoiceFor(Transaction $transaction): void
    {
        $invoice = $transaction->invoice;

        if ($invoice === null) {
            return;
        }

        $invoice->update([
            'amount' => $transaction->amount,
            'snapshot' => $this->snapshots->forTransaction($transaction),
        ]);
    }

    /**
     * Receipt view-model from the frozen Receipt snapshot. Shared by the staff
     * DocumentController and the client PortalController so the two can't drift.
     * 404 when the transaction has no Receipt (i.e. it is unpaid).
     */
    public function receiptViewModel(Transaction $transaction): array
    {
        $receipt = $transaction->receipt;

        abort_if($receipt === null, 404);

        return [
            'snapshot' => $receipt->snapshot,
            'number' => $receipt->number,
            'issuedAt' => $receipt->created_at,
        ];
    }

    /** INV-YYYYMMDD-NNN — daily sequence, mirrors RCP/TXN numbering. */
    private function nextInvoiceNumber(): string
    {
        $prefix = 'INV-'.now()->format('Ymd').'-';
        $last = Invoice::where('number', 'like', $prefix.'%')->orderByDesc('number')->value('number');
        $n = $last ? ((int) substr($last, -3)) + 1 : 1;

        return $prefix.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }
}
