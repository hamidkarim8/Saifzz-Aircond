<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Badge from '@/Components/Badge.vue';
import FeeModal from './Partials/FeeModal.vue';
import { serviceVariant } from '@/lib/badges';
import { confirmDanger } from '@/lib/swal';

defineProps({
    feeGroups: Object, // { serviceType: ServiceFee[] }
    serviceTypes: Array,
    modes: Array,
});

const modalOpen = ref(false);
const editing = ref(null);

const openAdd = () => { editing.value = null; modalOpen.value = true; };
const openEdit = (fee) => { editing.value = fee; modalOpen.value = true; };

const remove = async (fee) => {
    const label = fee.service_type + (fee.option ? ' · ' + fee.option : '');
    const ok = await confirmDanger({
        title: 'Delete this fee?',
        body: `<strong>${label}</strong><br>Existing records keep their snapshotted price.`,
        confirmText: 'Delete',
    });
    if (ok) {
        router.delete(route('fees.destroy', fee.id), { preserveScroll: true });
    }
};

const money = (v) => v == null ? '—' : 'RM ' + Number(v).toFixed(2);

const modeLabel = { fixed_per_unit: 'per unit', tiered: 'tiered', flexible: 'Flexible' };
</script>

<template>
    <Head title="Service Fees" />

    <AdminLayout>
        <template #header>
            <h1 class="text-lg font-bold tracking-tight text-navy-800">Service Fee Settings</h1>
        </template>

        <PageHeader
            title="Service Fee Settings"
            subtitle="Set price per service type and unit type"
        >
            <template #actions>
                <button
                    class="inline-flex items-center gap-2 rounded-ra bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover"
                    @click="openAdd"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg>
                    Add Fee Entry
                </button>
            </template>
        </PageHeader>

        <!-- Info banner -->
        <div class="mb-5 flex gap-3 rounded-ral border border-primary/20 bg-primary-50 px-4 py-3.5 text-sm text-primary">
            <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><path d="M12 8v4M12 16h.01" stroke-linecap="round" /></svg>
            <span>
                Rates here are <strong>auto-applied</strong> when a technician picks a service type and unit type on a job.
                Gas Top-Up entries are billed by PSI level; Repair jobs use <strong>flexible pricing</strong> set per-job by the technician.
                Changes only affect future service lines — past records keep their snapshotted rate.
            </span>
        </div>

        <!-- Fee table -->
        <Card title="Fee Schedule">
            <div v-if="Object.keys(feeGroups).length === 0" class="py-8 text-center text-sm text-ink-soft">
                No fee entries yet. Add your first fee entry to get started.
            </div>
            <table v-else class="w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">
                        <th class="pb-2.5 pr-4">Service Type</th>
                        <th class="pb-2.5 pr-4">Unit / Option</th>
                        <th class="pb-2.5 pr-4">Fee</th>
                        <th class="pb-2.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <template v-for="(fees, type) in feeGroups" :key="type">
                        <tr v-for="(f, idx) in fees" :key="f.id" class="group">
                            <td class="py-3 pr-4 align-middle">
                                <Badge v-if="idx === 0" :variant="serviceVariant(type)">{{ type }}</Badge>
                            </td>
                            <td class="py-3 pr-4 align-middle font-medium text-ink">
                                {{ f.option || 'Flat job' }}
                            </td>
                            <td class="py-3 pr-4 align-middle">
                                <Badge v-if="f.pricing_mode === 'flexible'" variant="amber">Flexible</Badge>
                                <span v-else class="font-mono font-semibold text-navy-800">
                                    {{ money(f.rate) }}<span class="ml-1 text-xs font-normal text-ink-soft">/ {{ modeLabel[f.pricing_mode] ?? f.pricing_mode }}</span>
                                </span>
                            </td>
                            <td class="py-3 align-middle text-right">
                                <div class="flex items-center justify-end gap-3">
                                    <button class="text-sm font-medium text-primary hover:text-primary-hover" @click="openEdit(f)">Edit</button>
                                    <button class="text-sm font-medium text-danger hover:underline" @click="remove(f)">Delete</button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </Card>

        <FeeModal :open="modalOpen" :fee="editing" :service-types="serviceTypes" :modes="modes" @close="modalOpen = false" />
    </AdminLayout>
</template>
