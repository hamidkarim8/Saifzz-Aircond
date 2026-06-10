# 05 — Design System & Responsive UX

The mockup (`../index.html`, "Service System v4") is the visual reference. Follow its look
and flows; improve where it clearly helps (accessibility, touch targets, responsiveness).
This document captures the design language and the responsive requirements.

## Design principles

- **Clean, professional, trustworthy** — it issues official receipts and takes payments.
- **Information-dense but scannable** — tables, KPI cards, status badges, colour-coded types.
- **Touch-first for field use** — technicians operate onsite on phones and iPads.
- **Consistent status colour language** across badges, warranty pills, and charts.

## Brand & colour tokens

Carried from the mockup CSS variables. A deep navy → blue scale on a light slate background.

| Token | Value | Use |
|-------|-------|-----|
| `--c1` | `#0A1628` | Darkest navy — sidebar, portal bg |
| `--c2` | `#0E2040` | Headings, total bars |
| `--c3` | `#1A3A5C` | Accents |
| `--c4` | `#1E6FAE` | **Primary** — buttons, active nav, links |
| `--c5` | `#2E8FD4` | Primary hover / focus ring |
| `--c6`–`--c9` | `#5AAFE8 … #EBF6FD` | Light blue tints, surfaces |
| `--bg` | `#F0F4F8` | App background |
| `--sf` / `--sf2` | `#FFFFFF` / `#F7FAFC` | Card surfaces |
| `--bd` / `--bd2` | `#DDE6EE` / `#C5D5E4` | Borders |
| `--tx` / `--tx2` / `--tx3` | `#0A1628` / `#4A6278` / `#8BAABB` | Text primary / secondary / muted |

**Status / semantic**

| Meaning | Colour | BG |
|---------|--------|-----|
| Success / Paid / Confirmed / active warranty | `--gr #16A34A` | `#DCFCE7` |
| Warning / Pending / Due / expiring | `--am #D97706` | `#FEF3C7` |
| Danger / Failed / Overdue / expired | `--rd #DC2626` | `#FEE2E2` |
| WhatsApp action | `#25D366` | — |
| Invoice accent | `#6366F1` | `#EDE9FE` |

**Service-type colours** (badges + chart): Cleaning `--c4` blue · Gas Top-Up `--am` amber ·
Repair `--rd` red · Installation `--gr` green · Troubleshoot `#6366F1` indigo.

## Typography

- **UI font:** Plus Jakarta Sans (300–700).
- **Monospace:** JetBrains Mono — serials, amounts, IDs, receipts.
- Base size 14px; scale up touch targets and tap-critical text on mobile.

## Shape & elevation

| Token | Value |
|-------|-------|
| Radius `--ra` / `--ral` / `--rax` | 10 / 14 / 18 px |
| Shadow `--sh` | `0 1px 8px rgba(10,22,40,.08)` |
| Shadow `--shl` | `0 8px 32px rgba(10,22,40,.14)` (modals) |

## Core components (from mockup)

Sidebar nav, top bar, KPI stat cards (coloured top accent), cards, data tables, status
**badges**, **buttons** (primary / ok / WhatsApp / cash / invoice / ghost / icon),
forms & inputs, search bar, **modals** (sm/md/default), service-block builder, total bar,
payment method selector + QR panel, **receipt/invoice** layout, reminder cards, calendar
grid + day panel, service-fee table, filter tabs, warranty countdown pills, toast.

## Responsive requirements — first-class mobile, iPad, desktop

The admin app is **not** desktop-only. Technicians work onsite. The current mockup is a
fixed 232px sidebar + wide tables; that must adapt. Targets:

### Breakpoints (guideline)
| Range | Device | Layout intent |
|-------|--------|---------------|
| ≥ 1024px | Desktop / large iPad landscape | Persistent sidebar, multi-column grids, full tables |
| 640–1023px | iPad / tablet | Collapsible sidebar (drawer), 1–2 column grids, tables condense |
| < 640px | Phone | Off-canvas nav (hamburger), single column, **tables → stacked cards** |

### Rules
- **Navigation:** sidebar collapses to a hamburger drawer below desktop; bottom-safe and
  thumb-reachable on phones.
- **Tables → cards:** dense tables (Clients, Transactions, Appointments) reflow into stacked
  cards on phones; never force horizontal scroll for primary data.
- **Touch targets:** interactive elements ≥ 44×44px on touch; increase button/icon padding.
- **Modals:** full-width / near-fullscreen sheets on phones; the multi-service builder and
  payment flow must be comfortable one-handed.
- **Forms:** single-column on mobile; numeric inputs use numeric keyboards; date/time use
  native pickers.
- **Calendar:** remains a 7-column grid but with larger touch cells; day panel stacks below.
- **Client portal:** already mobile-first — keep it that way; verify on small phones.
- **Receipts/invoices:** on-screen view scrolls cleanly on mobile; PDF is the print artifact.

### Verification
Test primary flows (record service → pay → receipt; reminders → WhatsApp; portal lookup)
on a phone viewport (~390px), an iPad viewport (~820px), and desktop before sign-off.

## Improvements allowed over the mockup

Permitted where they help, while keeping the visual identity:
- Better empty/loading/error states.
- Accessibility: focus states, contrast, labels, keyboard nav.
- Larger touch ergonomics and the table→card reflow above.
- Consistent toast/confirmation patterns for destructive actions.
