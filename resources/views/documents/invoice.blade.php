@extends('documents.layout', ['accent' => '#6366F1'])

@section('kind', 'INVOICE')

@section('body')
    @php
        $s = $snapshot;
        $money = fn ($v) => 'RM ' . number_format((float) $v, 2);
    @endphp

    {{-- Invoice + date details --}}
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
            <td class="k">Due Date</td>
            <td class="v">{{ $dueDate->format('d M Y') }}</td>
        </tr>
        <tr>
            <td class="k">Status</td>
            <td class="v">
                <span class="pill">{{ ucfirst($status) }}</span>
            </td>
        </tr>
    </table>

    <hr>

    {{-- Client details --}}
    <table class="kv">
        <tr>
            <td class="k">Bill To</td>
            <td class="v">{{ $s['client']['name'] }}</td>
        </tr>
        <tr>
            <td class="k">Phone</td>
            <td class="v">{{ $s['client']['phone'] }}</td>
        </tr>
        <tr>
            <td class="k">Address</td>
            <td class="v">{{ $s['client']['address'] }}</td>
        </tr>
        <tr>
            <td class="k">Serial No.</td>
            <td class="v mono">{{ $s['client']['serial_no'] }}</td>
        </tr>
    </table>

    <hr>

    {{-- Services --}}
    <div class="sec-label">Services</div>
    @foreach ($s['lines'] as $i => $l)
        <div class="line">
            <div class="line-title">{{ $i + 1 }}. {{ $l['service_type'] }}@if ($l['unit_type'] || $l['gas_option']) &mdash; {{ $l['unit_type'] ?: $l['gas_option'] }}@endif</div>
            <table class="kv">
                <tr>
                    <td class="k">Units</td>
                    <td class="v">{{ $l['units'] }}</td>
                </tr>
                <tr>
                    <td class="k">Rate</td>
                    <td class="v mono">{{ $money($l['rate']) }} / unit</td>
                </tr>
                <tr>
                    <td class="k">Subtotal</td>
                    <td class="v mono">{{ $money((float) $l['rate'] * (int) $l['units']) }}</td>
                </tr>
                @if ((float) $l['discount'] > 0)
                    <tr>
                        <td class="k">Discount</td>
                        <td class="v discount mono">&minus; {{ $money($l['discount']) }}</td>
                    </tr>
                @endif
                <tr>
                    <td class="k"><strong>Service Total</strong></td>
                    <td class="v mono">{{ $money($l['subtotal']) }}</td>
                </tr>
            </table>
        </div>
    @endforeach

    {{-- Dashed rule before total --}}
    <hr style="border-top: 1px dashed #DDE6EE; margin: 14px 0 10px;">

    {{-- Amount due block (indigo) --}}
    <div class="total" style="background: #6366F1;">
        <table>
            <tr>
                <td class="t-label">AMOUNT DUE</td>
                <td class="t-amount">{{ $money($s['total_amount']) }}</td>
            </tr>
        </table>
    </div>

    <div class="foot">Payment via {{ $s['method'] }} &nbsp;&middot;&nbsp; {{ $s['business']['phone'] }}</div>
@endsection
