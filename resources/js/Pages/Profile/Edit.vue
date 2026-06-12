<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';

defineProps({
    mustVerifyEmail: { type: Boolean },
    status: { type: String },
});

const user = computed(() => usePage().props.auth.user);
const isAdmin = computed(() => usePage().props.auth.isAdmin);

const initials = computed(() =>
    (user.value?.name ?? '?').split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase(),
);
</script>

<template>
    <Head title="My Profile" />

    <AdminLayout>
        <template #header>
            <PageHeader title="My Profile" />
        </template>

        <div class="mx-auto max-w-2xl space-y-5">
            <!-- Identity banner -->
            <div class="flex items-center gap-4 rounded-ral border border-line bg-surface px-5 py-4 shadow-card">
                <span class="grid h-14 w-14 shrink-0 place-items-center rounded-full bg-navy-800 text-lg font-bold text-white">
                    {{ initials }}
                </span>
                <div>
                    <div class="text-base font-bold text-ink">{{ user.name }}</div>
                    <div class="text-sm text-ink-soft">{{ user.email }}</div>
                    <div class="mt-0.5 font-mono text-xs text-ink-muted">{{ isAdmin ? 'Administrator' : 'Technician' }}</div>
                </div>
            </div>

            <Card title="Profile Information">
                <UpdateProfileInformationForm
                    :must-verify-email="mustVerifyEmail"
                    :status="status"
                />
            </Card>

            <Card title="Change Password">
                <UpdatePasswordForm />
            </Card>
        </div>
    </AdminLayout>
</template>
