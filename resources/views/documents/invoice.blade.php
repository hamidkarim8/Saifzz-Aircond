@extends('documents.layout', ['accent' => '#4338ca'])

@section('kind', 'INVOICE')

@section('body')
    @php
        $s = $snapshot;
        $money = fn ($v) => 'RM ' . number_format((float) $v, 2);
    @endphp

    <table class="kv">
        <tr><td class="k">Invoice No.</td><td class="v mono">{{ $number }}</td></tr>
        <tr><td class="k">Invoice Date</td><td class="v">{{ $issuedAt->format('d M Y') }}</td></tr>
        <tr><td class="k">Due Date</td><td class="v">{{ $dueDate->format('d M Y') }}</td></tr>
        <tr><td class="k">Status</td><td class="v"><span class="pill">{{ ucfirst($status) }}</span></td></tr>
    </table>
    <hr>
    <table class="kv">
        <tr><td class="k">Bill To</td><td class="v">{{ $s['client']['name'] }}</td></tr>
        <tr><td class="k">Phone</td><td class="v">{{ $s['client']['phone'] }}</td></tr>
        <tr><td class="k">Address</td><td class="v">{{ $s['client']['address'] }}</td></tr>
        <tr><td class="k">Serial No.</td><td class="v mono">{{ $s['client']['serial_no'] }}</td></tr>
    </table>
    <hr>
    <div class="sec-label">Services</div>
    @foreach ($s['lines'] as $i => $l)
        <div class="line">
            <div class="line-title">{{ $i + 1 }}. {{ $l['service_type'] }}@if ($l['unit_type'] || $l['gas_option']) — {{ $l['unit_type'] ?: $l['gas_option'] }}@endif</div>
            <table class="kv">
                <tr><td class="k">Units</td><td class="v">{{ $l['units'] }}</td></tr>
                <tr><td class="k">Rate</td><td class="v">{{ $money($l['rate']) }} / unit</td></tr>
                <tr><td class="k">Subtotal</td><td class="v">{{ $money((float) $l['rate'] * (int) $l['units']) }}</td></tr>
                @if ((float) $l['discount'] > 0)
                    <tr><td class="k">Discount</td><td class="v discount">- {{ $money($l['discount']) }}</td></tr>
                @endif
                <tr><td class="k"><strong>Total</strong></td><td class="v">{{ $money($l['subtotal']) }}</td></tr>
            </table>
        </div>
    @endforeach
    <hr>
    <div class="total" style="background:#4338ca">
        <table style="width:100%">
            <tr>
                <td style="color:#fff;font-weight:700;font-size:11px">AMOUNT DUE</td>
                <td style="color:#fff;font-weight:800;font-size:20px;text-align:right">{{ $money($s['total_amount']) }}</td>
            </tr>
        </table>
    </div>
    <div class="foot">Payment via {{ $s['method'] }} · {{ $s['business']['phone'] }}</div>
@endsection
