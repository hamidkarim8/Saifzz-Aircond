<script setup>
import InputError from '@/Components/InputError.vue';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const passwordInput = ref(null);
const currentPasswordInput = ref(null);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => {
            if (form.errors.password) {
                form.reset('password', 'password_confirmation');
                passwordInput.value.focus();
            }
            if (form.errors.current_password) {
                form.reset('current_password');
                currentPasswordInput.value.focus();
            }
        },
    });
};
</script>

<template>
    <form @submit.prevent="updatePassword" class="space-y-4">
        <div>
            <label class="mb-1.5 block text-sm font-semibold text-ink">Current Password</label>
            <input
                ref="currentPasswordInput"
                v-model="form.current_password"
                type="password"
                class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary"
                :class="{ 'border-danger focus:border-danger focus:ring-danger': form.errors.current_password }"
                autocomplete="current-password"
            />
            <InputError :message="form.errors.current_password" />
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-semibold text-ink">New Password</label>
            <input
                ref="passwordInput"
                v-model="form.password"
                type="password"
                class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary"
                :class="{ 'border-danger focus:border-danger focus:ring-danger': form.errors.password }"
                autocomplete="new-password"
            />
            <InputError :message="form.errors.password" />
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-semibold text-ink">Confirm New Password</label>
            <input
                v-model="form.password_confirmation"
                type="password"
                class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary"
                :class="{ 'border-danger focus:border-danger focus:ring-danger': form.errors.password_confirmation }"
                autocomplete="new-password"
            />
            <InputError :message="form.errors.password_confirmation" />
        </div>

        <div class="flex items-center gap-4 pt-1">
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded-ra bg-primary px-5 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover disabled:opacity-60"
            >
                Update password
            </button>
            <Transition
                enter-active-class="transition ease-in-out"
                enter-from-class="opacity-0"
                leave-active-class="transition ease-in-out"
                leave-to-class="opacity-0"
            >
                <p v-if="form.recentlySuccessful" class="text-sm font-medium text-ok">Saved.</p>
            </Transition>
        </div>
    </form>
</template>
