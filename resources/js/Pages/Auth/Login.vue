<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: Boolean,
    status: String,
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Log in" />

    <GuestLayout>
        <h1 class="text-xl font-bold text-navy-900">Sign in</h1>
        <p class="mt-1 text-sm text-ink-soft">Access the Saifzz Aircond service system.</p>

        <div v-if="status" class="mt-4 rounded-ra bg-ok-bg px-3 py-2 text-sm font-medium text-ok">{{ status }}</div>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label for="email" class="block text-sm font-semibold text-ink-soft">Email</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    required
                    autofocus
                    autocomplete="username"
                    class="mt-1 w-full rounded-ra border border-line px-3 py-2.5 focus:border-primary focus:ring-primary"
                />
                <p v-if="form.errors.email" class="mt-1 text-sm font-medium text-danger">{{ form.errors.email }}</p>
            </div>

            <div>
                <label for="password" class="block text-sm font-semibold text-ink-soft">Password</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    required
                    autocomplete="current-password"
                    class="mt-1 w-full rounded-ra border border-line px-3 py-2.5 focus:border-primary focus:ring-primary"
                />
                <p v-if="form.errors.password" class="mt-1 text-sm font-medium text-danger">{{ form.errors.password }}</p>
            </div>

            <div class="flex items-center justify-between">
                <label class="flex items-center gap-2 text-sm text-ink-soft">
                    <input v-model="form.remember" type="checkbox" class="rounded border-line text-primary focus:ring-primary" />
                    Remember me
                </label>
                <Link v-if="canResetPassword" :href="route('password.request')" class="text-sm font-medium text-primary hover:text-primary-hover">Forgot password?</Link>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-ra bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60"
            >Sign in</button>
        </form>

        <div class="mt-6 border-t border-line pt-4 text-center text-sm text-ink-soft">
            Are you a customer?
            <a :href="route('portal.login')" class="font-semibold text-primary hover:text-primary-hover">View your service history →</a>
        </div>
    </GuestLayout>
</template>
