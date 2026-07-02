# Task 6 Fix Report — PortalService `accountFor()` whereHas regression

## The bug

`PortalService::accountFor()` filtered a client's visits with:

```php
$client->load([
    'visits' => fn ($q) => $q->latest('visit_date')
        ->whereHas('transaction', fn ($t) => $t->whereNotIn('status', ['void', 'cancelled'])),
    'visits.lines',
    'visits.transaction',
]);
```

`whereHas()` requires a *matching related row to exist* (inner-join semantics). A visit with
**no transaction at all** has no row to match `whereNotIn('status', [...])` against, so it was
incorrectly excluded from the portal's visit list — even though "no void/cancelled transaction"
is trivially true when there's no transaction.

This broke the pre-existing test `tests/Feature/Portal/PortalAccountTest.php::test_authed_client_sees_account_page`,
whose fixture `clientWithHistory()` creates a visit via `$client->visits()->create([...])` without
attaching a transaction (deliberately, since that test targets `next_service_date` aggregation, not
billing status). Under the buggy filter the transaction-less visit vanished from `$client->visits`,
so `next_service_date` computed as `null` instead of the expected `'2026-08-01'`.

## The fix

Changed `whereHas` to `whereDoesntHave`, inverting the inner clause from `whereNotIn` to `whereIn`:

```php
$client->load([
    'visits' => fn ($q) => $q->latest('visit_date')
        ->whereDoesntHave('transaction', fn ($t) => $t->whereIn('status', ['void', 'cancelled'])),
    'visits.lines',
    'visits.transaction',
]);
```

`whereDoesntHave(..., whereIn(['void', 'cancelled']))` means: "exclude visits where a transaction
row exists AND its status is void or cancelled."
- Visit with no transaction → no matching row → `whereDoesntHave` is true → included (correct).
- Visit with `paid`/`pending`/`failed` transaction → no matching void/cancelled row → included (correct).
- Visit with `void`/`cancelled` transaction → matching row exists → excluded (correct, preserves
  original intent).

File changed: `app/Services/Portal/PortalService.php` (lines 36-37).

## Test evidence

### Before fix — `PortalAccountTest` (failing)

Command: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=PortalAccountTest`

```
 FAIL  Tests\Feature\Portal\PortalAccountTest
 ✓ guest without session is redirected to login                        0.67s
 ⨯ authed client sees account page                                     0.06s
 ✓ logout clears session                                               0.03s
 ────────────────────────────────────────────────────────────────────────────
 FAILED  Tests\Feature\Portal\PortalAccountTest > authed client sees accou…
  Property [next_service_date] does not match the expected value.
  Failed asserting that null is identical to '2026-08-01'.

  at tests/Feature/Portal/PortalAccountTest.php:38
     34▕         $res->assertOk();
     35▕         $res->assertInertia(fn (Assert $page) => $page
     36▕             ->component('Portal/Show')
     37▕             ->where('client.serial_no', $client->serial_no)
  ➜  38▕             ->where('next_service_date', '2026-08-01')
     39▕             ->has('visits', 1)
     40▕             ->has('business.wa')
     41▕         );
     42▕     }

Tests:    1 failed, 2 passed (19 assertions)
Duration: 0.86s
```

### After fix — `PortalServiceTest`

Command: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=PortalServiceTest`

```
 PASS  Tests\Feature\Portal\PortalServiceTest
 ✓ authenticate matches serial and phone last4                         0.56s
 ✓ authenticate rejects wrong phone4                                   0.02s
 ✓ authenticate rejects unknown serial                                 0.02s
 ✓ authenticate ignores phone formatting                                0.02s
 ✓ account next service is max ignoring nulls                          0.04s
 ✓ account excludes void and cancelled visits                          0.03s

Tests:    6 passed (10 assertions)
Duration: 0.82s
```

`account excludes void and cancelled visits` (Task 6's original test) still passes — the
void/cancelled exclusion behavior is preserved.

### After fix — `PortalAccountTest`

Command: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=PortalAccountTest`

```
 PASS  Tests\Feature\Portal\PortalAccountTest
 ✓ guest without session is redirected to login                        0.67s
 ✓ authed client sees account page                                     0.07s
 ✓ logout clears session                                               0.03s

Tests:    3 passed (22 assertions)
Duration: 0.88s
```

Both files pass together (9 tests total across the two runs, all green).

## Files changed

- `app/Services/Portal/PortalService.php` — `whereHas` → `whereDoesntHave`, `whereNotIn` → `whereIn`
  in the `visits` eager-load closure inside `accountFor()`.

## Concerns

None. The fix is a narrow, semantically-correct inversion of the relationship-existence filter.
The known, unrelated `TechnicianScopingTest` failure (stale hardcoded date fixture) was not
touched, per instructions, and is out of scope for this task.
