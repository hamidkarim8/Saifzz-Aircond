<script setup>
import { Link } from '@inertiajs/vue3';
import { IconShieldCheck, IconHistory, IconReceipt2 } from '@tabler/icons-vue';

defineProps({
    // When true, renders a two-pane branded split screen (used by the login page).
    branded: { type: Boolean, default: false },
});
</script>

<template>
    <!-- ===== Branded split screen ===== -->
    <div v-if="branded" class="flex min-h-screen bg-appbg">
        <!-- Left: brand panel (hidden on mobile) -->
        <aside class="relative hidden w-1/2 overflow-hidden bg-navy-900 text-white lg:flex lg:flex-col">
            <div class="pointer-events-none absolute inset-0">
                <div class="absolute -left-20 -top-20 h-80 w-80 rounded-full bg-primary/30 blur-3xl"></div>
                <div class="absolute bottom-10 right-0 h-72 w-72 rounded-full bg-primary-300/20 blur-3xl"></div>
            </div>
            <div
                class="pointer-events-none absolute inset-0 opacity-[0.07]"
                style="background-image: linear-gradient(#5AAFE8 1px, transparent 1px), linear-gradient(90deg, #5AAFE8 1px, transparent 1px); background-size: 44px 44px;"
            ></div>

            <div class="relative flex flex-1 flex-col justify-between p-12 xl:p-16">
                <Link href="/" class="flex items-center gap-3">
                    <img src="/img/logo-256.png" alt="Saifzz Aircond" class="h-14 w-14 rounded-ra object-cover shadow-lift" />
                    <div class="leading-tight">
                        <div class="text-xl font-bold tracking-tight text-white">Saifzz Aircond</div>
                        <div class="font-mono text-[11px] uppercase tracking-[0.2em] text-primary-300/70">Service System</div>
                    </div>
                </Link>

                <div class="py-8">
                    <h2 class="text-5xl font-extrabold leading-[1.05] tracking-tight xl:text-6xl">
                        Every job,<br />
                        <span class="bg-gradient-to-r from-primary-300 to-primary bg-clip-text text-transparent">fully tracked.</span>
                    </h2>
                    <p class="mt-6 max-w-md text-lg leading-relaxed text-primary-300/80">
                        The complete service system for Saifzz Aircond — clients, records, payments and reports in one place.
                    </p>
                    <ul class="mt-10 space-y-5">
                        <li class="flex items-center gap-4 text-lg font-medium text-white/90">
                            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-ra bg-white/10"><IconHistory :size="22" :stroke="2" class="text-primary-300" /></span>
                            Complete service history per unit
                        </li>
                        <li class="flex items-center gap-4 text-lg font-medium text-white/90">
                            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-ra bg-white/10"><IconShieldCheck :size="22" :stroke="2" class="text-primary-300" /></span>
                            Warranty tracking that never slips
                        </li>
                        <li class="flex items-center gap-4 text-lg font-medium text-white/90">
                            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-ra bg-white/10"><IconReceipt2 :size="22" :stroke="2" class="text-primary-300" /></span>
                            Invoices &amp; receipts on demand
                        </li>
                    </ul>
                </div>

                <p class="text-sm text-primary-300/50">© Saifzz Aircond Services · Powered by MK Technologies</p>
            </div>
        </aside>

        <!-- Right: form -->
        <main class="relative flex w-full flex-col items-center justify-center px-6 py-12 lg:w-1/2">
            <!-- Mobile branded glow (hidden on desktop where left panel handles branding) -->
            <div class="pointer-events-none absolute inset-x-0 top-0 h-48 overflow-hidden lg:hidden">
                <div class="absolute -top-20 left-1/2 h-56 w-72 -translate-x-1/2 rounded-full bg-primary/15 blur-3xl"></div>
            </div>

            <!-- Mobile logo -->
            <Link href="/" class="relative mb-8 flex flex-col items-center gap-3 lg:hidden">
                <img src="/img/logo-256.png" alt="Saifzz Aircond" class="h-16 w-16 rounded-ral object-cover shadow-lift" />
                <div class="text-center leading-tight">
                    <div class="text-xl font-bold tracking-tight text-navy-900">Saifzz Aircond</div>
                    <div class="font-mono text-[10px] uppercase tracking-[0.2em] text-ink-muted">Service System</div>
                </div>
            </Link>

            <!-- Form card -->
            <div class="relative w-full max-w-sm rounded-ral border border-line bg-surface p-7 shadow-card lg:border-0 lg:bg-transparent lg:p-0 lg:shadow-none">
                <slot />
            </div>
        </main>
    </div>

    <!-- ===== Default centered card (forgot/reset/verify) ===== -->
    <div v-else class="flex min-h-screen flex-col items-center justify-center bg-appbg px-4 py-10">
        <Link href="/" class="mb-6 flex items-center gap-3">
            <img src="/img/logo-256.png" alt="Saifzz Aircond" class="h-14 w-14 rounded-ra object-cover" />
            <div class="leading-tight">
                <div class="text-lg font-bold tracking-tight text-navy-900">Saifzz Aircond</div>
                <div class="font-mono text-[10px] uppercase tracking-widest text-ink-muted">Service System</div>
            </div>
        </Link>

        <div class="w-full max-w-md overflow-hidden rounded-ral border border-line bg-surface p-6 shadow-card sm:p-8">
            <slot />
        </div>

        <p class="mt-6 text-xs text-ink-muted">Staff access · © Saifzz Aircond Services</p>
    </div>
</template>
