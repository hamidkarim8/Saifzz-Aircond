<?php

namespace App\Services\Documents;

use App\Models\Transaction;

final class SnapshotBuilder
{
    /**
     * Freeze client + line + payment + issuer details so an issued document
     * (invoice / receipt) reprints identically regardless of later edits.
     */
    public function forTransaction(Transaction $transaction): array
    {
        $visit = $transaction->visit()->with(['client', 'lines'])->first();

        return [
            'business' => (function () use ($visit) {
                $b = \App\Models\BusinessSetting::forTenant($visit->tenant_id);
                return [
                    'name' => $b['name'],
                    'address' => $b['address'],
                    'phone' => $b['phone'],
                    'ssm_no' => $b['ssm_no'],
                ];
            })(),
            'txn_id' => $transaction->txn_id,
            'method' => $transaction->method,
            'paid_at' => optional($transaction->paid_at)->toIso8601String(),
            'client' => [
                'name' => $visit->client->name,
                'serial_no' => $visit->client->serial_no,
                'phone' => $visit->client->phone,
                'address' => $visit->client->address,
            ],
            'visit_date' => optional($visit->visit_date)->toDateString(),
            'warranty_months' => $visit->warranty_months,
            'warranty_end' => optional($visit->warranty_end)->toDateString(),
            'lines' => $visit->lines->map(fn ($l) => [
                'service_type' => $l->service_type,
                'unit_type' => $l->unit_type,
                'hp_value' => $l->hp_value,
                'units' => $l->units,
                'rate' => $l->rate,
                'discount' => $l->discount,
                'subtotal' => $l->subtotal,
                'repair_desc' => $l->repair_desc,
                'notes' => $l->notes,
                'next_service_date' => optional($l->next_service_date)->toDateString(),
            ])->all(),
            'total_amount' => $visit->total_amount,
        ];
    }
}
