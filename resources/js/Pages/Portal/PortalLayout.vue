<script setup>
import { router } from '@inertiajs/vue3';

const props = defineProps({
    business: { type: Object, default: () => ({ name: 'Service Portal' }) },
    showLogout: { type: Boolean, default: false },
});

const logout = () => router.post(route('portal.logout'));
</script>

<template>
    <div class="min-h-screen bg-surface-muted text-ink">
        <header class="bg-navy-900 text-white">
            <div class="mx-auto flex max-w-xl items-center justify-between px-4 py-4">
                <div class="font-bold tracking-tight">{{ business.name }}</div>
                <button
                    v-if="showLogout"
                    type="button"
                    class="text-sm font-semibold text-primary-300 hover:text-white"
                    @click="logout"
                >Sign out</button>
            </div>
        </header>
        <main class="mx-auto max-w-xl px-4 py-6">
            <slot />
        </main>
    </div>
</template>
