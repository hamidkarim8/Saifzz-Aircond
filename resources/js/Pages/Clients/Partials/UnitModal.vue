<script setup>
import { watch, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    open: Boolean,
    clientId: Number,
    unit: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const isEdit = computed(() => !!props.unit);

const HP_OPTIONS = [0.75, 1.0, 1.5, 2.0, 2.5];
const UNIT_TYPES = ['Wall Mounted', 'Cassette'];
const REFRIGERANTS = ['R32', 'R410A', 'R22'];

const form = useForm({
    label: '', unit_type: '', hp: null, brand: '', model: '',
    serial_no: '', refrigerant_type: null, notes: '',
});

watch(() => props.open, (open) => {
    if (!open) return;
    form.clearErrors();
    if (props.unit) {
        form.label = props.unit.label ?? '';
        form.unit_type = props.unit.unit_type ?? '';
        form.hp = props.unit.hp != null ? Number(props.unit.hp) : null;
        form.brand = props.unit.brand ?? '';
        form.model = props.unit.model ?? '';
        form.serial_no = props.unit.serial_no ?? '';
        form.refrigerant_type = props.unit.refrigerant_type ?? null;
        form.notes = props.unit.notes ?? '';
    } else {
        form.reset();
    }
});

const submit = () => {
    if (isEdit.value) {
        form.put(route('clients.units.update', [props.clientId, props.unit.id]), {
            onSuccess: () => emit('close'), preserveScroll: true,
        });
    } else {
        form.post(route('clients.units.store', props.clientId), {
            onSuccess: () => emit('close'), preserveScroll: true,
        });
    }
};
</script>

<template>
    <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0"
                leave-active-class="transition duration-150" leave-to-class="opacity-0">
        <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center bg-navy-900/60 p-0 backdrop-blur-sm sm:items-center sm:p-4"
             @click.self="emit('close')">
            <div class="w-full max-w-md rounded-t-rax bg-surface p-6 shadow-lift sm:rounded-rax">
                <div class="mb-5">
                    <h3 class="text-lg font-bold text-navy-800">{{ isEdit ? 'Edit unit' : 'Add unit' }}</h3>
                    <p class="mt-1 text-sm text-ink-soft">Details about this air-conditioning unit.</p>
                </div>

                <form class="space-y-4" @submit.prevent="submit">
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Location label <span class="text-danger">*</span></label>
                        <input v-model="form.label" type="text" placeholder="e.g. Master Bedroom"
                               class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-card focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary" />
                        <InputError :message="form.errors.label" />
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Unit type <span class="text-danger">*</span></label>
                        <select v-model="form.unit_type"
                                class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-card focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                            <option value="" disabled>Choose…</option>
                            <option v-for="t in UNIT_TYPES" :key="t" :value="t">{{ t }}</option>
                        </select>
                        <InputError :message="form.errors.unit_type" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1.5 block text-sm font-semibold text-ink">HP</label>
                            <select v-model="form.hp"
                                    class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-card focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                                <option :value="null">—</option>
                                <option v-for="h in HP_OPTIONS" :key="h" :value="h">{{ h }} HP</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-semibold text-ink">Refrigerant</label>
                            <select v-model="form.refrigerant_type"
                                    class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-card focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                                <option :value="null">—</option>
                                <option v-for="r in REFRIGERANTS" :key="r" :value="r">{{ r }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1.5 block text-sm font-semibold text-ink">Brand</label>
                            <input v-model="form.brand" type="text" placeholder="LG"
                                   class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-card focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary" />
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-semibold text-ink">Model</label>
                            <input v-model="form.model" type="text" placeholder="S12EQ"
                                   class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-card focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary" />
                        </div>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Unit serial no.</label>
                        <input v-model="form.serial_no" type="text" placeholder="Unit's own serial number"
                               class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-card focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary" />
                        <InputError :message="form.errors.serial_no" />
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Notes</label>
                        <textarea v-model="form.notes" rows="2" placeholder="Optional notes"
                                  class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-card focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary" />
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button type="button" class="text-sm font-medium text-ink-soft hover:text-ink" @click="emit('close')">Cancel</button>
                        <button type="submit" :disabled="form.processing"
                                class="rounded-ra bg-primary px-5 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover disabled:opacity-60">
                            {{ isEdit ? 'Save changes' : 'Add unit' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </Transition>
</template>
