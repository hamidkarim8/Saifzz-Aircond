<script setup>
import { watch, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormErrorSummary from '@/Components/FormErrorSummary.vue';
import InputError from '@/Components/InputError.vue';
import { permLabels } from '@/permissionLabels';

const props = defineProps({
    open: Boolean,
    user: { type: Object, default: null }, // null = create
    grantablePermissions: Array,
    presets: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['close']);

const isEdit = computed(() => !!props.user);

const applyLevel = (level) => {
    form.permissions = [...(props.presets[level] ?? [])];
};

const form = useForm({
    name: '',
    email: '',
    password: '',
    permissions: [],
});

watch(() => props.open, (open) => {
    if (!open) return;
    form.clearErrors();
    if (props.user) {
        form.name = props.user.name;
        form.email = props.user.email;
        form.password = '';
        form.permissions = [...(props.user.permissions ?? [])];
    } else {
        form.reset();
    }
});

const submit = () => {
    if (isEdit.value) {
        form.put(route('users.update', props.user.id), {
            onSuccess: () => emit('close'),
            preserveScroll: true,
        });
    } else {
        form.post(route('users.store'), {
            onSuccess: () => emit('close'),
            preserveScroll: true,
        });
    }
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
            <div class="w-full max-w-lg rounded-t-rax bg-surface p-6 shadow-lift sm:rounded-rax max-h-[90vh] overflow-y-auto">
                <!-- Header -->
                <div class="mb-5 flex items-center justify-between">
                    <h3 class="text-lg font-bold text-navy-800">{{ isEdit ? 'Edit user' : 'Add user' }}</h3>
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

                <!-- Error summary -->
                <FormErrorSummary :errors="form.errors" />

                <form class="space-y-4" @submit.prevent="submit">
                    <!-- Name -->
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Name</label>
                        <input
                            v-model="form.name"
                            type="text"
                            class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary"
                            :class="{ 'border-danger focus:border-danger focus:ring-danger': form.errors.name }"
                            placeholder="Full name"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <!-- Email (create only) -->
                    <div v-if="!isEdit">
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Email</label>
                        <input
                            v-model="form.email"
                            type="email"
                            class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary"
                            :class="{ 'border-danger focus:border-danger focus:ring-danger': form.errors.email }"
                            placeholder="staff@example.com"
                        />
                        <InputError :message="form.errors.email" />
                    </div>

                    <!-- Password (create only) -->
                    <div v-if="!isEdit">
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Password</label>
                        <input
                            v-model="form.password"
                            type="password"
                            class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary"
                            :class="{ 'border-danger focus:border-danger focus:ring-danger': form.errors.password }"
                            placeholder="Min. 8 characters"
                        />
                        <InputError :message="form.errors.password" />
                    </div>

                    <!-- Permissions -->
                    <div>
                        <p class="mb-2 text-sm font-semibold text-ink">Permissions</p>
                        <div class="mb-2 flex flex-wrap gap-2">
                            <button
                                v-for="lvl in [1, 2, 3]"
                                :key="lvl"
                                type="button"
                                class="rounded-ra border border-line px-3 py-1.5 text-xs font-semibold text-ink-soft transition hover:border-primary hover:bg-primary-50 hover:text-primary"
                                @click="applyLevel(lvl)"
                            >
                                Level {{ lvl }}
                            </button>
                        </div>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <label
                                v-for="perm in grantablePermissions"
                                :key="perm"
                                class="flex cursor-pointer items-start gap-3 rounded-ra border p-3 transition hover:bg-surface-muted"
                                :class="form.permissions.includes(perm)
                                    ? 'border-primary bg-primary-50'
                                    : 'border-line'"
                            >
                                <input
                                    type="checkbox"
                                    :value="perm"
                                    v-model="form.permissions"
                                    class="mt-0.5 shrink-0 rounded border-line text-primary focus:ring-primary"
                                />
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-ink leading-snug">{{ permLabels[perm] ?? perm }}</p>
                                    <p class="text-xs text-ink-soft font-mono mt-0.5">{{ perm }}</p>
                                </div>
                            </label>
                        </div>
                        <InputError :message="form.errors.permissions" />
                    </div>

                    <!-- Footer actions -->
                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button
                            type="button"
                            class="text-sm font-medium text-ink-soft hover:text-ink"
                            @click="emit('close')"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded-ra bg-primary px-5 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover disabled:opacity-60"
                        >
                            {{ isEdit ? 'Save changes' : 'Create user' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </Transition>
</template>
