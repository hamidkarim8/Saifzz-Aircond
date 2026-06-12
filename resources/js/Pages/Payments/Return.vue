<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Badge from '@/Components/Badge.vue';

const props = defineProps({ transaction: Object });

const money = (v) => 'RM ' + Number(v ?? 0).toFixed(2);

const view = computed(() => ({
    paid: {
        icon: '✓',
        iconCls: 'bg-ok text-white',
        ringCls: 'border-ok/30',
        badgeVariant: 'green',
        badgeLabel: 'Paid',
        title: 'Payment received',
    },
    failed: {
        icon: '✕',
        iconCls: 'bg-danger text-white',
        ringCls: 'border-danger/30',
        badgeVariant: 'red',
        badgeLabel: 'Failed',
        title: 'Payment failed',
    },
    pending: {
        icon: '…',
        iconCls: 'bg-warn text-white',
        ringCls: 'border-warn/30',
        badgeVariant: 'amber',
        badgeLabel: 'Pending',
        title: 'Awaiting payment',
    },
}[props.transaction.status] ?? {
    icon: '…',
    iconCls: 'bg-warn text-white',
    ringCls: 'border-warn/30',
    badgeVariant: 'amber',
    badgeLabel: 'Pending',
    title: 'Awaiting payment',
}));
</script>

<template>
    <Head title="Payment result" />

    <AdminLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm">
                <span class="text-ink-soft">Payments</span>
                <span class="text-ink-muted">/</span>
                <span class="font-mono font-semibold text-navy-800">{{ transaction.txn_id }}</span>
            </div>
        </template>

        <div class="mx-auto max-w-md space-y-5">
            <PageHeader title="Payment Result" />

            <Card>
                <!-- Status icon + badge -->
                <div class="flex flex-col items-center gap-3 py-4">
                    <div
                        class="grid h-16 w-16 place-items-center rounded-full text-2xl font-bold shadow-lift"
                        :class="view.iconCls"
                    >
                        {{ view.icon }}
                    </div>
                    <Badge :variant="view.badgeVariant">{{ view.badgeLabel }}</Badge>
                    <h2 class="text-lg font-bold text-ink">{{ view.title }}</h2>
                </div>

                <!-- Amount + client -->
                <div
                    class="rounded-ral border-2 px-5 py-4 text-center"
                    :class="view.ringCls"
                >
                    <div class="font-mono text-4xl font-extrabold text-navy-800">{{ money(transaction.amount) }}</div>
                    <div class="mt-1 text-sm text-ink-soft">{{ transaction.client.name }} · #{{ transaction.client.serial_no }}</div>
                    <div class="mt-2 font-mono text-xs text-ink-muted">{{ transaction.txn_id }}</div>
                </div>

                <!-- Receipt block (paid) -->
                <div v-if="transaction.receipt" class="mt-4 overflow-hidden rounded-ral border border-ok/30 bg-ok-bg">
                    <div class="flex items-center justify-between px-4 py-3">
                        <div class="text-sm text-ink-soft">
                            Receipt
                            <span class="ml-1 font-mono font-semibold text-ink">{{ transaction.receipt.number }}</span>
                        </div>
                        <div class="flex items-center gap-3 text-sm">
                            <a
                                :href="route('documents.receipt', transaction.id)"
                                target="_blank"
                                class="font-semibold text-primary hover:text-primary-600"
                            >View</a>
                            <span class="text-line">|</span>
                            <a
                                :href="route('documents.receipt.pdf', transaction.id)"
                                class="font-semibold text-primary hover:text-primary-600"
                            >Download PDF</a>
                        </div>
                    </div>
                </div>

                <!-- Retry (failed) -->
                <div v-if="transaction.status === 'failed'" class="mt-4 text-center">
                    <Link
                        :href="route('payments.show', transaction.id)"
                        class="inline-flex items-center gap-2 rounded-ral bg-primary px-5 py-2.5 font-semibold text-white hover:bg-primary-600 transition"
                    >
                        Retry payment
                    </Link>
                </div>
            </Card>

            <div class="text-center text-sm">
                <Link :href="route('service-records.show', transaction.visit_id)" class="text-ink-soft hover:text-ink">
                    Back to service record
                </Link>
            </div>
        </div>
    </AdminLayout>
</template>
