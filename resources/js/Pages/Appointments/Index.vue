<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';
import DataTable from '@/Components/DataTable.vue';
import Badge from '@/Components/Badge.vue';
import MonthCalendar from './Partials/MonthCalendar.vue';
import AppointmentModal from './Partials/AppointmentModal.vue';
import { serviceVariant, statusVariant } from '@/lib/badges';
import { confirmAction } from '@/lib/swal';

const props = defineProps({
    appointments: { type: Array, default: () => [] },   // full-month collection for calendar
    table:        { type: Object, default: null },       // Laravel paginator for DataTable
    today:        { type: Array, default: () => [] },
    month:        { type: String, required: true },
    stats:        { type: Object, default: () => ({}) },
    serviceTypes: { type: Array, default: () => [] },
    transitions:  { type: Object, default: () => ({}) },
    presetClient: { type: Object, default: null },
});

const modalOpen  = ref(false);
const editing    = ref(null);
const selectedDay = ref(null);

const openNew  = () => { editing.value = null; modalOpen.value = true; };
const openEdit = (a) => { editing.value = a;   modalOpen.value = true; };
if (props.presetClient) openNew();

// Month navigation
const shiftMonth = (delta) => {
    const [y, m] = props.month.split('-').map(Number);
    const d    = new Date(y, m - 1 + delta, 1);
    const next = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    router.get(route('appointments.index', { month: next }), {}, { preserveState: false });
};

// Selected-day panel
const dayList   = computed(() =>
    selectedDay.value === null ? [] :
    props.appointments.filter((a) => new Date(a.datetime).getDate() === selectedDay.value)
);
const selectDay = (day) => { selectedDay.value = selectedDay.value === day ? null : day; };

// Formatters
const fmtDate   = (dt) => new Date(dt).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
const fmtTime   = (dt) => (dt ?? '').slice(11, 16);
const money     = (v)  => (v == null ? '—' : 'RM ' + Number(v).toFixed(2));

// Status actions
const setStatus = async (a, status) => {
    const label = { confirmed: 'confirm', done: 'mark as done', cancelled: 'cancel' }[status] ?? status;
    const ok = await confirmAction({
        title: 'Update appointment?',
        body:  `This will <strong>${label}</strong> the appointment.`,
        confirmText: 'Update',
    });
    if (!ok) return;
    router.patch(route('appointments.status', a.id), { status }, { preserveScroll: true });
};

// DataTable columns
const columns = [
    { key: 'datetime',     label: 'Date / Time',  sortable: true },
    { key: 'client',       label: 'Client' },
    { key: 'phone',        label: 'Contact' },
    { key: 'service_type', label: 'Service' },
    { key: 'address',      label: 'Address' },
    { key: 'units',        label: 'Units',   align: 'center' },
    { key: 'amount',       label: 'Amount',  align: 'right' },
    { key: 'status',       label: 'Status' },
    { key: 'actions',      label: '' },
];
</script>

<template>
    <Head title="Appointments" />

    <AdminLayout>
        <PageHeader
            title="Appointments"
            subtitle="Schedule and track service visits."
        >
            <template #actions>
                <button
                    class="inline-flex items-center gap-2 rounded-ra bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover"
                    @click="openNew"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="M12 5v14M5 12h14" stroke-linecap="round" />
                    </svg>
                    New appointment
                </button>
            </template>
        </PageHeader>

        <!-- Stat cards -->
        <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
            <StatCard label="This month" :value="stats.month_total ?? 0" variant="primary"
                :sub="`${stats.month_confirmed ?? 0} confirmed`">
                <template #icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" stroke-linecap="round" />
                    </svg>
                </template>
            </StatCard>
            <StatCard label="Pending" :value="stats.month_pending ?? 0" variant="warn"
                :sub="'awaiting confirmation'">
                <template #icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10" /><path d="M12 6v6l4 2" stroke-linecap="round" />
                    </svg>
                </template>
            </StatCard>
            <StatCard label="Confirmed" :value="stats.month_confirmed ?? 0" variant="ok"
                :sub="'ready to go'">
                <template #icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 6L9 17l-5-5" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </template>
            </StatCard>
            <StatCard label="Today" :value="stats.today_total ?? 0" variant="primary"
                sub="scheduled today">
                <template #icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10" /><path d="M12 8v4l3 3" stroke-linecap="round" />
                    </svg>
                </template>
            </StatCard>
        </div>

        <!-- Calendar + day panel + today sidebar -->
        <div class="grid gap-5 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <MonthCalendar
                    :month="month"
                    :appointments="appointments"
                    :selected-day="selectedDay"
                    @select="selectDay"
                    @prev="shiftMonth(-1)"
                    @next="shiftMonth(1)"
                />

                <!-- Selected-day panel -->
                <div v-if="selectedDay !== null" class="rounded-ral border border-line bg-surface p-4 shadow-card">
                    <h3 class="mb-3 text-sm font-bold text-navy-800">
                        {{ selectedDay }} {{ month }}
                    </h3>
                    <div v-if="dayList.length" class="space-y-2">
                        <button
                            v-for="a in dayList"
                            :key="a.id"
                            class="flex w-full items-center gap-3 rounded-ra bg-surface-muted px-3 py-2.5 text-left text-sm transition hover:bg-primary-50"
                            @click="openEdit(a)"
                        >
                            <span class="font-mono font-semibold text-primary">{{ fmtTime(a.datetime) }}</span>
                            <span class="font-medium text-ink">{{ a.client?.name ?? 'Walk-in' }}</span>
                            <Badge :variant="serviceVariant(a.service_type)">{{ a.service_type }}</Badge>
                            <Badge class="ml-auto" :variant="statusVariant(a.status.charAt(0).toUpperCase() + a.status.slice(1))">{{ a.status }}</Badge>
                        </button>
                    </div>
                    <p v-else class="py-3 text-center text-sm text-ink-muted">No appointments on this date.</p>
                </div>
            </div>

            <!-- Today's schedule sidebar -->
            <div class="space-y-4">
                <div class="rounded-ral border border-line bg-surface p-5 shadow-card">
                    <div class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Today's schedule</div>
                    <div class="mt-1 text-xl font-bold text-navy-800">{{ stats.today_total ?? 0 }} appointment{{ (stats.today_total ?? 0) !== 1 ? 's' : '' }}</div>
                    <div v-if="today.length" class="mt-3 space-y-2">
                        <button
                            v-for="a in today"
                            :key="a.id"
                            class="flex w-full items-center gap-2 rounded-ra bg-surface-muted px-3 py-2 text-left text-[13px] transition hover:bg-primary-50"
                            @click="openEdit(a)"
                        >
                            <span class="font-mono font-semibold text-primary">{{ fmtTime(a.datetime) }}</span>
                            <span class="min-w-0 flex-1 truncate text-ink">{{ a.client?.name ?? 'Walk-in' }} — {{ a.service_type }}</span>
                        </button>
                    </div>
                    <p v-else class="mt-3 text-sm text-ink-muted">No appointments today.</p>
                </div>
            </div>
        </div>

        <!-- Month appointments DataTable -->
        <div class="mt-6">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-bold text-navy-800">All appointments — {{ month }}</h2>
            </div>
            <DataTable
                mode="server"
                route-name="appointments.index"
                :pagination="table"
                :columns="columns"
                :rows="table?.data ?? []"
                :filter-params="{ month }"
                search-placeholder="Search client, phone, address…"
                :searchable="true"
            >
                <!-- Date/time -->
                <template #cell-datetime="{ value }">
                    <div class="font-medium text-ink">{{ fmtDate(value) }}</div>
                    <div class="font-mono text-xs text-ink-muted">{{ fmtTime(value) }}</div>
                </template>

                <!-- Client -->
                <template #cell-client="{ row }">
                    <div class="font-medium text-ink">{{ row.client?.name ?? 'Walk-in' }}</div>
                    <div v-if="row.client" class="font-mono text-xs text-primary">#{{ row.client.serial_no }}</div>
                </template>

                <!-- Contact -->
                <template #cell-phone="{ value }">
                    <span class="font-mono text-xs text-ink-soft">{{ value }}</span>
                </template>

                <!-- Service -->
                <template #cell-service_type="{ row, value }">
                    <Badge :variant="serviceVariant(value)">{{ value }}</Badge>
                    <span v-if="row.units" class="ml-1 text-xs text-ink-muted">×{{ row.units }}</span>
                </template>

                <!-- Address -->
                <template #cell-address="{ value }">
                    <span class="max-w-[16rem] truncate text-ink-soft">{{ value }}</span>
                </template>

                <!-- Units -->
                <template #cell-units="{ value }">
                    <span class="text-ink-soft">{{ value ?? '—' }}</span>
                </template>

                <!-- Amount -->
                <template #cell-amount="{ value }">
                    <span class="font-mono font-semibold text-navy-800">{{ money(value) }}</span>
                </template>

                <!-- Status -->
                <template #cell-status="{ value }">
                    <Badge :variant="statusVariant(value.charAt(0).toUpperCase() + value.slice(1))">{{ value }}</Badge>
                </template>

                <!-- Actions -->
                <template #cell-actions="{ row }">
                    <div class="flex items-center justify-end gap-2 whitespace-nowrap text-xs font-medium">
                        <button class="text-primary hover:text-primary-hover" @click="openEdit(row)">Edit</button>
                        <button
                            v-for="next in (transitions[row.status] ?? [])"
                            :key="next"
                            class="hover:underline"
                            :class="next === 'cancelled' ? 'text-danger' : 'text-ok'"
                            @click="setStatus(row, next)"
                        >{{ { confirmed: 'Confirm', done: 'Mark done', cancelled: 'Cancel' }[next] }}</button>
                    </div>
                </template>

                <!-- Mobile card -->
                <template #card="{ row }">
                    <div class="rounded-ral border border-line bg-surface p-4 shadow-card">
                        <div class="mb-2 flex items-start justify-between gap-2">
                            <div>
                                <div class="font-medium text-ink">{{ row.client?.name ?? 'Walk-in' }}</div>
                                <div v-if="row.client" class="font-mono text-xs text-primary">#{{ row.client.serial_no }}</div>
                            </div>
                            <Badge :variant="statusVariant(row.status.charAt(0).toUpperCase() + row.status.slice(1))">{{ row.status }}</Badge>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-xs text-ink-muted">
                            <span class="font-mono font-semibold text-primary">{{ fmtDate(row.datetime) }} {{ fmtTime(row.datetime) }}</span>
                            <Badge :variant="serviceVariant(row.service_type)">{{ row.service_type }}</Badge>
                        </div>
                        <div class="mt-2 text-xs text-ink-soft">{{ row.phone }} · {{ row.address }}</div>
                        <div class="mt-3 flex items-center gap-2 text-xs font-medium">
                            <button class="text-primary hover:text-primary-hover" @click="openEdit(row)">Edit</button>
                            <button
                                v-for="next in (transitions[row.status] ?? [])"
                                :key="next"
                                class="hover:underline"
                                :class="next === 'cancelled' ? 'text-danger' : 'text-ok'"
                                @click="setStatus(row, next)"
                            >{{ { confirmed: 'Confirm', done: 'Mark done', cancelled: 'Cancel' }[next] }}</button>
                        </div>
                    </div>
                </template>

                <template #empty>No appointments this month.</template>
            </DataTable>
        </div>

        <AppointmentModal
            :open="modalOpen"
            :appointment="editing"
            :service-types="serviceTypes"
            :preset-client="presetClient"
            @close="modalOpen = false"
        />
    </AdminLayout>
</template>
