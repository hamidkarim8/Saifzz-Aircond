@extends('documents.layout', ['accent' => '#6366F1'])

@section('kind', 'INVOICE')

@section('body')
    @php
        $s = $snapshot;
        $money = fn ($v) => 'RM ' . number_format((float) $v, 2);
        // "Manual QR" is the internal label for an in-person DuitNow QR payment.
        $methodLabel = ($s['method'] ?? '') === 'Manual QR' ? 'Duitnow QR Code' : ($s['method'] ?? '');

        $lines = $s['lines'];
        $hasDiscount = collect($lines)->contains(fn ($l) => (float) ($l['discount'] ?? 0) > 0);
        $gross = collect($lines)->sum(fn ($l) => (float) $l['rate'] * (int) $l['units']);
        $totalDiscount = collect($lines)->sum(fn ($l) => (float) ($l['discount'] ?? 0));
    @endphp

    {{-- Bill-to block beside doc meta --}}
    <table class="split">
        <tr>
            <td class="bill">
                <div class="party-label">Bill To</div>
                <div class="party-name">{{ $s['client']['name'] }}</div>
                <div class="party-line">{{ $s['client']['phone'] }}</div>
                <div class="party-line">{{ $s['client']['address'] }}</div>
            </td>
            <td class="meta">
                <table class="kv">
                    <tr>
                        <td class="k">Invoice No.</td>
                        <td class="v mono">{{ $number }}</td>
                    </tr>
                    <tr>
                        <td class="k">Invoice Date</td>
                        <td class="v">{{ $issuedAt->format('d M Y') }}</td>
                    </tr>
                    <tr>
                        <td class="k">Serial No.</td>
                        <td class="v mono">{{ $s['client']['serial_no'] }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <hr>

    {{-- Services --}}
    <div class="sec-label">Services</div>
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

    {{-- Amount due block (indigo) --}}
    <div class="total" style="background: #6366F1;">
        <table>
            <tr>
                <td class="t-label">AMOUNT DUE</td>
                <td class="t-amount">{{ $money($s['total_amount']) }}</td>
            </tr>
        </table>
    </div>

    <div class="foot">Payment via {{ $methodLabel }} &nbsp;&middot;&nbsp; {{ $s['business']['phone'] }}</div>
@endsection
