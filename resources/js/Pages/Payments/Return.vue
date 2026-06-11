<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({ transaction: Object });

const money = (v) => 'RM ' + Number(v ?? 0).toFixed(2);

const view = computed(() => ({
    paid: { icon: '✓', cls: 'bg-ok-bg text-ok', title: 'Payment received', ring: 'border-ok/40' },
    failed: { icon: '✗', cls: 'bg-danger-bg text-danger', title: 'Payment failed', ring: 'border-danger/40' },
    pending: { icon: '…', cls: 'bg-warn-bg text-warn', title: 'Awaiting payment', ring: 'border-warn/40' },
}[props.transaction.status] ?? { icon: '…', cls: 'bg-warn-bg text-warn', title: 'Awaiting payment', ring: 'border-warn/40' }));
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

        <div class="mx-auto max-w-md space-y-6">
            <div class="rounded-ral border bg-surface p-8 text-center shadow-card" :class="view.ring">
                <div class="mx-auto grid h-16 w-16 place-items-center rounded-full text-3xl font-bold" :class="view.cls">
                    {{ view.icon }}
                </div>
                <h2 class="mt-4 text-xl font-bold text-ink">{{ view.title }}</h2>
                <div class="mt-1 font-mono text-sm text-ink-soft">{{ transaction.txn_id }}</div>
                <div class="mt-4 text-3xl font-extrabold text-navy-800">{{ money(transaction.amount) }}</div>
                <div class="mt-1 text-sm text-ink-soft">{{ transaction.client.name }} · #{{ transaction.client.serial_no }}</div>

                <div v-if="transaction.receipt" class="mt-4 rounded-ral bg-surface-muted px-4 py-3 text-sm text-ink-soft">
                    <div>Receipt <span class="font-mono font-semibold text-ink">{{ transaction.receipt.number }}</span></div>
                    <div class="mt-2 flex justify-center gap-3">
                        <a :href="route('documents.receipt', transaction.id)" target="_blank" class="font-semibold text-primary hover:text-primary-600">View</a>
                        <a :href="route('documents.receipt.pdf', transaction.id)" class="font-semibold text-primary hover:text-primary-600">Download PDF</a>
                    </div>
                </div>

                <div v-if="transaction.status === 'failed'" class="mt-5">
                    <Link
                        :href="route('payments.show', transaction.id)"
                        class="inline-block rounded-ral bg-primary px-4 py-2 font-semibold text-white hover:bg-primary-600"
                    >
                        Retry payment
                    </Link>
                </div>
            </div>

            <div class="text-center text-sm">
                <Link :href="route('service-records.show', transaction.visit_id)" class="text-ink-soft hover:text-ink">
                    Back to service record
                </Link>
            </div>
        </div>
    </AdminLayout>
</template>
