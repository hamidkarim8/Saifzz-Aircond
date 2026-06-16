<script setup>
import { computed, watch, ref } from 'vue';
import Badge from '@/Components/Badge.vue';
import InputError from '@/Components/InputError.vue';
import { serviceVariant } from '@/lib/badges';

const props = defineProps({
    line: { type: Object, required: true },
    index: { type: Number, required: true },
    feeMap: { type: Object, required: true },
    serviceTypes: Array,
    unitTypes: Array,
    gasOptions: Array,
    unitTypeServices: Array,
    clientUnits: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
    removable: Boolean,
    visitDate: { type: String, default: null },
    hpTiers: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['remove']);

const isRepair = computed(() => props.line.service_type === 'Repair');
const isGas = computed(() => props.line.service_type === 'Gas Top-Up');
const carriesUnitType = computed(() => props.unitTypeServices.includes(props.line.service_type));
const hasUnitSelected = computed(() => !!props.line.unit_id);

const isHpBased = computed(() => {
    if (!props.line.service_type) return false;
    const t = props.serviceTypes?.find(t => t.name === props.line.service_type);
    return t?.is_hp_based ?? false;
});

const currentServiceTypeId = computed(() => {
    const t = props.serviceTypes?.find(t => t.name === props.line.service_type);
    return t?.id ?? null;
});

const hpOptions = computed(() => {
    if (!currentServiceTypeId.value) return [];
    return props.hpTiers?.[currentServiceTypeId.value] ?? [];
});

const nextServiceMonths = ref(null);

const requiresNextService = computed(() => {
    if (!props.line.service_type) return false;
    const t = props.serviceTypes?.find(t => t.name === props.line.service_type);
    return t?.requires_next_service ?? false;
});

const err = (field) => props.errors[`lines.${props.index}.${field}`];

watch(() => props.line.service_type, () => {
    props.line.unit_type = null;
    props.line.unit_id = null;
    props.line.gas_option = null;
    props.line.hp_value = null;
    props.line.repair_desc = '';
    props.line.next_service_date = null;
    nextServiceMonths.value = null;
    props.line.notes = '';
    if (isRepair.value) props.line.rate = '';
    autofill();
});

// When a unit is selected, auto-fill unit_type from the unit record.
// When deselected, clear unit_type so the user can choose manually.
watch(() => props.line.unit_id, (unitId) => {
    if (unitId) {
        const unit = props.clientUnits.find(u => u.id === unitId);
        if (unit) props.line.unit_type = unit.unit_type;
    } else {
        props.line.unit_type = null;
    }
});

watch([() => props.line.unit_type, () => props.line.gas_option], autofill);

watch(() => props.line.hp_value, () => {
    if (!isHpBased.value) return;
    autofill();
});

watch(nextServiceMonths, (months) => {
    if (!months || !props.visitDate) { props.line.next_service_date = null; return; }
    const d = new Date(props.visitDate);
    d.setMonth(d.getMonth() + months);
    props.line.next_service_date = d.toISOString().slice(0, 10);
});

watch(() => props.visitDate, () => {
    if (!nextServiceMonths.value || !props.visitDate) { props.line.next_service_date = null; return; }
    const d = new Date(props.visitDate);
    d.setMonth(d.getMonth() + nextServiceMonths.value);
    props.line.next_service_date = d.toISOString().slice(0, 10);
});

function autofill() {
    if (isRepair.value || !props.line.service_type) return;
    const option = isGas.value ? props.line.gas_option : props.line.unit_type;
    let baseRate;
    if (!option) {
        const flat = props.feeMap[props.line.service_type];
        baseRate = flat != null ? Number(flat) : 0;
    } else {
        const r = props.feeMap[`${props.line.service_type}|${option}`];
        baseRate = r != null ? Number(r) : 0;
    }

    if (isHpBased.value && props.line.hp_value) {
        const tier = hpOptions.value.find(t => Number(t.hp_value) === Number(props.line.hp_value));
        const hpPrice = tier ? Number(tier.price) : 0;
        props.line.rate = baseRate + hpPrice;
    } else if (!isHpBased.value) {
        props.line.rate = baseRate !== 0 ? baseRate : '';
    }
    // If HP-based but no hp_value selected yet — leave rate as is
}

const subtotal = computed(() => {
    const units = hasUnitSelected.value ? 1 : (Number(props.line.units) || 0);
    const v = (Number(props.line.rate) || 0) * units - (Number(props.line.discount) || 0);
    return Math.max(0, v);
});

const money = (v) => 'RM ' + Number(v).toFixed(2);

const typeAccent = {
    Cleaning: 'border-l-primary', 'Gas Top-Up': 'border-l-warn', Repair: 'border-l-danger',
    Installation: 'border-l-ok', Troubleshoot: 'border-l-invoice',
};

const feeBadgeLabel = computed(() => {
    if (!props.line.service_type) return null;
    if (isRepair.value) return 'Flexible';
    if (props.line.rate !== '' && props.line.rate != null) return money(props.line.rate);
    return null;
});
const feeBadgeVariant = computed(() => isRepair.value ? 'amber' : 'blue');

const unitLabel = (u) => `${u.label} (${u.unit_type}${u.hp ? ' · ' + Number(u.hp) + 'HP' : ''})`;
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
                <!-- Unit selector (shown when client has units and service uses unit_type) -->
                <div v-if="clientUnits.length && carriesUnitType" class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-semibold text-ink">Unit <span class="font-normal text-xs text-ink-muted">(optional — skip to use count mode)</span></label>
                    <select v-model="line.unit_id"
                            class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-card focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                        <option :value="null">— No specific unit —</option>
                        <option v-for="u in clientUnits" :key="u.id" :value="u.id">{{ unitLabel(u) }}</option>
                    </select>
                </div>

                <!-- Service type -->
                <div>
                    <label class="mb-1.5 block text-sm font-semibold text-ink">Service type</label>
                    <select v-model="line.service_type" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary">
                        <option value="" disabled>Choose…</option>
                        <option v-for="t in serviceTypes" :key="t.name" :value="t.name">{{ t.name }}</option>
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

                <!-- HP dropdown (HP-based service types) -->
                <div v-if="isHpBased" class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-semibold text-ink">Horsepower (HP)</label>
                    <select
                        v-model.number="line.hp_value"
                        class="w-full rounded-ra border border-line bg-surface text-sm text-ink shadow-card focus:border-primary focus:ring-1 focus:ring-primary"
                    >
                        <option :value="null" disabled>Choose HP…</option>
                        <option v-for="tier in hpOptions" :key="tier.id" :value="Number(tier.hp_value)">
                            {{ Number(tier.hp_value).toFixed(1) }} HP — RM {{ Number(tier.price).toFixed(2) }}
                        </option>
                    </select>
                </div>

                <!-- Repair description -->
                <div v-if="isRepair" class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-semibold text-ink">Repair description</label>
                    <textarea v-model="line.repair_desc" rows="2" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary" placeholder="What was repaired?" />
                    <InputError :message="err('repair_desc')" />
                </div>

                <!-- Units count — hidden when a specific unit is selected (always 1) -->
                <div v-if="!hasUnitSelected">
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

                <!-- Next service months (R2) -->
                <div v-if="requiresNextService">
                    <label class="mb-1.5 block text-sm font-semibold text-ink">Next service</label>
                    <select v-model.number="nextServiceMonths" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary">
                        <option :value="null" disabled>Choose months…</option>
                        <option v-for="m in [3,4,5,6,7,8,9,10,11,12]" :key="m" :value="m">{{ m }} months</option>
                    </select>
                    <p v-if="line.next_service_date" class="mt-1 text-xs text-ok">Next service: {{ line.next_service_date }}</p>
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
