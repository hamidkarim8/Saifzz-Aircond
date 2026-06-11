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

    /** INV-YYYYMMDD-NNN — daily sequence, mirrors RCP/TXN numbering. */
    private function nextInvoiceNumber(): string
    {
        $prefix = 'INV-'.now()->format('Ymd').'-';
        $last = Invoice::where('number', 'like', $prefix.'%')->orderByDesc('number')->value('number');
        $n = $last ? ((int) substr($last, -3)) + 1 : 1;

        return $prefix.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }
}
