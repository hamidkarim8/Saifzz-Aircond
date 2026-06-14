<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import Badge from '@/Components/Badge.vue';
import { waLink as wa } from '@/lib/whatsapp';
import { serviceVariant } from '@/lib/badges';
import { confirmAction } from '@/lib/swal';

const props = defineProps({
    overdue: { type: Array, default: () => [] },
    due_this_month: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
});

const can = computed(() => usePage().props.auth.can ?? {});

// Date-only formatting via string slice — avoids the UTC-midnight tz drift of new Date('YYYY-MM-DD').
const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const fmtDate = (d) => {
    if (!d) return '—';
    const [y, m, day] = d.slice(0, 10).split('-');
    return `${day} ${months[+m - 1]} ${y}`;
};

// wa.me click-to-chat with a prefilled reminder (module 11 — shared builder).
const waLink = (item) =>
    wa(item.phone, `Hi ${item.name}, this is Saifzz Aircond Services. Your aircond service (#${item.serial_no}) is due on ${fmtDate(item.next_due)}. Reply to schedule a visit.`);

const setAppointment = (item) => route('appointments.index', { client: item.client_id });

const toggleContacted = (item) =>
    router.patch(route('reminders.contacted', item.client_id), {}, { preserveScroll: true });

// FEAT-008: search filter
const search = ref('');
const filterItems = (items) => {
    const q = search.value.trim().toLowerCase();
    if (!q) return items;
    return items.filter(i =>
        i.name?.toLowerCase().includes(q) ||
        i.phone?.toLowerCase().includes(q) ||
        i.serial_no?.toLowerCase().includes(q)
    );
};

// FEAT-009: tabs — default active tab is "due"
const activeTab = ref('due');
const filteredDue = computed(() => filterItems(props.due_this_month));
const filteredOverdue = computed(() => filterItems(props.overdue));
const activeItems = computed(() => activeTab.value === 'due' ? filteredDue.value : filteredOverdue.value);
const isDue = computed(() => activeTab.value === 'due');

const isEmpty = computed(() => props.overdue.length === 0 && props.due_this_month.length === 0);

// FEAT-010: dismiss reminder
const dismissReminder = async (item) => {
    const ok = await confirmAction({
        title: 'Dismiss reminder?',
        body: 'Clears the next service date for this client. Reminder reappears after the next service visit.',
        confirmText: 'Dismiss',
    });
    if (!ok) return;
    router.delete(route('reminders.dismiss', item.client_id), {}, { preserveScroll: true });
};
</script>

<template>
    <Head title="Reminders" />

    <AdminLayout>
        <template #header>
            <h1 class="text-base font-bold text-navy-800">Service Reminders</h1>
        </template>

        <!-- Summary stat cards -->
        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <StatCard label="Overdue" :value="stats.overdue ?? 0" variant="danger">
                <template #icon>
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" />
                    </svg>
                </template>
            </StatCard>

            <StatCard label="Due this month" :value="stats.due_this_month ?? 0" variant="warn">
                <template #icon>
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2" /><line x1="16" y1="2" x2="16" y2="6" /><line x1="8" y1="2" x2="8" y2="6" /><line x1="3" y1="10" x2="21" y2="10" />
                    </svg>
                </template>
            </StatCard>

            <StatCard label="Contacted" :value="stats.contacted ?? 0" variant="ok">
                <template #icon>
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><polyline points="22 4 12 14.01 9 11.01" />
                    </svg>
                </template>
            </StatCard>
        </div>

        <!-- Empty state -->
        <div v-if="isEmpty" class="rounded-ral border border-line bg-surface p-10 text-center shadow-card">
            <svg class="mx-auto mb-3 h-10 w-10 text-ink-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><polyline points="22 4 12 14.01 9 11.01" />
            </svg>
            <p class="text-sm font-medium text-ink-soft">No clients due for follow-up right now.</p>
            <p class="mt-1 text-sm text-ink-muted">Clients appear here once a service's next-service date arrives.</p>
        </div>

        <template v-else>
            <!-- FEAT-008: Search + FEAT-009: Tabs -->
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <!-- Tabs -->
                <div class="flex gap-1 rounded-ra bg-surface-muted p-1 w-fit">
                    <button
                        class="rounded px-4 py-1.5 text-sm font-semibold transition"
                        :class="activeTab === 'due' ? 'bg-surface text-ink shadow-card' : 'text-ink-soft hover:text-ink'"
                        @click="activeTab = 'due'"
                    >
                        Due this month
                        <span class="ml-1.5 rounded-full px-1.5 text-xs" :class="activeTab === 'due' ? 'bg-warn text-white' : 'bg-line text-ink-soft'">{{ props.due_this_month.length }}</span>
                    </button>
                    <button
                        class="rounded px-4 py-1.5 text-sm font-semibold transition"
                        :class="activeTab === 'overdue' ? 'bg-surface text-ink shadow-card' : 'text-ink-soft hover:text-ink'"
                        @click="activeTab = 'overdue'"
                    >
                        Overdue
                        <span class="ml-1.5 rounded-full px-1.5 text-xs" :class="activeTab === 'overdue' ? 'bg-danger text-white' : 'bg-line text-ink-soft'">{{ props.overdue.length }}</span>
                    </button>
                </div>

                <!-- Search -->
                <div class="relative">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.35-4.35" stroke-linecap="round" /></svg>
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search name, phone, serial…"
                        class="w-full rounded-ra border border-line bg-surface py-2 pl-9 pr-3 text-sm text-ink shadow-card focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary sm:w-64"
                    />
                </div>
            </div>

            <!-- Empty search result -->
            <p v-if="activeItems.length === 0" class="py-8 text-center text-sm text-ink-muted">No results for "{{ search }}".</p>

            <!-- Cards grid -->
            <div class="grid gap-3 md:grid-cols-2">
                <article
                    v-for="item in activeItems"
                    :key="item.client_id"
                    class="rounded-ral border-y border-r border-line border-l-4 bg-surface p-4 shadow-card"
                    :class="isDue ? 'border-l-warn' : 'border-l-danger'"
                >
                    <!-- Card header: name + contacted badge -->
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <Link :href="route('clients.show', item.client_id)" class="font-semibold text-navy-800 hover:text-primary">{{ item.name }}</Link>
                            <div v-if="item.address" class="mt-0.5 flex items-center gap-1 text-[12px] text-ink-soft">
                                <svg class="h-3 w-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" /><circle cx="12" cy="10" r="3" />
                                </svg>
                                <span class="truncate">{{ item.address }}</span>
                            </div>
                        </div>
                        <Badge v-if="item.contacted" variant="green" class="shrink-0">Contacted</Badge>
                    </div>

                    <!-- Service info row -->
                    <div class="mt-2.5 flex flex-wrap items-center gap-2">
                        <Badge v-if="item.service_type" :variant="serviceVariant(item.service_type)">{{ item.service_type }}</Badge>
                        <span v-if="item.units" class="text-[12px] text-ink-soft">{{ item.units }} unit{{ item.units !== 1 ? 's' : '' }}</span>
                        <span v-if="item.serial_no" class="font-mono text-[11px] tracking-widest text-ink-muted">#{{ item.serial_no }}</span>
                    </div>

                    <!-- Detail rows -->
                    <dl class="mt-3 space-y-1 text-[13px]">
                        <div class="flex justify-between">
                            <dt class="text-ink-soft">Due</dt>
                            <dd class="font-semibold" :class="isDue ? 'text-warn' : 'text-danger'">{{ fmtDate(item.next_due) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-ink-soft">Last service</dt>
                            <dd class="text-ink">{{ fmtDate(item.last_service_date) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-ink-soft">Phone Number</dt>
                            <dd class="font-mono text-ink">{{ item.phone }}</dd>
                        </div>
                    </dl>

                    <!-- Actions row -->
                    <div class="mt-4 flex flex-wrap gap-2">
                        <!-- WhatsApp -->
                        <a
                            :href="waLink(item)"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1.5 rounded-ra bg-wa px-3 py-2 text-xs font-semibold text-white transition hover:opacity-90"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M.057 24l1.687-6.163a11.867 11.867 0 0 1-1.587-5.946C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 0 1 8.413 3.488 11.824 11.824 0 0 1 3.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 0 1-5.688-1.448L.057 24z" />
                            </svg>
                            WhatsApp
                        </a>

                        <!-- Set appointment -->
                        <Link
                            v-if="can.set_appointment"
                            :href="setAppointment(item)"
                            class="inline-flex items-center gap-1.5 rounded-ra bg-primary px-3 py-2 text-xs font-semibold text-white transition hover:bg-primary-hover"
                        >
                            Set Appointment
                        </Link>

                        <!-- Mark contacted / Undo toggle -->
                        <button
                            class="inline-flex items-center gap-1.5 rounded-ra px-3 py-2 text-xs font-semibold transition"
                            :class="item.contacted
                                ? 'bg-surface-muted text-ink-soft hover:bg-line'
                                : 'border border-line text-ink hover:bg-surface-muted'"
                            @click="toggleContacted(item)"
                        >
                            {{ item.contacted ? 'Undo' : 'Mark contacted' }}
                        </button>

                        <!-- FEAT-010: Dismiss -->
                        <button
                            class="inline-flex items-center gap-1.5 rounded-ra px-3 py-2 text-xs font-semibold text-danger hover:bg-danger-bg transition"
                            @click="dismissReminder(item)"
                        >
                            Dismiss
                        </button>
                    </div>
                </article>
            </div>
        </template>
    </AdminLayout>
</template>
