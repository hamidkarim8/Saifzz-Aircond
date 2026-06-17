<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #0A1628; font-size: 12px; background: #f0f4f8; }
        .doc { max-width: 500px; width: 100%; margin: 20px auto; background: #fff; border: 1px solid #DDE6EE; border-radius: 10px; padding: 24px; }
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
        .sec-label { font-size: 9.5px; font-weight: 700; color: #4A6278; text-transform: uppercase; letter-spacing: .8px; margin-bottom: 8px; }

        /* ── Per-service box ── */
        .line { background: #f7f9fc; border: 1px solid #DDE6EE; border-radius: 7px; padding: 10px 13px; margin-bottom: 9px; }
        .line-title { font-weight: 700; color: #0E2040; margin-bottom: 5px; font-size: 12px; }
        .line table.kv td.k { font-size: 11px; }
        .line table.kv td.v { font-size: 11px; }

        /* ── Discount accent ── */
        .discount { color: #16A34A; }

        /* ── Total block ── */
        .total { border-radius: 8px; padding: 13px 14px; margin-top: 6px; }
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
                {{ $snapshot['business']['address'] ?? '' }}<br>
                {{ $snapshot['business']['phone'] ?? '' }}
                @if(!empty($snapshot['business']['ssm_no']))
                    <br>SSM: {{ $snapshot['business']['ssm_no'] }}
                @endif
            </div>
            <div class="kind">@yield('kind')</div>
        </div>
        @yield('body')
    </div>
</body>
</html>
