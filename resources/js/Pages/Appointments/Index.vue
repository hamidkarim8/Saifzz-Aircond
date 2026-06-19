<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';
import DataTable from '@/Components/DataTable.vue';
import Badge from '@/Components/Badge.vue';
import MonthCalendar from './Partials/MonthCalendar.vue';
import AppointmentModal from './Partials/AppointmentModal.vue';
import { statusVariant } from '@/lib/badges';

const waLink = (phone) => {
    if (!phone) return null;
    const digits = phone.replace(/\D/g, '');
    const number = digits.startsWith('0') ? '6' + digits : digits;
    return `https://wa.me/${number}`;
};
import { confirmAction, toast } from '@/lib/swal';

const props = defineProps({
    appointments: { type: Array, default: () => [] },   // full-month collection for calendar
    table:        { type: Object, default: null },       // Laravel paginator for DataTable
    today:        { type: Array, default: () => [] },
    month:        { type: String, required: true },
    stats:        { type: Object, default: () => ({}) },
    transitions:  { type: Object, default: () => ({}) },
    presetClient: { type: Object, default: null },
    technicians:  { type: Array, default: null },
});

const modalOpen  = ref(false);
const editing    = ref(null);
const selectedDay = ref(null);

const openNew  = () => { editing.value = null; modalOpen.value = true; };
const openEdit = (a) => { editing.value = a;   modalOpen.value = true; };
if (props.presetClient) openNew();

const onModalClose = () => {
    modalOpen.value = false;
    // Opened from a client profile (or reminder) → return there on cancel.
    if (props.presetClient && !editing.value) {
        router.visit(route('clients.show', props.presetClient.id));
    }
};

// Month navigation
const shiftMonth = (delta) => {
    const [y, m] = props.month.split('-').map(Number);
    const d    = new Date(y, m - 1 + delta, 1);
    const next = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    router.get(route('appointments.index', { month: next }), {}, { preserveState: false });
};

// datetime is a UTC-tagged ISO string but represents local wall-clock intent;
// parse the raw date part so `new Date()` tz-conversion can't shift the day (off-by-one).
const wallDate  = (dt) => { const [y, m, d] = (dt ?? '').slice(0, 10).split('-').map(Number); return new Date(y, m - 1, d); };

// Selected-day panel
const dayList   = computed(() =>
    selectedDay.value === null ? [] :
    props.appointments.filter((a) => wallDate(a.datetime).getDate() === selectedDay.value)
);
const selectDay = (day) => { selectedDay.value = selectedDay.value === day ? null : day; };

// Formatters
const fmtDate   = (dt) => wallDate(dt).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
const fmtTime   = (dt) => (dt ?? '').slice(11, 16);
const cap       = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : s);
const monthLabel = computed(() => {
    const [y, m] = props.month.split('-').map(Number);
    return new Date(y, m - 1, 1).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
});

// Status actions
const setStatus = async (a, status) => {
    const label = { cancelled: 'cancel', completed: 'complete' }[status] ?? status;
    const ok = await confirmAction({
        title: 'Update appointment?',
        body:  `This will <strong>${label}</strong> the appointment.`,
        confirmText: 'Update',
    });
    if (!ok) return;
    router.patch(route('appointments.status', a.id), { status }, {
        preserveScroll: true,
        onError: (errors) => {
            const msg = errors?.status ?? errors?.message ?? 'Could not update appointment status.';
            toast.error(msg);
        },
    });
};

// DataTable columns
const columns = [
    { key: 'datetime',     label: 'Date / Time',  sortable: true },
    { key: 'client',       label: 'Client' },
    { key: 'serial',       label: 'Serial' },
    { key: 'phone',        label: 'Contact' },
    { key: 'technician',   label: 'Technician' },
    { key: 'address',      label: 'Address' },
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
                :sub="`${stats.month_completed ?? 0} completed`">
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
            <StatCard label="Completed" :value="stats.month_completed ?? 0" variant="ok"
                :sub="'done this month'">
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
            <!-- Calendar -->
            <div>
                <MonthCalendar
                    :month="month"
                    :appointments="appointments"
                    :selected-day="selectedDay"
                    @select="selectDay"
                    @prev="shiftMonth(-1)"
                    @next="shiftMonth(1)"
                />
            </div>

            <!-- Selected-day panel -->
            <div class="rounded-ral border border-line bg-surface p-4 shadow-card">
                <h3 class="mb-3 text-sm font-bold text-navy-800">
                    <template v-if="selectedDay !== null">{{ selectedDay }} {{ monthLabel }}</template>
                    <template v-else>Select a day</template>
                </h3>
                <div v-if="selectedDay !== null && dayList.length" class="space-y-2">
                    <button
                        v-for="a in dayList"
                        :key="a.id"
                        class="flex w-full flex-wrap items-center gap-2 rounded-ra bg-surface-muted px-3 py-2.5 text-left text-sm transition hover:bg-primary-50"
                        @click="openEdit(a)"
                    >
                        <span class="font-mono font-semibold text-primary">{{ fmtTime(a.datetime) }}</span>
                        <span class="font-medium text-ink">{{ a.client?.name ?? a.customer_name ?? 'Walk-in' }}</span>
                        <Badge class="ml-auto" :variant="statusVariant(cap(a.status))">{{ cap(a.status) }}</Badge>
                    </button>
                </div>
                <p v-else-if="selectedDay !== null" class="py-3 text-center text-sm text-ink-muted">No appointments on this date.</p>
                <p v-else class="py-3 text-center text-sm text-ink-muted">Click a day on the calendar.</p>
            </div>

            <!-- Today's schedule sidebar -->
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
                        <span class="min-w-0 flex-1 truncate text-ink">{{ a.client?.name ?? a.customer_name ?? 'Walk-in' }}</span>
                    </button>
                </div>
                <p v-else class="mt-3 text-sm text-ink-muted">No appointments today.</p>
            </div>
        </div>

        <!-- Month appointments DataTable -->
        <div class="mt-6">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-bold text-navy-800">Appointments — {{ monthLabel }}</h2>
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
                    <div class="font-medium text-ink">{{ row.client?.name ?? row.customer_name ?? 'Walk-in' }}</div>
                </template>

                <!-- Serial -->
                <template #cell-serial="{ row }">
                    <Link v-if="row.client" :href="route('clients.show', row.client.id)" class="font-mono text-xs text-primary hover:underline">#{{ row.client.serial_no }}</Link>
                    <span v-else class="text-xs text-ink-soft">Non client</span>
                </template>

                <!-- Contact -->
                <template #cell-phone="{ value }">
                    <a v-if="waLink(value)" :href="waLink(value)" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-mono text-xs text-ok hover:underline">
                        <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.124.558 4.118 1.532 5.849L.057 23.526a.5.5 0 0 0 .611.658l5.849-1.531A11.946 11.946 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.896 0-3.671-.497-5.206-1.367l-.373-.215-3.872 1.014 1.013-3.799-.234-.389A9.946 9.946 0 0 1 2 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/></svg>
                        {{ value }}
                    </a>
                    <span v-else class="font-mono text-xs text-ink-soft">—</span>
                </template>

                <!-- Technician -->
                <template #cell-technician="{ row }">
                    <span class="text-sm text-ink">{{ row.technician?.name ?? '—' }}</span>
                </template>

                <!-- Address -->
                <template #cell-address="{ value }">
                    <span class="max-w-[16rem] truncate text-ink-soft">{{ value }}</span>
                </template>

                <!-- Status -->
                <template #cell-status="{ value }">
                    <Badge :variant="statusVariant(cap(value))">{{ cap(value) }}</Badge>
                </template>

                <!-- Actions -->
                <template #cell-actions="{ row }">
                    <div class="flex items-center justify-end gap-2 whitespace-nowrap text-xs font-medium">
                        <Link
                            v-if="row.client"
                            :href="route('service-records.create', { client: row.client.id, technician_id: row.technician_id, appointment: row.id })"
                            class="text-ok hover:text-ok/80"
                        >Add Record</Link>
                        <button class="text-primary hover:text-primary-hover" @click="openEdit(row)">Edit</button>
                        <button
                            v-if="(transitions[row.status] ?? []).includes('cancelled')"
                            class="text-danger hover:underline"
                            @click="setStatus(row, 'cancelled')"
                        >Cancel Appointment</button>
                    </div>
                </template>

                <!-- Mobile card -->
                <template #card="{ row }">
                    <div class="rounded-ral border border-line bg-surface p-4 shadow-card">
                        <div class="mb-2 flex items-start justify-between gap-2">
                            <div>
                                <div class="font-medium text-ink">{{ row.client?.name ?? row.customer_name ?? 'Walk-in' }}</div>
                                <Link v-if="row.client" :href="route('clients.show', row.client.id)" class="font-mono text-xs text-primary hover:underline">#{{ row.client.serial_no }}</Link>
                                <span v-else class="font-mono text-xs text-ink-soft">Non client</span>
                            </div>
                            <Badge :variant="statusVariant(cap(row.status))">{{ cap(row.status) }}</Badge>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-xs text-ink-muted">
                            <span class="font-mono font-semibold text-primary">{{ fmtDate(row.datetime) }} {{ fmtTime(row.datetime) }}</span>
                        </div>
                        <div class="mt-2 text-xs text-ink-soft">
                            <a v-if="waLink(row.phone)" :href="waLink(row.phone)" target="_blank" rel="noopener" class="inline-flex items-center gap-0.5 text-ok hover:underline">
                                <svg class="h-3 w-3 shrink-0" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.124.558 4.118 1.532 5.849L.057 23.526a.5.5 0 0 0 .611.658l5.849-1.531A11.946 11.946 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.896 0-3.671-.497-5.206-1.367l-.373-.215-3.872 1.014 1.013-3.799-.234-.389A9.946 9.946 0 0 1 2 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/></svg>
                                {{ row.phone }}
                            </a>
                            <span v-else>—</span>
                            · {{ row.address }}
                        </div>
                        <div class="mt-3 flex items-center gap-2 text-xs font-medium">
                            <Link v-if="row.client" :href="route('service-records.create', { client: row.client.id, technician_id: row.technician_id, appointment: row.id })" class="text-ok hover:text-ok/80">Add Record</Link>
                            <button class="text-primary hover:text-primary-hover" @click="openEdit(row)">Edit</button>
                            <button
                                v-if="(transitions[row.status] ?? []).includes('cancelled')"
                                class="text-danger hover:underline"
                                @click="setStatus(row, 'cancelled')"
                            >Cancel Appointment</button>
                        </div>
                    </div>
                </template>

                <template #empty>No appointments this month.</template>
            </DataTable>
        </div>

        <AppointmentModal
            :open="modalOpen"
            :appointment="editing"
            :preset-client="presetClient"
            :technicians="technicians"
            @saved="modalOpen = false"
            @close="onModalClose"
        />
    </AdminLayout>
</template>
