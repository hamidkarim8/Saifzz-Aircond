<script setup>
import { ref, watch, computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    clients: Object,
    filters: Object,
    serviceTypes: Array,
});

const can = computed(() => usePage().props.auth.can ?? {});

const search = ref(props.filters.search ?? '');
const activeType = ref(props.filters.service_type ?? null);

// Debounced server-side search + filter.
let timer = null;
const reload = () => {
    router.get(
        route('clients.index'),
        { search: search.value || undefined, service_type: activeType.value || undefined },
        { preserveState: true, replace: true, preserveScroll: true },
    );
};
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(reload, 300);
});
const setType = (t) => {
    activeType.value = activeType.value === t ? null : t;
    reload();
};

const archive = (client) => {
    if (confirm(`Archive client ${client.serial_no}? Their history is preserved.`)) {
        router.delete(route('clients.destroy', client.id), { preserveScroll: true });
    }
};

const typeColor = {
    Cleaning: 'bg-primary-50 text-primary',
    'Gas Top-Up': 'bg-warn-bg text-warn',
    Repair: 'bg-danger-bg text-danger',
    Installation: 'bg-ok-bg text-ok',
    Troubleshoot: 'bg-invoice-bg text-invoice',
};
</script>

<template>
    <Head title="Clients" />

    <AdminLayout>
        <template #header>
            <h1 class="text-lg font-bold tracking-tight text-navy-800">Clients</h1>
        </template>

        <!-- Toolbar -->
        <div class="mb-5 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="relative w-full sm:max-w-sm">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-ink-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="m21 21-4.3-4.3" stroke-linecap="round" /></svg>
                <input
                    v-model="search"
                    type="search"
                    placeholder="Search name, serial or phone…"
                    class="w-full rounded-ral border-line bg-surface pl-10 text-ink shadow-card focus:border-primary focus:ring-primary"
                />
            </div>
            <Link
                v-if="can.edit_client"
                :href="route('clients.create')"
                class="inline-flex shrink-0 items-center justify-center gap-2 rounded-ra bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg>
                New client
            </Link>
        </div>

        <!-- Filter tabs -->
        <div class="mb-5 flex flex-wrap gap-2">
            <button
                v-for="t in serviceTypes"
                :key="t"
                class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition"
                :class="activeType === t ? 'bg-navy-800 text-white' : 'bg-surface text-ink-soft shadow-card hover:text-ink'"
                @click="setType(t)"
            >{{ t }}</button>
        </div>

        <!-- Desktop table -->
        <div class="hidden overflow-hidden rounded-ral border border-line bg-surface shadow-card md:block">
            <table class="w-full text-sm">
                <thead class="border-b border-line bg-surface-muted text-left text-xs uppercase tracking-wide text-ink-soft">
                    <tr>
                        <th class="px-5 py-3 font-semibold">Serial</th>
                        <th class="px-5 py-3 font-semibold">Name</th>
                        <th class="px-5 py-3 font-semibold">Phone</th>
                        <th class="px-5 py-3 font-semibold">Visits</th>
                        <th class="px-5 py-3 text-right font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="c in clients.data" :key="c.id" class="hover:bg-surface-muted">
                        <td class="px-5 py-3"><span class="font-mono font-semibold text-primary">{{ c.serial_no }}</span></td>
                        <td class="px-5 py-3 font-medium text-ink">{{ c.name }}</td>
                        <td class="px-5 py-3 font-mono text-ink-soft">{{ c.phone }}</td>
                        <td class="px-5 py-3"><span class="rounded-full bg-surface-muted px-2.5 py-0.5 text-xs font-semibold text-ink-soft">{{ c.visits_count }}</span></td>
                        <td class="px-5 py-3">
                            <div class="flex items-center justify-end gap-3">
                                <Link :href="route('clients.show', c.id)" class="font-medium text-primary hover:text-primary-hover">View</Link>
                                <Link v-if="can.edit_client" :href="route('clients.edit', c.id)" class="font-medium text-ink-soft hover:text-ink">Edit</Link>
                                <button v-if="can.edit_client" class="font-medium text-danger hover:underline" @click="archive(c)">Archive</button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!clients.data.length">
                        <td colspan="5" class="px-5 py-12 text-center text-ink-soft">No clients found.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Mobile cards -->
        <div class="space-y-3 md:hidden">
            <Link
                v-for="c in clients.data"
                :key="c.id"
                :href="route('clients.show', c.id)"
                class="block rounded-ral border border-line bg-surface p-4 shadow-card"
            >
                <div class="flex items-start justify-between">
                    <div>
                        <div class="font-semibold text-ink">{{ c.name }}</div>
                        <div class="mt-0.5 font-mono text-sm text-ink-soft">{{ c.phone }}</div>
                    </div>
                    <span class="font-mono text-sm font-semibold text-primary">{{ c.serial_no }}</span>
                </div>
                <div class="mt-3 text-xs text-ink-soft">{{ c.visits_count }} visit(s)</div>
            </Link>
            <p v-if="!clients.data.length" class="rounded-ral border border-line bg-surface py-12 text-center text-ink-soft shadow-card">No clients found.</p>
        </div>

        <!-- Pagination -->
        <div v-if="clients.links.length > 3" class="mt-5 flex flex-wrap justify-center gap-1">
            <component
                :is="link.url ? Link : 'span'"
                v-for="link in clients.links"
                :key="link.label"
                :href="link.url"
                preserve-scroll
                class="min-w-9 rounded-ra px-3 py-2 text-center text-sm transition"
                :class="[
                    link.active ? 'bg-primary text-white' : 'bg-surface text-ink-soft shadow-card hover:text-ink',
                    !link.url && 'opacity-40',
                ]"
                v-html="link.label"
            />
        </div>
    </AdminLayout>
</template>
