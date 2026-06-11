<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    canLogin: Boolean,
});
</script>

<template>
    <Head title="Saifzz Aircond" />

    <div class="min-h-screen bg-appbg">
        <div class="mx-auto flex min-h-screen max-w-3xl flex-col px-6 py-8">
            <!-- Brand bar -->
            <header class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="grid h-10 w-10 place-items-center rounded-ra bg-primary text-lg font-bold text-white">S</div>
                    <div class="leading-tight">
                        <div class="font-bold tracking-tight text-navy-900">Saifzz</div>
                        <div class="font-mono text-[10px] uppercase tracking-widest text-ink-muted">Aircond</div>
                    </div>
                </div>
                <Link v-if="canLogin" :href="route('login')" class="text-sm font-semibold text-primary hover:text-primary-hover">Staff login →</Link>
            </header>

            <!-- Hero + entry points -->
            <main class="flex flex-1 flex-col justify-center py-12">
                <h1 class="text-3xl font-bold tracking-tight text-navy-900 sm:text-4xl">Saifzz Aircond Services</h1>
                <p class="mt-3 max-w-prose text-ink-soft">
                    Cleaning, gas top-up, repair and installation — with full service history and warranty tracking.
                </p>

                <div class="mt-8 grid gap-4 sm:grid-cols-2">
                    <!-- Customer portal -->
                    <a
                        :href="route('portal.login')"
                        class="group rounded-ral border border-line bg-surface p-6 shadow-card transition hover:border-primary hover:shadow-lift"
                    >
                        <div class="grid h-11 w-11 place-items-center rounded-ra bg-primary-50 text-primary">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2M9 12h6M9 16h4" />
                            </svg>
                        </div>
                        <h2 class="mt-4 font-bold text-navy-900">Customer portal</h2>
                        <p class="mt-1 text-sm text-ink-soft">View your service history, warranty status and receipts.</p>
                        <span class="mt-3 inline-block text-sm font-semibold text-primary">Enter serial →</span>
                    </a>

                    <!-- Staff sign in -->
                    <Link
                        v-if="canLogin"
                        :href="route('login')"
                        class="group rounded-ral border border-line bg-surface p-6 shadow-card transition hover:border-primary hover:shadow-lift"
                    >
                        <div class="grid h-11 w-11 place-items-center rounded-ra bg-navy-900 text-white">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="11" width="18" height="11" rx="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" />
                            </svg>
                        </div>
                        <h2 class="mt-4 font-bold text-navy-900">Staff sign in</h2>
                        <p class="mt-1 text-sm text-ink-soft">Manage clients, service records, payments and reports.</p>
                        <span class="mt-3 inline-block text-sm font-semibold text-primary">Sign in →</span>
                    </Link>
                </div>
            </main>

            <footer class="text-xs text-ink-muted">© Saifzz Aircond · Service Management System</footer>
        </div>
    </div>
</template>
