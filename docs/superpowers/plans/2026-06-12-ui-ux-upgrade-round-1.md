# UI/UX Upgrade Round 1 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring the live app to a close, consistent visual match with the mockup (`C:\Saifzz-Aircond\index.html`) across all admin pages + the client portal, fully responsive, with full-feature datatables and a polished toast/confirm system.

**Architecture:** Build a shared UI layer first (SweetAlert2 wrapper + flash bridge, a hybrid client/server `DataTable.vue`, and small presentational primitives), upgrade the `AdminLayout` shell, then refactor each page onto that layer. Backend touches (Clients enrichment, server-mode table params, `reminderCount` shared prop) are done TDD with the existing PHP feature suite as the regression net.

**Tech Stack:** Laravel 13 · Inertia + Vue 3 · Tailwind v3 (tokens in `tailwind.config.js`) · Ziggy · SweetAlert2 · `@tabler/icons-vue` · PHPUnit feature tests.

**Reference:** Spec at `docs/superpowers/specs/2026-06-12-ui-ux-upgrade-round-1-design.md`. Mockup is authoritative for look. Design tokens already exist (navy/primary/ink/ok/warn/danger/invoice/wa, radii ra/ral/rax, shadows card/lift, fonts Plus Jakarta Sans + JetBrains Mono).

**Conventions for executors:**
- Run frontend dev with `npm run dev` (Vite HMR) for visual checks — do NOT run a production build to eyeball.
- Run PHP tests with `./vendor/bin/sail test` (or `php artisan test` if sail unavailable) — the repo runs on Sail/Docker.
- The full PHP suite is **151 tests / 458 assertions** and MUST stay green.
- Work directly on `main` (no feature branches).
- Commit messages: do NOT add a Claude co-author trailer.
- Service-type → badge color map (use everywhere): Cleaning→blue, Gas Top-Up→amber, Repair→gray, Installation→indigo, Troubleshoot→purple. Status: Paid/Confirmed/Done→green, Pending→amber, Failed/Cancelled→red.

---

## File Structure

**New files:**
- `resources/js/lib/swal.js` — themed SweetAlert2 instance + `toast`/`confirmDanger`/`confirmAction` exports.
- `resources/js/composables/useFlashToast.js` — watches Inertia flash → routes to `toast`.
- `resources/js/Components/DataTable.vue` — hybrid client/server datatable.
- `resources/js/Components/Badge.vue` — status/service-type pill.
- `resources/js/Components/WarrantyPill.vue` — warranty state pill.
- `resources/js/Components/StatCard.vue` — KPI card with colored top border.
- `resources/js/Components/Card.vue` — header+body card.
- `resources/js/Components/PageHeader.vue` — page title + subtitle + actions slot.
- `resources/js/Components/FormErrorSummary.vue` — submit-time error list.
- `resources/js/lib/badges.js` — service-type/status → variant maps (single source of truth).

**Modified files (high level):**
- `package.json` — deps.
- `resources/js/Components/InputError.vue` — restyle.
- `resources/js/Layouts/AdminLayout.vue` — sections, user block, reminder badge, drop old toast.
- `app/Http/Middleware/HandleInertiaRequests.php` — share `reminderCount`.
- `app/Http/Controllers/ClientController.php` — index enrichment.
- `app/Http/Controllers/ServiceVisitController.php`, `AppointmentController.php` — server-mode sort/search/per_page.
- Page components: `Pages/Clients/*`, `Pages/Users/*`, `Pages/Fees/*`, `Pages/ServiceRecords/*`, `Pages/Appointments/*`, `Pages/Reminders/Index.vue`, `Pages/Payments/*`, `Pages/Dashboard.vue`, `Pages/Portal/*`.
- Blade: `resources/views/documents/*.blade.php` — visual tidy only.

---

## PHASE 0 — Foundation

### Task 1: Install dependencies

**Files:**
- Modify: `package.json`

- [ ] **Step 1: Install**

```bash
npm install sweetalert2 @tabler/icons-vue
```

- [ ] **Step 2: Verify they resolve**

Run: `node -e "require.resolve('sweetalert2'); require.resolve('@tabler/icons-vue'); console.log('ok')"`
Expected: `ok`

- [ ] **Step 3: Commit**

```bash
git add package.json package-lock.json
git commit -m "build: add sweetalert2 + @tabler/icons-vue"
```

---

### Task 2: Themed SweetAlert2 wrapper + flash composable

**Files:**
- Create: `resources/js/lib/swal.js`
- Create: `resources/js/composables/useFlashToast.js`

- [ ] **Step 1: Write `resources/js/lib/swal.js`**

```js
import Swal from 'sweetalert2';

// Navy-themed instance — classes are plain Tailwind so output matches the app.
const base = Swal.mixin({
    buttonsStyling: false,
    customClass: {
        popup: 'rounded-rax font-sans',
        title: 'text-navy-800 text-lg font-bold',
        htmlContainer: 'text-ink-soft text-sm',
        confirmButton:
            'inline-flex items-center gap-2 rounded-ra bg-primary px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-hover',
        cancelButton:
            'inline-flex items-center gap-2 rounded-ra border border-line bg-surface px-4 py-2.5 text-sm font-semibold text-ink hover:bg-surface-muted',
        actions: 'gap-3',
    },
});

const toastInstance = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 2500,
    timerProgressBar: true,
    customClass: { popup: 'rounded-ral font-sans text-sm shadow-lift' },
});

export const toast = {
    success: (title) => toastInstance.fire({ icon: 'success', title }),
    error: (title) => toastInstance.fire({ icon: 'error', title }),
    info: (title) => toastInstance.fire({ icon: 'info', title }),
};

// Returns a Promise<boolean> — true if the user confirmed.
export async function confirmDanger({ title, body = '', confirmText = 'Confirm' }) {
    const r = await base.fire({
        icon: 'warning',
        title,
        html: body,
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: 'Cancel',
        customClass: {
            ...base.params?.customClass,
            confirmButton:
                'inline-flex items-center gap-2 rounded-ra bg-danger px-4 py-2.5 text-sm font-semibold text-white hover:opacity-90',
            cancelButton:
                'inline-flex items-center gap-2 rounded-ra border border-line bg-surface px-4 py-2.5 text-sm font-semibold text-ink hover:bg-surface-muted',
            actions: 'gap-3',
        },
    });
    return r.isConfirmed;
}

export async function confirmAction({ title, body = '', confirmText = 'Confirm' }) {
    const r = await base.fire({
        title,
        html: body,
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: 'Cancel',
    });
    return r.isConfirmed;
}
```

- [ ] **Step 2: Write `resources/js/composables/useFlashToast.js`**

```js
import { watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { toast } from '@/lib/swal';

// Call once inside a layout setup(). Routes Inertia flash messages to toasts.
export function useFlashToast() {
    const page = usePage();
    watch(
        () => page.props.flash,
        (flash) => {
            if (flash?.success) toast.success(flash.success);
            if (flash?.error) toast.error(flash.error);
        },
        { immediate: true, deep: true },
    );
}
```

- [ ] **Step 3: Verify Vite compiles**

Run: `npm run build` (one-off compile check) — Expected: build succeeds, no import errors. (Then return to `npm run dev` for visual work.)

- [ ] **Step 4: Commit**

```bash
git add resources/js/lib/swal.js resources/js/composables/useFlashToast.js
git commit -m "feat(ui): themed sweetalert2 wrapper + flash-to-toast composable"
```

---

### Task 3: Wire flash bridge into AdminLayout, remove hand-rolled toast

**Files:**
- Modify: `resources/js/Layouts/AdminLayout.vue` (script: remove `toast` ref + its `watch`; add `useFlashToast()`; template: remove the `<Transition>` flash toast block at the bottom)

- [ ] **Step 1: In `<script setup>` remove the flash `toast` ref + watch block (lines ~16-27) and add:**

```js
import { useFlashToast } from '@/composables/useFlashToast';
useFlashToast();
```

- [ ] **Step 2: Remove the template flash toast `<Transition>...</Transition>` block** (the `v-if="toast"` element near the end of the template).

- [ ] **Step 3: Verify**

Run `npm run dev`, trigger any flashing action (e.g. create a client) → Expected: a corner SweetAlert toast appears, no console errors, old centered toast gone.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Layouts/AdminLayout.vue
git commit -m "feat(ui): route flash messages through sweetalert toast"
```

---

## PHASE 1 — Shared components

### Task 4: badges.js maps + Badge.vue

**Files:**
- Create: `resources/js/lib/badges.js`
- Create: `resources/js/Components/Badge.vue`

- [ ] **Step 1: Write `resources/js/lib/badges.js`**

```js
// Single source of truth for pill colors.
export const VARIANT_CLASS = {
    blue: 'bg-primary-50 text-primary-hover',
    green: 'bg-ok-bg text-ok',
    amber: 'bg-warn-bg text-warn',
    red: 'bg-danger-bg text-danger',
    gray: 'bg-surface-muted text-ink-soft',
    indigo: 'bg-invoice-bg text-invoice',
    purple: 'bg-[#EDE9FE] text-[#5B21B6]',
};

export const SERVICE_TYPE_VARIANT = {
    Cleaning: 'blue',
    'Gas Top-Up': 'amber',
    Repair: 'gray',
    Installation: 'indigo',
    Troubleshoot: 'purple',
};

export const STATUS_VARIANT = {
    Paid: 'green', Confirmed: 'green', Done: 'green', Active: 'green',
    Pending: 'amber',
    Failed: 'red', Cancelled: 'red',
};

export const serviceVariant = (t) => SERVICE_TYPE_VARIANT[t] ?? 'gray';
export const statusVariant = (s) => STATUS_VARIANT[s] ?? 'gray';
```

- [ ] **Step 2: Write `resources/js/Components/Badge.vue`**

```vue
<script setup>
import { computed } from 'vue';
import { VARIANT_CLASS } from '@/lib/badges';

const props = defineProps({
    variant: { type: String, default: 'gray' },
});
const cls = computed(() => VARIANT_CLASS[props.variant] ?? VARIANT_CLASS.gray);
</script>

<template>
    <span :class="cls" class="inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold">
        <slot />
    </span>
</template>
```

- [ ] **Step 3: Verify** `npm run dev` compiles. Temporarily drop `<Badge variant="amber">Gas Top-Up</Badge>` on any page to eyeball, then revert.

- [ ] **Step 4: Commit**

```bash
git add resources/js/lib/badges.js resources/js/Components/Badge.vue
git commit -m "feat(ui): Badge component + service/status color maps"
```

---

### Task 5: WarrantyPill.vue

**Files:**
- Create: `resources/js/Components/WarrantyPill.vue`

- [ ] **Step 1: Write the component**

```vue
<script setup>
import { computed } from 'vue';
import { IconShieldCheck, IconShieldHalf, IconShieldOff } from '@tabler/icons-vue';

// state: 'active' | 'expiring' | 'expired' | 'none'; label: text to show.
const props = defineProps({
    state: { type: String, default: 'none' },
    label: { type: String, default: '' },
});

const map = {
    active: { cls: 'bg-ok-bg text-ok', icon: IconShieldCheck },
    expiring: { cls: 'bg-warn-bg text-warn', icon: IconShieldHalf },
    expired: { cls: 'bg-surface-muted text-ink-soft', icon: IconShieldOff },
    none: { cls: 'bg-surface-muted text-ink-soft', icon: IconShieldOff },
};
const cfg = computed(() => map[props.state] ?? map.none);
const text = computed(() => props.label || (props.state === 'none' ? 'No warranty' : ''));
</script>

<template>
    <span :class="cfg.cls" class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold">
        <component :is="cfg.icon" :size="13" :stroke="2" />
        {{ text }}
    </span>
</template>
```

- [ ] **Step 2: Verify** `npm run dev` compiles, icons resolve.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/WarrantyPill.vue
git commit -m "feat(ui): WarrantyPill component"
```

---

### Task 6: StatCard.vue

**Files:**
- Create: `resources/js/Components/StatCard.vue`

- [ ] **Step 1: Write the component**

```vue
<script setup>
import { computed } from 'vue';

// variant drives the 3px top border + icon box color.
const props = defineProps({
    label: String,
    value: [String, Number],
    sub: { type: String, default: '' },
    subPositive: { type: Boolean, default: false },
    variant: { type: String, default: 'primary' }, // primary | ok | warn | danger
});

const bar = {
    primary: 'before:bg-primary', ok: 'before:bg-ok', warn: 'before:bg-warn', danger: 'before:bg-danger',
};
const iconBox = {
    primary: 'bg-primary-50 text-primary', ok: 'bg-ok-bg text-ok',
    warn: 'bg-warn-bg text-warn', danger: 'bg-danger-bg text-danger',
};
const barCls = computed(() => bar[props.variant] ?? bar.primary);
const iconCls = computed(() => iconBox[props.variant] ?? iconBox.primary);
</script>

<template>
    <div
        class="relative overflow-hidden rounded-ral border border-line bg-surface p-4 shadow-card
               before:absolute before:inset-x-0 before:top-0 before:h-[3px] before:content-['']"
        :class="barCls"
    >
        <div v-if="$slots.icon" class="mb-2.5 grid h-9 w-9 place-items-center rounded-ra" :class="iconCls">
            <slot name="icon" />
        </div>
        <div class="text-[11px] font-medium uppercase tracking-wide text-ink-soft">{{ label }}</div>
        <div class="mt-0.5 text-2xl font-bold leading-none text-ink">{{ value }}</div>
        <div v-if="sub" class="mt-1 text-xs" :class="subPositive ? 'font-semibold text-ok' : 'text-ink-muted'">{{ sub }}</div>
    </div>
</template>
```

- [ ] **Step 2: Verify** `npm run dev` compiles.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/StatCard.vue
git commit -m "feat(ui): StatCard component"
```

---

### Task 7: Card.vue + PageHeader.vue

**Files:**
- Create: `resources/js/Components/Card.vue`
- Create: `resources/js/Components/PageHeader.vue`

- [ ] **Step 1: Write `resources/js/Components/Card.vue`**

```vue
<script setup>
defineProps({ title: { type: String, default: '' } });
</script>

<template>
    <div class="overflow-hidden rounded-ral border border-line bg-surface shadow-card">
        <div v-if="title || $slots.title || $slots.actions" class="flex items-center justify-between border-b border-line px-4 py-3.5">
            <div class="flex items-center gap-2 text-sm font-bold text-ink">
                <slot name="title">{{ title }}</slot>
            </div>
            <div v-if="$slots.actions"><slot name="actions" /></div>
        </div>
        <div class="p-4"><slot /></div>
    </div>
</template>
```

- [ ] **Step 2: Write `resources/js/Components/PageHeader.vue`**

```vue
<script setup>
defineProps({ title: String, subtitle: { type: String, default: '' } });
</script>

<template>
    <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-lg font-bold tracking-tight text-navy-800">{{ title }}</h1>
            <p v-if="subtitle" class="mt-0.5 text-sm text-ink-soft">{{ subtitle }}</p>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2"><slot name="actions" /></div>
    </div>
</template>
```

- [ ] **Step 3: Verify** compiles.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/Card.vue resources/js/Components/PageHeader.vue
git commit -m "feat(ui): Card + PageHeader components"
```

---

### Task 8: InputError restyle + FormErrorSummary.vue

**Files:**
- Modify: `resources/js/Components/InputError.vue`
- Create: `resources/js/Components/FormErrorSummary.vue`

- [ ] **Step 1: Replace `resources/js/Components/InputError.vue` content**

```vue
<script setup>
defineProps({ message: { type: String, default: '' } });
</script>

<template>
    <p v-show="message" class="mt-1 flex items-start gap-1 break-words text-xs font-medium text-danger">
        <svg class="mt-0.5 h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><path d="M12 8v4M12 16h.01" stroke-linecap="round" /></svg>
        <span>{{ message }}</span>
    </p>
</template>
```

- [ ] **Step 2: Write `resources/js/Components/FormErrorSummary.vue`**

```vue
<script setup>
import { computed } from 'vue';

// Pass an Inertia form `errors` object. Renders a summary box when non-empty.
const props = defineProps({ errors: { type: Object, default: () => ({}) } });
const list = computed(() => Object.values(props.errors).filter(Boolean));
</script>

<template>
    <div v-if="list.length" class="mb-4 rounded-ra border border-danger/30 bg-danger-bg px-4 py-3 text-sm text-danger">
        <div class="mb-1 font-semibold">Please fix the following:</div>
        <ul class="list-inside list-disc space-y-0.5">
            <li v-for="(msg, i) in list" :key="i" class="break-words">{{ msg }}</li>
        </ul>
    </div>
</template>
```

- [ ] **Step 3: Verify** compiles; existing forms still show field errors (InputError prop unchanged: `message`).

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/InputError.vue resources/js/Components/FormErrorSummary.vue
git commit -m "feat(ui): restyle InputError + add FormErrorSummary"
```

---

### Task 9: DataTable.vue (hybrid client/server)

**Files:**
- Create: `resources/js/Components/DataTable.vue`

- [ ] **Step 1: Write the component**

```vue
<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
    // columns: [{ key, label, sortable=false, align='left', formatter=null, headerClass='', cellClass='' }]
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },            // client mode dataset
    pagination: { type: Object, default: null },          // server mode Laravel paginator
    mode: { type: String, default: 'client' },            // 'client' | 'server'
    searchable: { type: Boolean, default: true },
    searchKeys: { type: Array, default: () => [] },       // client-mode fields to match
    perPageOptions: { type: Array, default: () => [10, 25, 50] },
    perPage: { type: Number, default: 10 },
    routeName: { type: String, default: '' },             // server mode reload target
    filterParams: { type: Object, default: () => ({}) },  // server mode extra query
    searchPlaceholder: { type: String, default: 'Search…' },
});

const isServer = computed(() => props.mode === 'server');

/* ----- search ----- */
const search = ref('');
let timer = null;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        if (isServer.value) reloadServer();
        else page.value = 1;
    }, 300);
});

/* ----- sort ----- */
const sortKey = ref('');
const sortDir = ref('');     // '', 'asc', 'desc'
function toggleSort(col) {
    if (!col.sortable) return;
    if (sortKey.value !== col.key) { sortKey.value = col.key; sortDir.value = 'asc'; }
    else if (sortDir.value === 'asc') sortDir.value = 'desc';
    else if (sortDir.value === 'desc') { sortKey.value = ''; sortDir.value = ''; }
    else sortDir.value = 'asc';
    if (isServer.value) reloadServer(); else page.value = 1;
}

/* ----- client-mode pipeline ----- */
const page = ref(1);
const pp = ref(props.perPage);
watch(pp, () => { page.value = 1; if (isServer.value) reloadServer(); });

const filtered = computed(() => {
    if (isServer.value) return props.rows;
    let out = props.rows;
    const q = search.value.trim().toLowerCase();
    if (q && props.searchKeys.length) {
        out = out.filter((r) => props.searchKeys.some((k) => String(r[k] ?? '').toLowerCase().includes(q)));
    }
    if (sortKey.value) {
        const dir = sortDir.value === 'desc' ? -1 : 1;
        out = [...out].sort((a, b) => {
            const x = a[sortKey.value], y = b[sortKey.value];
            if (x == null) return 1; if (y == null) return -1;
            return (typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y))) * dir;
        });
    }
    return out;
});
const totalRows = computed(() => filtered.value.length);
const pageCount = computed(() => Math.max(1, Math.ceil(totalRows.value / pp.value)));
const pageRows = computed(() => {
    if (isServer.value) return props.rows;
    const start = (page.value - 1) * pp.value;
    return filtered.value.slice(start, start + pp.value);
});

/* ----- server-mode reload ----- */
function reloadServer() {
    if (!props.routeName) return;
    router.get(
        route(props.routeName),
        {
            ...props.filterParams,
            search: search.value || undefined,
            sort: sortKey.value || undefined,
            dir: sortDir.value || undefined,
            per_page: pp.value,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}

const align = (a) => (a === 'right' ? 'text-right' : a === 'center' ? 'text-center' : 'text-left');
const sortIcon = (col) => (sortKey.value !== col.key ? '↕' : sortDir.value === 'asc' ? '↑' : '↓');
</script>

<template>
    <div>
        <!-- Toolbar -->
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div v-if="searchable" class="relative w-full sm:max-w-xs">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="m21 21-4.3-4.3" stroke-linecap="round" /></svg>
                <input v-model="search" type="search" :placeholder="searchPlaceholder"
                    class="w-full rounded-ra border-line bg-surface pl-9 text-sm shadow-card focus:border-primary focus:ring-primary" />
            </div>
            <div class="flex items-center gap-2"><slot name="filters" /></div>
        </div>

        <!-- Desktop table -->
        <div class="hidden overflow-x-auto rounded-ral border border-line bg-surface shadow-card md:block">
            <table class="w-full text-sm">
                <thead class="border-b border-line bg-surface-muted text-xs uppercase tracking-wide text-ink-soft">
                    <tr>
                        <th v-for="col in columns" :key="col.key"
                            class="px-4 py-3 font-semibold" :class="[align(col.align), col.headerClass]">
                            <button v-if="col.sortable" class="inline-flex items-center gap-1 hover:text-ink" @click="toggleSort(col)">
                                {{ col.label }} <span class="text-ink-muted">{{ sortIcon(col) }}</span>
                            </button>
                            <span v-else>{{ col.label }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="(row, i) in pageRows" :key="row.id ?? i" class="hover:bg-surface-muted">
                        <td v-for="col in columns" :key="col.key" class="px-4 py-3" :class="[align(col.align), col.cellClass]">
                            <slot :name="`cell-${col.key}`" :row="row" :value="row[col.key]">
                                {{ col.formatter ? col.formatter(row[col.key], row) : row[col.key] }}
                            </slot>
                        </td>
                    </tr>
                    <tr v-if="!pageRows.length">
                        <td :colspan="columns.length" class="px-4 py-12 text-center text-ink-soft">
                            <slot name="empty">No records found.</slot>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Mobile cards -->
        <div class="space-y-3 md:hidden">
            <template v-if="pageRows.length">
                <slot v-for="(row, i) in pageRows" name="card" :row="row" :key="row.id ?? i" />
            </template>
            <p v-else class="rounded-ral border border-line bg-surface py-12 text-center text-ink-soft shadow-card">
                <slot name="empty">No records found.</slot>
            </p>
        </div>

        <!-- Footer: per-page + pagination -->
        <div class="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row">
            <label class="flex items-center gap-2 text-xs text-ink-soft">
                Rows per page
                <select v-model.number="pp" class="rounded-ra border-line py-1 text-xs shadow-card focus:border-primary focus:ring-primary">
                    <option v-for="n in perPageOptions" :key="n" :value="n">{{ n }}</option>
                </select>
            </label>

            <!-- client pagination -->
            <div v-if="!isServer && pageCount > 1" class="flex flex-wrap gap-1">
                <button class="min-w-9 rounded-ra px-3 py-1.5 text-sm shadow-card disabled:opacity-40"
                    :disabled="page === 1" @click="page--">‹</button>
                <button v-for="p in pageCount" :key="p" class="min-w-9 rounded-ra px-3 py-1.5 text-sm transition"
                    :class="p === page ? 'bg-primary text-white' : 'bg-surface text-ink-soft shadow-card hover:text-ink'" @click="page = p">{{ p }}</button>
                <button class="min-w-9 rounded-ra px-3 py-1.5 text-sm shadow-card disabled:opacity-40"
                    :disabled="page === pageCount" @click="page++">›</button>
            </div>

            <!-- server pagination -->
            <div v-else-if="isServer && pagination && pagination.links?.length > 3" class="flex flex-wrap gap-1">
                <component :is="link.url ? Link : 'span'" v-for="link in pagination.links" :key="link.label"
                    :href="link.url" preserve-scroll preserve-state
                    class="min-w-9 rounded-ra px-3 py-1.5 text-center text-sm transition"
                    :class="[link.active ? 'bg-primary text-white' : 'bg-surface text-ink-soft shadow-card hover:text-ink', !link.url && 'opacity-40']"
                    v-html="link.label" />
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Verify** `npm run dev` compiles with no errors.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/DataTable.vue
git commit -m "feat(ui): hybrid client/server DataTable component"
```

---

## PHASE 2 — Shell

### Task 10: Share reminderCount via Inertia (TDD)

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Test: `tests/Feature/InertiaSharedPropsTest.php` (create)

> First READ `app/Http/Middleware/HandleInertiaRequests.php` and `app/Services/Reminders/ReminderService.php` to confirm the `share()` structure and how `dueList()` returns data (it partitions overdue + due-this-month).

- [ ] **Step 1: Write failing test `tests/Feature/InertiaSharedPropsTest.php`**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InertiaSharedPropsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reminder_count_is_shared_for_users_who_can_view_clients(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->has('reminderCount'));
    }
}
```

- [ ] **Step 2: Run — expect fail**

Run: `./vendor/bin/sail test --filter=InertiaSharedPropsTest`
Expected: FAIL (`reminderCount` missing).

- [ ] **Step 3: Add to `HandleInertiaRequests::share()`** (merge into the returned array; gate by permission, default 0)

```php
'reminderCount' => $this->reminderCount($request),
```

And add a private method (use the existing `ReminderService`; count overdue + due lists). Adapt names after reading the service:

```php
private function reminderCount(\Illuminate\Http\Request $request): int
{
    $user = $request->user();
    if (! $user || ! $user->can('view_clients')) {
        return 0;
    }
    $list = app(\App\Services\Reminders\ReminderService::class)->dueList();

    // dueList() returns partitioned overdue + due collections — sum their counts.
    return collect($list)->only(['overdue', 'due'])->flatten(1)->count();
}
```

> If `dueList()` returns a different shape, adjust the count expression to total the overdue + due-this-month entries. Keep it a single int.

- [ ] **Step 4: Run — expect pass**

Run: `./vendor/bin/sail test --filter=InertiaSharedPropsTest` → Expected: PASS.
Then run full suite: `./vendor/bin/sail test` → Expected: still 152+ passing (new test added), none broken.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Middleware/HandleInertiaRequests.php tests/Feature/InertiaSharedPropsTest.php
git commit -m "feat(ui): share reminderCount for sidebar badge"
```

---

### Task 11: AdminLayout shell upgrade (sections, user block, reminder badge, Tabler icons)

**Files:**
- Modify: `resources/js/Layouts/AdminLayout.vue`

- [ ] **Step 1: Replace the nav model + icons.** Use grouped sections and `@tabler/icons-vue` components. In `<script setup>`:

```js
import {
    IconLayoutDashboard, IconUsers, IconBell, IconClipboardPlus,
    IconCurrencyDollar, IconCalendarEvent, IconQrcode, IconUserCog,
    IconAirConditioning, IconLogout, IconMenu2,
} from '@tabler/icons-vue';

const reminderCount = computed(() => page.props.reminderCount ?? 0);

// Grouped nav. Each item gated by a permission key (null = always).
const sections = computed(() => {
    const def = [
        { title: 'Main', items: [
            { label: 'Dashboard', route: 'dashboard', icon: IconLayoutDashboard, permission: null },
            { label: 'Clients', route: 'clients.index', match: 'clients', icon: IconUsers, permission: 'view_clients' },
            { label: 'Reminders', route: 'reminders.index', match: 'reminders', icon: IconBell, permission: 'view_clients', badge: reminderCount.value },
            { label: 'Service Records', route: 'service-records.index', match: 'service-records', icon: IconClipboardPlus, permission: 'record_service' },
        ]},
        { title: 'Management', items: [
            { label: 'Service Fees', route: 'fees.index', match: 'fees', icon: IconCurrencyDollar, permission: 'edit_fees' },
            { label: 'Appointments', route: 'appointments.index', match: 'appointments', icon: IconCalendarEvent, permission: 'set_appointment' },
            { label: 'Users', route: 'users.index', match: 'users', icon: IconUserCog, permission: 'manage_users' },
        ]},
        { title: 'Portal', items: [
            { label: 'Client Portal', route: 'portal.login', match: 'portal', icon: IconQrcode, permission: null, external: true },
        ]},
    ];
    return def
        .map((s) => ({ ...s, items: s.items.filter((i) => i.permission === null || can.value[i.permission]) }))
        .filter((s) => s.items.length);
});
```

> Confirm the portal route name (`portal.login` or similar) by checking `routes/web.php`; adjust `route`/`match`. If the portal should open in a new tab, render it as a plain `<a target="_blank">`.

- [ ] **Step 2: Rewrite the sidebar template** — logo with `IconAirConditioning`, section headers, nav items with icon + label + optional badge, and a bottom user block (avatar + name + role + logout). Replace the existing `<nav>` and footer:

```vue
<div class="flex h-16 items-center gap-3 px-5">
    <div class="grid h-9 w-9 place-items-center rounded-ra bg-primary text-white"><IconAirConditioning :size="20" /></div>
    <div class="leading-tight">
        <div class="text-sm font-bold text-white">Saifzz Aircond</div>
        <div class="font-mono text-[10px] uppercase tracking-widest text-primary-300/60">Service System</div>
    </div>
</div>

<nav class="flex-1 space-y-1 overflow-y-auto px-3 py-3">
    <template v-for="section in sections" :key="section.title">
        <div class="px-3 pb-1 pt-3 text-[9.5px] font-bold uppercase tracking-[0.12em] text-primary-300/40">{{ section.title }}</div>
        <Link v-for="item in section.items" :key="item.label" :href="route(item.route)"
            class="group flex items-center gap-3 rounded-ra px-3 py-2.5 text-sm font-medium transition-colors"
            :class="isActive(item) ? 'bg-primary text-white shadow-card' : 'text-primary-300 hover:bg-navy-800 hover:text-white'">
            <component :is="item.icon" :size="18" :stroke="2" class="shrink-0" />
            <span class="flex-1">{{ item.label }}</span>
            <span v-if="item.badge" class="rounded-full bg-danger px-1.5 text-[10px] font-bold leading-5 text-white">{{ item.badge }}</span>
        </Link>
    </template>
</nav>

<div class="border-t border-navy-700/60 p-3">
    <div class="flex items-center gap-3 rounded-ra px-2 py-2">
        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary text-sm font-bold text-white">{{ initials }}</span>
        <div class="min-w-0 flex-1">
            <div class="truncate text-sm font-semibold text-white">{{ user?.name }}</div>
            <div class="text-[11px] text-primary-300/60">{{ isAdmin ? 'Administrator' : 'Technician' }}</div>
        </div>
        <button class="text-primary-300/50 hover:text-white" aria-label="Log out" @click="logout"><IconLogout :size="17" /></button>
    </div>
</div>
```

- [ ] **Step 3: Update `isActive`** to handle the icon-component item shape (logic unchanged — it reads `item.match`/`item.route`). Keep the mobile drawer + topbar as-is (topbar keeps the user-menu). Optionally swap the topbar hamburger SVG for `IconMenu2`.

- [ ] **Step 4: Verify** `npm run dev` — Expected: sidebar shows Main/Management/Portal sections, Tabler icons, reminder badge when count > 0, bottom user block with working logout; mobile drawer still opens; active highlighting works.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Layouts/AdminLayout.vue
git commit -m "feat(ui): AdminLayout sections, Tabler icons, user block, reminder badge"
```

---

## PHASE 3 — Admin pages

> Each page task: refactor onto the shared components, match mockup styling, replace any native `confirm()`/ad-hoc toast with `confirmDanger`/`toast`. After each, run `npm run dev` and eyeball at phone (375px), iPad (768px), desktop (1280px) widths. Where a controller changes, run the full PHP suite.

### Task 12: Clients controller enrichment (TDD)

**Files:**
- Modify: `app/Http/Controllers/ClientController.php` (`index`)
- Test: `tests/Feature/ClientControllerTest.php` (extend)

> READ `ClientController@index` + the `Client` model relations first. The index currently returns clients with `visits_count`. Add per-client aggregates: `last_service_date`, `service_types` (array), `units` (sum on latest visit), `next_service_date`, `last_amount`, `warranty_state` (`active|expiring|expired|none`), `warranty_label`. Compute from the latest `ServiceVisit` + its lines; reuse model accessors where they exist (warranty_end, next_service_date). Keep pagination + existing search/filter intact.

- [ ] **Step 1: Add a failing test** to `ClientControllerTest`

```php
public function test_index_includes_enriched_table_fields(): void
{
    $admin = \App\Models\User::factory()->admin()->create();
    $client = \App\Models\Client::factory()->create();
    // ... create a ServiceVisit + lines for $client so aggregates are non-null
    // (follow the existing factory pattern used elsewhere in this test file)

    $this->actingAs($admin)
        ->get(route('clients.index'))
        ->assertInertia(fn ($page) => $page
            ->has('clients.data.0', fn ($row) => $row
                ->hasAll(['serial_no', 'name', 'phone', 'last_service_date', 'service_types', 'next_service_date', 'warranty_state'])
                ->etc()));
}
```

- [ ] **Step 2: Run — expect fail.** `./vendor/bin/sail test --filter=ClientControllerTest`

- [ ] **Step 3: Implement the aggregates** in `index` (eager-load latest visit + lines; map each client to include the new fields). Compute `warranty_state` by comparing `warranty_end` to now (`expired` if past, `expiring` if within 30 days, `active` otherwise, `none` if null) and a human `warranty_label` (e.g. "2 mos left" / "Expires 20 Jul" / "No warranty").

- [ ] **Step 4: Run — expect pass**, then full suite green. `./vendor/bin/sail test`

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ClientController.php tests/Feature/ClientControllerTest.php
git commit -m "feat(clients): enrich index payload for richer table"
```

---

### Task 13: Clients/Index → DataTable (rich columns)

**Files:**
- Modify: `resources/js/Pages/Clients/Index.vue`

- [ ] **Step 1: Rewrite** using `PageHeader`, `DataTable` (client mode, `searchKeys: ['serial_no','name','phone']`), `Badge`, `WarrantyPill`. Keep the service-type filter as a `#filters` slot (filter tabs). Columns: Serial (mono), Name (+address sub), Phone, Last Service, Services (Badge per type via `serviceVariant`), Units, Next Service (Badge), Amount (RM), Warranty (WarrantyPill from `warranty_state`/`warranty_label`), Actions (View/Edit/Archive). Provide `#card` slot for mobile. Replace `confirm(...)` archive with:

```js
import { confirmDanger, toast } from '@/lib/swal';
const archive = async (client) => {
    if (await confirmDanger({ title: `Archive ${client.serial_no}?`, body: 'History is preserved.', confirmText: 'Archive' })) {
        router.delete(route('clients.destroy', client.id), { preserveScroll: true });
    }
};
```

> Note: with the service-type filter, decide client vs server filtering. Since the page still receives a paginated `clients` from the server, keep the existing server filter for `service_type` + server search via the toolbar OR switch the page to load all clients for client-mode DataTable. **Recommended:** keep server pagination/search/filter as today (pass `mode="server"`, `routeName="clients.index"`, `:pagination="clients"`, `:filterParams="{ service_type: activeType }"`), and let DataTable handle sort via server params. This avoids loading all clients. Add `sort`/`dir`/`per_page` handling to `ClientController@index` (orderBy whitelist: serial_no, name, last_service_date, next_service_date, last_amount).

- [ ] **Step 2: If server sort added**, extend `ClientController@index` to apply `sort`/`dir` (whitelisted) + `per_page`; add a quick test asserting `?sort=name&dir=desc` orders results. Run full suite.

- [ ] **Step 3: Verify** at 3 widths — table on desktop/iPad, cards on phone; sort arrows work; filter tabs work; archive shows themed confirm.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Clients/Index.vue app/Http/Controllers/ClientController.php tests/Feature/ClientControllerTest.php
git commit -m "feat(clients): rich DataTable index matching mockup"
```

---

### Task 14: Clients Show / Create / Edit / ClientForm polish

**Files:**
- Modify: `resources/js/Pages/Clients/Show.vue`, `Create.vue`, `Edit.vue`, `Partials/ClientForm.vue`

- [ ] **Step 1:** Wrap sections in `Card`; use `PageHeader`; render service history with `Badge` (service types) + `WarrantyPill`; payment status via `Badge`. In `ClientForm`, add `FormErrorSummary :errors="form.errors"` at top and ensure each field uses the restyled `InputError`. Match the mockup form styling (uppercase labels optional — keep current label style but ensure spacing/inputs match `.fc`).

- [ ] **Step 2: Verify** create/edit validation errors show inline + summary; show page matches mockup history layout.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Clients/
git commit -m "feat(clients): polish show/create/edit onto shared UI"
```

---

### Task 15: Users Index + UserModal

**Files:**
- Modify: `resources/js/Pages/Users/Index.vue`, `Partials/UserModal.vue`

- [ ] **Step 1:** `PageHeader` + `DataTable` (client mode, rows = users; `searchKeys: ['name','email']`). Columns: Name, Email, Role (Badge), Permissions (count or chips), Status (active toggle switch), Actions (Edit for technicians). Replace deactivate `confirm()`/flow with `confirmDanger`. Toast on success via flash bridge (already wired). Style the permission checkbox grid in `UserModal` to match mockup; show `FormErrorSummary`.

- [ ] **Step 2: Verify** active toggle, edit modal, permission grid, self-deactivate guard still 422s (toast.error shows).

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Users/
git commit -m "feat(users): DataTable index + modal polish"
```

---

### Task 16: Fees Index + FeeModal

**Files:**
- Modify: `resources/js/Pages/Fees/Index.vue`, `Partials/FeeModal.vue`

- [ ] **Step 1:** Match mockup service-fee table (grouped by service type, unit/option, fee mono, mode badges incl. "Flexible" for Repair). The fee list is small — use a `Card` + plain grouped rows OR `DataTable` client mode (searchable by type). Add the info banner (`.ib2`) explaining auto-applied rates. Replace delete `confirm()` with `confirmDanger`; toast on save/delete (flash). `FeeModal`: match mockup (service-type select toggles unit/PSI/flexible rows); `FormErrorSummary`.

- [ ] **Step 2: Verify** add/edit/delete fee; Repair shows flexible note; duplicate type+option still blocked (error toast).

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Fees/
git commit -m "feat(fees): match mockup fee table + modal"
```

---

### Task 17: ServiceRecords/Index → server DataTable (TDD controller)

**Files:**
- Modify: `app/Http/Controllers/ServiceVisitController.php` (`index`)
- Modify: `resources/js/Pages/ServiceRecords/Index.vue`
- Test: `tests/Feature/` (extend the service-visit test)

> READ `ServiceVisitController@index` first. Add `search` (client name/serial/txn), `sort`/`dir` (whitelist: visit_date, total, serial), `per_page`. Keep gating `can:record_service`.

- [ ] **Step 1: Add failing test** asserting `?sort=visit_date&dir=desc&per_page=5` returns paginated, ordered data (follow existing test patterns in the file).

- [ ] **Step 2: Run — fail.** Implement controller params. **Run — pass**, full suite green.

- [ ] **Step 3: Refactor `Index.vue`** to `DataTable` server mode (`routeName="service-records.index"`, `:pagination="..."`). Columns: Date/Time, Client, Serial, Services (Badges), Amount, Status (Badge), Actions (View, + Invoice/Receipt links). `#card` slot for mobile.

- [ ] **Step 4: Verify** sort/search/paginate hit the server and update; 3-width check.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ServiceVisitController.php resources/js/Pages/ServiceRecords/Index.vue tests/Feature/
git commit -m "feat(service-records): server DataTable with sort/search/paginate"
```

---

### Task 18: ServiceRecords/Create builder + Show polish

**Files:**
- Modify: `resources/js/Pages/ServiceRecords/Create.vue`, `Partials/ClientPicker.vue`, `Partials/ServiceLineCard.vue`, `Show.vue`

- [ ] **Step 1:** Match mockup add-service modal/builder: service blocks (`.svb`) with numbered header + fee badge (`.fd`), adaptive rows (unit type / PSI select / repair fee), live subtotal, sticky grand-total bar (`.tbar`, navy), warranty block at the bottom. **Do not change R1–R8 business logic or field names** — only presentation. `ClientPicker`: match new/existing toggle (`.po` option cards). `Show`: `Card` + `Badge` + `WarrantyPill` + document links. Add `FormErrorSummary`.

- [ ] **Step 2: Verify** building a record still computes totals correctly, snapshots rate, and submits; visual matches mockup; 3-width check.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/ServiceRecords/
git commit -m "feat(service-records): builder + show match mockup"
```

---

### Task 19: Appointments controller params (TDD) + Index refactor

**Files:**
- Modify: `app/Http/Controllers/AppointmentController.php` (`index`)
- Modify: `resources/js/Pages/Appointments/Index.vue`, `Partials/MonthCalendar.vue`, `Partials/AppointmentModal.vue`
- Test: `tests/Feature/AppointmentTest.php` (extend)

> READ `AppointmentController@index` (returns month-scoped list + today list + stats). Add `search`/`sort`/`dir`/`per_page` for the month table without breaking the calendar month scope.

- [ ] **Step 1: Add failing test** for the table query params (follow `AppointmentTest` patterns). **Run — fail.**

- [ ] **Step 2: Implement** controller params. **Run — pass**, full suite green.

- [ ] **Step 3: Refactor `Index.vue`:** `MonthCalendar` with day-dots polish (mockup `.cd.ha`), summary `StatCard`s, and the month appointments table as `DataTable` server mode (columns per mockup: Date/Time, Client, Contact, Serial, Service (Badge), Address, Units, Amount, Status (Badge) with inline lifecycle actions). Replace any `confirm()` on status changes with `confirmAction`. `AppointmentModal`: match mockup; `FormErrorSummary`; toast on illegal transition (422 → error).

- [ ] **Step 4: Verify** calendar dots, day panel, table sort/paginate, status lifecycle; 3-width check.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/AppointmentController.php resources/js/Pages/Appointments/ tests/Feature/AppointmentTest.php
git commit -m "feat(appointments): calendar polish + server DataTable"
```

---

### Task 20: Reminders/Index refactor

**Files:**
- Modify: `resources/js/Pages/Reminders/Index.vue`

- [ ] **Step 1:** `PageHeader` + `StatCard`s (overdue / due / contacted). Reminder cards with left-border state color (`overdue`=danger, `due`=warn, `ok`=primary) per mockup `.rem`. Each card: WhatsApp button (`wa` color), Set Appointment (opens module-7 modal), Mark Contacted / Undo toggle. Use `toast` on contacted toggle (flash already bridges). Keep existing data shape from `ReminderService`.

- [ ] **Step 2: Verify** sections render, toggle works, WhatsApp link correct; 3-width check.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Reminders/Index.vue
git commit -m "feat(reminders): match mockup card styling"
```

---

### Task 21: Payments Show / Return

**Files:**
- Modify: `resources/js/Pages/Payments/Show.vue`, `Return.vue`

- [ ] **Step 1:** Match mockup payment modal: amount panel (`.app`), method chooser cards (`.po` DuitNow/Cash), cash confirm state, result screen with receipt number on `Return`. Use `confirmAction` before marking cash collected; `toast` on outcome.

- [ ] **Step 2: Verify** cash + gateway flows visually; 3-width check.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Payments/
git commit -m "feat(payments): match mockup payment screens"
```

---

### Task 22: Dashboard refactor

**Files:**
- Modify: `resources/js/Pages/Dashboard.vue`

- [ ] **Step 1:** Rebuild to mockup: 4 `StatCard`s (Total Clients / Revenue this month / All-time revenue / Pending reminders — variants primary/ok/primary/warn), period filter tabs, mini `MonthCalendar` + day panel, Services-by-Type CSS bars, recent transactions as `DataTable` (client mode or simple table) + Export CSV button. Keep the existing report payload + the technician launcher fallback (non-`view_reports` users). **Do not change report logic** — presentation only.

- [ ] **Step 2: Verify** admin sees KPIs/charts/table; technician still sees launcher; period tabs work; 3-width check.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Dashboard.vue
git commit -m "feat(dashboard): match mockup layout"
```

---

### Task 23: Documents blade visual tidy

**Files:**
- Modify: `resources/views/documents/layout.blade.php`, `invoice.blade.php`, `receipt.blade.php`

- [ ] **Step 1:** Ensure on-screen invoice/receipt match mockup styling (mono figures, dashed rules, navy totals, business header). These are server-rendered Blade + dompdf — keep print-safe CSS inline. Visual only; no data changes.

- [ ] **Step 2: Verify** view an invoice + a receipt in browser; download PDFs still render.

- [ ] **Step 3: Commit**

```bash
git add resources/views/documents/
git commit -m "style(documents): align invoice/receipt with mockup"
```

---

## PHASE 4 — Portal

### Task 24: Portal Login / Show / PortalLayout polish

**Files:**
- Modify: `resources/js/Pages/Portal/Login.vue`, `Show.vue`, `PortalLayout.vue`

- [ ] **Step 1:** Match mockup portal: gradient navy background, mobile-first cards, serial entry card (big mono input), generic error box (no enumeration oracle — keep existing message), next-service banner (`.nb`), client header card, service history cards with `WarrantyPill`, per-paid-visit receipt download, WhatsApp CTAs. Route portal flash to `toast` (call `useFlashToast()` in `PortalLayout`). Keep all portal security behavior (session-scoped, paid-only receipts, rate limiting) unchanged — presentation only.

- [ ] **Step 2: Verify** login (valid + invalid), account view, receipt download, WhatsApp links; phone-first at 375px + desktop.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Portal/
git commit -m "feat(portal): match mockup mobile-first design"
```

---

## PHASE 5 — Verify

### Task 25: Full regression + visual pass

- [ ] **Step 1: Run the full PHP suite**

Run: `./vendor/bin/sail test`
Expected: all green (≥ 151 original + new tests added in Tasks 10/12/13/17/19).

- [ ] **Step 2: Production build sanity**

Run: `npm run build`
Expected: builds with no errors.

- [ ] **Step 3: Visual pass** (`npm run dev`) across every page at 375px (phone), 768px (iPad), 1280px (desktop): sidebar sections + badge + user block; every table sorts/searches/paginates and reflows to cards on phone; toasts appear on actions; confirm dialogs themed; form errors legible and non-overflowing; portal mobile-first. Note any gaps.

- [ ] **Step 4: Update status docs**

Update `docs/STATUS.md` (add a "UI/UX Round 1 done" entry) and append to `docs/SESSION-LOG.md`.

- [ ] **Step 5: Commit**

```bash
git add docs/STATUS.md docs/SESSION-LOG.md
git commit -m "docs: UI/UX upgrade round 1 complete"
```

---

## Self-review notes (for the author)

- **Spec coverage:** swal+flash (T2-3) ✓; DataTable hybrid (T9) ✓; primitives Badge/WarrantyPill/StatCard/Card/PageHeader/InputError/FormErrorSummary (T4-8) ✓; Tabler icons (T1, used T5/11) ✓; AdminLayout sections+user block+badge+reminderCount (T10-11) ✓; all admin pages (T12-23) ✓; portal (T24) ✓; responsive cards/table (DataTable `#card`, every page step) ✓; error handling (InputError/FormErrorSummary, every form page) ✓; verify suite green + visual (T25) ✓.
- **Icon.vue:** dropped in favor of direct `@tabler/icons-vue` component imports (better tree-shaking) — matches the spec's deferred decision.
- **Open item for executor:** confirm exact route name for the client portal link in the sidebar (Task 11) and the `dueList()` return shape (Task 10) by reading the files before implementing.
