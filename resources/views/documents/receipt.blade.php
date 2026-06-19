@extends('documents.layout', ['accent' => '#1E6FAE'])

@section('kind', 'OFFICIAL RECEIPT')

@section('body')
    @php
        $s = $snapshot;
        $money = fn ($v) => 'RM ' . number_format((float) $v, 2);
        $date  = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y') : '—';
        // "Manual QR" is the internal label for a DuitNow QR taken in person — show
        // the customer-facing name on the document.
        $methodLabel = ($s['method'] ?? '') === 'Manual QR' ? 'Duitnow QR Code' : ($s['method'] ?? '');
    @endphp

    {{-- Receipt + payment details --}}
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
            <td class="k">Txn ID</td>
            <td class="v mono">{{ $s['txn_id'] }}</td>
        </tr>
    </table>

    <hr>

    {{-- Client + unit details --}}
    <table class="kv">
        <tr>
            <td class="k">Client</td>
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
        @if (!empty($s['warranty_months']))
            <tr>
                <td class="k">Warranty</td>
                <td class="v">{{ $s['warranty_months'] }} months &mdash; expires {{ $date($s['warranty_end']) }}</td>
            </tr>
        @endif
    </table>

    <hr>

    {{-- Services performed --}}
    <div class="sec-label">Services Performed</div>
    @foreach ($s['lines'] as $i => $l)
        <div class="line">
            <div class="line-title">{{ $i + 1 }}. {{ $l['service_type'] }}@if ($l['unit_type']) &mdash; {{ $l['unit_type'] }}@endif</div>
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
                @if (!empty($l['next_service_date']))
                    <tr>
                        <td class="k">Next Service</td>
                        <td class="v">{{ $date($l['next_service_date']) }}</td>
                    </tr>
                @endif
                @if (!empty($l['repair_desc']))
                    <tr>
                        <td class="k">Details</td>
                        <td class="v">{{ $l['repair_desc'] }}</td>
                    </tr>
                @endif
            </table>
        </div>
    @endforeach

    {{-- Dashed rule before total --}}
    <hr style="border-top: 1px dashed #DDE6EE; margin: 14px 0 10px;">

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
