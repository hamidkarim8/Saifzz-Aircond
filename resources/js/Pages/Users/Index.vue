<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import UserModal from './Partials/UserModal.vue';

defineProps({
    users: Array,
    grantablePermissions: Array,
});

const modalOpen = ref(false);
const editing = ref(null);

const openAdd = () => { editing.value = null; modalOpen.value = true; };
const openEdit = (user) => { editing.value = user; modalOpen.value = true; };

const toggleActive = (user) => {
    router.patch(route('users.active', user.id), {}, { preserveScroll: true });
};

const roleBadge = {
    admin: 'bg-primary-50 text-primary',
    technician: 'bg-surface-muted text-ink-soft',
};
</script>

<template>
    <Head title="Users" />

    <AdminLayout>
        <template #header>
            <h1 class="text-lg font-bold tracking-tight text-navy-800">Users</h1>
        </template>

        <div class="mb-5 flex items-center justify-between">
            <p class="text-sm text-ink-soft">Staff accounts. Only admins can create or modify users.</p>
            <button
                class="inline-flex items-center gap-2 rounded-ra bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover"
                @click="openAdd"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M12 5v14M5 12h14" stroke-linecap="round" />
                </svg>
                Add user
            </button>
        </div>

        <div class="overflow-hidden rounded-ral border border-line bg-surface shadow-card">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-line bg-surface-muted text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">
                        <th class="px-5 py-3">Name</th>
                        <th class="px-5 py-3">Email</th>
                        <th class="px-5 py-3">Role</th>
                        <th class="px-5 py-3">Permissions</th>
                        <th class="px-5 py-3">Active</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="user in users" :key="user.id" class="hover:bg-surface-muted/50">
                        <td class="px-5 py-3.5 font-medium text-ink">{{ user.name }}</td>
                        <td class="px-5 py-3.5 text-ink-soft">{{ user.email }}</td>
                        <td class="px-5 py-3.5">
                            <span
                                class="inline-block rounded-full px-2.5 py-0.5 text-[11px] font-semibold capitalize"
                                :class="roleBadge[user.role] ?? roleBadge.technician"
                            >
                                {{ user.role }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-ink-soft">
                            <span v-if="user.role === 'admin'" class="text-xs font-semibold text-primary">All</span>
                            <span v-else class="tabular-nums">{{ user.permissions?.length ?? 0 }} / {{ grantablePermissions.length }}</span>
                        </td>
                        <td class="px-5 py-3.5">
                            <button
                                v-if="user.role !== 'admin'"
                                class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors"
                                :class="user.active ? 'bg-ok' : 'bg-line'"
                                :title="user.active ? 'Deactivate' : 'Activate'"
                                @click="toggleActive(user)"
                            >
                                <span
                                    class="pointer-events-none inline-block h-4 w-4 rounded-full bg-white shadow-card transition-transform"
                                    :class="user.active ? 'translate-x-4' : 'translate-x-0'"
                                />
                            </button>
                            <span v-else class="text-xs text-ink-muted">—</span>
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <button
                                v-if="user.role !== 'admin'"
                                class="text-sm font-medium text-primary hover:text-primary-hover"
                                @click="openEdit(user)"
                            >
                                Edit
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!users.length">
                        <td colspan="6" class="px-5 py-8 text-center text-sm text-ink-soft">No users found.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <UserModal
            :open="modalOpen"
            :user="editing"
            :grantable-permissions="grantablePermissions"
            @close="modalOpen = false"
        />
    </AdminLayout>
</template>
