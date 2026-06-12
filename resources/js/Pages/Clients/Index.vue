<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import DataTable from '@/Components/DataTable.vue';
import Badge from '@/Components/Badge.vue';
import WarrantyPill from '@/Components/WarrantyPill.vue';
import { serviceVariant } from '@/lib/badges';
import { confirmDanger } from '@/lib/swal';

const props = defineProps({
    clients: Object,
    filters: Object,
    serviceTypes: Array,
});

const can = computed(() => usePage().props.auth.can ?? {});

const activeType = ref(props.filters.service_type ?? null);

const setType = (t) => {
    activeType.value = activeType.value === t ? null : t;
    router.get(
        route('clients.index'),
        {
            search: props.filters.search || undefined,
            service_type: activeType.value || undefined,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
};

const archive = async (client) => {
    if (await confirmDanger({
        title: `Archive ${client.serial_no}?`,
        body: 'History is preserved.',
        confirmText: 'Archive',
    })) {
        router.delete(route('clients.destroy', client.id), { preserveScroll: true });
    }
};

const formatDate = (val) => {
    if (!val) return '—';
    const d = new Date(val);
    return d.toLocaleDateString('en-MY', { day: 'numeric', month: 'short', year: 'numeric' });
};

const formatRM = (val) => (val != null ? `RM ${Number(val).toFixed(2)}` : '—');

const columns = [
    { key: 'serial_no',        label: 'Serial',       sortable: true,  cellClass: 'font-mono font-semibold text-primary' },
    { key: 'name',             label: 'Name',         sortable: true  },
    { key: 'phone',            label: 'Phone',        sortable: false, cellClass: 'font-mono text-ink-soft' },
    { key: 'last_service_date',label: 'Last Service', sortable: true,  formatter: formatDate },
    { key: 'service_types',    label: 'Services',     sortable: false },
    { key: 'units',            label: 'Units',        sortable: false, align: 'center' },
    { key: 'next_service_date',label: 'Next Service', sortable: true,  formatter: formatDate },
    { key: 'last_amount',      label: 'Amount',       sortable: true,  align: 'right', formatter: formatRM },
    { key: 'warranty_state',   label: 'Warranty',     sortable: false },
    { key: '_actions',         label: '',             sortable: false, align: 'right' },
];
</script>

<template>
    <Head title="Clients" />

    <AdminLayout>
        <template #header>
            <h1 class="text-lg font-bold tracking-tight text-navy-800">Clients</h1>
        </template>

        <PageHeader title="Clients" :subtitle="`${clients.total} client${clients.total !== 1 ? 's' : ''}`">
            <template #actions>
                <Link
                    v-if="can.edit_client"
                    :href="route('clients.create')"
                    class="inline-flex shrink-0 items-center justify-center gap-2 rounded-ra bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg>
                    New client
                </Link>
            </template>
        </PageHeader>

        <DataTable
            mode="server"
            route-name="clients.index"
            :pagination="clients"
            :rows="clients.data"
            :columns="columns"
            :filter-params="{ service_type: activeType || undefined }"
            searchable
            search-placeholder="Search name, serial or phone…"
            :per-page="10"
            :per-page-options="[10, 25, 50]"
        >
            <!-- Service-type filter tabs -->
            <template #filters>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="t in serviceTypes"
                        :key="t"
                        class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition"
                        :class="activeType === t
                            ? 'bg-navy-800 text-white'
                            : 'bg-surface text-ink-soft shadow-card hover:text-ink'"
                        @click="setType(t)"
                    >{{ t }}</button>
                </div>
            </template>

            <!-- Name column: name + address sub-line -->
            <template #cell-name="{ row }">
                <div class="font-medium text-ink">{{ row.name }}</div>
                <div v-if="row.address" class="text-xs text-ink-soft">{{ row.address }}</div>
            </template>

            <!-- Services column: badge per type -->
            <template #cell-service_types="{ row }">
                <div class="flex flex-wrap gap-1">
                    <Badge
                        v-for="type in row.service_types"
                        :key="type"
                        :variant="serviceVariant(type)"
                    >{{ type }}</Badge>
                    <span v-if="!row.service_types?.length" class="text-ink-muted">—</span>
                </div>
            </template>

            <!-- Units column -->
            <template #cell-units="{ row }">
                <span v-if="row.units">{{ row.units }}</span>
                <span v-else class="text-ink-muted">—</span>
            </template>

            <!-- Next service date: shown as a badge when present -->
            <template #cell-next_service_date="{ row }">
                <Badge v-if="row.next_service_date" variant="blue">{{ formatDate(row.next_service_date) }}</Badge>
                <span v-else class="text-ink-muted">—</span>
            </template>

            <!-- Warranty column -->
            <template #cell-warranty_state="{ row }">
                <WarrantyPill :state="row.warranty_state" :label="row.warranty_label" />
            </template>

            <!-- Actions column -->
            <template #cell-_actions="{ row }">
                <div class="flex items-center justify-end gap-3">
                    <Link :href="route('clients.show', row.id)" class="font-medium text-primary hover:text-primary-hover">View</Link>
                    <Link v-if="can.edit_client" :href="route('clients.edit', row.id)" class="font-medium text-ink-soft hover:text-ink">Edit</Link>
                    <button v-if="can.edit_client" class="font-medium text-danger hover:underline" @click="archive(row)">Archive</button>
                </div>
            </template>

            <!-- Mobile card slot -->
            <template #card="{ row }">
                <div class="rounded-ral border border-line bg-surface p-4 shadow-card">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-semibold text-ink truncate">{{ row.name }}</div>
                            <div class="mt-0.5 font-mono text-sm text-ink-soft">{{ row.phone }}</div>
                            <div v-if="row.address" class="mt-0.5 text-xs text-ink-soft truncate">{{ row.address }}</div>
                        </div>
                        <span class="shrink-0 font-mono text-sm font-semibold text-primary">{{ row.serial_no }}</span>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <span v-if="row.last_service_date" class="text-xs text-ink-soft">Last: {{ formatDate(row.last_service_date) }}</span>
                        <WarrantyPill :state="row.warranty_state" :label="row.warranty_label" />
                    </div>
                    <div class="mt-3">
                        <Link :href="route('clients.show', row.id)" class="text-sm font-medium text-primary hover:text-primary-hover">View →</Link>
                    </div>
                </div>
            </template>

            <template #empty>No clients found.</template>
        </DataTable>
    </AdminLayout>
</template>
