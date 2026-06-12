<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Badge from '@/Components/Badge.vue';
import WarrantyPill from '@/Components/WarrantyPill.vue';
import { serviceVariant, statusVariant } from '@/lib/badges';
import { waLink as wa } from '@/lib/whatsapp';

const props = defineProps({ client: Object });
const can = computed(() => usePage().props.auth.can ?? {});

const money = (v) => 'RM ' + Number(v ?? 0).toFixed(2);
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-MY', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';

// Warranty display status (R5) from warranty_end.
const warrantyState = (end) => {
    if (!end) return { state: 'none', label: 'No warranty' };
    const days = Math.ceil((new Date(end) - new Date()) / 86400000);
    if (days < 0) return { state: 'expired', label: 'Expired' };
    if (days <= 30) return { state: 'expiring', label: `Expiring · ${days}d` };
    return { state: 'active', label: `Active · until ${fmtDate(end)}` };
};

const waLink = computed(() => wa(props.client.phone));
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

        <!-- Profile header using PageHeader + Card -->
        <PageHeader :title="client.name" :subtitle="'#' + client.serial_no">
            <template #actions>
                <a :href="waLink" target="_blank" class="inline-flex items-center gap-2 rounded-ra bg-wa px-4 py-2.5 text-sm font-semibold text-white transition hover:opacity-90">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M.057 24l1.687-6.163a11.867 11.867 0 0 1-1.587-5.946C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 0 1 8.413 3.488 11.824 11.824 0 0 1 3.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 0 1-5.688-1.448L.057 24z" /></svg>
                    WhatsApp
                </a>
                <Link v-if="can.edit_client" :href="route('clients.edit', client.id)" class="rounded-ra border border-line bg-surface px-4 py-2.5 text-sm font-semibold text-ink shadow-card transition hover:bg-surface-muted">Edit</Link>
            </template>
        </PageHeader>

        <!-- Client profile card -->
        <Card class="mb-6">
            <template #title>Client profile</template>
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <div class="mb-0.5 text-xs font-bold uppercase tracking-wide text-ink-soft">Serial</div>
                    <div class="font-mono font-semibold text-navy-800">#{{ client.serial_no }}</div>
                </div>
                <div>
                    <div class="mb-0.5 text-xs font-bold uppercase tracking-wide text-ink-soft">Phone</div>
                    <div class="font-mono text-ink">{{ client.phone }}</div>
                </div>
                <div class="sm:col-span-1">
                    <div class="mb-0.5 text-xs font-bold uppercase tracking-wide text-ink-soft">Address</div>
                    <div class="text-sm text-ink">{{ client.address }}</div>
                </div>
            </div>
        </Card>

        <div class="grid gap-6 lg:grid-cols-3">
            <!-- Service history -->
            <section class="lg:col-span-2">
                <h3 class="mb-3 text-sm font-bold uppercase tracking-wide text-ink-soft">Service history</h3>
                <div v-if="client.visits.length" class="space-y-4">
                    <Card v-for="v in client.visits" :key="v.id">
                        <template #title>{{ fmtDate(v.visit_date) }}</template>
                        <template #actions>
                            <div class="flex items-center gap-2">
                                <WarrantyPill v-bind="warrantyState(v.warranty_end)" />
                                <Badge v-if="v.transaction" :variant="statusVariant(v.transaction.status === 'paid' ? 'Paid' : v.transaction.status === 'pending' ? 'Pending' : 'Failed')" class="capitalize">
                                    {{ v.transaction.status }}
                                </Badge>
                            </div>
                        </template>
                        <ul class="divide-y divide-line">
                            <li v-for="l in v.lines" :key="l.id" class="flex items-center justify-between py-2.5 text-sm">
                                <div class="flex items-center gap-2">
                                    <Badge :variant="serviceVariant(l.service_type)">{{ l.service_type }}</Badge>
                                    <span v-if="l.unit_type || l.gas_option" class="text-ink-soft">{{ l.unit_type || l.gas_option }}</span>
                                    <span class="text-ink-muted">× {{ l.units }}</span>
                                </div>
                                <span class="font-mono font-semibold text-ink">{{ money(l.subtotal) }}</span>
                            </li>
                        </ul>
                        <div class="mt-2 flex justify-between border-t border-line pt-3 text-sm">
                            <span class="font-semibold text-ink-soft">Total</span>
                            <span class="font-mono text-base font-bold text-navy-800">{{ money(v.total_amount) }}</span>
                        </div>
                        <div v-if="v.transaction" class="mt-2 text-right text-xs">
                            <a
                                :href="v.transaction.status === 'paid' ? route('documents.receipt', v.transaction.id) : route('documents.invoice', v.transaction.id)"
                                target="_blank"
                                class="font-semibold text-primary hover:text-primary-600"
                            >
                                {{ v.transaction.status === 'paid' ? 'View receipt' : 'View invoice' }} →
                            </a>
                        </div>
                    </Card>
                </div>
                <p v-else class="rounded-ral border border-dashed border-line bg-surface py-10 text-center text-sm text-ink-soft">No service records yet.</p>
            </section>

            <!-- Appointments -->
            <section>
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-bold uppercase tracking-wide text-ink-soft">Appointments</h3>
                    <Link
                        v-if="can.set_appointment"
                        :href="route('appointments.index', { client: client.id })"
                        class="text-sm font-semibold text-primary hover:text-primary-hover"
                    >+ New appointment</Link>
                </div>
                <div v-if="client.appointments.length" class="space-y-3">
                    <Card v-for="a in client.appointments" :key="a.id">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-semibold text-ink">{{ a.service_type }}</span>
                            <Badge :variant="statusVariant(a.status)" class="capitalize">{{ a.status }}</Badge>
                        </div>
                        <div class="mt-1 text-sm text-ink-soft">{{ fmtDate(a.datetime) }}</div>
                    </Card>
                </div>
                <p v-else class="rounded-ral border border-dashed border-line bg-surface py-8 text-center text-sm text-ink-soft">None scheduled.</p>
            </section>
        </div>
    </AdminLayout>
</template>
