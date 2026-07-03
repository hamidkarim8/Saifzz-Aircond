<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { confirmAction, confirmWithReason } from '@/lib/swal';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import DataTable from '@/Components/DataTable.vue';
import Badge from '@/Components/Badge.vue';
import { serviceVariant, statusVariant } from '@/lib/badges';

const props = defineProps({ visits: Object, status: { type: String, default: 'all' } });

const seesAllData = computed(() => usePage().props.auth.can.view_all_data);
const pageTitle    = computed(() => seesAllData.value ? 'Service Records' : 'My Jobs');
const pageSubtitle = computed(() => seesAllData.value ? 'Recorded visits, newest first.' : 'Service visits you performed.');

const money = (v) => 'RM ' + Number(v ?? 0).toFixed(2);

const cancelRecord = async (row) => {
    const ok = await confirmAction({
        title: 'Cancel this record?',
        body: 'Voids the pending payment. Service history remains.',
        confirmText: 'Cancel record',
    });
    if (!ok) return;
    router.delete(route('service-records.destroy', row.id), {}, { preserveScroll: true });
};

const STATUSES = ['all', 'paid', 'pending', 'cancelled', 'void'];

const setStatus = (s) => {
    router.get(route('service-records.index'), { status: s }, { preserveState: true, replace: true });
};

const voidRecord = async (row) => {
    const reason = await confirmWithReason({
        title: 'Void this paid record?',
        body: 'Reverses the payment and removes it from the customer portal. Notes stay on file for audit.',
        confirmText: 'Void record',
    });
    if (!reason) return;
    router.delete(route('service-records.destroy', row.id), { data: { reason }, preserveScroll: true });
};
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-MY', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
const fmtTime = (d) => d ? new Date(d).toLocaleTimeString('en-MY', { hour: '2-digit', minute: '2-digit' }) : '';

const roleLabel = (r) => r ? r.charAt(0).toUpperCase() + r.slice(1) : '';

const columns = computed(() => [
    { key: 'visit_date', label: 'Date / Time', sortable: true },
    { key: 'txn_id',     label: 'Transaction ID' },
    { key: 'client',     label: 'Client' },
    { key: 'serial',     label: 'Serial', headerClass: 'font-mono' },
    { key: 'lines',      label: 'Services' },
    // Admins (all-data) see who recorded each visit; scoped techs only see their own.
    ...(seesAllData.value ? [{ key: 'creator', label: 'Created by' }] : []),
    { key: 'total_amount', label: 'Amount', sortable: true, align: 'right' },
    { key: 'status',     label: 'Status', align: 'center' },
    { key: 'actions',    label: '',        align: 'right' },
]);
</script>

<template>
    <Head :title="pageTitle" />

    <AdminLayout>
        <template #header>
            <h1 class="text-lg font-bold tracking-tight text-navy-800">{{ pageTitle }}</h1>
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
            :filter-params="{ status: props.status }"
            searchable
            search-placeholder="Search client, serial or txn…"
        >
            <template #filters>
                <div class="flex items-center gap-1">
                    <span class="mr-1 text-xs font-semibold text-ink-muted">Status</span>
                    <button
                        v-for="s in STATUSES"
                        :key="s"
                        class="rounded-ra px-2.5 py-1 text-xs font-semibold capitalize transition"
                        :class="props.status === s
                            ? 'bg-primary text-white shadow-card'
                            : 'border border-line bg-surface text-ink-soft hover:bg-surface-muted hover:text-ink'"
                        @click="setStatus(s)"
                    >
                        {{ s === 'all' ? 'All' : s }}
                    </button>
                </div>
            </template>

            <!-- Date / Time -->
            <template #cell-visit_date="{ row }">
                <span class="font-medium text-ink">{{ fmtDate(row.visit_date) }}</span>
                <span v-if="fmtTime(row.created_at)" class="ml-1 text-xs text-ink-muted">{{ fmtTime(row.created_at) }}</span>
            </template>

            <!-- Transaction ID -->
            <template #cell-txn_id="{ row }">
                <span class="font-mono text-xs text-ink-soft">{{ row.transaction?.txn_id ?? '—' }}</span>
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

            <!-- Created by (admin only) -->
            <template #cell-creator="{ row }">
                <span v-if="row.creator" class="text-sm text-ink">
                    {{ row.creator.name }}
                    <span class="text-xs text-ink-muted">({{ roleLabel(row.creator.role) }})</span>
                </span>
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
                <div class="flex items-center justify-end gap-2 whitespace-nowrap">
                    <Link
                        :href="route('service-records.show', row.id)"
                        class="rounded-ra px-3 py-1.5 text-xs font-medium text-primary shadow-card hover:bg-surface-muted transition"
                    >
                        View
                    </Link>
                    <Link
                        v-if="row.transaction?.status === 'pending'"
                        :href="route('service-records.edit', row.id)"
                        class="text-xs font-medium text-ink-soft hover:text-ink transition"
                    >
                        Edit
                    </Link>
                    <button
                        v-if="row.transaction?.status === 'pending'"
                        class="text-xs font-medium text-danger hover:underline transition"
                        @click="cancelRecord(row)"
                    >
                        Cancel
                    </button>
                    <button
                        v-if="row.transaction?.status === 'paid'"
                        class="text-xs font-medium text-danger hover:underline transition"
                        @click="voidRecord(row)"
                    >
                        Void
                    </button>
                </div>
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
                            <div v-if="row.transaction?.txn_id" class="mt-0.5 font-mono text-xs text-ink-muted">{{ row.transaction.txn_id }}</div>
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
