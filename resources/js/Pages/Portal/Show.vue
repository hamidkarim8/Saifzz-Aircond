<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import PortalLayout from './PortalLayout.vue';
import WarrantyPill from '@/Components/WarrantyPill.vue';
import Badge from '@/Components/Badge.vue';
import { waLink as wa } from '@/lib/whatsapp';
import { serviceVariant } from '@/lib/badges';

const props = defineProps({
    client: Object,
    visits: Array,
    next_service_date: { type: String, default: null },
    business: Object,
});

const money = (v) => 'RM ' + Number(v ?? 0).toFixed(2);
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
// Raw-parse so UTC-tagged datetimes don't shift a day under UTC+8.
const fmtDate = (d) => {
    if (!d) return '—';
    const [y, m, day] = d.slice(0, 10).split('-').map(Number);
    return `${day} ${MONTHS[m - 1]} ${y}`;
};

// Derive WarrantyPill state + label from warranty_end date.
const warrantyPill = (end) => {
    if (!end) return { state: 'none', label: 'No warranty' };
    const days = Math.ceil((new Date(end) - new Date()) / 86400000);
    if (days < 0) return { state: 'expired', label: 'Expired' };
    if (days <= 30) return { state: 'expiring', label: `Expiring · ${days}d` };
    return { state: 'active', label: `Active · until ${fmtDate(end)}` };
};

// Avatar initials from client name (up to 2 chars).
const initials = computed(() => {
    if (!props.client?.name) return '?';
    return props.client.name
        .split(/\s+/)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('');
});

// business.wa arrives already normalized; the shared builder keeps it as-is (module 11).
const waAppointment = computed(() => wa(props.business.wa, `Hi, I'd like to set an appointment. ${props.client.name}, serial ${props.client.serial_no}.`));
</script>

<template>
    <Head title="My services" />

    <PortalLayout :business="business" :show-logout="true">

        <!-- ── Next Recommended Service Banner ── -->
        <div class="overflow-hidden rounded-ral bg-gradient-to-r from-primary to-navy-800 p-5 text-center text-white shadow-lift">
            <p class="text-xs font-bold uppercase tracking-widest text-primary-300">
                Next Recommended Service
            </p>
            <p v-if="next_service_date" class="mt-1 text-2xl font-bold tracking-tight">
                {{ fmtDate(next_service_date) }}
            </p>
            <p v-else class="mt-1 text-base text-white/70">
                No upcoming service scheduled.
            </p>
            <a
                :href="waAppointment"
                target="_blank"
                rel="noopener noreferrer"
                class="mt-4 inline-flex items-center gap-2 rounded-ra bg-wa px-4 py-2.5 text-sm font-bold text-white transition hover:opacity-90"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                </svg>
                Set Appointment
            </a>
        </div>

        <!-- ── Client Header Card ── -->
        <div class="mt-4 overflow-hidden rounded-ral bg-surface p-5 shadow-card">
            <div class="flex items-center gap-4">
                <!-- Avatar initials circle -->
                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-navy-800 text-lg font-bold tracking-tight text-white">
                    {{ initials }}
                </div>
                <div class="min-w-0">
                    <div class="truncate font-mono text-xs tracking-widest text-ink-muted">
                        #{{ client.serial_no }}
                    </div>
                    <h1 class="truncate text-lg font-bold leading-tight text-navy-800">
                        {{ client.name }}
                    </h1>
                    <p v-if="client.address" class="mt-0.5 line-clamp-2 text-sm text-ink-soft">
                        {{ client.address }}
                    </p>
                </div>
            </div>
        </div>

        <!-- ── Service History ── -->
        <h2 class="mb-3 mt-6 text-xs font-bold uppercase tracking-widest text-white/60">
            Service History
        </h2>

        <div v-if="visits && visits.length" class="space-y-4">
            <article
                v-for="v in visits"
                :key="v.id"
                class="overflow-hidden rounded-ral bg-surface shadow-card"
            >
                <!-- Visit header row -->
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-3.5">
                    <span class="font-semibold text-navy-800">{{ fmtDate(v.visit_date) }}</span>
                    <WarrantyPill
                        :state="warrantyPill(v.warranty_end).state"
                        :label="warrantyPill(v.warranty_end).label"
                    />
                </div>

                <!-- Line items -->
                <ul class="divide-y divide-line px-5">
                    <li
                        v-for="(l, i) in v.lines"
                        :key="i"
                        class="flex items-start justify-between gap-3 py-3 text-sm"
                    >
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <Badge :variant="serviceVariant(l.service_type)">{{ l.service_type }}</Badge>
                                <span v-if="l.unit_type" class="text-ink-soft">
                                    {{ l.unit_type }}
                                </span>
                            </div>
                            <span class="mt-0.5 block text-xs text-ink-muted">× {{ l.units }} unit{{ l.units !== 1 ? 's' : '' }}</span>
                            <span v-if="l.next_service_date" class="mt-0.5 block text-xs font-medium text-primary">Next service: {{ fmtDate(l.next_service_date) }}</span>
                        </div>
                        <span class="shrink-0 font-mono font-semibold text-ink">{{ money(l.subtotal) }}</span>
                    </li>
                </ul>

                <!-- Visit footer: total + optional receipt download -->
                <div class="border-t border-line px-5 py-3.5">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-semibold text-ink-soft">Total</span>
                        <span class="font-mono text-base font-bold text-navy-800">{{ money(v.total_amount) }}</span>
                    </div>
                    <!-- Receipt: server-enforced — only rendered when paid -->
                    <div v-if="v.transaction && v.transaction.status === 'paid'" class="mt-2.5 text-right">
                        <a
                            :href="route('portal.receipt.pdf', v.transaction.id)"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1.5 rounded-ra border border-primary/30 bg-primary-50 px-3 py-1.5 text-xs font-bold text-primary transition hover:bg-primary hover:text-white"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                            </svg>
                            Receipt
                        </a>
                    </div>
                </div>
            </article>
        </div>

        <p v-else class="rounded-ral border border-dashed border-white/20 bg-white/5 py-10 text-center text-sm text-white/60">
            No service records yet.
        </p>

    </PortalLayout>
</template>
