<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import FeeModal from './Partials/FeeModal.vue';

defineProps({
    feeGroups: Object, // { serviceType: ServiceFee[] }
    serviceTypes: Array,
    modes: Array,
});

const modalOpen = ref(false);
const editing = ref(null);

const openAdd = () => { editing.value = null; modalOpen.value = true; };
const openEdit = (fee) => { editing.value = fee; modalOpen.value = true; };

const remove = (fee) => {
    if (confirm(`Remove ${fee.service_type}${fee.option ? ' · ' + fee.option : ''}?`)) {
        router.delete(route('fees.destroy', fee.id), { preserveScroll: true });
    }
};

const money = (v) => v == null ? '—' : 'RM ' + Number(v).toFixed(2);

const typeAccent = {
    Cleaning: 'border-primary',
    'Gas Top-Up': 'border-warn',
    Repair: 'border-danger',
    Installation: 'border-ok',
    Troubleshoot: 'border-invoice',
};
const modeBadge = {
    fixed_per_unit: 'bg-primary-50 text-primary',
    tiered: 'bg-warn-bg text-warn',
    flexible: 'bg-invoice-bg text-invoice',
};
const modeLabel = { fixed_per_unit: 'per unit', tiered: 'tiered', flexible: 'flexible' };
</script>

<template>
    <Head title="Service Fees" />

    <AdminLayout>
        <template #header>
            <h1 class="text-lg font-bold tracking-tight text-navy-800">Service Fees</h1>
        </template>

        <div class="mb-5 flex items-center justify-between">
            <p class="text-sm text-ink-soft">The price book that auto-fills service rates. Edits affect future records only.</p>
            <button class="inline-flex items-center gap-2 rounded-ra bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover" @click="openAdd">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg>
                Add fee
            </button>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <section v-for="(fees, type) in feeGroups" :key="type" class="overflow-hidden rounded-ral border border-line bg-surface shadow-card border-l-4" :class="typeAccent[type]">
                <header class="border-b border-line px-5 py-3 font-bold text-navy-800">{{ type }}</header>
                <ul class="divide-y divide-line">
                    <li v-for="f in fees" :key="f.id" class="flex items-center justify-between gap-3 px-5 py-3">
                        <div class="min-w-0">
                            <div class="truncate font-medium text-ink">{{ f.option ?? 'Flat job' }}</div>
                            <span class="mt-0.5 inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="modeBadge[f.pricing_mode]">{{ modeLabel[f.pricing_mode] }}</span>
                        </div>
                        <div class="flex items-center gap-4">
                            <span class="font-mono font-semibold text-navy-800">{{ money(f.rate) }}</span>
                            <div class="flex items-center gap-2 text-sm">
                                <button class="font-medium text-primary hover:text-primary-hover" @click="openEdit(f)">Edit</button>
                                <button class="font-medium text-danger hover:underline" @click="remove(f)">Remove</button>
                            </div>
                        </div>
                    </li>
                </ul>
            </section>
        </div>

        <FeeModal :open="modalOpen" :fee="editing" :service-types="serviceTypes" :modes="modes" @close="modalOpen = false" />
    </AdminLayout>
</template>
