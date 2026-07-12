# Invoice / Receipt v2 — Design

**Date:** 2026-07-13
**Source:** Khalid feedback, 7 items on the invoice + receipt PDFs (`docs/FEEDBACK-13072026.md`)
**Status:** Approved

## Problem

The invoice and receipt render each service as a boxed card containing a five-row
key/value table (Units, Rate, Subtotal, Discount, Service Total). A four-service
record therefore spills onto a second page, and one card splits across the page
boundary mid-way. Khalid's summary: it is crowded, it breaks badly, and it is
missing the one field that distinguishes two otherwise-identical lines — the HP
rating. A stray `?` also appears in front of every discount amount.

The seven reported items are, verbatim:

1. Remove the word "Official" from the receipt label.
2. Show HP per service line — currently impossible to tell two HP-based services apart.
3. Remove Due Date and Status from the invoice.
4. Many services in one record overflow the PDF; page breaks must be clean.
5. A `?` appears in front of the discount price.
6. Next-service should appear once, under Warranty, not repeated per line.
7. Overall the document feels crowded — present the same information more neatly.

## Root causes

**The `?` (item 5).** Both blades write the discount as `&minus;` (U+2212 MINUS
SIGN) inside a cell carrying the `.mono` class, which resolves to DejaVu Sans
**Mono**. That font has no glyph for U+2212 in the dompdf bundle, so it renders as
`?`. The `&mdash;` used in service titles renders correctly because that cell is
*not* mono. Any non-ASCII character inside a `.mono` cell is suspect.

**Missing HP (item 2).** `service_lines.hp_value` has existed since the HP-pricing
feature, but `SnapshotBuilder` never copied it into the document snapshot, so the
blades have nothing to render. Documents render from a snapshot **frozen at issue
time** and stored on the `documents` row — so this is a data-layer gap, not a
template gap.

**Overflow and crowding (items 4 and 7).** One card per service, five rows each,
plus no `page-break-inside` rule anywhere in `layout.blade.php`.

## Decisions

| Decision | Choice | Why |
| --- | --- | --- |
| Services layout | Line-item table | Standard invoice convention. One row per service instead of a five-row card, so ~20 services fit a page and the overflow largely disappears on its own. |
| Next-service with differing dates | Distinct dates, comma-joined | Lines *can* carry different dates (the paid-record next-service-date feature sets them per line). Showing only the earliest would silently hide a later one. All-same — the normal case Khalid describes — collapses to a single date. |
| Already-issued documents | Leave them HP-less | The snapshot is a frozen record of what was issued; rewriting it on live production data to inject HP would be both a lie about history and a risk. New documents carry HP; the blade null-guards the field so old ones simply omit it. |
| Document header | Two columns (client block \| doc meta) | Dropping Due Date and Status frees vertical space; spending it on a side-by-side header is what actually resolves "crowded" at the top of the page. Applied to **both** documents so the two read as siblings. The client block is a left-aligned paragraph (name, phone, address) rather than a right-aligned key/value table, because a long address wraps gracefully that way and badly the other. |

## Changes

### Data layer

`SnapshotBuilder::lines` gains `'hp_value' => $l->hp_value`. Nothing else in the
snapshot shape changes. `BusinessSettingController::sampleSnapshot()` gains HP on
its sample lines so the Business Settings live preview matches a real document.

### Shared layout (`layout.blade.php`)

- New `table.items` styles for the line-item table: repeating `<thead>`, hairline
  row separators, right-aligned numeric columns.
- `page-break-inside: avoid` on item rows and on the totals block, so no service
  row splits and the total never orphans onto a page of its own.
- `.line` card styles are removed once both blades stop using them.

### Both documents — the services table

Replaces the per-service cards. Columns:

```
 #  SERVICE                     QTY    RATE    DISC   AMOUNT
 1  Installation · Wall Mounted
    1.0 HP                        1  370.00  -100.00  270.00
```

- **Service** cell: service type + unit type on line one; HP on a muted second
  line when `hp_value` is present. HP reads as an attribute of the service rather
  than a column that would sit empty for every non-HP job (cleaning, gas top-up).
- **Disc** column renders only when at least one line on the document carries a
  discount, so a no-discount job does not display a dead column.
- Discount amounts use an ASCII `-`, never `&minus;`.
- Below the table, **Subtotal** and **Discount** summary rows appear only when a
  discount exists, followed by the existing coloured total block unchanged
  (indigo AMOUNT DUE on the invoice, navy TOTAL PAID on the receipt).
- On the receipt, `repair_desc` stays as a muted note beneath its service name.

### Invoice only

- Due Date and Status rows removed. `dueDate` and `status` then have no reader, so
  they are dropped from `DocumentController::invoiceData()` and from the preview
  call in `BusinessSettingController`.
- Header columns: "Bill To" block (name, phone, address) beside Invoice No. /
  Invoice Date / Serial No.

### Receipt only

- `OFFICIAL RECEIPT` → `RECEIPT`.
- Header columns: "Received From" block beside Receipt No. / Date / Payment /
  Transaction ID / Serial No. / Warranty / Next Service.
- Next Service leaves the per-line table and becomes a single row directly under
  Warranty, listing the distinct `next_service_date` values across all lines,
  comma-joined, formatted `d M Y`. The row is omitted when no line has a date.

## Testing

Existing `DocumentControllerTest`, `InvoiceGenerationTest`, and
`BusinessSettingTest` must stay green — they assert on rendered document content.

New assertions:

- HP value appears on a document whose line has `hp_value`; a line without one
  renders no HP text and does not error.
- A document rendered from a legacy snapshot with no `hp_value` key renders
  without error (guards the frozen-snapshot decision).
- The discount amount renders with an ASCII `-` and the document body contains no
  U+2212.
- The invoice contains neither "Due Date" nor a status pill.
- The receipt heading is "RECEIPT" and not "OFFICIAL RECEIPT".
- Distinct next-service dates: two lines sharing a date render it once; two lines
  with different dates render both.

**Manual verification (required, not optional):** generate a real PDF from a
four-line HP-based record with discounts and read it back. The `?` bug is
invisible to a unit test that asserts on the HTML — it only appears once dompdf
resolves the font. Confirm: no `?`, HP visible per line, single page, clean break
when a record is long enough to need two.

## Out of scope

- No migration. `hp_value` already exists on `service_lines`.
- No change to how documents are minted, numbered, or frozen.
- No backfill of existing snapshots.
