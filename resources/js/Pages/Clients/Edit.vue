<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import ClientForm from './Partials/ClientForm.vue';

const props = defineProps({ client: Object });
</script>

<template>
    <Head title="Edit client" />

    <AdminLayout>
        <template #header>
            <h1 class="text-lg font-bold tracking-tight text-navy-800">Edit client</h1>
        </template>

        <div class="mx-auto max-w-2xl">
            <PageHeader :title="client.name" :subtitle="'Edit client · #' + client.serial_no">
                <template #actions>
                    <Link :href="route('clients.show', client.id)" class="text-sm font-medium text-ink-soft hover:text-ink">← Back to profile</Link>
                </template>
            </PageHeader>

            <Card>
                <ClientForm :client="client">
                    <template #cancel>
                        <Link :href="route('clients.show', client.id)" class="text-sm font-medium text-ink-soft hover:text-ink">Cancel</Link>
                    </template>
                </ClientForm>
            </Card>
        </div>
    </AdminLayout>
</template>
