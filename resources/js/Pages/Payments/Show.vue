<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { confirmAction } from '@/lib/swal.js';

const props = defineProps({ transaction: Object });

const money = (v) => 'RM ' + Number(v ?? 0).toFixed(2);
const processing = ref(false);
const method = ref(null); // 'duitnow' | 'cash'

const payByGateway = () => {
    processing.value = true;
    router.post(route('payments.pay', props.transaction.id), {}, {
        onFinish: () => (processing.value = false),
    });
};

const payByCash = async () => {
    const ok = await confirmAction({
        title: 'Confirm cash received?',
        body: 'This marks the transaction paid and issues a receipt.',
        confirmText: 'Confirm payment',
    });
    if (!ok) return;
    processing.value = true;
    router.post(route('payments.cash', props.transaction.id), {}, {
        onFinish: () => (processing.value = false),
    });
};

const handleConfirm = () => {
    if (method.value === 'duitnow') payByGateway();
    else if (method.value === 'cash') payByCash();
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

        <div class="mx-auto max-w-lg space-y-5">
            <PageHeader title="Collect Payment" :subtitle="transaction.client.name + ' · #' + transaction.client.serial_no" />

            <!-- Amount panel -->
            <div class="overflow-hidden rounded-ral bg-navy-900 shadow-card">
                <div class="px-6 py-5 text-center text-white">
                    <div class="text-xs font-semibold tracking-widest text-primary-300 uppercase">Total Amount</div>
                    <div class="mt-2 font-mono text-5xl font-extrabold tracking-tight">{{ money(transaction.amount) }}</div>
                    <div class="mt-2 font-mono text-xs text-primary-300">{{ transaction.txn_id }}</div>
                </div>
            </div>

            <!-- Method selection -->
            <div class="space-y-3">
                <!-- DuitNow QR -->
                <button
                    type="button"
                    :disabled="processing"
                    class="w-full rounded-ral border-2 bg-surface p-4 text-left transition focus:outline-none disabled:opacity-50"
                    :class="method === 'duitnow'
                        ? 'border-primary bg-primary-50'
                        : 'border-line hover:border-primary/40 hover:bg-primary-50/30'"
                    @click="method = 'duitnow'"
                >
                    <div class="flex items-center gap-4">
                        <div
                            class="grid h-11 w-11 shrink-0 place-items-center rounded-ral text-lg font-bold"
                            :class="method === 'duitnow' ? 'bg-primary text-white' : 'bg-primary-50 text-primary'"
                        >
                            QR
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="font-semibold text-ink">DuitNow QR</div>
                            <div class="mt-0.5 text-sm text-ink-soft">Client scans with banking app</div>
                        </div>
                        <div
                            v-if="method === 'duitnow'"
                            class="h-5 w-5 shrink-0 rounded-full bg-primary text-white text-xs grid place-items-center"
                        >✓</div>
                    </div>

                    <!-- QR placeholder area shown when selected -->
                    <div
                        v-if="method === 'duitnow'"
                        class="mt-4 flex flex-col items-center gap-2 rounded-ral border border-primary/20 bg-white px-4 py-6"
                    >
                        <div class="grid h-28 w-28 place-items-center rounded-ra border-2 border-dashed border-primary/30 bg-primary-50 text-primary-300">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
                            </svg>
                        </div>
                        <p class="text-xs text-ink-soft">QR generated on confirm</p>
                    </div>
                </button>

                <!-- Cash -->
                <button
                    type="button"
                    :disabled="processing"
                    class="w-full rounded-ral border-2 bg-surface p-4 text-left transition focus:outline-none disabled:opacity-50"
                    :class="method === 'cash'
                        ? 'border-amber-400 bg-amber-50'
                        : 'border-line hover:border-amber-300/60 hover:bg-amber-50/30'"
                    @click="method = 'cash'"
                >
                    <div class="flex items-center gap-4">
                        <div
                            class="grid h-11 w-11 shrink-0 place-items-center rounded-ral text-lg"
                            :class="method === 'cash' ? 'bg-amber-400 text-white' : 'bg-amber-50 text-amber-500'"
                        >
                            💵
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="font-semibold text-ink">Cash</div>
                            <div class="mt-0.5 text-sm text-ink-soft">Received in hand</div>
                        </div>
                        <div
                            v-if="method === 'cash'"
                            class="h-5 w-5 shrink-0 rounded-full bg-amber-400 text-white text-xs grid place-items-center"
                        >✓</div>
                    </div>

                    <!-- Cash confirm state -->
                    <div
                        v-if="method === 'cash'"
                        class="mt-4 rounded-ral border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
                    >
                        <span class="font-semibold">{{ money(transaction.amount) }}</span> will be marked as received — a receipt will be issued immediately.
                    </div>
                </button>
            </div>

            <!-- Confirm button -->
            <button
                type="button"
                :disabled="!method || processing"
                class="w-full rounded-ral px-4 py-3.5 font-semibold text-white transition disabled:opacity-40"
                :class="method === 'cash'
                    ? 'bg-amber-500 hover:bg-amber-600'
                    : method === 'duitnow'
                        ? 'bg-primary hover:bg-primary-600'
                        : 'bg-primary hover:bg-primary-600'"
                @click="handleConfirm"
            >
                <span v-if="processing">Processing…</span>
                <span v-else-if="method === 'duitnow'">Confirm Payment — DuitNow QR</span>
                <span v-else-if="method === 'cash'">Confirm Payment — Cash</span>
                <span v-else>Select a payment method</span>
            </button>

            <div class="text-center text-sm">
                <Link :href="route('service-records.show', transaction.visit_id ?? transaction.id)" class="text-ink-soft hover:text-ink">
                    Back to service record
                </Link>
            </div>
        </div>
    </AdminLayout>
</template>
