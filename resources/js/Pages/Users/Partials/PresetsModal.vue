<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { permLabels } from '@/permissionLabels';

const props = defineProps({
    open: Boolean,
    grantablePermissions: Array,
    presets: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['close']);

const form = useForm({
    presets: { 1: [], 2: [], 3: [] },
});

watch(() => props.open, (open) => {
    if (!open) return;
    form.clearErrors();
    form.presets = {
        1: [...(props.presets[1] ?? [])],
        2: [...(props.presets[2] ?? [])],
        3: [...(props.presets[3] ?? [])],
    };
});

const submit = () => {
    form.put(route('permission-presets.update'), {
        onSuccess: () => emit('close'),
        preserveScroll: true,
    });
};
</script>

<template>
    <Transition
        enter-active-class="transition duration-200" enter-from-class="opacity-0"
        leave-active-class="transition duration-150" leave-to-class="opacity-0"
    >
        <div
            v-if="open"
            class="fixed inset-0 z-50 flex items-end justify-center bg-navy-900/60 p-0 backdrop-blur-sm sm:items-center sm:p-4"
            @click.self="emit('close')"
        >
            <div class="w-full max-w-2xl rounded-t-rax bg-surface p-6 shadow-lift sm:rounded-rax max-h-[90vh] overflow-y-auto">
                <div class="mb-5 flex items-center justify-between">
                    <h3 class="text-lg font-bold text-navy-800">Permission levels</h3>
                    <button
                        type="button"
                        class="rounded-ra p-1 text-ink-muted transition hover:bg-surface-muted hover:text-ink"
                        @click="emit('close')"
                        aria-label="Close"
                    >
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M18 6 6 18M6 6l12 12" stroke-linecap="round" />
                        </svg>
                    </button>
                </div>

                <p class="mb-4 text-sm text-ink-soft">
                    These baselines auto-fill the permission checkboxes when you pick a level
                    while creating a technician. They do not change existing technicians.
                </p>

                <form class="space-y-6" @submit.prevent="submit">
                    <div v-for="lvl in [1, 2, 3]" :key="lvl">
                        <p class="mb-2 text-sm font-semibold text-ink">Level {{ lvl }}</p>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <label
                                v-for="perm in grantablePermissions"
                                :key="perm"
                                class="flex cursor-pointer items-start gap-3 rounded-ra border p-3 transition hover:bg-surface-muted"
                                :class="form.presets[lvl].includes(perm) ? 'border-primary bg-primary-50' : 'border-line'"
                            >
                                <input
                                    type="checkbox"
                                    :value="perm"
                                    v-model="form.presets[lvl]"
                                    class="mt-0.5 shrink-0 rounded border-line text-primary focus:ring-primary"
                                />
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-ink leading-snug">{{ permLabels[perm] ?? perm }}</p>
                                    <p class="text-xs text-ink-soft font-mono mt-0.5">{{ perm }}</p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button type="button" class="text-sm font-medium text-ink-soft hover:text-ink" @click="emit('close')">
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded-ra bg-primary px-5 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover disabled:opacity-60"
                        >
                            Save levels
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </Transition>
</template>
