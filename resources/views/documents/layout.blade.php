<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $number }}</title>
    <style>
        /* Do NOT reset the margin of `html` or `body` (and do not use a `*` reset).
           dompdf carries the @page margin on the root frame, so zeroing html/body's
           margin silently zeroes the page margin too — which left every continuation
           page flush against the paper edge. Reset only the elements we actually use. */
        * { box-sizing: border-box; }
        div, p, span, table, thead, tbody, tr, th, td, img, hr, h1, h2, h3 { margin: 0; padding: 0; }

        /* PDF only — browsers ignore @page. Vertical only: the sides stay at 0 so the
           card keeps its original width and side spacing (which comes from centering a
           500px card on the sheet). The top/bottom margin is what gives page 2 the same
           gap above the card that page 1 has — it is the only way to offset a
           continuation page, since a block's own margin is drawn at its start only. */
        @page { margin: 5mm 0; }

        /* The root background paints the whole sheet, including the @page margin — that
           is what puts the blue behind the card on every page. `body` alone would only
           paint its own content box, leaving the page margin white. */
        html { background: #f0f4f8; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #0A1628; font-size: 12px; background: #f0f4f8; }
        .doc { max-width: 500px; width: 100%; margin: 0 auto; background: #fff; border: 1px solid #DDE6EE; border-radius: 10px; padding: 24px; }
        @media (max-width: 540px) {
            body { background: #fff; }
            .doc { margin: 0; border: none; border-radius: 0; padding: 20px 16px; }
        }

        /* ── Header ── */
        .head { text-align: center; border-bottom: 2px solid #0E2040; padding-bottom: 16px; margin-bottom: 16px; }
        .co { font-size: 19px; font-weight: 700; color: #0E2040; letter-spacing: .3px; }
        .co-sub { font-size: 10.5px; color: #4A6278; margin-top: 4px; line-height: 1.5; }
        .kind { font-size: 14px; font-weight: 700; margin-top: 10px; letter-spacing: 1px; text-transform: uppercase; color: {{ $accent ?? '#1E6FAE' }}; }

        /* ── Key-value pairs ── */
        table.kv { width: 100%; border-collapse: collapse; }
        table.kv td { padding: 3.5px 0; vertical-align: top; line-height: 1.4; }
        table.kv td.k { color: #4A6278; width: 42%; }
        table.kv td.v { text-align: right; font-weight: 600; color: #0A1628; }

        /* ── Mono for IDs / amounts ── */
        .mono { font-family: 'DejaVu Sans Mono', monospace; font-size: 11.5px; }

        /* ── Section divider ── */
        hr { border: none; border-top: 1px dashed #DDE6EE; margin: 13px 0; }

        /* ── Section label ── */
        .sec-label { font-size: 9.5px; font-weight: 700; color: #4A6278; text-transform: uppercase; letter-spacing: .8px; margin-bottom: 0; }

        /* ── Two-column header: bill-to block | doc meta ── */
        table.split { width: 100%; border-collapse: collapse; }
        table.split td.bill { width: 56%; vertical-align: top; padding: 0 12px 0 0; }
        table.split td.meta { width: 44%; vertical-align: top; padding: 0; }
        .party-label { font-size: 9.5px; font-weight: 700; color: #4A6278; text-transform: uppercase; letter-spacing: .8px; margin-bottom: 5px; }
        .party-name { font-weight: 700; color: #0A1628; font-size: 12.5px; }
        .party-line { color: #4A6278; line-height: 1.5; margin-top: 2px; }
        table.split td.meta table.kv td.k { width: 48%; }

        /* ── Line-item table ── */
        table.items { width: 100%; border-collapse: collapse; }
        /* The <thead> re-renders on every page, so its top padding is what keeps the
           header row off the card's top edge after a break — the card's own padding is
           drawn only at its start and end. .sec-label's margin-bottom is zeroed so page
           1 does not gain double spacing above the table. */
        table.items thead th { font-size: 9px; font-weight: 700; color: #4A6278; text-transform: uppercase; letter-spacing: .6px; text-align: left; padding: 22px 0 6px; border-bottom: 1.5px solid #0E2040; }
        table.items thead th.num { text-align: right; padding-left: 6px; }
        table.items tbody tr { page-break-inside: avoid; }
        table.items td { padding: 7px 0; border-bottom: 1px solid #EDF2F7; vertical-align: top; line-height: 1.35; }
        table.items td.idx { width: 6%; color: #4A6278; }
        table.items td.desc { width: 42%; }
        table.items td.num { text-align: right; padding-left: 6px; font-family: 'DejaVu Sans Mono', monospace; font-size: 10.5px; white-space: nowrap; }
        /* Keeps a date whole — the meta column is narrow enough to split "12 Apr 2027" across lines. */
        .nb { white-space: nowrap; }

        .svc { font-weight: 700; color: #0E2040; }
        .svc-meta { color: #4A6278; font-size: 10px; margin-top: 2px; }
        .disc-amt { color: #16A34A; }

        /* ── Totals summary ── */
        table.sum { width: 100%; border-collapse: collapse; margin-top: 7px; }
        table.sum td { padding: 3px 0; }
        table.sum td.s-label { text-align: right; color: #4A6278; }
        table.sum td.s-value { text-align: right; width: 34%; padding-left: 6px; font-family: 'DejaVu Sans Mono', monospace; font-size: 11.5px; font-weight: 600; }

        /* ── Total block ── */
        .total { border-radius: 8px; padding: 13px 14px; margin-top: 6px; page-break-inside: avoid; }
        .total table { width: 100%; border-collapse: collapse; }
        .total td.t-label { color: #fff; font-weight: 700; font-size: 10.5px; letter-spacing: .5px; vertical-align: middle; }
        .total td.t-amount { color: #fff; font-weight: 800; font-size: 21px; text-align: right; vertical-align: middle; font-family: 'DejaVu Sans Mono', monospace; }

        /* ── Status pill ── */
        .pill { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 10px; font-weight: 700; background: #fef3c7; color: #92400e; }
        .pill-ok { background: #dcfce7; color: #14532d; }

        /* ── Footer ── */
        .foot { text-align: center; margin-top: 14px; font-size: 10.5px; color: #4A6278; border-top: 1px dashed #DDE6EE; padding-top: 10px; }
    </style>
</head>
<body>
    <div class="doc">
        <div class="head">
            @if(!empty($logo))
                <img src="{{ $logo }}" alt="" style="height:64px;width:64px;border-radius:50%;object-fit:cover;display:block;margin:0 auto 10px;">
            @endif
            <div class="co">{{ $snapshot['business']['name'] ?? config('business.name') }}</div>
            <div class="co-sub">
                @if(!empty($snapshot['business']['ssm_no']))
                    SSM: {{ $snapshot['business']['ssm_no'] }}<br>
                @endif
                Phone Number: {{ $snapshot['business']['phone'] ?? '' }}
            </div>
            <div class="kind">@yield('kind')</div>
        </div>
        @yield('body')
    </div>
</body>
</html>
