<script setup>
import { computed, reactive, ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/Card.vue';
import Badge from '@/Components/Badge.vue';
import { useForm, router, usePage } from '@inertiajs/vue3';
import { IconPencil, IconCheck, IconX, IconPlus } from '@tabler/icons-vue';
import { serviceVariant } from '@/lib/badges';
import { confirmDanger } from '@/lib/swal';

const props = defineProps({
    serviceTypes: Array,
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
const canEditFees = computed(() => usePage().props.auth?.can?.edit_fees ?? false);

function buildEditor(type) {
    const mode = type.pricing_mode;
    let unitBlocks = [];
    if (mode === 'hp_tiered') {
        const byUnit = {};
        for (const f of type.fees) {
            (byUnit[f.unit_type] ??= []).push({ hp_value: Number(f.hp_value), price: Number(f.price) });
        }
        unitBlocks = Object.entries(byUnit).map(([unit_type, tiers]) => ({ unit_type, tiers }));
    } else if (mode === 'flat') {
        unitBlocks = type.fees.map(f => ({ unit_type: f.unit_type, price: Number(f.price) }));
    }
    return reactive({ pricing_mode: mode, unitBlocks, saving: false, errors: {} });
}

const editors = reactive({});
for (const t of props.serviceTypes) editors[t.id] = buildEditor(t);

function addUnit(ed) {
    ed.unitBlocks.push(ed.pricing_mode === 'hp_tiered'
        ? { unit_type: '', tiers: [{ hp_value: '', price: '' }] }
        : { unit_type: '', price: '' });
}
function removeUnit(ed, i) { ed.unitBlocks.splice(i, 1); }
function addTier(block) { block.tiers.push({ hp_value: '', price: '' }); }
function removeTier(block, i) { block.tiers.splice(i, 1); }

function onModeChange(ed) {
    // Reset blocks when switching mode so flat<->hp_tiered shapes stay valid.
    ed.unitBlocks = [];
}

function flatten(ed) {
    if (ed.pricing_mode === 'flexible') return [];
    if (ed.pricing_mode === 'flat') {
        return ed.unitBlocks.map(b => ({ unit_type: b.unit_type, hp_value: null, price: b.price }));
    }
    const rows = [];
    for (const b of ed.unitBlocks) {
        for (const t of b.tiers) rows.push({ unit_type: b.unit_type, hp_value: t.hp_value, price: t.price });
    }
    return rows;
}

function saveFees(type) {
    const ed = editors[type.id];
    ed.saving = true;
    ed.errors = {};
    router.put(route('service-types.fees.sync', type.id), {
        pricing_mode: ed.pricing_mode,
        fees: flatten(ed),
    }, {
        preserveScroll: true,
        onError: (e) => { ed.errors = e; ed.saving = false; },
        onSuccess: () => { ed.saving = false; },
    });
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h1 class="text-base font-bold text-navy-800">Services</h1>
                <!-- Show Add Service Type button only on types tab -->
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
            <p class="mb-5 text-sm text-ink-soft">
                Set each service's pricing. HP-tiered services price every unit type by HP. Flexible services let the technician enter price + description per job. Changes apply to future records only.
            </p>

            <div class="space-y-5">
                <div v-for="type in serviceTypes" :key="type.id" class="overflow-hidden rounded-ra border border-line bg-surface shadow-card">
                    <div class="flex items-center justify-between gap-3 border-b border-line bg-surface-muted px-4 py-2.5">
                        <Badge :variant="serviceVariant(type.name)">{{ type.name }}</Badge>
                        <select v-if="canEditFees" v-model="editors[type.id].pricing_mode" @change="onModeChange(editors[type.id])"
                            class="rounded-ra border-line bg-surface text-sm text-ink shadow-card focus:border-primary focus:ring-primary">
                            <option value="flat">Flat (per unit type)</option>
                            <option value="hp_tiered">HP-tiered</option>
                            <option value="flexible">Flexible (manual)</option>
                        </select>
                    </div>

                    <p v-if="editors[type.id].errors.pricing_mode" class="px-4 pt-2 text-xs text-danger">{{ editors[type.id].errors.pricing_mode }}</p>
                    <p v-if="editors[type.id].errors.fees" class="px-4 pt-2 text-xs text-danger">{{ editors[type.id].errors.fees }}</p>

                    <div v-if="editors[type.id].pricing_mode === 'flexible'" class="px-4 py-4 text-sm text-ink-soft">
                        No fixed prices — technician enters price and description at time of job.
                    </div>

                    <div v-else class="divide-y divide-line">
                        <div v-for="(block, bi) in editors[type.id].unitBlocks" :key="bi" class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <input v-model="block.unit_type" placeholder="Unit type (e.g. Wall Mounted)"
                                    class="flex-1 rounded-ra border-line bg-surface text-sm text-ink shadow-card focus:border-primary focus:ring-primary" />
                                <input v-if="editors[type.id].pricing_mode === 'flat'" v-model.number="block.price" type="number" step="0.01" min="0" placeholder="Price"
                                    class="w-28 rounded-ra border-line bg-surface font-mono text-sm text-ink shadow-card focus:border-primary focus:ring-primary" />
                                <button v-if="canEditFees" type="button" class="text-sm font-medium text-danger hover:underline" @click="removeUnit(editors[type.id], bi)">Remove</button>
                            </div>

                            <div v-if="editors[type.id].pricing_mode === 'hp_tiered'" class="mt-3 space-y-2 pl-1">
                                <div v-for="(tier, ti) in block.tiers" :key="ti" class="flex items-center gap-3">
                                    <input v-model.number="tier.hp_value" type="number" step="0.5" min="0.5" max="20" placeholder="HP"
                                        class="w-24 rounded-ra border-line bg-surface font-mono text-sm text-ink shadow-card focus:border-primary focus:ring-primary" />
                                    <input v-model.number="tier.price" type="number" step="0.01" min="0" placeholder="Price"
                                        class="w-28 rounded-ra border-line bg-surface font-mono text-sm text-ink shadow-card focus:border-primary focus:ring-primary" />
                                    <button type="button" class="text-xs text-danger hover:underline" @click="removeTier(block, ti)">×</button>
                                </div>
                                <button type="button" class="text-sm text-primary hover:underline" @click="addTier(block)">+ Add HP tier</button>
                            </div>
                        </div>

                        <div v-if="canEditFees" class="flex items-center justify-between px-4 py-3">
                            <button type="button" class="flex items-center gap-1.5 text-sm text-primary hover:underline" @click="addUnit(editors[type.id])">
                                <IconPlus class="h-4 w-4" /> Add unit type
                            </button>
                            <button type="button" :disabled="editors[type.id].saving"
                                class="rounded-ra bg-primary px-4 py-1.5 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60"
                                @click="saveFees(type)">Save fees</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
