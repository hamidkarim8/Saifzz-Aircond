<script setup>
import { ref, watch, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';

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
                <h3 class="text-lg font-bold text-navy-800">{{ isEdit ? 'Edit fee' : 'Add fee' }}</h3>
                <p class="mt-1 text-sm text-ink-soft">Changes apply to future service lines only — past records keep their snapshot rate.</p>

                <form class="mt-5 space-y-4" @submit.prevent="submit">
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Service type</label>
                        <select v-model="form.service_type" :disabled="isEdit" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary disabled:bg-surface-muted disabled:text-ink-soft">
                            <option value="" disabled>Choose…</option>
                            <option v-for="t in serviceTypes" :key="t" :value="t">{{ t }}</option>
                        </select>
                        <p v-if="form.errors.service_type" class="mt-1 text-sm text-danger">{{ form.errors.service_type }}</p>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Option <span class="font-normal text-ink-muted">(unit type / gas option — blank for Repair)</span></label>
                        <input v-model="form.option" :disabled="isEdit" type="text" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary disabled:bg-surface-muted disabled:text-ink-soft" placeholder="e.g. Wall Mounted" />
                        <p v-if="form.errors.option" class="mt-1 text-sm text-danger">{{ form.errors.option }}</p>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Pricing mode</label>
                        <select v-model="form.pricing_mode" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary">
                            <option v-for="m in modes" :key="m" :value="m">{{ modeLabel[m] }}</option>
                        </select>
                    </div>

                    <div v-if="!isFlexible">
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Rate (RM)</label>
                        <input v-model="form.rate" type="number" step="0.01" min="0" inputmode="decimal" class="w-full rounded-ra border-line bg-surface font-mono text-ink shadow-card focus:border-primary focus:ring-primary" placeholder="0.00" />
                        <p v-if="form.errors.rate" class="mt-1 text-sm text-danger">{{ form.errors.rate }}</p>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button type="button" class="text-sm font-medium text-ink-soft hover:text-ink" @click="emit('close')">Cancel</button>
                        <button type="submit" :disabled="form.processing" class="rounded-ra bg-primary px-5 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover disabled:opacity-60">
                            {{ isEdit ? 'Save' : 'Add fee' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </Transition>
</template>
