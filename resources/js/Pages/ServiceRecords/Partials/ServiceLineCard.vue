<script setup>
import { computed, watch } from 'vue';

const props = defineProps({
    line: { type: Object, required: true },
    index: { type: Number, required: true },
    feeMap: { type: Object, required: true }, // "type|option" -> rate
    serviceTypes: Array,
    unitTypes: Array,
    gasOptions: Array,
    unitTypeServices: Array,
    errors: { type: Object, default: () => ({}) },
    removable: Boolean,
});
const emit = defineEmits(['remove']);

const isRepair = computed(() => props.line.service_type === 'Repair');
const isGas = computed(() => props.line.service_type === 'Gas Top-Up');
const carriesUnitType = computed(() => props.unitTypeServices.includes(props.line.service_type));

const err = (field) => props.errors[`lines.${props.index}.${field}`];

// Reset type-specific fields + auto-fill rate when the shape changes.
watch(() => props.line.service_type, () => {
    props.line.unit_type = null;
    props.line.gas_option = null;
    props.line.repair_desc = '';
    props.line.next_service_date = null;
    props.line.notes = '';
    if (isRepair.value) props.line.rate = '';
    autofill();
});
watch([() => props.line.unit_type, () => props.line.gas_option], autofill);

function autofill() {
    if (isRepair.value || !props.line.service_type) return;
    const option = isGas.value ? props.line.gas_option : props.line.unit_type;
    if (!option) { props.line.rate = ''; return; }
    const rate = props.feeMap[`${props.line.service_type}|${option}`];
    props.line.rate = rate != null ? rate : '';
}

const subtotal = computed(() => {
    const v = (Number(props.line.rate) || 0) * (Number(props.line.units) || 0) - (Number(props.line.discount) || 0);
    return Math.max(0, v);
});

const money = (v) => 'RM ' + Number(v).toFixed(2);

const typeAccent = {
    Cleaning: 'border-l-primary', 'Gas Top-Up': 'border-l-warn', Repair: 'border-l-danger',
    Installation: 'border-l-ok', Troubleshoot: 'border-l-invoice',
};
</script>

<template>
    <div class="rounded-ral border border-line border-l-4 bg-surface p-4 shadow-card sm:p-5" :class="line.service_type ? typeAccent[line.service_type] : 'border-l-line'">
        <div class="mb-4 flex items-center justify-between">
            <span class="text-xs font-bold uppercase tracking-wide text-ink-muted">Service {{ index + 1 }}</span>
            <button v-if="removable" type="button" class="text-sm font-medium text-danger hover:underline" @click="emit('remove')">Remove</button>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <!-- Service type -->
            <div>
                <label class="mb-1.5 block text-sm font-semibold text-ink">Service type</label>
                <select v-model="line.service_type" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary">
                    <option value="" disabled>Choose…</option>
                    <option v-for="t in serviceTypes" :key="t" :value="t">{{ t }}</option>
                </select>
                <p v-if="err('service_type')" class="mt-1 text-sm text-danger">{{ err('service_type') }}</p>
            </div>

            <!-- Unit type (Cleaning / Installation / Troubleshoot) -->
            <div v-if="carriesUnitType">
                <label class="mb-1.5 block text-sm font-semibold text-ink">Unit type</label>
                <select v-model="line.unit_type" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary">
                    <option :value="null" disabled>Choose…</option>
                    <option v-for="u in unitTypes" :key="u" :value="u">{{ u }}</option>
                </select>
                <p v-if="err('unit_type')" class="mt-1 text-sm text-danger">{{ err('unit_type') }}</p>
            </div>

            <!-- Gas option -->
            <div v-if="isGas">
                <label class="mb-1.5 block text-sm font-semibold text-ink">Gas option</label>
                <select v-model="line.gas_option" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary">
                    <option :value="null" disabled>Choose…</option>
                    <option v-for="g in gasOptions" :key="g" :value="g">{{ g }}</option>
                </select>
                <p v-if="err('gas_option')" class="mt-1 text-sm text-danger">{{ err('gas_option') }}</p>
            </div>

            <!-- Repair description -->
            <div v-if="isRepair" class="sm:col-span-2">
                <label class="mb-1.5 block text-sm font-semibold text-ink">Repair description</label>
                <textarea v-model="line.repair_desc" rows="2" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary" placeholder="What was repaired?" />
                <p v-if="err('repair_desc')" class="mt-1 text-sm text-danger">{{ err('repair_desc') }}</p>
            </div>

            <!-- Units -->
            <div>
                <label class="mb-1.5 block text-sm font-semibold text-ink">Units</label>
                <input v-model.number="line.units" type="number" min="1" inputmode="numeric" class="w-full rounded-ra border-line bg-surface font-mono text-ink shadow-card focus:border-primary focus:ring-primary" />
                <p v-if="err('units')" class="mt-1 text-sm text-danger">{{ err('units') }}</p>
            </div>

            <!-- Rate: auto for fee-driven, manual for Repair -->
            <div>
                <label class="mb-1.5 block text-sm font-semibold text-ink">
                    Rate (RM) <span v-if="!isRepair" class="font-normal text-ink-muted">· auto</span>
                </label>
                <input v-model.number="line.rate" type="number" step="0.01" min="0" :readonly="!isRepair" inputmode="decimal" class="w-full rounded-ra border-line bg-surface font-mono text-ink shadow-card focus:border-primary focus:ring-primary read-only:bg-surface-muted read-only:text-ink-soft" :placeholder="isRepair ? 'Enter price' : '—'" />
                <p v-if="err('rate')" class="mt-1 text-sm text-danger">{{ err('rate') }}</p>
            </div>

            <!-- Discount -->
            <div>
                <label class="mb-1.5 block text-sm font-semibold text-ink">Discount (RM)</label>
                <input v-model.number="line.discount" type="number" step="0.01" min="0" inputmode="decimal" class="w-full rounded-ra border-line bg-surface font-mono text-ink shadow-card focus:border-primary focus:ring-primary" placeholder="0.00" />
            </div>

            <!-- Next service date (R2) -->
            <div v-if="carriesUnitType">
                <label class="mb-1.5 block text-sm font-semibold text-ink">Next service date</label>
                <input v-model="line.next_service_date" type="date" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary" />
            </div>

            <!-- Notes (R3: not for Repair) -->
            <div v-if="!isRepair" class="sm:col-span-2">
                <label class="mb-1.5 block text-sm font-semibold text-ink">Notes <span class="font-normal text-ink-muted">(optional)</span></label>
                <input v-model="line.notes" type="text" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary" />
            </div>
        </div>

        <!-- Line subtotal -->
        <div class="mt-4 flex items-center justify-end gap-3 border-t border-line pt-3">
            <span class="text-sm text-ink-soft">Subtotal</span>
            <span class="font-mono text-base font-bold text-navy-800">{{ money(subtotal) }}</span>
        </div>
    </div>
</template>
