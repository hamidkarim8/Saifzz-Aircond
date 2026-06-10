<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
const can = computed(() => page.props.auth.can ?? {});
</script>

<template>
    <Head title="Dashboard" />

    <AdminLayout>
        <template #header>
            <h1 class="text-lg font-bold tracking-tight text-navy-800">Dashboard</h1>
        </template>

        <div class="rounded-ral border border-line bg-surface p-8 shadow-card">
            <p class="font-mono text-xs uppercase tracking-widest text-primary">Saifzz Aircond</p>
            <h2 class="mt-2 text-2xl font-bold tracking-tight text-navy-800">Welcome back, {{ user?.name }}.</h2>
            <p class="mt-2 text-sm text-ink-soft">
                The KPI dashboard (revenue, reminders, charts) lands later. For now, jump into the modules that are live.
            </p>

            <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-if="can.view_clients"
                    :href="route('clients.index')"
                    class="group rounded-ral border border-line bg-surface-muted p-5 transition hover:border-primary hover:shadow-card"
                >
                    <div class="flex h-10 w-10 items-center justify-center rounded-ra bg-primary text-white">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z" /></svg>
                    </div>
                    <div class="mt-3 font-semibold text-ink group-hover:text-primary">Clients</div>
                    <div class="mt-1 text-sm text-ink-soft">Registry, search, profiles & history.</div>
                </Link>

                <div class="rounded-ral border border-dashed border-line p-5 opacity-70">
                    <div class="flex h-10 w-10 items-center justify-center rounded-ra bg-surface-muted text-ink-muted">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8v8m-4-4h8" stroke-linecap="round" /></svg>
                    </div>
                    <div class="mt-3 font-semibold text-ink-soft">More modules</div>
                    <div class="mt-1 text-sm text-ink-muted">Service records, payments, appointments — coming next.</div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
