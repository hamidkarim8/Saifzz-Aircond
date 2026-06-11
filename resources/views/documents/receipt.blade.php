@extends('documents.layout', ['accent' => '#1e3a8a'])

@section('kind', 'OFFICIAL RECEIPT')

@section('body')
    @php
        $s = $snapshot;
        $money = fn ($v) => 'RM ' . number_format((float) $v, 2);
        $date = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y') : '—';
    @endphp

    <table class="kv">
        <tr><td class="k">Receipt No.</td><td class="v mono">{{ $number }}</td></tr>
        <tr><td class="k">Date</td><td class="v">{{ $issuedAt->format('d M Y, h:i A') }}</td></tr>
        <tr><td class="k">Payment</td><td class="v">{{ $s['method'] }}</td></tr>
        <tr><td class="k">Txn ID</td><td class="v mono">{{ $s['txn_id'] }}</td></tr>
    </table>
    <hr>
    <table class="kv">
        <tr><td class="k">Client</td><td class="v">{{ $s['client']['name'] }}</td></tr>
        <tr><td class="k">Phone</td><td class="v">{{ $s['client']['phone'] }}</td></tr>
        <tr><td class="k">Address</td><td class="v">{{ $s['client']['address'] }}</td></tr>
        <tr><td class="k">Serial No.</td><td class="v mono">{{ $s['client']['serial_no'] }}</td></tr>
        @if (!empty($s['warranty_months']))
            <tr><td class="k">Warranty</td><td class="v">{{ $s['warranty_months'] }} Months (expires {{ $date($s['warranty_end']) }})</td></tr>
        @endif
    </table>
    <hr>
    <div class="sec-label">Services Performed</div>
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
                <tr><td class="k"><strong>Service Total</strong></td><td class="v">{{ $money($l['subtotal']) }}</td></tr>
                @if (!empty($l['next_service_date']))
                    <tr><td class="k">Next Service</td><td class="v">{{ $date($l['next_service_date']) }}</td></tr>
                @endif
                @if (!empty($l['repair_desc']))
                    <tr><td class="k">Details</td><td class="v">{{ $l['repair_desc'] }}</td></tr>
                @endif
            </table>
        </div>
    @endforeach
    <hr>
    <div class="total" style="background:#0f1f3d">
        <table style="width:100%">
            <tr>
                <td style="color:#fff;font-weight:700;font-size:11px">TOTAL PAID</td>
                <td style="color:#fff;font-weight:800;font-size:20px;text-align:right">{{ $money($s['total_amount']) }}</td>
            </tr>
        </table>
    </div>
    <div class="foot">Thank you for trusting {{ $s['business']['name'] }}.</div>
@endsection
