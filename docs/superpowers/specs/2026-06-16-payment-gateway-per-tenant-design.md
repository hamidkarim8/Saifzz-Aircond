# FEAT-016: Payment Gateway Per Tenant

## Goal

Each boss (Khalid, Saifzz) has their own BayarCash portal credentials stored encrypted in the DB. Payments route through the correct boss's gateway based on the transaction's tenant. Bosses configure credentials via a new Payment Settings UI.

## Data Layer

### New table: `tenant_gateways`

| column | type | notes |
|--------|------|-------|
| id | bigint PK | |
| tenant_id | bigint FK→users | unique — one row per boss |
| api_token | text | Laravel `encrypted` cast |
| portal_key | text | Laravel `encrypted` cast |
| api_secret | text | Laravel `encrypted` cast |
| created_at | timestamp | |
| updated_at | timestamp | |

`TenantGateway` model: `$fillable = ['tenant_id','api_token','portal_key','api_secret']`, casts all three fields as `encrypted`.

## Payment Routing

`PaymentService` — remove DI-injected `PaymentGateway $gateway` constructor param. Add private `resolveGateway(?int $tenantId): PaymentGateway`:

1. Load `TenantGateway::where('tenant_id', $tenantId)->first()`
2. If row exists → construct `BayarCashGateway` with decrypted credentials + channel=5 + env base_url
3. If no row → fall back to env-configured driver (`fake` or `live` via `config('services.bayarcash')`)

`startGateway(Transaction $transaction)` — load visit → `tenant_id` → call `resolveGateway($tenantId)`.

`confirmCash()` — no gateway needed, no change.

## Webhook Fix

`PaymentWebhookController::handle()` — single POST endpoint for all tenants. Fix:

1. Extract `order_number` from raw request before any verification
2. Look up `Transaction::where('txn_id', $orderNumber)->with('visit')->first()`
3. Resolve gateway for `$transaction->visit->tenant_id`
4. Verify checksum with that tenant's `api_secret`
5. Proceed as before (reject if not verified, call `HandleGatewayCallback`)

This avoids cross-tenant secret mismatch on webhook verification.

## Controller

`PaymentGatewayController`:

- `index()` — `abort_unless($user->isAdmin(), 403)`. Loads own `TenantGateway` row. Passes `isConfigured` bool + `portalKeyHint` (last 4 chars of portal_key, or null). Never passes raw credentials.
- `update(UpdatePaymentGatewayRequest $request)` — validates `api_token`, `portal_key`, `api_secret` (all required on first save; nullable on update = keep existing). Upserts by `tenant_id = auth()->id()`.

`UpdatePaymentGatewayRequest` — if row exists and field is null/empty, skip field (keep existing encrypted value). If row doesn't exist, all three are required.

Routes (admin only, no extra permission gate — `isAdmin()` check in controller):
```
GET  /payment-settings → PaymentGatewayController@index   (name: payment-settings.index)
PUT  /payment-settings → PaymentGatewayController@update  (name: payment-settings.update)
```

## UI: `Pages/PaymentSettings/Index.vue`

- Status banner: green "Gateway configured ✓" or yellow "Not configured — payments will use test mode"
- Three `<input type="password">` fields: API Token, Portal Key, API Secret
- If already configured: placeholder = "••••••••  (ending …{hint})" for portal_key, "••••••••" for others
- Leave blank = keep existing value
- Save button → PUT `/payment-settings` → flash success toast
- `portalKeyHint` shown only for portal_key field (least sensitive of the three)

## Sidebar

`AdminLayout.vue` — add "Payment Settings" to Settings group:
- `adminOnly: true`
- `IconCreditCard` icon
- Route: `payment-settings.index`
- Position: after "Permission Levels" (Users), before "Clients"

## Migrations

1. `create_tenant_gateways_table` — new table as above

## Testing

- Boss A configures gateway → `tenant_gateways` row created with encrypted values
- Boss A pays → `resolveGateway` returns `BayarCashGateway` with Boss A's credentials
- Boss B (no gateway configured) → falls back to fake driver
- Webhook with Boss A's `order_number` → resolves Boss A's secret for verification
- Cross-tenant: Boss B cannot view/update Boss A's gateway (tenant_id = auth()->id(), no lookup by param)
- UI: blank fields on update → existing credentials unchanged

## Deployment

Run `php artisan migrate` on prod after deploy.
