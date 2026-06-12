<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import ClientForm from './Partials/ClientForm.vue';
</script>

<template>
    <Head title="New client" />

    <AdminLayout>
        <template #header>
            <h1 class="text-lg font-bold tracking-tight text-navy-800">New client</h1>
        </template>

        <div class="mx-auto max-w-2xl">
            <PageHeader title="New client" subtitle="A unique 6-digit serial is assigned automatically on creation — it goes on the remote sticker." />

            <Card>
                <ClientForm>
                    <template #cancel>
                        <Link :href="route('clients.index')" class="text-sm font-medium text-ink-soft hover:text-ink">Cancel</Link>
                    </template>
                </ClientForm>
            </Card>
        </div>
    </AdminLayout>
</template>
