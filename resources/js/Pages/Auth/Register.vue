<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <Head title="Register" />

    <GuestLayout>
        <h1 class="text-xl font-bold text-navy-900">Create staff account</h1>
        <p class="mt-1 text-sm text-ink-soft">Register a new Saifzz Aircond Services staff login.</p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label for="name" class="block text-sm font-semibold text-ink-soft">Name</label>
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    required
                    autofocus
                    autocomplete="name"
                    class="mt-1 w-full rounded-ra border border-line px-3 py-2.5 focus:border-primary focus:ring-primary"
                />
                <p v-if="form.errors.name" class="mt-1 text-sm font-medium text-danger">{{ form.errors.name }}</p>
            </div>

            <div>
                <label for="email" class="block text-sm font-semibold text-ink-soft">Email</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    required
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
                    autocomplete="new-password"
                    class="mt-1 w-full rounded-ra border border-line px-3 py-2.5 focus:border-primary focus:ring-primary"
                />
                <p v-if="form.errors.password" class="mt-1 text-sm font-medium text-danger">{{ form.errors.password }}</p>
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-semibold text-ink-soft">Confirm password</label>
                <input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    required
                    autocomplete="new-password"
                    class="mt-1 w-full rounded-ra border border-line px-3 py-2.5 focus:border-primary focus:ring-primary"
                />
                <p v-if="form.errors.password_confirmation" class="mt-1 text-sm font-medium text-danger">{{ form.errors.password_confirmation }}</p>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-ra bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60"
            >Create account</button>
        </form>

        <div class="mt-6 border-t border-line pt-4 text-center text-sm text-ink-soft">
            Already registered?
            <Link :href="route('login')" class="font-semibold text-primary hover:text-primary-hover">Sign in</Link>
        </div>
    </GuestLayout>
</template>
