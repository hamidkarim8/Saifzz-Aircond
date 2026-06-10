<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({ visit: Object });

const money = (v) => 'RM ' + Number(v ?? 0).toFixed(2);
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-MY', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';

const txn = computed(() => props.visit.transaction);
const txnStatus = { paid: 'bg-ok-bg text-ok', pending: 'bg-warn-bg text-warn', failed: 'bg-danger-bg text-danger' };

const warranty = computed(() => {
    if (!props.visit.warranty_end) return null;
    const days = Math.ceil((new Date(props.visit.warranty_end) - new Date()) / 86400000);
    if (days < 0) return { label: 'Expired', cls: 'bg-danger-bg text-danger' };
    if (days <= 30) return { label: `Expiring · ${days}d`, cls: 'bg-warn-bg text-warn' };
    return { label: `Active until ${fmtDate(props.visit.warranty_end)}`, cls: 'bg-ok-bg text-ok' };
});

const lineLabel = (l) => [l.unit_type, l.gas_option].filter(Boolean).join(' ') || (l.service_type === 'Repair' ? 'Flat job' : '');
</script>

<template>
    <Head title="Service record" />

    <AdminLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm">
                <Link :href="route('service-records.index')" class="text-ink-soft hover:text-ink">Service Records</Link>
                <span class="text-ink-muted">/</span>
                <span class="font-mono font-semibold text-navy-800">{{ txn?.txn_id }}</span>
            </div>
        </template>

        <div class="mx-auto max-w-3xl space-y-6">
            <!-- Summary header -->
            <div class="overflow-hidden rounded-ral border border-line bg-surface shadow-card">
                <div class="flex flex-col gap-4 bg-navy-900 p-6 text-white sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div class="font-mono text-sm tracking-widest text-primary-300">{{ txn?.txn_id }}</div>
                        <h2 class="mt-1 text-xl font-bold tracking-tight">{{ fmtDate(visit.visit_date) }}</h2>
                        <Link :href="route('clients.show', visit.client.id)" class="mt-2 inline-block text-sm text-primary-300 hover:text-white">
                            {{ visit.client.name }} · #{{ visit.client.serial_no }}
                        </Link>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span v-if="warranty" class="rounded-full px-3 py-1 text-xs font-semibold" :class="warranty.cls">{{ warranty.label }}</span>
                        <span v-if="txn" class="rounded-full px-3 py-1 text-xs font-semibold capitalize" :class="txnStatus[txn.status]">{{ txn.status }} · {{ txn.method }}</span>
                    </div>
                </div>
            </div>

            <!-- Lines -->
            <div class="overflow-hidden rounded-ral border border-line bg-surface shadow-card">
                <ul class="divide-y divide-line">
                    <li v-for="l in visit.lines" :key="l.id" class="px-5 py-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="font-semibold text-ink">{{ l.service_type }} <span v-if="lineLabel(l)" class="font-normal text-ink-soft">· {{ lineLabel(l) }}</span></div>
                                <div class="mt-0.5 text-sm text-ink-soft">
                                    <span class="font-mono">{{ money(l.rate) }}</span> × {{ l.units }}
                                    <span v-if="Number(l.discount) > 0"> − {{ money(l.discount) }} disc.</span>
                                </div>
                                <p v-if="l.repair_desc" class="mt-1 text-sm text-ink-soft">{{ l.repair_desc }}</p>
                                <p v-if="l.notes" class="mt-1 text-sm italic text-ink-muted">{{ l.notes }}</p>
                                <p v-if="l.next_service_date" class="mt-1 text-xs text-primary">Next service: {{ fmtDate(l.next_service_date) }}</p>
                            </div>
                            <span class="font-mono font-semibold text-navy-800">{{ money(l.subtotal) }}</span>
                        </div>
                    </li>
                </ul>
                <div class="flex items-center justify-between border-t border-line bg-surface-muted px-5 py-4">
                    <span class="font-semibold text-ink-soft">Total</span>
                    <span class="font-mono text-xl font-bold text-navy-800">{{ money(visit.total_amount) }}</span>
                </div>
            </div>

            <!-- Payment status (collection handled by the Payments module) -->
            <div v-if="txn && txn.status === 'pending'" class="rounded-ral border border-warn/30 bg-warn-bg px-5 py-4 text-sm text-warn">
                Payment pending via {{ txn.method }}. Collection &amp; receipt come from the Payments module.
            </div>
        </div>
    </AdminLayout>
</template>
