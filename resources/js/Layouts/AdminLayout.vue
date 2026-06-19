<script setup>
import { ref, computed, watch } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import { useFlashToast } from '@/composables/useFlashToast';
import {
    IconLayoutDashboard, IconUsers, IconBell, IconClipboardPlus,
    IconCalendarEvent, IconUserCog,
    IconLogout, IconMenu2, IconCategory, IconReceipt2, IconBook, IconBuildingStore,
} from '@tabler/icons-vue';

const page = usePage();
const can = computed(() => page.props.auth.can ?? {});
const isAdmin = computed(() => page.props.auth.isAdmin);
const user = computed(() => page.props.auth.user);

const drawerOpen = ref(false);
const userMenu = ref(false);

// Close the mobile drawer on navigation.
watch(() => page.url, () => { drawerOpen.value = false; });

// Wire the shared flash toast system.
useFlashToast();

const reminderCount = computed(() => page.props.reminderCount ?? 0);
const notificationCount = computed(() => page.props.notificationCount ?? 0);

// Grouped nav. Each item gated by a permission key (null = always).
const sections = computed(() => {
    const def = [
        { title: 'Main', items: [
            { label: 'Dashboard', route: 'dashboard', icon: IconLayoutDashboard, permission: 'view_reports' },
            { label: 'Appointments', route: 'appointments.index', match: 'appointments', icon: IconCalendarEvent, permission: 'set_appointment' },
            { label: 'Catalog', route: 'catalog.index', match: 'catalog', icon: IconBook, permission: null },
            { label: 'Service Records', route: 'service-records.index', match: 'service-records', icon: IconClipboardPlus, permission: 'record_service' },
            { label: 'Reminders', route: 'reminders.index', match: 'reminders', icon: IconBell, permission: 'view_clients', badge: reminderCount.value },
            { label: 'Transactions', route: 'transactions.index', match: 'transactions', icon: IconReceipt2, permission: 'view_reports' },
        ]},
        { title: 'Settings', items: [
            { label: 'Services', route: 'service-types.index', match: 'service-types', icon: IconCategory, permission: 'manage_service_types' },
            { label: 'Business', route: 'business-settings.show', match: 'business-settings', icon: IconBuildingStore, permission: null, adminOnly: true },
            { label: 'Users', route: 'users.index', match: 'users', icon: IconUserCog, permission: 'manage_users', adminOnly: true },
            { label: 'Clients', route: 'clients.index', match: 'clients', icon: IconUsers, permission: 'view_clients' },
        ]},

    ];
    return def
        .map((s) => ({ ...s, items: s.items.filter((i) => (i.permission === null || can.value[i.permission]) && (!i.adminOnly || isAdmin.value)) }))
        .filter((s) => s.items.length);
});

const isActive = (item) => {
    const current = page.url.replace(/^\//, '');
    if (item.match) return current === item.match || current.startsWith(item.match + '/');
    return route().current(item.route);
};

const initials = computed(() =>
    (user.value?.name ?? '?').split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase(),
);

const logout = () => router.post(route('logout'));

// Close the user menu shortly after blur (allows a click on a menu item to register first).
const closeUserMenuSoon = () => setTimeout(() => (userMenu.value = false), 150);
</script>

<template>
    <div class="min-h-screen bg-appbg" style="padding-top: env(safe-area-inset-top)">
        <!-- Notch / status-bar strip (PWA standalone) — navy to match brand; 0-height on devices without a notch -->
        <div class="fixed inset-x-0 top-0 z-50 bg-navy-900" style="height: env(safe-area-inset-top)" />

        <!-- Mobile drawer backdrop -->
        <div
            v-show="drawerOpen"
            class="fixed inset-0 z-30 bg-navy-900/60 backdrop-blur-sm lg:hidden"
            @click="drawerOpen = false"
        />

        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-navy-900 text-white transition-transform duration-300 lg:translate-x-0"
            :class="drawerOpen ? 'translate-x-0' : '-translate-x-full'"
            style="padding-top: env(safe-area-inset-top)"
        >
            <!-- Logo -->
            <div class="flex h-16 items-center gap-3 px-5">
                <img src="/img/logo-256.png" alt="Saifzz Aircond" class="h-11 w-11 rounded-ra object-cover" />
                <div class="leading-tight">
                    <div class="text-sm font-bold text-white">Saifzz Aircond</div>
                    <div class="font-mono text-[10px] uppercase tracking-widest text-primary-300/60">Service System</div>
                </div>
            </div>

            <!-- Nav -->
            <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-3">
                <template v-for="section in sections" :key="section.title">
                    <div class="px-3 pb-1 pt-3 text-[9.5px] font-bold uppercase tracking-[0.12em] text-primary-300/40">{{ section.title }}</div>
                    <Link v-for="item in section.items" :key="item.label" :href="route(item.route)"
                        class="group flex items-center gap-3 rounded-ra px-3 py-2.5 text-sm font-medium transition-colors"
                        :class="isActive(item) ? 'bg-primary text-white shadow-card' : 'text-primary-300 hover:bg-navy-800 hover:text-white'">
                        <component :is="item.icon" :size="18" :stroke="2" class="shrink-0" />
                        <span class="flex-1">{{ item.label }}</span>
                        <span v-if="item.badge" class="rounded-full bg-danger px-1.5 text-[10px] font-bold leading-5 text-white">{{ item.badge }}</span>
                    </Link>
                </template>
            </nav>

            <!-- User block -->
            <div class="border-t border-navy-700/60 p-3">
                <div class="flex items-center gap-3 rounded-ra px-2 py-2">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary text-sm font-bold text-white">{{ initials }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-semibold text-white">{{ user?.name }}</div>
                        <div class="text-[11px] text-primary-300/60">{{ isAdmin ? 'Administrator' : 'Technician' }}</div>
                    </div>
                    <button class="text-primary-300/50 hover:text-white" aria-label="Log out" @click="logout"><IconLogout :size="17" /></button>
                </div>
            </div>
        </aside>

        <!-- Main column -->
        <div class="lg:pl-64">
            <!-- Top bar -->
            <header class="sticky z-20 flex h-16 items-center gap-4 border-b border-line bg-surface/80 px-4 backdrop-blur-md sm:px-6" style="top: env(safe-area-inset-top)">
                <button
                    class="grid h-10 w-10 place-items-center rounded-ra text-ink-soft hover:bg-surface-muted lg:hidden"
                    aria-label="Open menu"
                    @click="drawerOpen = true"
                >
                    <IconMenu2 :size="24" />
                </button>

                <div class="flex-1">
                    <slot name="header" />
                </div>

                <Link :href="route('notifications.index')" class="relative flex h-9 w-9 items-center justify-center rounded-ra border border-line bg-surface text-ink-soft hover:bg-surface-muted transition">
                    <IconBell :size="18" />
                    <span v-if="notificationCount > 0" class="absolute -right-1 -top-1 flex h-4 w-4 items-center justify-center rounded-full bg-danger text-[10px] font-bold text-white leading-none">
                        {{ notificationCount > 9 ? '9+' : notificationCount }}
                    </span>
                </Link>

                <!-- User menu -->
                <div class="relative">
                    <button
                        class="flex items-center gap-2 rounded-ra py-1.5 pl-1.5 pr-3 hover:bg-surface-muted"
                        @click="userMenu = !userMenu"
                        @blur="closeUserMenuSoon"
                    >
                        <span class="grid h-9 w-9 place-items-center rounded-ra bg-navy-800 text-sm font-semibold text-white">{{ initials }}</span>
                        <span class="hidden text-left sm:block">
                            <span class="block text-sm font-semibold leading-tight">{{ user?.name }}</span>
                            <span class="block text-xs text-ink-soft">{{ user?.email }}</span>
                        </span>
                    </button>
                    <div
                        v-show="userMenu"
                        class="absolute right-0 mt-2 w-48 overflow-hidden rounded-ral border border-line bg-surface shadow-lift"
                    >
                        <Link :href="route('profile.edit')" class="block px-4 py-2.5 text-sm text-ink hover:bg-surface-muted">Profile</Link>
                        <button class="block w-full px-4 py-2.5 text-left text-sm text-danger hover:bg-danger-bg" @click="logout">Log out</button>
                    </div>
                </div>
            </header>

            <main class="px-4 py-6 sm:px-6 lg:px-8">
                <slot />
                <footer class="mt-8 border-t border-line pt-4 text-center text-xs text-ink-muted">
                    Powered by MK Technologies
                </footer>
            </main>
        </div>
    </div>
</template>
