<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Badge from '@/Components/Badge.vue';
import UnitModal from './UnitModal.vue';

const props = defineProps({
    client: Object,
    canManage: Boolean,
});

const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const fmtDate = (d) => {
    if (!d) return null;
    const [y, m, day] = d.slice(0, 10).split('-');
    return `${day} ${months[+m - 1]} ${y}`;
};
const hpLabel = (hp) => hp ? `${Number(hp)} HP` : null;

const modalOpen = ref(false);
const editingUnit = ref(null);

const openAdd = () => { editingUnit.value = null; modalOpen.value = true; };
const openEdit = (unit) => { editingUnit.value = unit; modalOpen.value = true; };
const closeModal = () => { modalOpen.value = false; editingUnit.value = null; };

const deactivate = (unit) => {
    if (!confirm(`Deactivate "${unit.label}"? It won't show in new service records.`)) return;
    router.patch(route('clients.units.deactivate', [props.client.id, unit.id]), {}, { preserveScroll: true });
};

const units = props.client.units ?? [];
const activeUnits = units.filter(u => u.is_active);
</script>

<template>
    <section>
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-sm font-bold uppercase tracking-wide text-ink-soft">Units ({{ activeUnits.length }})</h3>
            <button v-if="canManage" class="text-sm font-semibold text-primary hover:text-primary-hover"
                    @click="openAdd">+ Add unit</button>
        </div>

        <div v-if="activeUnits.length" class="space-y-2">
            <div v-for="unit in activeUnits" :key="unit.id"
                 class="rounded-ral border border-line bg-surface p-3 shadow-card">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="font-semibold text-sm text-navy-800">{{ unit.label }}</div>
                        <div class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-ink-soft">
                            <span>{{ unit.unit_type }}</span>
                            <span v-if="hpLabel(unit.hp)">· {{ hpLabel(unit.hp) }}</span>
                            <span v-if="unit.brand">· {{ unit.brand }}</span>
                            <span v-if="unit.model" class="text-ink-muted">{{ unit.model }}</span>
                        </div>
                        <div v-if="unit.serial_no" class="mt-0.5 font-mono text-[11px] tracking-wide text-ink-muted">SN: {{ unit.serial_no }}</div>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <Badge v-if="unit.refrigerant_type" variant="blue">{{ unit.refrigerant_type }}</Badge>
                        <div v-if="canManage" class="flex gap-2">
                            <button class="text-xs font-medium text-primary hover:underline" @click="openEdit(unit)">Edit</button>
                            <button class="text-xs font-medium text-danger hover:underline" @click="deactivate(unit)">Deactivate</button>
                        </div>
                    </div>
                </div>
                <div v-if="unit.next_service_date" class="mt-2 flex items-center gap-1 text-xs text-ink-soft">
                    <svg class="h-3 w-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                    Next service: <span class="font-semibold text-ink">{{ fmtDate(unit.next_service_date) }}</span>
                    <span v-if="unit.next_service_type" class="text-ink-muted">({{ unit.next_service_type }})</span>
                </div>
            </div>
        </div>
        <p v-else class="rounded-ral border border-dashed border-line bg-surface py-6 text-center text-sm text-ink-soft">
            No units registered yet.
        </p>

        <UnitModal
            :open="modalOpen"
            :client-id="client.id"
            :unit="editingUnit"
            @close="closeModal"
        />
    </section>
</template>
