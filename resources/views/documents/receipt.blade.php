@extends('documents.layout', ['accent' => '#1E6FAE'])

@section('kind', 'RECEIPT')

@section('body')
    @php
        $s = $snapshot;
        $money = fn ($v) => 'RM ' . number_format((float) $v, 2);
        $date  = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y') : '—';
        // "Manual QR" is the internal label for a DuitNow QR taken in person — show
        // the customer-facing name on the document.
        $methodLabel = ($s['method'] ?? '') === 'Manual QR' ? 'Duitnow QR Code' : ($s['method'] ?? '');

        $lines = $s['lines'];
        $hasDiscount = collect($lines)->contains(fn ($l) => (float) ($l['discount'] ?? 0) > 0);
        $gross = collect($lines)->sum(fn ($l) => (float) $l['rate'] * (int) $l['units']);
        $totalDiscount = collect($lines)->sum(fn ($l) => (float) ($l['discount'] ?? 0));

        // One next-service row for the whole document. Lines normally share a
        // date; when they don't, show each distinct one rather than silently
        // dropping the later.
        $nextServices = collect($lines)
            ->pluck('next_service_date')
            ->filter()
            ->unique()
            ->sort()
            ->map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('d M Y'))
            ->implode(', ');
    @endphp

    {{-- Received-from block beside doc meta --}}
    <table class="split">
        <tr>
            <td class="bill">
                <div class="party-label">Received From</div>
                <div class="party-name">{{ $s['client']['name'] }}</div>
                <div class="party-line">{{ $s['client']['phone'] }}</div>
                <div class="party-line">{{ $s['client']['address'] }}</div>
            </td>
            <td class="meta">
                <table class="kv">
                    <tr>
                        <td class="k">Receipt No.</td>
                        <td class="v mono">{{ $number }}</td>
                    </tr>
                    <tr>
                        <td class="k">Date</td>
                        <td class="v">{{ $issuedAt->format('d M Y, h:i A') }}</td>
                    </tr>
                    <tr>
                        <td class="k">Payment</td>
                        <td class="v">{{ $methodLabel }}</td>
                    </tr>
                    <tr>
                        <td class="k">Transaction ID</td>
                        <td class="v mono">{{ $s['txn_id'] }}</td>
                    </tr>
                    <tr>
                        <td class="k">Serial No.</td>
                        <td class="v mono">{{ $s['client']['serial_no'] }}</td>
                    </tr>
                    @if (!empty($s['warranty_months']))
                        <tr>
                            <td class="k">Warranty</td>
                            <td class="v">{{ $s['warranty_months'] }} months &mdash; expires {{ $date($s['warranty_end']) }}</td>
                        </tr>
                    @endif
                    @if ($nextServices !== '')
                        <tr>
                            <td class="k">Next Service</td>
                            <td class="v">{{ $nextServices }}</td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <hr>

    {{-- Services performed --}}
    <div class="sec-label">Services Performed</div>
    <table class="items">
        <thead>
            <tr>
                <th class="idx">#</th>
                <th>Service</th>
                <th class="num">Qty</th>
                <th class="num">Rate</th>
                @if ($hasDiscount)
                    <th class="num">Disc</th>
                @endif
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $i => $l)
                <tr>
                    <td class="idx">{{ $i + 1 }}</td>
                    <td class="desc">
                        <div class="svc">{{ $l['service_type'] }}@if ($l['unit_type']) &middot; {{ $l['unit_type'] }}@endif</div>
                        @if (!empty($l['hp_value']))
                            <div class="svc-meta">{{ number_format((float) $l['hp_value'], 1) }} HP</div>
                        @endif
                        @if (!empty($l['repair_desc']))
                            <div class="svc-meta">{{ $l['repair_desc'] }}</div>
                        @endif
                    </td>
                    <td class="num">{{ $l['units'] }}</td>
                    <td class="num">{{ $money($l['rate']) }}</td>
                    @if ($hasDiscount)
                        <td class="num disc-amt">@if ((float) ($l['discount'] ?? 0) > 0)- {{ $money($l['discount']) }}@endif</td>
                    @endif
                    <td class="num">{{ $money($l['subtotal']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Subtotal / discount summary, only when something was discounted --}}
    @if ($hasDiscount)
        <table class="sum">
            <tr>
                <td class="s-label">Subtotal</td>
                <td class="s-value">{{ $money($gross) }}</td>
            </tr>
            <tr>
                <td class="s-label">Discount</td>
                <td class="s-value disc-amt">- {{ $money($totalDiscount) }}</td>
            </tr>
        </table>
    @endif

    {{-- Total paid block (navy) --}}
    <div class="total" style="background: #0E2040;">
        <table>
            <tr>
                <td class="t-label">TOTAL PAID</td>
                <td class="t-amount">{{ $money($s['total_amount']) }}</td>
            </tr>
        </table>
    </div>

    <div class="foot">Thank you for trusting {{ $s['business']['name'] }}.</div>
@endsection
