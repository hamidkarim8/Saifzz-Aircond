<script setup>
import InputError from '@/Components/InputError.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';

defineProps({
    mustVerifyEmail: { type: Boolean },
    status: { type: String },
});

const user = usePage().props.auth.user;

const form = useForm({
    name: user.name,
    email: user.email,
});
</script>

<template>
    <form @submit.prevent="form.patch(route('profile.update'))" class="space-y-4">
        <div>
            <label class="mb-1.5 block text-sm font-semibold text-ink">Name</label>
            <input
                v-model="form.name"
                type="text"
                class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary"
                :class="{ 'border-danger focus:border-danger focus:ring-danger': form.errors.name }"
                autocomplete="name"
                required
                autofocus
            />
            <InputError :message="form.errors.name" />
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-semibold text-ink">Email</label>
            <input
                v-model="form.email"
                type="email"
                class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary"
                :class="{ 'border-danger focus:border-danger focus:ring-danger': form.errors.email }"
                autocomplete="username"
                required
            />
            <InputError :message="form.errors.email" />
        </div>

        <div v-if="mustVerifyEmail && user.email_verified_at === null">
            <p class="text-sm text-ink-soft">
                Email unverified.
                <Link
                    :href="route('verification.send')"
                    method="post"
                    as="button"
                    class="text-primary underline hover:text-primary-600"
                >
                    Re-send verification email.
                </Link>
            </p>
            <p v-show="status === 'verification-link-sent'" class="mt-1 text-sm font-medium text-ok">
                Verification link sent.
            </p>
        </div>

        <div class="flex items-center gap-4 pt-1">
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded-ra bg-primary px-5 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover disabled:opacity-60"
            >
                Save changes
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
