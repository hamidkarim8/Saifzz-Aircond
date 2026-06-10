<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import ClientForm from './Partials/ClientForm.vue';

defineProps({ client: Object });
</script>

<template>
    <Head title="Edit client" />

    <AdminLayout>
        <template #header>
            <h1 class="text-lg font-bold tracking-tight text-navy-800">Edit client</h1>
        </template>

        <div class="mx-auto max-w-2xl">
            <div class="rounded-ral border border-line bg-surface p-6 shadow-card sm:p-8">
                <ClientForm :client="client">
                    <template #cancel>
                        <Link :href="route('clients.show', client.id)" class="text-sm font-medium text-ink-soft hover:text-ink">Cancel</Link>
                    </template>
                </ClientForm>
            </div>
        </div>
    </AdminLayout>
</template>
