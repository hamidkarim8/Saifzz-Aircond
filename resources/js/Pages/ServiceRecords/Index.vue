<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import DataTable from '@/Components/DataTable.vue';
import Badge from '@/Components/Badge.vue';
import { serviceVariant, statusVariant } from '@/lib/badges';

defineProps({ visits: Object });

const seesAllData = computed(() => usePage().props.auth.can.view_all_data);
const pageTitle    = computed(() => seesAllData.value ? 'Service Records' : 'My Jobs');
const pageSubtitle = computed(() => seesAllData.value ? 'Recorded visits, newest first.' : 'Service visits you performed.');

const money = (v) => 'RM ' + Number(v ?? 0).toFixed(2);
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-MY', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
const fmtTime = (d) => d ? new Date(d).toLocaleTimeString('en-MY', { hour: '2-digit', minute: '2-digit' }) : '';

const columns = [
    { key: 'visit_date', label: 'Date / Time', sortable: true },
    { key: 'client',     label: 'Client' },
    { key: 'serial',     label: 'Serial', headerClass: 'font-mono' },
    { key: 'lines',      label: 'Services' },
    { key: 'total_amount', label: 'Amount', sortable: true, align: 'right' },
    { key: 'status',     label: 'Status', align: 'center' },
    { key: 'actions',    label: '',        align: 'right' },
];
</script>

<template>
    <Head title="Service Records" />

    <AdminLayout>
        <template #header>
            <h1 class="text-lg font-bold tracking-tight text-navy-800">Service Records</h1>
        </template>

        <PageHeader :title="pageTitle" :subtitle="pageSubtitle">
            <template #actions>
                <Link
                    :href="route('service-records.create')"
                    class="inline-flex items-center gap-2 rounded-ra bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg>
                    New record
                </Link>
            </template>
        </PageHeader>

        <DataTable
            mode="server"
            route-name="service-records.index"
            :rows="visits.data"
            :pagination="visits"
            :columns="columns"
            searchable
            search-placeholder="Search client, serial or txn…"
        >
            <!-- Date / Time -->
            <template #cell-visit_date="{ row }">
                <span class="font-medium text-ink">{{ fmtDate(row.visit_date) }}</span>
                <span v-if="fmtTime(row.visit_date)" class="ml-1 text-xs text-ink-muted">{{ fmtTime(row.visit_date) }}</span>
            </template>

            <!-- Client name -->
            <template #cell-client="{ row }">
                <span class="font-medium text-ink">{{ row.client?.name ?? '—' }}</span>
            </template>

            <!-- Serial (mono) -->
            <template #cell-serial="{ row }">
                <span class="font-mono text-xs text-primary">#{{ row.client?.serial_no }}</span>
            </template>

            <!-- Service badges -->
            <template #cell-lines="{ row }">
                <span v-if="row.lines && row.lines.length" class="flex flex-wrap gap-1">
                    <Badge v-for="(line, i) in row.lines" :key="i" :variant="serviceVariant(line.service_type)">
                        {{ line.service_type }}
                    </Badge>
                </span>
                <span v-else-if="row.lines_count" class="text-ink-soft text-xs">{{ row.lines_count }} service(s)</span>
                <span v-else class="text-ink-muted text-xs">—</span>
            </template>

            <!-- Amount -->
            <template #cell-total_amount="{ row }">
                <span class="font-mono font-semibold text-navy-800">{{ money(row.total_amount) }}</span>
            </template>

            <!-- Status badge -->
            <template #cell-status="{ row }">
                <span v-if="row.transaction" class="flex flex-col items-center gap-0.5">
                    <Badge :variant="statusVariant(row.transaction.status)" class="capitalize">{{ row.transaction.status }}</Badge>
                    <span class="text-[10px] text-ink-muted">{{ row.transaction.method }}</span>
                </span>
                <span v-else class="text-ink-muted text-xs">—</span>
            </template>

            <!-- Actions -->
            <template #cell-actions="{ row }">
                <Link
                    :href="route('service-records.show', row.id)"
                    class="rounded-ra px-3 py-1.5 text-xs font-medium text-primary shadow-card hover:bg-surface-muted transition"
                >
                    View
                </Link>
            </template>

            <!-- Mobile card slot -->
            <template #card="{ row }">
                <Link :href="route('service-records.show', row.id)" class="block rounded-ral border border-line bg-surface p-4 shadow-card">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="font-semibold text-ink">{{ row.client?.name }}</div>
                            <div class="mt-0.5 text-sm text-ink-soft">
                                {{ fmtDate(row.visit_date) }}
                                <span class="font-mono text-xs text-primary ml-1">#{{ row.client?.serial_no }}</span>
                            </div>
                            <div v-if="row.lines && row.lines.length" class="mt-1 flex flex-wrap gap-1">
                                <Badge v-for="(line, i) in row.lines" :key="i" :variant="serviceVariant(line.service_type)">
                                    {{ line.service_type }}
                                </Badge>
                            </div>
                        </div>
                        <Badge v-if="row.transaction" :variant="statusVariant(row.transaction.status)" class="capitalize">
                            {{ row.transaction.status }}
                        </Badge>
                    </div>
                    <div class="mt-2 font-mono font-bold text-navy-800">{{ money(row.total_amount) }}</div>
                </Link>
            </template>

            <template #empty>No service records yet.</template>
        </DataTable>
    </AdminLayout>
</template>
