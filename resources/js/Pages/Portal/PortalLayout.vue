<script setup>
import { router } from '@inertiajs/vue3';
import { useFlashToast } from '@/composables/useFlashToast';

const props = defineProps({
    business: { type: Object, default: () => ({ name: 'Service Portal' }) },
    showLogout: { type: Boolean, default: false },
    // Vertically center the slot content (used by the login screen).
    center: { type: Boolean, default: false },
});

useFlashToast();

const logout = () => router.post(route('portal.logout'));
</script>

<template>
    <!-- Full-page navy gradient background -->
    <div class="relative flex min-h-screen flex-col overflow-hidden bg-gradient-to-br from-navy-900 via-navy-800 to-navy-700 text-ink">
        <!-- Airflow glow accents -->
        <div class="pointer-events-none absolute inset-0">
            <div class="absolute -left-24 top-10 h-72 w-72 rounded-full bg-primary/20 blur-3xl"></div>
            <div class="absolute -right-20 bottom-0 h-72 w-72 rounded-full bg-primary-300/10 blur-3xl"></div>
        </div>

        <!-- Top bar (hidden on the centered login screen, where the logo badge carries the brand) -->
        <header v-if="!center" class="sticky top-0 z-10 bg-navy-900/80 backdrop-blur-sm">
            <div class="mx-auto flex max-w-[480px] items-center justify-between px-4 py-3.5">
                <!-- Brand -->
                <div class="flex items-center gap-2">
                    <span class="text-sm font-bold tracking-tight text-white">{{ business.name }}</span>
                </div>

                <!-- Right side: logout only (admin link hidden for customers) -->
                <button
                    v-if="showLogout"
                    type="button"
                    class="rounded-ra bg-white/10 px-3 py-1 text-xs font-semibold text-white transition hover:bg-white/20"
                    @click="logout"
                >Sign out</button>
                <!-- Admin link commented out — no admin-login redirect from customer portal
                <a
                    v-else
                    :href="route('dashboard')"
                    class="rounded-ra bg-white/10 px-3 py-1 text-xs font-semibold text-primary-300 transition hover:bg-white/20 hover:text-white"
                >Admin</a>
                -->
            </div>
        </header>

        <!-- Page content — mobile-first centered column -->
        <main
            class="relative z-0 mx-auto w-full max-w-[480px] px-4"
            :class="center ? 'flex flex-1 flex-col justify-center py-8' : 'py-8'"
        >
            <slot />
        </main>
    </div>
</template>
