<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({ client: Object });
const can = computed(() => usePage().props.auth.can ?? {});

const money = (v) => 'RM ' + Number(v ?? 0).toFixed(2);
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-MY', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';

// Warranty display status (R5) from warranty_end.
const warrantyStatus = (end) => {
    if (!end) return { label: 'No warranty', cls: 'bg-surface-muted text-ink-soft' };
    const days = Math.ceil((new Date(end) - new Date()) / 86400000);
    if (days < 0) return { label: 'Expired', cls: 'bg-danger-bg text-danger' };
    if (days <= 30) return { label: `Expiring · ${days}d`, cls: 'bg-warn-bg text-warn' };
    return { label: `Active · until ${fmtDate(end)}`, cls: 'bg-ok-bg text-ok' };
};

const txnStatus = {
    paid: 'bg-ok-bg text-ok',
    pending: 'bg-warn-bg text-warn',
    failed: 'bg-danger-bg text-danger',
};

const waLink = computed(() => {
    const phone = (props.client.phone ?? '').replace(/\D/g, '').replace(/^0/, '60');
    return `https://wa.me/${phone}`;
});
</script>

<template>
    <Head :title="client.name" />

    <AdminLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm">
                <Link :href="route('clients.index')" class="text-ink-soft hover:text-ink">Clients</Link>
                <span class="text-ink-muted">/</span>
                <span class="font-semibold text-navy-800">{{ client.name }}</span>
            </div>
        </template>

        <!-- Profile header -->
        <div class="mb-6 overflow-hidden rounded-ral border border-line bg-surface shadow-card">
            <div class="flex flex-col gap-4 bg-navy-900 p-6 text-white sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="font-mono text-sm tracking-widest text-primary-300">#{{ client.serial_no }}</div>
                    <h2 class="mt-1 text-2xl font-bold tracking-tight">{{ client.name }}</h2>
                    <div class="mt-2 space-y-0.5 text-sm text-primary-300">
                        <div class="font-mono">{{ client.phone }}</div>
                        <div>{{ client.address }}</div>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a :href="waLink" target="_blank" class="inline-flex items-center gap-2 rounded-ra bg-wa px-4 py-2.5 text-sm font-semibold text-white transition hover:opacity-90">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M.057 24l1.687-6.163a11.867 11.867 0 0 1-1.587-5.946C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 0 1 8.413 3.488 11.824 11.824 0 0 1 3.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 0 1-5.688-1.448L.057 24z" /></svg>
                        WhatsApp
                    </a>
                    <Link v-if="can.edit_client" :href="route('clients.edit', client.id)" class="rounded-ra bg-white/10 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-white/20">Edit</Link>
                </div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <!-- Service history -->
            <section class="lg:col-span-2">
                <h3 class="mb-3 text-sm font-bold uppercase tracking-wide text-ink-soft">Service history</h3>
                <div v-if="client.visits.length" class="space-y-4">
                    <article v-for="v in client.visits" :key="v.id" class="rounded-ral border border-line bg-surface p-5 shadow-card">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line pb-3">
                            <div class="font-semibold text-ink">{{ fmtDate(v.visit_date) }}</div>
                            <div class="flex items-center gap-2">
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="warrantyStatus(v.warranty_end).cls">{{ warrantyStatus(v.warranty_end).label }}</span>
                                <span v-if="v.transaction" class="rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize" :class="txnStatus[v.transaction.status]">{{ v.transaction.status }}</span>
                            </div>
                        </div>
                        <ul class="divide-y divide-line">
                            <li v-for="l in v.lines" :key="l.id" class="flex items-center justify-between py-2.5 text-sm">
                                <div>
                                    <span class="font-medium text-ink">{{ l.service_type }}</span>
                                    <span v-if="l.unit_type || l.gas_option" class="text-ink-soft"> · {{ l.unit_type || l.gas_option }}</span>
                                    <span class="text-ink-muted"> × {{ l.units }}</span>
                                </div>
                                <span class="font-mono font-semibold text-ink">{{ money(l.subtotal) }}</span>
                            </li>
                        </ul>
                        <div class="mt-2 flex justify-between border-t border-line pt-3 text-sm">
                            <span class="font-semibold text-ink-soft">Total</span>
                            <span class="font-mono text-base font-bold text-navy-800">{{ money(v.total_amount) }}</span>
                        </div>
                    </article>
                </div>
                <p v-else class="rounded-ral border border-dashed border-line bg-surface py-10 text-center text-sm text-ink-soft">No service records yet.</p>
            </section>

            <!-- Appointments -->
            <section>
                <h3 class="mb-3 text-sm font-bold uppercase tracking-wide text-ink-soft">Appointments</h3>
                <div v-if="client.appointments.length" class="space-y-3">
                    <div v-for="a in client.appointments" :key="a.id" class="rounded-ral border border-line bg-surface p-4 shadow-card">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-semibold text-ink">{{ a.service_type }}</span>
                            <span class="rounded-full bg-surface-muted px-2.5 py-0.5 text-xs font-semibold capitalize text-ink-soft">{{ a.status }}</span>
                        </div>
                        <div class="mt-1 text-sm text-ink-soft">{{ fmtDate(a.datetime) }}</div>
                    </div>
                </div>
                <p v-else class="rounded-ral border border-dashed border-line bg-surface py-8 text-center text-sm text-ink-soft">None scheduled.</p>
            </section>
        </div>
    </AdminLayout>
</template>
