<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineProps({ visits: Object });

const money = (v) => 'RM ' + Number(v ?? 0).toFixed(2);
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-MY', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';

const txnStatus = { paid: 'bg-ok-bg text-ok', pending: 'bg-warn-bg text-warn', failed: 'bg-danger-bg text-danger' };
</script>

<template>
    <Head title="Service Records" />

    <AdminLayout>
        <template #header>
            <h1 class="text-lg font-bold tracking-tight text-navy-800">Service Records</h1>
        </template>

        <div class="mb-5 flex items-center justify-between">
            <p class="text-sm text-ink-soft">Recorded visits, newest first.</p>
            <Link :href="route('service-records.create')" class="inline-flex items-center gap-2 rounded-ra bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg>
                New record
            </Link>
        </div>

        <!-- Desktop -->
        <div class="hidden overflow-hidden rounded-ral border border-line bg-surface shadow-card md:block">
            <table class="w-full text-sm">
                <thead class="border-b border-line bg-surface-muted text-left text-xs uppercase tracking-wide text-ink-soft">
                    <tr>
                        <th class="px-5 py-3 font-semibold">Date</th>
                        <th class="px-5 py-3 font-semibold">Client</th>
                        <th class="px-5 py-3 font-semibold">Services</th>
                        <th class="px-5 py-3 font-semibold">Total</th>
                        <th class="px-5 py-3 font-semibold">Payment</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="v in visits.data" :key="v.id" class="cursor-pointer hover:bg-surface-muted" @click="$inertia.visit(route('service-records.show', v.id))">
                        <td class="px-5 py-3 font-medium text-ink">{{ fmtDate(v.visit_date) }}</td>
                        <td class="px-5 py-3">
                            <span class="font-medium text-ink">{{ v.client?.name }}</span>
                            <span class="ml-1 font-mono text-xs text-primary">#{{ v.client?.serial_no }}</span>
                        </td>
                        <td class="px-5 py-3 text-ink-soft">{{ v.lines_count }}</td>
                        <td class="px-5 py-3 font-mono font-semibold text-navy-800">{{ money(v.total_amount) }}</td>
                        <td class="px-5 py-3">
                            <span v-if="v.transaction" class="rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize" :class="txnStatus[v.transaction.status]">{{ v.transaction.status }}</span>
                            <span class="ml-1 text-xs text-ink-muted">{{ v.transaction?.method }}</span>
                        </td>
                    </tr>
                    <tr v-if="!visits.data.length"><td colspan="5" class="px-5 py-12 text-center text-ink-soft">No service records yet.</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Mobile cards -->
        <div class="space-y-3 md:hidden">
            <Link v-for="v in visits.data" :key="v.id" :href="route('service-records.show', v.id)" class="block rounded-ral border border-line bg-surface p-4 shadow-card">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="font-semibold text-ink">{{ v.client?.name }}</div>
                        <div class="mt-0.5 text-sm text-ink-soft">{{ fmtDate(v.visit_date) }} · {{ v.lines_count }} service(s)</div>
                    </div>
                    <span v-if="v.transaction" class="rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize" :class="txnStatus[v.transaction.status]">{{ v.transaction.status }}</span>
                </div>
                <div class="mt-2 font-mono font-bold text-navy-800">{{ money(v.total_amount) }}</div>
            </Link>
            <p v-if="!visits.data.length" class="rounded-ral border border-line bg-surface py-12 text-center text-ink-soft shadow-card">No service records yet.</p>
        </div>

        <div v-if="visits.links.length > 3" class="mt-5 flex flex-wrap justify-center gap-1">
            <component :is="link.url ? Link : 'span'" v-for="link in visits.links" :key="link.label" :href="link.url" preserve-scroll
                class="min-w-9 rounded-ra px-3 py-2 text-center text-sm transition"
                :class="[link.active ? 'bg-primary text-white' : 'bg-surface text-ink-soft shadow-card hover:text-ink', !link.url && 'opacity-40']"
                v-html="link.label" />
        </div>
    </AdminLayout>
</template>
