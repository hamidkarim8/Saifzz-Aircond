<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import MonthCalendar from './Partials/MonthCalendar.vue';
import AppointmentModal from './Partials/AppointmentModal.vue';

const props = defineProps({
    appointments: { type: Array, default: () => [] }, // selected month, ordered by datetime
    today: { type: Array, default: () => [] },
    month: { type: String, required: true },          // 'YYYY-MM'
    stats: { type: Object, default: () => ({}) },
    serviceTypes: { type: Array, default: () => [] },
    transitions: { type: Object, default: () => ({}) },
    presetClient: { type: Object, default: null },
});

const modalOpen = ref(false);
const editing = ref(null);
const selectedDay = ref(null);

const openNew = () => { editing.value = null; modalOpen.value = true; };
const openEdit = (a) => { editing.value = a; modalOpen.value = true; };
// If we arrived with a preset client, open the modal straight away.
if (props.presetClient) openNew();

// ── Month navigation (server round-trip keeps the data month-scoped) ──
const shiftMonth = (delta) => {
    const [y, m] = props.month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    const next = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    router.get(route('appointments.index', { month: next }), {}, { preserveState: false });
};

// ── Selected-day panel ──
const dayList = computed(() => {
    if (!selectedDay.value) return [];
    return props.appointments.filter((a) => new Date(a.datetime).getDate() === selectedDay.value);
});
const selectDay = (day) => { selectedDay.value = selectedDay.value === day ? null : day; };

// ── Formatting ──
const fmtDate = (dt) => new Date(dt).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
const fmtTime = (dt) => (dt ?? '').slice(11, 16); // 'HH:MM' — slice avoids tz drift
const money = (v) => (v == null ? '—' : 'RM ' + Number(v).toFixed(2));

const statusBadge = {
    pending: 'bg-warn-bg text-warn',
    confirmed: 'bg-ok-bg text-ok',
    done: 'bg-primary-50 text-primary',
    cancelled: 'bg-surface-muted text-ink-soft',
};
const typeBadge = {
    Cleaning: 'bg-primary-50 text-primary',
    'Gas Top-Up': 'bg-warn-bg text-warn',
    Repair: 'bg-danger-bg text-danger',
    Installation: 'bg-ok-bg text-ok',
    Troubleshoot: 'bg-invoice-bg text-invoice',
};
const statusVerb = { confirmed: 'Confirm', done: 'Mark done', cancelled: 'Cancel' };

const setStatus = (a, status) => {
    if (status === 'cancelled' && !confirm('Cancel this appointment?')) return;
    router.patch(route('appointments.status', a.id), { status }, { preserveScroll: true });
};
</script>

<template>
    <Head title="Appointments" />

    <AdminLayout>
        <template #header>
            <h1 class="text-lg font-bold tracking-tight text-navy-800">Appointments</h1>
        </template>

        <div class="mb-5 flex items-center justify-between">
            <p class="text-sm text-ink-soft">Schedule and track visits. Click a date to see that day's appointments.</p>
            <button class="inline-flex items-center gap-2 rounded-ra bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover" @click="openNew">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg>
                New appointment
            </button>
        </div>

        <!-- Calendar + day panel | summary -->
        <div class="grid gap-5 lg:grid-cols-3">
            <div class="lg:col-span-2 space-y-4">
                <MonthCalendar
                    :month="month"
                    :appointments="appointments"
                    :selected-day="selectedDay"
                    @select="selectDay"
                    @prev="shiftMonth(-1)"
                    @next="shiftMonth(1)"
                />

                <!-- Selected-day list -->
                <div v-if="selectedDay" class="rounded-ral border border-line bg-surface p-4 shadow-card">
                    <h3 class="mb-3 text-sm font-bold text-navy-800">{{ selectedDay }} {{ month }}</h3>
                    <div v-if="dayList.length" class="space-y-2">
                        <button
                            v-for="a in dayList"
                            :key="a.id"
                            class="flex w-full items-center gap-3 rounded-ra bg-surface-muted px-3 py-2.5 text-left text-sm transition hover:bg-primary-50"
                            @click="openEdit(a)"
                        >
                            <span class="font-mono font-semibold text-primary">{{ fmtTime(a.datetime) }}</span>
                            <span class="font-medium text-ink">{{ a.client?.name ?? 'Walk-in' }}</span>
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="typeBadge[a.service_type]">{{ a.service_type }}</span>
                            <span class="ml-auto rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="statusBadge[a.status]">{{ a.status }}</span>
                        </button>
                    </div>
                    <p v-else class="py-3 text-center text-sm text-ink-muted">No appointments on this date.</p>
                </div>
            </div>

            <!-- Summary stats -->
            <div class="space-y-4">
                <div class="rounded-ral border border-line bg-surface p-5 shadow-card">
                    <div class="text-xs font-semibold uppercase tracking-wide text-ink-muted">This month</div>
                    <div class="mt-1 text-2xl font-bold text-primary">{{ stats.month_total ?? 0 }} appointments</div>
                    <div class="mt-1 text-sm text-ink-soft">{{ stats.month_confirmed ?? 0 }} confirmed · {{ stats.month_pending ?? 0 }} pending</div>
                </div>
                <div class="rounded-ral border border-line bg-surface p-5 shadow-card">
                    <div class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Today</div>
                    <div class="mt-1 text-2xl font-bold text-navy-800">{{ stats.today_total ?? 0 }} appointments</div>
                    <div v-if="today.length" class="mt-3 space-y-1.5">
                        <div v-for="a in today" :key="a.id" class="rounded-ra bg-surface-muted px-3 py-2 text-[13px]">
                            <span class="font-mono font-semibold text-primary">{{ fmtTime(a.datetime) }}</span>
                            <span class="ml-2 text-ink">{{ a.client?.name ?? 'Walk-in' }} — {{ a.service_type }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Full month table -->
        <div class="mt-5 overflow-hidden rounded-ral border border-line bg-surface shadow-card">
            <header class="border-b border-line px-5 py-3 font-bold text-navy-800">All appointments — {{ month }}</header>

            <div v-if="appointments.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-line text-left text-xs uppercase tracking-wide text-ink-muted">
                            <th class="px-5 py-3 font-semibold">Date &amp; time</th>
                            <th class="px-5 py-3 font-semibold">Client</th>
                            <th class="px-5 py-3 font-semibold">Contact</th>
                            <th class="px-5 py-3 font-semibold">Service</th>
                            <th class="px-5 py-3 font-semibold">Address</th>
                            <th class="px-5 py-3 text-right font-semibold">Amount</th>
                            <th class="px-5 py-3 font-semibold">Status</th>
                            <th class="px-5 py-3 text-right font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="a in appointments" :key="a.id" class="hover:bg-surface-muted/50">
                            <td class="px-5 py-3 whitespace-nowrap">
                                <div class="font-medium text-ink">{{ fmtDate(a.datetime) }}</div>
                                <div class="font-mono text-xs text-ink-muted">{{ fmtTime(a.datetime) }}</div>
                            </td>
                            <td class="px-5 py-3">
                                <div class="font-medium text-ink">{{ a.client?.name ?? 'Walk-in' }}</div>
                                <div v-if="a.client" class="font-mono text-xs text-primary">#{{ a.client.serial_no }}</div>
                            </td>
                            <td class="px-5 py-3 font-mono text-xs text-ink-soft">{{ a.phone }}</td>
                            <td class="px-5 py-3">
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="typeBadge[a.service_type]">{{ a.service_type }}</span>
                                <span v-if="a.units" class="ml-1 text-xs text-ink-muted">×{{ a.units }}</span>
                            </td>
                            <td class="px-5 py-3 max-w-[16rem] truncate text-ink-soft">{{ a.address }}</td>
                            <td class="px-5 py-3 text-right font-mono font-semibold text-navy-800">{{ money(a.amount) }}</td>
                            <td class="px-5 py-3"><span class="rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="statusBadge[a.status]">{{ a.status }}</span></td>
                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-2 whitespace-nowrap text-xs font-medium">
                                    <button class="text-primary hover:text-primary-hover" @click="openEdit(a)">Edit</button>
                                    <button
                                        v-for="next in (transitions[a.status] ?? [])"
                                        :key="next"
                                        class="hover:underline"
                                        :class="next === 'cancelled' ? 'text-danger' : 'text-ok'"
                                        @click="setStatus(a, next)"
                                    >{{ statusVerb[next] }}</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="px-5 py-10 text-center text-sm text-ink-muted">No appointments this month. Click “New appointment” to schedule one.</p>
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
