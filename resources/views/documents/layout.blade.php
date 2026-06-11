<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #1f2937; font-size: 12px; background: #f3f4f6; }
        .doc { width: 480px; margin: 24px auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 28px; }
        .head { text-align: center; border-bottom: 2px solid #0f1f3d; padding-bottom: 14px; margin-bottom: 14px; }
        .co { font-size: 18px; font-weight: 700; color: #0f1f3d; }
        .co-sub { font-size: 11px; color: #6b7280; margin-top: 3px; }
        .kind { font-size: 15px; font-weight: 700; margin-top: 10px; letter-spacing: .5px; color: {{ $accent ?? '#1e3a8a' }}; }
        table.kv { width: 100%; border-collapse: collapse; }
        table.kv td { padding: 3px 0; vertical-align: top; }
        table.kv td.k { color: #6b7280; }
        table.kv td.v { text-align: right; font-weight: 600; }
        .mono { font-family: 'DejaVu Sans Mono', monospace; }
        hr { border: none; border-top: 1px dashed #d1d5db; margin: 12px 0; }
        .sec-label { font-size: 10px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 6px; }
        .line { background: #f9fafb; border-radius: 7px; padding: 10px 12px; margin-bottom: 8px; }
        .line-title { font-weight: 700; margin-bottom: 4px; }
        .total { border-radius: 8px; padding: 12px; margin-top: 4px; }
        .discount { color: #16a34a; }
        .pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; background: #fef3c7; color: #92400e; }
        .foot { text-align: center; margin-top: 12px; font-size: 11px; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="doc">
        <div class="head">
            <div class="co">{{ $snapshot['business']['name'] ?? config('business.name') }}</div>
            <div class="co-sub">{{ $snapshot['business']['address'] ?? '' }} · {{ $snapshot['business']['phone'] ?? '' }}</div>
            <div class="kind">@yield('kind')</div>
        </div>
        @yield('body')
    </div>
</body>
</html>
