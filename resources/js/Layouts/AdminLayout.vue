<script setup>
import { ref, computed, watch } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';

const page = usePage();
const can = computed(() => page.props.auth.can ?? {});
const isAdmin = computed(() => page.props.auth.isAdmin);
const user = computed(() => page.props.auth.user);

const drawerOpen = ref(false);
const userMenu = ref(false);

// Close the mobile drawer on navigation.
watch(() => page.url, () => { drawerOpen.value = false; });

// Flash toast.
const toast = ref(null);
watch(
    () => page.props.flash,
    (flash) => {
        const msg = flash?.success || flash?.error;
        if (!msg) return;
        toast.value = { type: flash.success ? 'ok' : 'error', msg };
        setTimeout(() => (toast.value = null), 4000);
    },
    { immediate: true, deep: true },
);

// Nav model — each item gated by a permission key (null = always visible).
const nav = computed(() => [
    { label: 'Dashboard', route: 'dashboard', icon: 'grid', permission: null },
    { label: 'Clients', route: 'clients.index', match: 'clients', icon: 'users', permission: 'view_clients' },
].filter((i) => i.permission === null || can.value[i.permission]));

const isActive = (item) => {
    const current = page.url.replace(/^\//, '');
    if (item.match) return current === item.match || current.startsWith(item.match + '/');
    return route().current(item.route);
};

const initials = computed(() =>
    (user.value?.name ?? '?').split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase(),
);

const logout = () => router.post(route('logout'));

const icons = {
    grid: 'M3 3h7v7H3V3zm11 0h7v7h-7V3zM3 14h7v7H3v-7zm11 0h7v7h-7v-7z',
    users: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm13 10v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
};
</script>

<template>
    <div class="min-h-screen bg-appbg">
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
        >
            <div class="flex h-16 items-center gap-3 px-6">
                <div class="flex h-9 w-9 items-center justify-center rounded-ra bg-primary font-bold">S</div>
                <div class="leading-tight">
                    <div class="font-bold tracking-tight">Saifzz</div>
                    <div class="font-mono text-[10px] uppercase tracking-widest text-ink-muted">Aircond</div>
                </div>
            </div>

            <nav class="flex-1 space-y-1 px-3 py-4">
                <Link
                    v-for="item in nav"
                    :key="item.label"
                    :href="route(item.route)"
                    class="group flex items-center gap-3 rounded-ra px-3 py-2.5 text-sm font-medium transition-colors"
                    :class="isActive(item)
                        ? 'bg-primary text-white shadow-card'
                        : 'text-primary-300 hover:bg-navy-800 hover:text-white'"
                >
                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path :d="icons[item.icon]" />
                    </svg>
                    {{ item.label }}
                </Link>
            </nav>

            <div class="border-t border-navy-700/60 p-3 text-[11px] text-ink-muted">
                <span class="font-mono">{{ isAdmin ? 'ADMIN' : 'TECHNICIAN' }}</span>
            </div>
        </aside>

        <!-- Main column -->
        <div class="lg:pl-64">
            <!-- Top bar -->
            <header class="sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-line bg-surface/80 px-4 backdrop-blur-md sm:px-6">
                <button
                    class="grid h-10 w-10 place-items-center rounded-ra text-ink-soft hover:bg-surface-muted lg:hidden"
                    aria-label="Open menu"
                    @click="drawerOpen = true"
                >
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round" /></svg>
                </button>

                <div class="flex-1">
                    <slot name="header" />
                </div>

                <!-- User menu -->
                <div class="relative">
                    <button
                        class="flex items-center gap-2 rounded-ra py-1.5 pl-1.5 pr-3 hover:bg-surface-muted"
                        @click="userMenu = !userMenu"
                        @blur="setTimeout(() => (userMenu = false), 150)"
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
            </main>
        </div>

        <!-- Flash toast -->
        <Transition
            enter-active-class="transition duration-300" enter-from-class="translate-y-3 opacity-0"
            leave-active-class="transition duration-200" leave-to-class="translate-y-3 opacity-0"
        >
            <div
                v-if="toast"
                class="fixed bottom-5 left-1/2 z-50 -translate-x-1/2 rounded-ral px-5 py-3 text-sm font-medium text-white shadow-lift"
                :class="toast.type === 'ok' ? 'bg-ok' : 'bg-danger'"
            >
                {{ toast.msg }}
            </div>
        </Transition>
    </div>
</template>
