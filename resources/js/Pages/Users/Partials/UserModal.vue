<script setup>
import { watch, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    open: Boolean,
    user: { type: Object, default: null }, // null = create
    grantablePermissions: Array,
});
const emit = defineEmits(['close']);

const isEdit = computed(() => !!props.user);

const permLabels = {
    view_clients: 'View clients & history',
    record_service: 'Record service visits',
    set_appointment: 'Schedule appointments',
    collect_payment: 'Collect payments',
    edit_client: 'Create & edit clients',
    view_reports: 'View reports dashboard',
    edit_fees: 'Manage price book',
    export_data: 'Export data to CSV',
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
            <div class="w-full max-w-lg rounded-t-rax bg-surface p-6 shadow-lift sm:rounded-rax">
                <h3 class="text-lg font-bold text-navy-800">{{ isEdit ? 'Edit user' : 'Add user' }}</h3>

                <form class="mt-5 space-y-4" @submit.prevent="submit">
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Name</label>
                        <input
                            v-model="form.name"
                            type="text"
                            class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary"
                            placeholder="Full name"
                        />
                        <p v-if="form.errors.name" class="mt-1 text-sm text-danger">{{ form.errors.name }}</p>
                    </div>

                    <div v-if="!isEdit">
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Email</label>
                        <input
                            v-model="form.email"
                            type="email"
                            class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary"
                            placeholder="staff@example.com"
                        />
                        <p v-if="form.errors.email" class="mt-1 text-sm text-danger">{{ form.errors.email }}</p>
                    </div>

                    <div v-if="!isEdit">
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Password</label>
                        <input
                            v-model="form.password"
                            type="password"
                            class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary"
                            placeholder="Min. 8 characters"
                        />
                        <p v-if="form.errors.password" class="mt-1 text-sm text-danger">{{ form.errors.password }}</p>
                    </div>

                    <div>
                        <p class="mb-2 text-sm font-semibold text-ink">Permissions</p>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <label
                                v-for="perm in grantablePermissions"
                                :key="perm"
                                class="flex cursor-pointer items-start gap-2.5 rounded-ra border border-line p-3 hover:bg-surface-muted"
                                :class="{ 'border-primary bg-primary-50': form.permissions.includes(perm) }"
                            >
                                <input
                                    type="checkbox"
                                    :value="perm"
                                    v-model="form.permissions"
                                    class="mt-0.5 rounded border-line text-primary focus:ring-primary"
                                />
                                <span class="text-sm text-ink">{{ permLabels[perm] ?? perm }}</span>
                            </label>
                        </div>
                        <p v-if="form.errors.permissions" class="mt-1 text-sm text-danger">{{ form.errors.permissions }}</p>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button type="button" class="text-sm font-medium text-ink-soft hover:text-ink" @click="emit('close')">Cancel</button>
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
