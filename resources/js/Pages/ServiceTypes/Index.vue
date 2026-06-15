<script setup>
import { computed, ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/Card.vue';
import Badge from '@/Components/Badge.vue';
import FeeModal from './Partials/FeeModal.vue';
import { useForm, router } from '@inertiajs/vue3';
import { IconPencil, IconCheck, IconX, IconPlus } from '@tabler/icons-vue';
import { serviceVariant } from '@/lib/badges';
import { confirmDanger } from '@/lib/swal';

const props = defineProps({
    serviceTypes: Array,
    feeGroups: Object,
    modes: Array,
});

// --- Service Types ---
const addForm = useForm({ name: '' });
const showAdd = ref(false);

function submitAdd() {
    addForm.post(route('service-types.store'), {
        onSuccess: () => { addForm.reset(); showAdd.value = false; },
    });
}

const editingId = ref(null);
const editForm = useForm({ name: '', requires_next_service: false });

function startEdit(type) {
    editingId.value = type.id;
    editForm.name = type.name;
    editForm.requires_next_service = type.requires_next_service;
}

function cancelEdit() {
    editingId.value = null;
    editForm.reset();
}

function submitEdit(type) {
    editForm.put(route('service-types.update', type.id), {
        onSuccess: () => { editingId.value = null; },
    });
}

function toggleNextService(type) {
    router.put(route('service-types.update', type.id), {
        name: type.name,
        requires_next_service: !type.requires_next_service,
    }, { preserveScroll: true });
}

// --- Fees ---
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
const modeLabel = { fixed_per_unit: 'per unit', flexible: 'Flexible' };
const serviceTypeNames = computed(() => props.serviceTypes.map((t) => t.name));
</script>

<template>
    <AdminLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h1 class="text-base font-bold text-navy-800">Service Settings</h1>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-ra border border-line bg-surface px-3 py-1.5 text-sm font-medium text-ink shadow-card hover:bg-surface-muted"
                        @click="showAdd = true"
                    >
                        <IconPlus class="h-4 w-4" />
                        New Service Type
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-ra bg-primary px-3 py-1.5 text-sm font-semibold text-white shadow-card hover:bg-primary-hover"
                        @click="openAdd"
                    >
                        <IconPlus class="h-4 w-4" />
                        Set Fee
                    </button>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-3xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
            <!-- Service Types section -->
            <Card title="Service Types">
                <div class="divide-y divide-line">
                    <div
                        v-for="type in serviceTypes"
                        :key="type.id"
                        class="flex items-center gap-3 px-4 py-3"
                    >
                        <template v-if="editingId !== type.id">
                            <span class="flex-1 text-sm font-medium text-ink">{{ type.name }}</span>
                            <button
                                type="button"
                                class="flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium transition"
                                :class="type.requires_next_service ? 'bg-primary-50 text-primary' : 'bg-surface-muted text-ink-soft'"
                                @click="toggleNextService(type)"
                            >
                                <span
                                    class="h-3.5 w-3.5 rounded-full border-2 transition"
                                    :class="type.requires_next_service ? 'border-primary bg-primary' : 'border-ink-muted bg-transparent'"
                                />
                                {{ type.requires_next_service ? 'Next service' : 'No follow-up' }}
                            </button>
                            <button
                                type="button"
                                class="rounded p-1 text-ink-muted hover:text-primary"
                                @click="startEdit(type)"
                            >
                                <IconPencil class="h-4 w-4" />
                            </button>
                        </template>

                        <template v-else>
                            <input
                                v-model="editForm.name"
                                class="flex-1 rounded-ra border-line bg-surface px-3 py-1.5 text-sm text-ink focus:border-primary focus:ring-primary"
                                @keyup.enter="submitEdit(type)"
                                @keyup.escape="cancelEdit"
                            />
                            <p v-if="editForm.errors.name" class="text-xs text-danger">{{ editForm.errors.name }}</p>
                            <button
                                type="button"
                                class="rounded p-1 text-success hover:text-success/80"
                                :disabled="editForm.processing"
                                @click="submitEdit(type)"
                            >
                                <IconCheck class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                class="rounded p-1 text-ink-muted hover:text-danger"
                                @click="cancelEdit"
                            >
                                <IconX class="h-4 w-4" />
                            </button>
                        </template>
                    </div>

                    <div class="px-4 py-3">
                        <template v-if="!showAdd">
                            <button
                                type="button"
                                class="flex items-center gap-1.5 text-sm text-primary hover:underline"
                                @click="showAdd = true"
                            >
                                <IconPlus class="h-4 w-4" />
                                Add type
                            </button>
                        </template>
                        <template v-else>
                            <div class="flex items-center gap-3">
                                <input
                                    v-model="addForm.name"
                                    placeholder="Type name…"
                                    class="flex-1 rounded-ra border-line bg-surface px-3 py-1.5 text-sm text-ink focus:border-primary focus:ring-primary"
                                    @keyup.enter="submitAdd"
                                    @keyup.escape="showAdd = false; addForm.reset()"
                                />
                                <button
                                    type="button"
                                    class="rounded p-1 text-success hover:text-success/80"
                                    :disabled="addForm.processing"
                                    @click="submitAdd"
                                >
                                    <IconCheck class="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    class="rounded p-1 text-ink-muted hover:text-danger"
                                    @click="showAdd = false; addForm.reset()"
                                >
                                    <IconX class="h-4 w-4" />
                                </button>
                            </div>
                            <p v-if="addForm.errors.name" class="mt-1 text-xs text-danger">{{ addForm.errors.name }}</p>
                        </template>
                    </div>
                </div>
            </Card>

            <!-- Fee Schedule section -->
            <div>
                <div class="mb-5 flex gap-3 rounded-ral border border-primary/20 bg-primary-50 px-4 py-3.5 text-sm text-primary">
                    <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><path d="M12 8v4M12 16h.01" stroke-linecap="round" /></svg>
                    <span>
                        Rates here are <strong>auto-applied</strong> when a technician picks a service type and unit type on a job.
                        Gas Top-Up entries are billed by PSI level; Repair jobs use <strong>flexible pricing</strong> set per-job by the technician.
                        Changes only affect future service lines — past records keep their snapshotted rate.
                    </span>
                </div>

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
            </div>
        </div>

        <FeeModal
            :open="modalOpen"
            :fee="editing"
            :service-types="serviceTypeNames"
            :modes="modes"
            @close="modalOpen = false"
        />
    </AdminLayout>
</template>
