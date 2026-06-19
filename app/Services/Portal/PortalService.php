<?php

namespace App\Services\Portal;

use App\Models\Client;
use Illuminate\Support\Carbon;

final class PortalService
{
    /**
     * Match a client by exact serial + the last 4 digits of the phone on file.
     * Two-factor gate (spec decision 1) — serials alone are enumerable. Phone is
     * compared digits-only so stored formatting (012-345 6789) is irrelevant.
     */
    public function authenticate(string $serial, string $phone4): ?Client
    {
        $client = Client::where('serial_no', $serial)->first();

        if ($client === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $client->phone);

        return str_ends_with($digits, $phone4) ? $client : null;
    }

    /**
     * Read-only portal view-model: client header, history (latest first), and the
     * next recommended service date = MAX(line.next_service_date) ignoring nulls
     * (Repair/Gas lines carry none), mirroring ReminderService aggregation.
     */
    public function accountFor(Client $client): array
    {
        $client->load([
            'visits' => fn ($q) => $q->latest('visit_date'),
            'visits.lines',
            'visits.transaction',
        ]);

        $next = $client->visits
            ->flatMap->lines
            ->pluck('next_service_date')
            ->filter()
            ->max();

        return [
            'client' => [
                'serial_no' => $client->serial_no,
                'name' => $client->name,
            ],
            'visits' => $client->visits->map(fn ($v) => [
                'id' => $v->id,
                'visit_date' => $v->visit_date?->toDateString(),
                'warranty_end' => $v->warranty_end?->toDateString(),
                'total_amount' => $v->total_amount,
                'lines' => $v->lines->map(fn ($l) => [
                    'service_type' => $l->service_type,
                    'unit_type' => $l->unit_type,
                    'units' => $l->units,
                    'subtotal' => $l->subtotal,
                ])->values(),
                'transaction' => $v->transaction ? [
                    'id' => $v->transaction->id,
                    'status' => $v->transaction->status,
                ] : null,
            ])->values(),
            'next_service_date' => $next ? Carbon::parse($next)->toDateString() : null,
        ];
    }
}
