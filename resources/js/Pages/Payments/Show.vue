<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({ transaction: Object });

const money = (v) => 'RM ' + Number(v ?? 0).toFixed(2);
const processing = ref(false);

const payByGateway = () => {
    processing.value = true;
    router.post(route('payments.pay', props.transaction.id), {}, {
        onFinish: () => (processing.value = false),
    });
};

const payByCash = () => {
    if (!confirm('Confirm cash received for ' + money(props.transaction.amount) + '?')) return;
    processing.value = true;
    router.post(route('payments.cash', props.transaction.id), {}, {
        onFinish: () => (processing.value = false),
    });
};
</script>

<template>
    <Head title="Collect payment" />

    <AdminLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm">
                <span class="text-ink-soft">Payments</span>
                <span class="text-ink-muted">/</span>
                <span class="font-mono font-semibold text-navy-800">{{ transaction.txn_id }}</span>
            </div>
        </template>

        <div class="mx-auto max-w-md space-y-6">
            <div class="overflow-hidden rounded-ral border border-line bg-surface shadow-card">
                <div class="bg-navy-900 p-6 text-center text-white">
                    <div class="font-mono text-xs tracking-widest text-primary-300">{{ transaction.txn_id }}</div>
                    <div class="mt-2 text-sm text-primary-200">{{ transaction.client.name }} · #{{ transaction.client.serial_no }}</div>
                    <div class="mt-3 text-4xl font-extrabold">{{ money(transaction.amount) }}</div>
                </div>

                <div class="space-y-3 p-6">
                    <button
                        type="button"
                        :disabled="processing"
                        class="w-full rounded-ral bg-primary px-4 py-3 font-semibold text-white transition hover:bg-primary-600 disabled:opacity-50"
                        @click="payByGateway"
                    >
                        Pay with DuitNow QR
                    </button>
                    <button
                        type="button"
                        :disabled="processing"
                        class="w-full rounded-ral border border-line bg-surface px-4 py-3 font-semibold text-ink transition hover:bg-surface-muted disabled:opacity-50"
                        @click="payByCash"
                    >
                        Record cash payment
                    </button>
                </div>
            </div>

            <div class="text-center text-sm">
                <Link :href="route('service-records.show', transaction.visit_id ?? transaction.id)" class="text-ink-soft hover:text-ink">
                    Back to service record
                </Link>
            </div>
        </div>
    </AdminLayout>
</template>
