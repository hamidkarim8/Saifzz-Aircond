<script setup>
import { ref, watch, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormErrorSummary from '@/Components/FormErrorSummary.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    open: Boolean,
    fee: { type: Object, default: null }, // null = add new
    serviceTypes: Array,
    modes: Array,
});
const emit = defineEmits(['close']);

const isEdit = computed(() => !!props.fee);

const form = useForm({
    service_type: '',
    option: '',
    pricing_mode: 'fixed_per_unit',
    rate: '',
});

// Reset form whenever the modal opens.
watch(() => props.open, (open) => {
    if (!open) return;
    form.clearErrors();
    if (props.fee) {
        form.service_type = props.fee.service_type;
        form.option = props.fee.option ?? '';
        form.pricing_mode = props.fee.pricing_mode;
        form.rate = props.fee.rate ?? '';
    } else {
        form.reset();
        form.pricing_mode = 'fixed_per_unit';
    }
});

const isFlexible = computed(() => form.pricing_mode === 'flexible');
const isRepair = computed(() => form.service_type === 'Repair');

// Auto-set pricing_mode when switching to/from Repair so form submits correct data.
watch(() => form.service_type, (type) => {
    if (type === 'Repair') {
        form.pricing_mode = 'flexible';
        form.option = '';
        form.rate = '';
    } else if (form.pricing_mode === 'flexible') {
        form.pricing_mode = 'fixed_per_unit';
    }
});

const submit = () => {
    if (isEdit.value) {
        form.put(route('fees.update', props.fee.id), { onSuccess: () => emit('close'), preserveScroll: true });
    } else {
        form.post(route('fees.store'), { onSuccess: () => emit('close'), preserveScroll: true });
    }
};

const modeLabel = { fixed_per_unit: 'Fixed per unit', tiered: 'Tiered', flexible: 'Flexible (no fixed rate)' };
</script>

<template>
    <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0" leave-active-class="transition duration-150" leave-to-class="opacity-0">
        <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center bg-navy-900/60 p-0 backdrop-blur-sm sm:items-center sm:p-4" @click.self="emit('close')">
            <div class="w-full max-w-md rounded-t-rax bg-surface p-6 shadow-lift sm:rounded-rax">

                <!-- Header -->
                <div class="mb-5">
                    <h3 class="text-lg font-bold text-navy-800">{{ isEdit ? 'Edit fee entry' : 'Add fee entry' }}</h3>
                    <p class="mt-1 text-sm text-ink-soft">Changes apply to future service lines only — past records keep their snapshot rate.</p>
                </div>

                <!-- Error summary -->
                <FormErrorSummary :errors="form.errors" />

                <form class="space-y-4" @submit.prevent="submit">
                    <!-- Service type -->
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Service type</label>
                        <select
                            v-model="form.service_type"
                            :disabled="isEdit"
                            class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-card focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary disabled:bg-surface-muted disabled:text-ink-soft"
                        >
                            <option value="" disabled>Choose service type…</option>
                            <option v-for="t in serviceTypes" :key="t" :value="t">{{ t }}</option>
                        </select>
                        <InputError :message="form.errors.service_type" />
                    </div>

                    <!-- Option / unit type — hidden for Repair -->
                    <div v-if="!isRepair">
                        <label class="mb-1.5 block text-sm font-semibold text-ink">
                            Unit / option
                            <span class="ml-1 font-normal text-ink-muted text-xs">(e.g. Wall Mounted, 1/2 HP, PSI level)</span>
                        </label>
                        <input
                            v-model="form.option"
                            :disabled="isEdit"
                            type="text"
                            class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-card focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary disabled:bg-surface-muted disabled:text-ink-soft"
                            placeholder="e.g. Wall Mounted"
                        />
                        <InputError :message="form.errors.option" />
                    </div>

                    <!-- Repair flexible pricing notice -->
                    <div v-if="isRepair" class="flex gap-2.5 rounded-ra border border-warn/30 bg-warn-bg px-3.5 py-3 text-sm text-warn">
                        <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        <span>
                            <strong>Flexible pricing</strong> — Repair jobs do not use a fixed rate.
                            The technician sets the price per job at the time of service.
                        </span>
                    </div>

                    <!-- Pricing mode -->
                    <div v-if="!isRepair">
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Pricing mode</label>
                        <select
                            v-model="form.pricing_mode"
                            class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-card focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        >
                            <option v-for="m in modes" :key="m" :value="m">{{ modeLabel[m] }}</option>
                        </select>
                        <InputError :message="form.errors.pricing_mode" />
                    </div>

                    <!-- Rate — hidden when flexible or Repair -->
                    <div v-if="!isFlexible && !isRepair">
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Rate (RM)</label>
                        <input
                            v-model="form.rate"
                            type="number"
                            step="0.01"
                            min="0"
                            inputmode="decimal"
                            class="w-full rounded-ra border border-line bg-surface px-3 py-2 font-mono text-sm text-ink shadow-card focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            placeholder="0.00"
                        />
                        <InputError :message="form.errors.rate" />
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button type="button" class="text-sm font-medium text-ink-soft hover:text-ink" @click="emit('close')">Cancel</button>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded-ra bg-primary px-5 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover disabled:opacity-60"
                        >
                            {{ isEdit ? 'Save changes' : 'Add fee entry' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </Transition>
</template>
