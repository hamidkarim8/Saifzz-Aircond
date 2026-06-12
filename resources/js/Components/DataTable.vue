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
