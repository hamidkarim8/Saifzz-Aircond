<script setup>
import { computed, watch, ref } from 'vue';
import Badge from '@/Components/Badge.vue';
import InputError from '@/Components/InputError.vue';
import { serviceVariant } from '@/lib/badges';

const props = defineProps({
    line: { type: Object, required: true },
    index: { type: Number, required: true },
    serviceTypes: Array,
    clientUnits: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
    removable: Boolean,
    visitDate: { type: String, default: null },
});
const emit = defineEmits(['remove']);

const serviceType = computed(() => props.serviceTypes?.find(t => t.name === props.line.service_type) ?? null);
const mode = computed(() => serviceType.value?.pricing_mode ?? null);
const isFlexible = computed(() => mode.value === 'flexible');
const isHp = computed(() => mode.value === 'hp_tiered');
const carriesUnitType = computed(() => mode.value === 'flat' || mode.value === 'hp_tiered');
const requiresNextService = computed(() => serviceType.value?.requires_next_service ?? false);

const hasUnitSelected = computed(() => !!props.line.unit_id);

const fees = computed(() => serviceType.value?.fees ?? []);
const unitTypeOptions = computed(() => [...new Set(fees.value.map(f => f.unit_type))]);
const hpOptions = computed(() => fees.value
    .filter(f => f.unit_type === props.line.unit_type)
    .map(f => ({ hp_value: Number(f.hp_value), price: Number(f.price) })));

const nextServiceMonths = ref(null);

const err = (field) => props.errors[`lines.${props.index}.${field}`];

function autofill() {
    if (isFlexible.value || !props.line.service_type) return;
    if (isHp.value) {
        const tier = hpOptions.value.find(t => Number(t.hp_value) === Number(props.line.hp_value));
        props.line.rate = tier ? tier.price : '';
    } else { // flat
        const fee = fees.value.find(f => f.unit_type === props.line.unit_type);
        props.line.rate = fee ? Number(fee.price) : '';
    }
}

watch(() => props.line.service_type, () => {
    props.line.unit_type = null;
    props.line.unit_id = null;
    props.line.hp_value = null;
    props.line.repair_desc = '';
    props.line.next_service_date = null;
    nextServiceMonths.value = null;
    props.line.notes = '';
    if (isFlexible.value) props.line.rate = '';
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

watch(() => props.line.unit_type, () => { props.line.hp_value = null; autofill(); });
watch(() => props.line.hp_value, () => { if (isHp.value) autofill(); });

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
    if (isFlexible.value) return 'Flexible';
    if (props.line.rate !== '' && props.line.rate != null) return money(props.line.rate);
    return null;
});
const feeBadgeVariant = computed(() => isFlexible.value ? 'amber' : 'blue');

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
                <!-- Unit selector — HIDDEN pending requirement discussion (see docs/UNITS-TODO.md). Re-enable: restore v-if="clientUnits.length && carriesUnitType". -->
                <div v-if="false" class="sm:col-span-2">
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

                <!-- Unit type (flat / hp_tiered service types) -->
                <div v-if="carriesUnitType">
                    <label class="mb-1.5 block text-sm font-semibold text-ink">Unit type</label>
                    <select v-if="unitTypeOptions.length > 0" v-model="line.unit_type" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary">
                        <option :value="null" disabled>Choose…</option>
                        <option v-for="u in unitTypeOptions" :key="u" :value="u">{{ u }}</option>
                    </select>
                    <p v-if="unitTypeOptions.length === 0" class="mt-1.5 text-xs text-warn">
                        No pricing configured for this service yet — ask an admin to set fees in Services.
                    </p>
                    <InputError :message="err('unit_type')" />
                </div>

                <!-- HP dropdown (hp_tiered service types) -->
                <div v-if="isHp" class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-semibold text-ink">Horsepower (HP)</label>
                    <select
                        v-model.number="line.hp_value"
                        class="w-full rounded-ra border border-line bg-surface text-sm text-ink shadow-card focus:border-primary focus:ring-1 focus:ring-primary"
                    >
                        <option :value="null" disabled>Choose HP…</option>
                        <option v-for="tier in hpOptions" :key="tier.hp_value" :value="Number(tier.hp_value)">
                            {{ Number(tier.hp_value).toFixed(1) }} HP — RM {{ Number(tier.price).toFixed(2) }}
                        </option>
                    </select>
                </div>

                <!-- Description (flexible service types) -->
                <div v-if="isFlexible" class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-semibold text-ink">Description</label>
                    <textarea v-model="line.repair_desc" rows="2" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary" placeholder="What was done?" />
                    <InputError :message="err('repair_desc')" />
                </div>

                <!-- Units count — hidden when a specific unit is selected (always 1) -->
                <div v-if="!hasUnitSelected">
                    <label class="mb-1.5 block text-sm font-semibold text-ink">Units</label>
                    <input v-model.number="line.units" type="number" min="1" inputmode="numeric" class="w-full rounded-ra border-line bg-surface font-mono text-ink shadow-card focus:border-primary focus:ring-primary" />
                    <InputError :message="err('units')" />
                </div>

                <!-- Rate: auto for fee-driven, manual for flexible -->
                <div>
                    <label class="mb-1.5 block text-sm font-semibold text-ink">
                        Rate (RM)
                        <span v-if="!isFlexible" class="font-normal text-ink-muted"> · auto-filled</span>
                    </label>
                    <input
                        v-model.number="line.rate"
                        type="number"
                        step="0.01"
                        min="0"
                        :readonly="!isFlexible"
                        inputmode="decimal"
                        class="w-full rounded-ra border-line bg-surface font-mono text-ink shadow-card focus:border-primary focus:ring-primary read-only:bg-surface-muted read-only:text-ink-soft"
                        :placeholder="isFlexible ? 'Enter price' : '—'"
                    />
                    <InputError :message="err('rate')" />
                </div>

                <!-- Discount -->
                <div>
                    <label class="mb-1.5 block text-sm font-semibold text-ink">Discount (RM)</label>
                    <input v-model.number="line.discount" type="number" step="0.01" min="0" inputmode="decimal" class="w-full rounded-ra border-line bg-surface font-mono text-ink shadow-card focus:border-primary focus:ring-primary" placeholder="0.00" />
                    <InputError :message="err('discount')" />
                </div>

                <!-- Next service months -->
                <div v-if="requiresNextService">
                    <label class="mb-1.5 block text-sm font-semibold text-ink">Next service</label>
                    <select v-model.number="nextServiceMonths" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary">
                        <option :value="null" disabled>Choose months…</option>
                        <option v-for="m in [3,4,5,6,7,8,9,10,11,12]" :key="m" :value="m">{{ m }} months</option>
                    </select>
                    <p v-if="line.next_service_date" class="mt-1 text-xs text-ok">Next service: {{ line.next_service_date }}</p>
                </div>

                <!-- Notes (not for flexible — flexible uses description field instead) -->
                <div v-if="!isFlexible" class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-semibold text-ink">Notes <span class="font-normal text-ink-muted">(optional — shown to the customer on the invoice and receipt)</span></label>
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
