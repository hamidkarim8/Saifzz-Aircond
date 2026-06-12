<script setup>
import { computed, watch } from 'vue';
import Badge from '@/Components/Badge.vue';
import InputError from '@/Components/InputError.vue';
import { serviceVariant } from '@/lib/badges';

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

// Fee badge: show auto-filled rate for fee-driven types, "Flexible" for Repair.
const feeBadgeLabel = computed(() => {
    if (!props.line.service_type) return null;
    if (isRepair.value) return 'Flexible';
    if (props.line.rate !== '' && props.line.rate != null) return money(props.line.rate);
    return null;
});
const feeBadgeVariant = computed(() => {
    if (isRepair.value) return 'amber';
    return 'blue';
});
</script>

<template>
    <div
        class="overflow-hidden rounded-ral border border-line border-l-4 bg-surface shadow-card"
        :class="line.service_type ? typeAccent[line.service_type] : 'border-l-line'"
    >
        <!-- Block header -->
        <div class="flex items-center justify-between border-b border-line bg-surface-muted px-4 py-2.5">
            <div class="flex items-center gap-2.5">
                <span class="flex h-5 w-5 items-center justify-center rounded-full bg-navy-800 text-[10px] font-bold text-white">{{ index + 1 }}</span>
                <span class="text-xs font-bold uppercase tracking-wide text-ink-soft">
                    {{ line.service_type || 'Service' }}
                </span>
                <Badge v-if="feeBadgeLabel" :variant="feeBadgeVariant">{{ feeBadgeLabel }}</Badge>
            </div>
            <button v-if="removable" type="button" class="text-xs font-medium text-danger hover:underline transition" @click="emit('remove')">Remove</button>
        </div>

        <!-- Fields -->
        <div class="p-4 sm:p-5">
            <div class="grid gap-4 sm:grid-cols-2">
                <!-- Service type -->
                <div>
                    <label class="mb-1.5 block text-sm font-semibold text-ink">Service type</label>
                    <select v-model="line.service_type" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary">
                        <option value="" disabled>Choose…</option>
                        <option v-for="t in serviceTypes" :key="t" :value="t">{{ t }}</option>
                    </select>
                    <InputError :message="err('service_type')" />
                </div>

                <!-- Unit type (Cleaning / Installation / Troubleshoot) -->
                <div v-if="carriesUnitType">
                    <label class="mb-1.5 block text-sm font-semibold text-ink">Unit type</label>
                    <select v-model="line.unit_type" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary">
                        <option :value="null" disabled>Choose…</option>
                        <option v-for="u in unitTypes" :key="u" :value="u">{{ u }}</option>
                    </select>
                    <InputError :message="err('unit_type')" />
                </div>

                <!-- Gas option -->
                <div v-if="isGas">
                    <label class="mb-1.5 block text-sm font-semibold text-ink">Gas / PSI option</label>
                    <select v-model="line.gas_option" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary">
                        <option :value="null" disabled>Choose…</option>
                        <option v-for="g in gasOptions" :key="g" :value="g">{{ g }}</option>
                    </select>
                    <InputError :message="err('gas_option')" />
                </div>

                <!-- Repair description -->
                <div v-if="isRepair" class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-semibold text-ink">Repair description</label>
                    <textarea v-model="line.repair_desc" rows="2" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary" placeholder="What was repaired?" />
                    <InputError :message="err('repair_desc')" />
                </div>

                <!-- Units -->
                <div>
                    <label class="mb-1.5 block text-sm font-semibold text-ink">Units</label>
                    <input v-model.number="line.units" type="number" min="1" inputmode="numeric" class="w-full rounded-ra border-line bg-surface font-mono text-ink shadow-card focus:border-primary focus:ring-primary" />
                    <InputError :message="err('units')" />
                </div>

                <!-- Rate: auto for fee-driven, manual for Repair -->
                <div>
                    <label class="mb-1.5 block text-sm font-semibold text-ink">
                        Rate (RM)
                        <span v-if="!isRepair" class="font-normal text-ink-muted"> · auto-filled</span>
                    </label>
                    <input
                        v-model.number="line.rate"
                        type="number"
                        step="0.01"
                        min="0"
                        :readonly="!isRepair"
                        inputmode="decimal"
                        class="w-full rounded-ra border-line bg-surface font-mono text-ink shadow-card focus:border-primary focus:ring-primary read-only:bg-surface-muted read-only:text-ink-soft"
                        :placeholder="isRepair ? 'Enter price' : '—'"
                    />
                    <InputError :message="err('rate')" />
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
            <div class="mt-4 flex items-center justify-between rounded-ra bg-surface-muted px-4 py-2.5">
                <span class="text-xs uppercase tracking-wide font-semibold text-ink-soft">Subtotal</span>
                <span class="font-mono text-base font-bold text-navy-800">{{ money(subtotal) }}</span>
            </div>
        </div>
    </div>
</template>
