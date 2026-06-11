<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BayarCash (Stub) — Payment</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; display: grid; place-items: center; min-height: 100vh; margin: 0; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 32px; width: min(420px, 92vw); }
        .badge { display: inline-block; font-size: 12px; letter-spacing: .1em; text-transform: uppercase; color: #fbbf24; border: 1px solid #fbbf2455; border-radius: 999px; padding: 2px 10px; }
        h1 { font-size: 20px; margin: 16px 0 4px; }
        .ref { font-family: ui-monospace, monospace; color: #93c5fd; font-size: 14px; }
        .amount { font-size: 34px; font-weight: 800; margin: 18px 0; }
        form { display: inline; }
        button { font-size: 15px; font-weight: 600; border: 0; border-radius: 10px; padding: 12px 18px; cursor: pointer; width: 100%; margin-top: 10px; }
        .paid { background: #22c55e; color: #06210f; }
        .failed { background: #ef4444; color: #2a0707; }
        .note { color: #94a3b8; font-size: 12px; margin-top: 18px; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="card">
        <span class="badge">BayarCash · Stub</span>
        <h1>Confirm payment</h1>
        <div class="ref">Order: {{ $order }} · Ref: {{ $ref }}</div>
        <div class="amount">RM {{ number_format((float) $amount, 2) }}</div>

        <form method="POST" action="{{ route('dev.bayarcash.simulate', ['ref' => $ref]) }}">
            <input type="hidden" name="order" value="{{ $order }}">
            <input type="hidden" name="outcome" value="paid">
            <button class="paid" type="submit">Simulate Paid</button>
        </form>

        <form method="POST" action="{{ route('dev.bayarcash.simulate', ['ref' => $ref]) }}">
            <input type="hidden" name="order" value="{{ $order }}">
            <input type="hidden" name="outcome" value="failed">
            <button class="failed" type="submit">Simulate Failed</button>
        </form>

        <p class="note">Local stand-in for the real BayarCash hosted page. Buttons fire a
        checksum-signed callback through the production webhook handler. Replace with the live
        gateway by setting <code>BAYARCASH_DRIVER=live</code>.</p>
    </div>
</body>
</html>
