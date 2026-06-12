<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Badge from '@/Components/Badge.vue';
import WarrantyPill from '@/Components/WarrantyPill.vue';
import { serviceVariant, statusVariant } from '@/lib/badges';

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

// WarrantyPill state derivation (for WarrantyPill component)
const warrantyState = computed(() => {
    if (!props.visit.warranty_end) return 'none';
    const days = Math.ceil((new Date(props.visit.warranty_end) - new Date()) / 86400000);
    if (days < 0) return 'expired';
    if (days <= 30) return 'expiring';
    return 'active';
});
const warrantyLabel = computed(() => {
    if (!warranty.value) return '';
    return warranty.value.label;
});

const lineLabel = (l) => [l.unit_type, l.gas_option].filter(Boolean).join(' ') || (l.service_type === 'Repair' ? 'Flat job' : '');

const canCollect = computed(() => usePage().props.auth?.can?.collect_payment ?? false);
</script>

<template>
    <Head title="Service record" />

    <AdminLayout>
        <template #header>
            <PageHeader :title="txn?.txn_id ?? 'Service Record'">
                <template #actions>
                    <Link :href="route('service-records.index')" class="text-sm font-medium text-ink-soft hover:text-ink transition">
                        ← All records
                    </Link>
                </template>
            </PageHeader>
        </template>

        <div class="mx-auto max-w-3xl space-y-5">
            <!-- Summary card: dark hero -->
            <div class="overflow-hidden rounded-ral border border-line shadow-card">
                <div class="flex flex-col gap-4 bg-navy-900 px-6 py-5 text-white sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div class="font-mono text-xs tracking-widest text-navy-300">{{ txn?.txn_id }}</div>
                        <h2 class="mt-1 text-xl font-bold tracking-tight">{{ fmtDate(visit.visit_date) }}</h2>
                        <Link :href="route('clients.show', visit.client.id)" class="mt-2 inline-flex items-center gap-1.5 text-sm text-primary-300 hover:text-white transition">
                            {{ visit.client.name }}
                            <span class="font-mono opacity-70">#{{ visit.client.serial_no }}</span>
                        </Link>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 sm:mt-1">
                        <WarrantyPill :state="warrantyState" :label="warrantyLabel" />
                        <Badge v-if="txn" :variant="statusVariant(txn.status === 'paid' ? 'Paid' : txn.status === 'pending' ? 'Pending' : 'Failed')">
                            {{ txn.status }} · {{ txn.method }}
                        </Badge>
                    </div>
                </div>
            </div>

            <!-- Service lines card -->
            <Card title="Services">
                <div class="-mx-4 -mt-4">
                    <ul class="divide-y divide-line">
                        <li v-for="l in visit.lines" :key="l.id" class="px-5 py-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <Badge :variant="serviceVariant(l.service_type)">{{ l.service_type }}</Badge>
                                        <span v-if="lineLabel(l)" class="text-sm text-ink-soft">{{ lineLabel(l) }}</span>
                                    </div>
                                    <div class="mt-1.5 text-sm text-ink-soft">
                                        <span class="font-mono">{{ money(l.rate) }}</span>
                                        <span class="mx-1 text-ink-muted">×</span>
                                        <span>{{ l.units }}</span>
                                        <span v-if="Number(l.discount) > 0" class="ml-1.5 text-warn">− {{ money(l.discount) }} disc.</span>
                                    </div>
                                    <p v-if="l.repair_desc" class="mt-1 text-sm text-ink-soft">{{ l.repair_desc }}</p>
                                    <p v-if="l.notes" class="mt-1 text-xs italic text-ink-muted">{{ l.notes }}</p>
                                    <p v-if="l.next_service_date" class="mt-1 text-xs font-medium text-primary">Next service: {{ fmtDate(l.next_service_date) }}</p>
                                </div>
                                <span class="shrink-0 font-mono text-sm font-bold text-navy-800">{{ money(l.subtotal) }}</span>
                            </div>
                        </li>
                    </ul>
                    <div class="flex items-center justify-between border-t border-line bg-surface-muted px-5 py-3.5">
                        <span class="text-sm font-semibold text-ink-soft">Total</span>
                        <span class="font-mono text-xl font-bold text-navy-800">{{ money(visit.total_amount) }}</span>
                    </div>
                </div>
            </Card>

            <!-- Payment / document card -->
            <div v-if="txn && txn.status === 'pending'" class="overflow-hidden rounded-ral border border-warn/40 bg-warn-bg shadow-card">
                <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-2">
                        <Badge variant="amber">Pending</Badge>
                        <span class="text-sm text-warn">Payment pending via {{ txn.method }}.</span>
                    </div>
                    <span class="flex flex-wrap items-center gap-3">
                        <a :href="route('documents.invoice', txn.id)" target="_blank" class="text-sm font-semibold text-warn underline hover:text-warn/80 transition">View invoice</a>
                        <a :href="route('documents.invoice.pdf', txn.id)" class="text-sm font-semibold text-warn underline hover:text-warn/80 transition">Download PDF</a>
                        <Link
                            v-if="canCollect"
                            :href="route('payments.show', txn.id)"
                            class="inline-block rounded-ral bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-600"
                        >
                            Collect payment
                        </Link>
                    </span>
                </div>
            </div>
            <div v-else-if="txn && txn.status === 'paid'" class="overflow-hidden rounded-ral border border-ok/40 bg-ok-bg shadow-card">
                <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-2">
                        <Badge variant="green">Paid</Badge>
                        <span class="text-sm text-ok">Paid via {{ txn.method }}.</span>
                    </div>
                    <span class="flex flex-wrap items-center gap-3">
                        <a :href="route('documents.receipt', txn.id)" target="_blank" class="text-sm font-semibold text-ok underline hover:text-ok/80 transition">View receipt</a>
                        <a :href="route('documents.receipt.pdf', txn.id)" class="text-sm font-semibold text-ok underline hover:text-ok/80 transition">Download PDF</a>
                    </span>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
