# 06 — Integrations

External-facing requirements and contracts. Concrete library/vendor choices are finalised
with the stack decision; this document defines **what** each integration must do.

---

## 1. Payment gateway (DuitNow QR)

**Goal:** Real, auto-verified DuitNow QR payments — not a static image. Plus Cash as a
manual method.

**Requirements**
- Generate a payment for a Transaction with an exact amount and reference.
- Present a DuitNow QR to the client (dynamic, amount-encoded).
- Receive an asynchronous **webhook** on payment success/failure and update the
  Transaction (`paid` / `failed`, `paid_at`, `gateway_ref`).
- Be idempotent: the same webhook delivered twice must not double-apply.
- Verify webhook authenticity (signature / secret).

**Candidate Malaysian gateways** (decide with stack):
- **ToyyibPay** — low fees, simple, popular with small MY businesses, supports FPX + DuitNow.
- **Billplz** — well-documented API, FPX/DuitNow, widely used.
- **iPay88** — established, broader methods, heavier onboarding.
- **Curlec (Razorpay MY) / Stripe MY** — modern APIs; verify current DuitNow QR support.

Selection criteria: DuitNow QR support, transaction fee, settlement time, ease of merchant
onboarding for a small business, webhook quality, sandbox availability.

**Webhook contract (abstract)**
```
POST /webhooks/payment
  verify signature
  look up Transaction by gateway_ref / our reference
  if success and not already paid:
      mark paid, set paid_at, generate Receipt
  respond 200 quickly (process idempotently)
```

**Cash path:** user with `collect_payment` confirms manually → Transaction `paid` →
Receipt generated.

---

## 2. WhatsApp

**Important reality:** WhatsApp has **no free open bot/webhook** like Discord or Telegram.
There is no instant free token. Options ranked:

| Approach | Cost | Setup | Auto-send |
|----------|------|-------|:---:|
| **wa.me click-to-chat** (v1) | Free | None | No (manual send) |
| Meta WhatsApp Cloud API | Free tier + per-msg | Meta business verification + approved templates + dedicated number | Yes |
| Twilio WhatsApp | Paid/msg | Easier than raw Meta | Yes |
| Unofficial (Baileys / whatsapp-web.js) | Free | QR scan | Yes — but **against ToS, ban risk, fragile. Not for production.** |

**v1 decision: wa.me click-to-chat.**
- Buttons open `https://wa.me/<intl-number>?text=<url-encoded message>`.
- Messages pre-filled with client/reminder context; the user presses send.
- Zero setup, zero blocker on launch day.

**Future: Meta Cloud API** for automated reminders. Utility-template conversations are cheap
in Malaysia (≈ RM0.07–0.10/msg) with a monthly free service-conversation allowance, but
require Meta Business verification and pre-approved templates.

**Architecture rule:** all outbound messaging goes through the **Notifications** module's
interface (module 11). The wa.me implementation is swappable for the Cloud API
implementation without touching callers.

---

## 3. PDF documents (Invoice & Receipt)

**Goal:** Server-generated PDFs in addition to on-screen views.

**Requirements**
- Render Invoice (`INV-…`) and Receipt (`RCP-…`) matching the mockup layout.
- Available as on-demand download; reusable for future WhatsApp/file sharing.
- Snapshot data at generation time so reprints are stable.
- Include business header (name, address, phone), client details, serial, itemised
  services, discounts, totals, payment method, warranty, and next-service date.

**Approach:** server-side HTML-to-PDF (final library chosen with the stack). On-screen view
and PDF should share one template/source of truth to avoid drift.

---

## Integration summary

| Integration | v1 | Later |
|-------------|----|-------|
| Payment | Real gateway, DuitNow QR + webhook, + Cash | — |
| WhatsApp | wa.me click-to-chat | Meta Cloud API auto-reminders |
| Documents | On-screen + server PDF | — |
