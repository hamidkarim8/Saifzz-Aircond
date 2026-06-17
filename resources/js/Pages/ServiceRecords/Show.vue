<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { confirmAction } from '@/lib/swal';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/Card.vue';
import Badge from '@/Components/Badge.vue';
import WarrantyPill from '@/Components/WarrantyPill.vue';
import Modal from '@/Components/Modal.vue';
import { serviceVariant, statusVariant } from '@/lib/badges';

const props = defineProps({
    visit: Object,
    googleReview: { type: Object, default: () => ({ qrUrl: null, url: null }) },
});

const showReview = ref(false);

const money = (v) => 'RM ' + Number(v ?? 0).toFixed(2);
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-MY', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';

const txn = computed(() => props.visit.transaction);

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

const cancelRecord = async () => {
    const ok = await confirmAction({
        title: 'Cancel this record?',
        body: 'This will void the pending payment. The service history will remain.',
        confirmText: 'Cancel record',
    });
    if (!ok) return;
    router.delete(route('service-records.destroy', props.visit.id));
};
</script>

<template>
    <Head title="Service record" />

    <AdminLayout>
        <template #header>
            <div class="flex min-w-0 items-center justify-between gap-4">
                <span class="truncate font-mono text-base font-bold text-navy-800">{{ txn?.txn_id ?? 'Service Record' }}</span>
                <div class="flex shrink-0 items-center gap-3 text-sm font-medium">
                    <Link v-if="txn?.status === 'pending'" :href="route('service-records.edit', visit.id)" class="text-ink-soft hover:text-ink transition">Edit</Link>
                    <Link :href="route('service-records.index')" class="text-ink-soft hover:text-ink transition">← All records</Link>
                </div>
            </div>
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
                        <Badge v-if="txn" :variant="statusVariant(txn.status === 'paid' ? 'Paid' : txn.status === 'pending' ? 'Pending' : 'Failed')" class="capitalize">
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
                <div class="px-5 py-4 space-y-3">
                    <div class="flex items-center gap-2">
                        <Badge variant="amber">Pending</Badge>
                        <span class="text-sm text-warn">Payment pending via {{ txn.method }}.</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a
                            :href="route('documents.invoice', txn.id)"
                            target="_blank"
                            class="inline-flex items-center rounded-ra border border-warn/50 bg-white px-3 py-1.5 text-sm font-semibold text-warn transition hover:bg-warn/10"
                        >View invoice</a>
                        <a
                            :href="route('documents.invoice.pdf', txn.id)"
                            class="inline-flex items-center rounded-ra border border-warn/50 bg-white px-3 py-1.5 text-sm font-semibold text-warn transition hover:bg-warn/10"
                        >Download PDF</a>
                        <Link
                            v-if="canCollect"
                            :href="route('payments.show', txn.id)"
                            class="inline-flex items-center rounded-ra bg-primary px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-primary-hover"
                        >Collect payment</Link>
                        <button
                            class="inline-flex items-center rounded-ra border border-danger/40 bg-white px-3 py-1.5 text-sm font-semibold text-danger transition hover:bg-danger/10"
                            @click="cancelRecord"
                        >Cancel record</button>
                    </div>
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
                        <button
                            v-if="googleReview.qrUrl"
                            type="button"
                            class="inline-flex items-center rounded-ra border border-ok/50 bg-white px-3 py-1.5 text-sm font-semibold text-ok transition hover:bg-ok/10"
                            @click="showReview = true"
                        >Google Review</button>
                    </span>
                </div>
            </div>
        </div>
        <Modal :show="showReview" @close="showReview = false">
            <div class="space-y-4 p-6 text-center">
                <h3 class="text-base font-bold text-navy-800">Rate us on Google</h3>
                <p class="text-sm text-ink-soft">Scan the QR code to leave a review.</p>
                <img v-if="googleReview.qrUrl" :src="googleReview.qrUrl" alt="Google Review QR" class="mx-auto h-56 w-56 object-contain" />
                <a v-if="googleReview.url" :href="googleReview.url" target="_blank" rel="noopener"
                    class="block text-sm font-semibold text-primary underline">Open review page</a>
                <button type="button" class="rounded-ra border border-line px-4 py-2 text-sm font-semibold text-ink-soft hover:bg-surface" @click="showReview = false">Close</button>
            </div>
        </Modal>
    </AdminLayout>
</template>
