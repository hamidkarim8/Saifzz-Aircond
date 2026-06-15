<script setup>
import { computed, ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/Card.vue';
import Badge from '@/Components/Badge.vue';
import FeeModal from './Partials/FeeModal.vue';
import { useForm, router, usePage } from '@inertiajs/vue3';
import { IconPencil, IconCheck, IconX, IconPlus } from '@tabler/icons-vue';
import { serviceVariant } from '@/lib/badges';
import { confirmDanger } from '@/lib/swal';

const props = defineProps({
    serviceTypes: Array,
    feeGroups: { type: Object, default: () => ({}) },
    modes: Array,
});

// --- Tabs ---
const activeTab = ref('types');

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
const canEditFees = computed(() => usePage().props.auth?.can?.edit_fees ?? false);
</script>

<template>
    <AdminLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h1 class="text-base font-bold text-navy-800">Services</h1>
                <!-- Show one button depending on active tab -->
                <button
                    v-if="activeTab === 'types'"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-ra bg-primary px-3 py-1.5 text-sm font-semibold text-white shadow-card hover:bg-primary-hover"
                    @click="showAdd = true"
                >
                    <IconPlus class="h-4 w-4" />
                    <span class="hidden sm:inline">Add Service Type</span>
                    <span class="sm:hidden">Add</span>
                </button>
                <button
                    v-else-if="activeTab === 'fees' && canEditFees"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-ra bg-primary px-3 py-1.5 text-sm font-semibold text-white shadow-card hover:bg-primary-hover"
                    @click="openAdd"
                >
                    <IconPlus class="h-4 w-4" />
                    <span class="hidden sm:inline">Set Fee</span>
                    <span class="sm:hidden">Add</span>
                </button>
            </div>
        </template>

        <!-- Tabs -->
        <div class="mb-6 border-b border-line">
            <div class="flex gap-0">
                <button
                    class="border-b-2 px-5 py-3 text-sm font-semibold transition"
                    :class="activeTab === 'types' ? 'border-primary text-primary' : 'border-transparent text-ink-soft hover:text-ink'"
                    @click="activeTab = 'types'"
                >
                    Service Types
                </button>
                <button
                    class="border-b-2 px-5 py-3 text-sm font-semibold transition"
                    :class="activeTab === 'fees' ? 'border-primary text-primary' : 'border-transparent text-ink-soft hover:text-ink'"
                    @click="activeTab = 'fees'"
                >
                    Fee Schedule
                </button>
            </div>
        </div>

        <!-- Service Types Tab -->
        <div v-if="activeTab === 'types'">
            <div class="overflow-hidden rounded-ral border border-line bg-surface shadow-card">
                <div class="divide-y divide-line">
                    <div
                        v-for="type in serviceTypes"
                        :key="type.id"
                        class="flex items-center gap-3 px-4 py-3.5"
                    >
                        <template v-if="editingId !== type.id">
                            <span class="flex-1 text-sm font-medium text-ink">{{ type.name }}</span>
                            <button
                                type="button"
                                class="flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium transition"
                                :class="type.requires_next_service ? 'bg-primary/10 text-primary' : 'bg-surface-muted text-ink-soft'"
                                @click="toggleNextService(type)"
                            >
                                <span
                                    class="h-3 w-3 rounded-full border-2 transition"
                                    :class="type.requires_next_service ? 'border-primary bg-primary' : 'border-ink-muted bg-transparent'"
                                />
                                <span class="hidden sm:inline">{{ type.requires_next_service ? 'Next service on' : 'No follow-up' }}</span>
                                <span class="sm:hidden">{{ type.requires_next_service ? 'On' : 'Off' }}</span>
                            </button>
                            <button
                                type="button"
                                class="rounded p-1.5 text-ink-muted hover:text-primary hover:bg-surface-muted transition"
                                @click="startEdit(type)"
                            >
                                <IconPencil class="h-4 w-4" />
                            </button>
                        </template>

                        <template v-else>
                            <input
                                v-model="editForm.name"
                                class="flex-1 rounded-ra border border-line bg-surface px-3 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                @keyup.enter="submitEdit(type)"
                                @keyup.escape="cancelEdit"
                                autofocus
                            />
                            <p v-if="editForm.errors.name" class="text-xs text-danger">{{ editForm.errors.name }}</p>
                            <button
                                type="button"
                                class="rounded p-1.5 text-ok hover:bg-ok-bg transition"
                                :disabled="editForm.processing"
                                @click="submitEdit(type)"
                            >
                                <IconCheck class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                class="rounded p-1.5 text-ink-muted hover:text-danger hover:bg-danger-bg transition"
                                @click="cancelEdit"
                            >
                                <IconX class="h-4 w-4" />
                            </button>
                        </template>
                    </div>

                    <!-- Add row -->
                    <div class="px-4 py-3.5">
                        <template v-if="!showAdd">
                            <button
                                type="button"
                                class="flex items-center gap-1.5 text-sm text-primary hover:underline"
                                @click="showAdd = true"
                            >
                                <IconPlus class="h-4 w-4" />
                                Add service type
                            </button>
                        </template>
                        <template v-else>
                            <div class="flex items-center gap-3">
                                <input
                                    v-model="addForm.name"
                                    placeholder="Service type name…"
                                    class="flex-1 rounded-ra border border-line bg-surface px-3 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                    @keyup.enter="submitAdd"
                                    @keyup.escape="showAdd = false; addForm.reset()"
                                    autofocus
                                />
                                <button
                                    type="button"
                                    class="rounded p-1.5 text-ok hover:bg-ok-bg transition"
                                    :disabled="addForm.processing"
                                    @click="submitAdd"
                                >
                                    <IconCheck class="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    class="rounded p-1.5 text-ink-muted hover:text-danger hover:bg-danger-bg transition"
                                    @click="showAdd = false; addForm.reset()"
                                >
                                    <IconX class="h-4 w-4" />
                                </button>
                            </div>
                            <p v-if="addForm.errors.name" class="mt-1 text-xs text-danger">{{ addForm.errors.name }}</p>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fee Schedule Tab -->
        <div v-else-if="activeTab === 'fees'">
            <!-- Subtle info note -->
            <p class="mb-5 text-sm text-ink-soft">
                Rates are auto-applied when a technician picks a service type. Editing a rate only affects future service records — existing records already have the price locked in at time of job.
            </p>

            <div v-if="Object.keys(feeGroups).length === 0" class="rounded-ral border border-line bg-surface p-10 text-center shadow-card">
                <p class="text-sm font-medium text-ink-soft">No fee entries yet.</p>
                <p class="mt-1 text-sm text-ink-muted">Click "Set Fee" to add your first pricing entry.</p>
            </div>

            <div v-else class="space-y-4">
                <div
                    v-for="(fees, type) in feeGroups"
                    :key="type"
                    class="overflow-hidden rounded-ral border border-line bg-surface shadow-card"
                >
                    <!-- Service type header -->
                    <div class="flex items-center gap-3 border-b border-line bg-surface-muted px-4 py-2.5">
                        <Badge :variant="serviceVariant(type)">{{ type }}</Badge>
                    </div>

                    <!-- Fee rows -->
                    <div class="divide-y divide-line">
                        <div
                            v-for="f in fees"
                            :key="f.id"
                            class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3"
                        >
                            <div class="flex items-center gap-3">
                                <span class="text-sm font-medium text-ink">{{ f.option || 'Flat job' }}</span>
                            </div>
                            <div class="flex items-center gap-4">
                                <Badge v-if="f.pricing_mode === 'flexible'" variant="amber">Flexible</Badge>
                                <span v-else class="font-mono font-semibold text-navy-800">
                                    {{ money(f.rate) }}<span class="ml-1 text-xs font-normal text-ink-soft">/ {{ modeLabel[f.pricing_mode] ?? f.pricing_mode }}</span>
                                </span>
                                <div v-if="canEditFees" class="flex items-center gap-3">
                                    <button type="button" class="text-sm font-medium text-primary hover:text-primary-hover" @click="openEdit(f)">Edit</button>
                                    <button type="button" class="text-sm font-medium text-danger hover:underline" @click="remove(f)">Delete</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
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
