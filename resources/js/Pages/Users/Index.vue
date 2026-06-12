<script setup>
import { ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import DataTable from '@/Components/DataTable.vue';
import Badge from '@/Components/Badge.vue';
import UserModal from './Partials/UserModal.vue';
import { confirmDanger, toast } from '@/lib/swal.js';

const props = defineProps({
    users: Array,
    grantablePermissions: Array,
});

const page = usePage();

const modalOpen = ref(false);
const editing = ref(null);

const openAdd = () => { editing.value = null; modalOpen.value = true; };
const openEdit = (user) => { editing.value = user; modalOpen.value = true; };

const toggleActive = async (user) => {
    // Deactivation needs a confirmation; activation does not
    if (user.active) {
        const ok = await confirmDanger({
            title: `Deactivate ${user.name}?`,
            body: 'They will not be able to sign in.',
            confirmText: 'Deactivate',
        });
        if (!ok) return;
    }
    router.patch(route('users.active', user.id), {}, {
        preserveScroll: true,
        onError: (errors) => {
            const msg = errors?.active ?? errors?.message ?? 'Could not update status.';
            toast.error(msg);
        },
    });
};

const columns = [
    { key: 'name',  label: 'Name',  sortable: true  },
    { key: 'email', label: 'Email', sortable: true  },
    { key: 'role',  label: 'Role',  sortable: true  },
    { key: 'permissions', label: 'Permissions', sortable: false },
    { key: 'active',      label: 'Active',      sortable: false, align: 'center', headerClass: 'text-center' },
    { key: '_actions',    label: '',            sortable: false, align: 'right'  },
];
</script>

<template>
    <Head title="Users" />

    <AdminLayout>
        <template #header>
            <h1 class="text-lg font-bold tracking-tight text-navy-800">Users</h1>
        </template>

        <PageHeader title="Users" subtitle="Staff accounts &amp; permissions">
            <template #actions>
                <button
                    class="inline-flex items-center gap-2 rounded-ra bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover"
                    @click="openAdd"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="M12 5v14M5 12h14" stroke-linecap="round" />
                    </svg>
                    Add user
                </button>
            </template>
        </PageHeader>

        <DataTable
            :columns="columns"
            :rows="users"
            mode="client"
            :searchable="true"
            :search-keys="['name', 'email']"
            search-placeholder="Search name or email…"
            :per-page="10"
        >
            <!-- Role cell -->
            <template #cell-role="{ value }">
                <Badge :variant="value === 'admin' ? 'blue' : 'gray'" class="capitalize">
                    {{ value }}
                </Badge>
            </template>

            <!-- Permissions cell -->
            <template #cell-permissions="{ row }">
                <span v-if="row.role === 'admin'" class="text-xs font-semibold text-primary">All</span>
                <span v-else class="tabular-nums text-ink-soft text-sm">
                    {{ row.permissions?.length ?? 0 }} / {{ grantablePermissions.length }}
                </span>
            </template>

            <!-- Active toggle cell -->
            <template #cell-active="{ row }">
                <div class="flex justify-center">
                    <button
                        v-if="row.role !== 'admin'"
                        class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors"
                        :class="row.active ? 'bg-ok' : 'bg-line'"
                        :title="row.active ? 'Deactivate' : 'Activate'"
                        @click="toggleActive(row)"
                    >
                        <span
                            class="pointer-events-none inline-block h-4 w-4 rounded-full bg-white shadow-card transition-transform"
                            :class="row.active ? 'translate-x-4' : 'translate-x-0'"
                        />
                    </button>
                    <span v-else class="text-xs text-ink-muted">—</span>
                </div>
            </template>

            <!-- Actions cell -->
            <template #cell-_actions="{ row }">
                <button
                    v-if="row.role !== 'admin'"
                    class="text-sm font-medium text-primary hover:text-primary-hover"
                    @click="openEdit(row)"
                >
                    Edit
                </button>
            </template>

            <!-- Mobile card slot -->
            <template #card="{ row }">
                <div class="rounded-ral border border-line bg-surface p-4 shadow-card">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate font-semibold text-ink">{{ row.name }}</p>
                            <p class="truncate text-sm text-ink-soft">{{ row.email }}</p>
                        </div>
                        <Badge :variant="row.role === 'admin' ? 'blue' : 'gray'" class="capitalize shrink-0 mt-0.5">
                            {{ row.role }}
                        </Badge>
                    </div>
                    <div class="mt-3 flex items-center justify-between">
                        <span v-if="row.role === 'admin'" class="text-xs font-semibold text-primary">All permissions</span>
                        <span v-else class="text-xs text-ink-soft tabular-nums">
                            {{ row.permissions?.length ?? 0 }} / {{ grantablePermissions.length }} permissions
                        </span>
                        <div class="flex items-center gap-3">
                            <button
                                v-if="row.role !== 'admin'"
                                class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors"
                                :class="row.active ? 'bg-ok' : 'bg-line'"
                                :title="row.active ? 'Deactivate' : 'Activate'"
                                @click="toggleActive(row)"
                            >
                                <span
                                    class="pointer-events-none inline-block h-4 w-4 rounded-full bg-white shadow-card transition-transform"
                                    :class="row.active ? 'translate-x-4' : 'translate-x-0'"
                                />
                            </button>
                            <button
                                v-if="row.role !== 'admin'"
                                class="text-sm font-medium text-primary hover:text-primary-hover"
                                @click="openEdit(row)"
                            >
                                Edit
                            </button>
                        </div>
                    </div>
                </div>
            </template>

            <template #empty>No users found.</template>
        </DataTable>

        <UserModal
            :open="modalOpen"
            :user="editing"
            :grantable-permissions="grantablePermissions"
            @close="modalOpen = false"
        />
    </AdminLayout>
</template>
