<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import DataTable from '@/Components/DataTable.vue';

const props = defineProps({
    clients: Object,
    filters: Object,
    serviceTypes: Array,
});

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

// View-only registry: serial, name, phone. Full detail lives behind View.
const columns = [
    { key: 'serial_no', label: 'Serial',       sortable: true,  cellClass: 'font-mono font-semibold text-primary' },
    { key: 'name',      label: 'Name',         sortable: true  },
    { key: 'phone',     label: 'Phone Number', sortable: false, cellClass: 'font-mono text-ink-soft' },
    { key: '_actions',  label: '',             sortable: false, align: 'right' },
];
</script>

<template>
    <Head title="Clients" />

    <AdminLayout>
        <template #header>
            <h1 class="text-lg font-bold tracking-tight text-navy-800">Clients</h1>
        </template>

        <PageHeader title="Clients" :subtitle="`${clients.total} client${clients.total !== 1 ? 's' : ''}`" />

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

            <!-- Actions column: view only -->
            <template #cell-_actions="{ row }">
                <div class="flex items-center justify-end">
                    <Link :href="route('clients.show', row.id)" class="font-medium text-primary hover:text-primary-hover">View</Link>
                </div>
            </template>

            <!-- Mobile card slot -->
            <template #card="{ row }">
                <div class="rounded-ral border border-line bg-surface p-4 shadow-card">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-semibold text-ink truncate">{{ row.name }}</div>
                            <div class="mt-0.5 font-mono text-sm text-ink-soft">{{ row.phone }}</div>
                        </div>
                        <span class="shrink-0 font-mono text-sm font-semibold text-primary">{{ row.serial_no }}</span>
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
