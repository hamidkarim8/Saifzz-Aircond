<script setup>
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    notifications: { type: Array, default: () => [] },
});

const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const fmtDate = (d) => {
    if (!d) return '—';
    const dt = new Date(d);
    return `${dt.getDate()} ${months[dt.getMonth()]} ${dt.getFullYear()}, ${dt.getHours().toString().padStart(2,'0')}:${dt.getMinutes().toString().padStart(2,'0')}`;
};
</script>

<template>
    <Head title="Notifications" />
    <AdminLayout>
        <template #header>
            <h1 class="text-base font-bold text-navy-800">Notifications</h1>
        </template>

        <div v-if="notifications.length === 0" class="rounded-ral border border-line bg-surface p-10 text-center shadow-card">
            <p class="text-sm font-medium text-ink-soft">No notifications yet.</p>
        </div>

        <div v-else class="space-y-2">
            <div
                v-for="n in notifications"
                :key="n.id"
                class="flex items-start gap-4 rounded-ral border border-line bg-surface p-4 shadow-card"
                :class="!n.read_at ? 'border-l-4 border-l-primary' : ''"
            >
                <div class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" /><path d="M13.73 21a2 2 0 0 1-3.46 0" />
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-navy-800">New appointment — {{ n.data.client_name }} <span class="font-mono text-xs text-ink-muted">#{{ n.data.serial_no }}</span></p>
                    <p class="mt-0.5 text-xs text-ink-soft">{{ fmtDate(n.data.datetime) }}</p>
                    <p v-if="n.data.notes" class="mt-1 text-xs text-ink-muted line-clamp-1">{{ n.data.notes }}</p>
                    <p class="mt-1.5 text-[11px] text-ink-muted">{{ fmtDate(n.created_at) }}</p>
                </div>
                <a :href="route('appointments.index')" class="shrink-0 text-xs font-semibold text-primary hover:underline">View →</a>
            </div>
        </div>
    </AdminLayout>
</template>
