<script setup>
import { computed } from 'vue';
import { Head, Link, usePage, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { waLink as wa } from '@/lib/whatsapp';

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
    wa(item.phone, `Hi ${item.name}, this is Saifzz Aircond. Your aircond service (#${item.serial_no}) is due on ${fmtDate(item.next_due)}. Reply to schedule a visit.`);

const setAppointment = (item) => route('appointments.index', { client: item.client_id });

const toggleContacted = (item) =>
    router.patch(route('reminders.contacted', item.client_id), {}, { preserveScroll: true });

const isEmpty = computed(() => props.overdue.length === 0 && props.due_this_month.length === 0);
</script>

<template>
    <Head title="Reminders" />

    <AdminLayout>
        <template #header>
            <h1 class="text-lg font-bold tracking-tight text-navy-800">Reminders</h1>
        </template>

        <p class="mb-5 text-sm text-ink-soft">Clients due or overdue for service, derived from their next-service dates.</p>

        <!-- Summary stats -->
        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <div class="rounded-ral border border-line bg-surface p-5 shadow-card">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Overdue</div>
                <div class="mt-1 text-2xl font-bold text-danger">{{ stats.overdue ?? 0 }}</div>
            </div>
            <div class="rounded-ral border border-line bg-surface p-5 shadow-card">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Due this month</div>
                <div class="mt-1 text-2xl font-bold text-warn">{{ stats.due_this_month ?? 0 }}</div>
            </div>
            <div class="rounded-ral border border-line bg-surface p-5 shadow-card">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Contacted</div>
                <div class="mt-1 text-2xl font-bold text-ok">{{ stats.contacted ?? 0 }}</div>
            </div>
        </div>

        <!-- Empty state -->
        <div v-if="isEmpty" class="rounded-ral border border-line bg-surface p-10 text-center shadow-card">
            <p class="text-sm font-medium text-ink-soft">No clients due for follow-up right now.</p>
            <p class="mt-1 text-sm text-ink-muted">Clients appear here once a service's next-service date arrives.</p>
        </div>

        <!-- Overdue -->
        <section v-if="overdue.length" class="mb-8">
            <h2 class="mb-3 flex items-center gap-2 text-sm font-bold text-danger">
                <span class="inline-block h-2 w-2 rounded-full bg-danger" />
                Overdue ({{ overdue.length }})
            </h2>
            <div class="grid gap-3 md:grid-cols-2">
                <article
                    v-for="item in overdue"
                    :key="item.client_id"
                    class="rounded-ral border-l-4 border-danger border-y border-r border-line bg-surface p-4 shadow-card"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <Link :href="route('clients.show', item.client_id)" class="font-semibold text-navy-800 hover:text-primary">{{ item.name }}</Link>
                            <div class="font-mono text-xs tracking-widest text-ink-muted">#{{ item.serial_no }}</div>
                        </div>
                        <span v-if="item.contacted" class="shrink-0 rounded-full bg-ok-bg px-2 py-0.5 text-[11px] font-semibold text-ok">Contacted</span>
                    </div>

                    <dl class="mt-3 space-y-1 text-[13px]">
                        <div class="flex justify-between"><dt class="text-ink-soft">Due</dt><dd class="font-semibold text-danger">{{ fmtDate(item.next_due) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-ink-soft">Last service</dt><dd class="text-ink">{{ fmtDate(item.last_service_date) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-ink-soft">Phone</dt><dd class="font-mono text-ink">{{ item.phone }}</dd></div>
                    </dl>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <a :href="waLink(item)" target="_blank" class="inline-flex items-center gap-1.5 rounded-ra bg-wa px-3 py-2 text-xs font-semibold text-white transition hover:opacity-90">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="M.057 24l1.687-6.163a11.867 11.867 0 0 1-1.587-5.946C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 0 1 8.413 3.488 11.824 11.824 0 0 1 3.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 0 1-5.688-1.448L.057 24z" /></svg>
                            WhatsApp
                        </a>
                        <Link v-if="can.set_appointment" :href="setAppointment(item)" class="inline-flex items-center gap-1.5 rounded-ra bg-primary px-3 py-2 text-xs font-semibold text-white transition hover:bg-primary-hover">
                            Set appointment
                        </Link>
                        <button
                            class="inline-flex items-center gap-1.5 rounded-ra px-3 py-2 text-xs font-semibold transition"
                            :class="item.contacted ? 'bg-surface-muted text-ink-soft hover:bg-line' : 'border border-line text-ink hover:bg-surface-muted'"
                            @click="toggleContacted(item)"
                        >
                            {{ item.contacted ? 'Undo' : 'Mark contacted' }}
                        </button>
                    </div>
                </article>
            </div>
        </section>

        <!-- Due this month -->
        <section v-if="due_this_month.length">
            <h2 class="mb-3 flex items-center gap-2 text-sm font-bold text-warn">
                <span class="inline-block h-2 w-2 rounded-full bg-warn" />
                Due this month ({{ due_this_month.length }})
            </h2>
            <div class="grid gap-3 md:grid-cols-2">
                <article
                    v-for="item in due_this_month"
                    :key="item.client_id"
                    class="rounded-ral border-l-4 border-warn border-y border-r border-line bg-surface p-4 shadow-card"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <Link :href="route('clients.show', item.client_id)" class="font-semibold text-navy-800 hover:text-primary">{{ item.name }}</Link>
                            <div class="font-mono text-xs tracking-widest text-ink-muted">#{{ item.serial_no }}</div>
                        </div>
                        <span v-if="item.contacted" class="shrink-0 rounded-full bg-ok-bg px-2 py-0.5 text-[11px] font-semibold text-ok">Contacted</span>
                    </div>

                    <dl class="mt-3 space-y-1 text-[13px]">
                        <div class="flex justify-between"><dt class="text-ink-soft">Due</dt><dd class="font-semibold text-warn">{{ fmtDate(item.next_due) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-ink-soft">Last service</dt><dd class="text-ink">{{ fmtDate(item.last_service_date) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-ink-soft">Phone</dt><dd class="font-mono text-ink">{{ item.phone }}</dd></div>
                    </dl>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <a :href="waLink(item)" target="_blank" class="inline-flex items-center gap-1.5 rounded-ra bg-wa px-3 py-2 text-xs font-semibold text-white transition hover:opacity-90">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="M.057 24l1.687-6.163a11.867 11.867 0 0 1-1.587-5.946C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 0 1 8.413 3.488 11.824 11.824 0 0 1 3.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 0 1-5.688-1.448L.057 24z" /></svg>
                            WhatsApp
                        </a>
                        <Link v-if="can.set_appointment" :href="setAppointment(item)" class="inline-flex items-center gap-1.5 rounded-ra bg-primary px-3 py-2 text-xs font-semibold text-white transition hover:bg-primary-hover">
                            Set appointment
                        </Link>
                        <button
                            class="inline-flex items-center gap-1.5 rounded-ra px-3 py-2 text-xs font-semibold transition"
                            :class="item.contacted ? 'bg-surface-muted text-ink-soft hover:bg-line' : 'border border-line text-ink hover:bg-surface-muted'"
                            @click="toggleContacted(item)"
                        >
                            {{ item.contacted ? 'Undo' : 'Mark contacted' }}
                        </button>
                    </div>
                </article>
            </div>
        </section>
    </AdminLayout>
</template>
