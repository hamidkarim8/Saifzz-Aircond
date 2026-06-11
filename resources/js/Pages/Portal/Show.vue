<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import PortalLayout from './PortalLayout.vue';
import { waLink as wa } from '@/lib/whatsapp';

const props = defineProps({
    client: Object,
    visits: Array,
    next_service_date: { type: String, default: null },
    business: Object,
});

const money = (v) => 'RM ' + Number(v ?? 0).toFixed(2);
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-MY', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';

// Warranty display (R5) — same semantics as the staff Clients/Show page.
const warrantyStatus = (end) => {
    if (!end) return { label: 'No warranty', cls: 'bg-surface-muted text-ink-soft' };
    const days = Math.ceil((new Date(end) - new Date()) / 86400000);
    if (days < 0) return { label: 'Expired', cls: 'bg-danger-bg text-danger' };
    if (days <= 30) return { label: `Expiring · ${days}d`, cls: 'bg-warn-bg text-warn' };
    return { label: `Active · until ${fmtDate(end)}`, cls: 'bg-ok-bg text-ok' };
};

// business.wa arrives already normalized; the shared builder keeps it as-is (module 11).
const waContact = computed(() => wa(props.business.wa, `Hi, this is ${props.client.name} (serial ${props.client.serial_no}).`));
const waAppointment = computed(() => wa(props.business.wa, `Hi, I'd like to set an appointment. ${props.client.name}, serial ${props.client.serial_no}.`));
</script>

<template>
    <Head title="My services" />

    <PortalLayout :business="business" :show-logout="true">
        <!-- Client header -->
        <div class="overflow-hidden rounded-ral border border-line bg-navy-900 p-6 text-white shadow-card">
            <div class="font-mono text-sm tracking-widest text-primary-300">#{{ client.serial_no }}</div>
            <h1 class="mt-1 text-2xl font-bold tracking-tight">{{ client.name }}</h1>
        </div>

        <!-- Next recommended service -->
        <div class="mt-4 rounded-ral border border-line bg-surface p-4 shadow-card">
            <div class="text-xs font-bold uppercase tracking-wide text-ink-soft">Next recommended service</div>
            <div v-if="next_service_date" class="mt-1 text-lg font-bold text-navy-800">{{ fmtDate(next_service_date) }}</div>
            <div v-else class="mt-1 text-sm text-ink-soft">No upcoming service scheduled.</div>
        </div>

        <!-- WhatsApp actions -->
        <div class="mt-4 grid grid-cols-2 gap-3">
            <a :href="waContact" target="_blank" rel="noopener noreferrer" class="rounded-ra bg-wa px-4 py-2.5 text-center text-sm font-semibold text-white transition hover:opacity-90">Contact us</a>
            <a :href="waAppointment" target="_blank" rel="noopener noreferrer" class="rounded-ra border border-line bg-surface px-4 py-2.5 text-center text-sm font-semibold text-navy-800 transition hover:bg-surface-muted">Request appointment</a>
        </div>

        <!-- Service history -->
        <h2 class="mb-3 mt-6 text-sm font-bold uppercase tracking-wide text-ink-soft">Service history</h2>
        <div v-if="visits.length" class="space-y-4">
            <article v-for="v in visits" :key="v.id" class="rounded-ral border border-line bg-surface p-5 shadow-card">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line pb-3">
                    <div class="font-semibold text-ink">{{ fmtDate(v.visit_date) }}</div>
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="warrantyStatus(v.warranty_end).cls">{{ warrantyStatus(v.warranty_end).label }}</span>
                </div>
                <ul class="divide-y divide-line">
                    <li v-for="(l, i) in v.lines" :key="i" class="flex items-center justify-between py-2.5 text-sm">
                        <div>
                            <span class="font-medium text-ink">{{ l.service_type }}</span>
                            <span v-if="l.unit_type || l.gas_option" class="text-ink-soft"> · {{ l.unit_type || l.gas_option }}</span>
                            <span class="text-ink-muted"> × {{ l.units }}</span>
                        </div>
                        <span class="font-mono font-semibold text-ink">{{ money(l.subtotal) }}</span>
                    </li>
                </ul>
                <div class="mt-2 flex items-center justify-between border-t border-line pt-3 text-sm">
                    <span class="font-semibold text-ink-soft">Total</span>
                    <span class="font-mono text-base font-bold text-navy-800">{{ money(v.total_amount) }}</span>
                </div>
                <div v-if="v.transaction && v.transaction.status === 'paid'" class="mt-2 text-right text-xs">
                    <a :href="route('portal.receipt.pdf', v.transaction.id)" target="_blank" rel="noopener noreferrer" class="font-semibold text-primary hover:text-primary-600">Download receipt →</a>
                </div>
            </article>
        </div>
        <p v-else class="rounded-ral border border-dashed border-line bg-surface py-10 text-center text-sm text-ink-soft">No service records yet.</p>
    </PortalLayout>
</template>
