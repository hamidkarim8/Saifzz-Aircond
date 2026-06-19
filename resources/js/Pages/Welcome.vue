<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { IconClipboardList, IconLock, IconArrowRight, IconDroplet, IconFlame, IconTool, IconBuildingStore } from '@tabler/icons-vue';

defineProps({
    canLogin: Boolean,
});

const services = [
    { label: 'Cleaning', icon: IconDroplet },
    { label: 'Gas top-up', icon: IconFlame },
    { label: 'Repair', icon: IconTool },
    { label: 'Installation', icon: IconBuildingStore },
];
</script>

<template>
    <Head title="Saifzz Aircond Services" />

    <div class="min-h-screen bg-appbg">
        <!-- ===== HERO ===== -->
        <section class="relative overflow-hidden bg-navy-900 text-white">
            <!-- Airflow glow accents -->
            <div class="pointer-events-none absolute inset-0">
                <div class="absolute -left-24 -top-24 h-80 w-80 rounded-full bg-primary/30 blur-3xl"></div>
                <div class="absolute -right-16 top-1/3 h-72 w-72 rounded-full bg-primary-300/20 blur-3xl"></div>
                <div class="absolute bottom-0 left-1/3 h-64 w-96 rounded-full bg-navy-700/60 blur-3xl"></div>
            </div>
            <!-- Subtle grid texture -->
            <div
                class="pointer-events-none absolute inset-0 opacity-[0.07]"
                style="background-image: linear-gradient(#5AAFE8 1px, transparent 1px), linear-gradient(90deg, #5AAFE8 1px, transparent 1px); background-size: 44px 44px;"
            ></div>

            <div class="relative mx-auto max-w-5xl px-6">
                <!-- Brand bar -->
                <header class="flex items-center justify-between py-6">
                    <div class="flex items-center gap-3">
                        <img src="/img/logo-256.png" alt="Saifzz Aircond" class="h-12 w-12 rounded-ra object-cover shadow-lift" />
                        <div class="leading-tight">
                            <div class="font-bold tracking-tight text-white">Saifzz Aircond</div>
                            <div class="font-mono text-[10px] uppercase tracking-[0.2em] text-primary-300/70">Service System</div>
                        </div>
                    </div>
                    <Link
                        v-if="canLogin"
                        :href="route('login')"
                        class="inline-flex items-center gap-1.5 rounded-ra border border-white/15 px-4 py-2 text-sm font-semibold text-white transition hover:border-primary-300 hover:bg-white/5"
                    >
                        Staff login <IconArrowRight :size="15" :stroke="2.5" />
                    </Link>
                </header>

                <!-- Headline -->
                <div class="max-w-2xl pb-32 pt-16 sm:pt-24">
                    <span class="inline-flex items-center gap-2 rounded-full border border-primary-300/30 bg-primary/10 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-primary-300">
                        <span class="h-1.5 w-1.5 rounded-full bg-primary-300"></span>
                        Trusted aircond servicing
                    </span>
                    <h1 class="mt-5 text-4xl font-extrabold leading-[1.05] tracking-tight sm:text-6xl">
                        Cool comfort,<br />
                        <span class="bg-gradient-to-r from-primary-300 to-primary bg-clip-text text-transparent">fully tracked.</span>
                    </h1>
                    <p class="mt-5 max-w-lg text-base leading-relaxed text-primary-300/80">
                        Cleaning, gas top-up, repair and installation — every job logged with complete service history and warranty tracking.
                    </p>

                    <!-- Service pills -->
                    <div class="mt-7 flex flex-wrap gap-2">
                        <span
                            v-for="s in services"
                            :key="s.label"
                            class="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/5 px-3.5 py-1.5 text-sm font-medium text-white/90 backdrop-blur-sm"
                        >
                            <component :is="s.icon" :size="15" :stroke="2" class="text-primary-300" />
                            {{ s.label }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Bottom curve into light section -->
            <div class="absolute inset-x-0 bottom-0 h-16 bg-appbg" style="clip-path: ellipse(75% 100% at 50% 100%);"></div>
        </section>

        <!-- ===== ENTRY CARDS (overlap hero) ===== -->
        <section class="relative mx-auto -mt-24 max-w-5xl px-6 pb-16">
            <div class="grid gap-5 sm:grid-cols-2">
                <!-- Customer portal -->
                <a
                    :href="route('portal.login')"
                    class="group relative overflow-hidden rounded-ral border border-line bg-surface p-7 shadow-card transition duration-200 hover:-translate-y-1 hover:border-primary hover:shadow-lift"
                >
                    <div class="absolute -right-8 -top-8 h-28 w-28 rounded-full bg-primary-50 transition-transform duration-300 group-hover:scale-150"></div>
                    <div class="relative">
                        <div class="grid h-12 w-12 place-items-center rounded-ra bg-primary-50 text-primary">
                            <IconClipboardList :size="24" :stroke="2" />
                        </div>
                        <h2 class="mt-5 text-lg font-bold text-navy-900">Customer portal</h2>
                        <p class="mt-1.5 text-sm leading-relaxed text-ink-soft">View your service history, warranty status and receipts — enter your unit serial to begin.</p>
                        <span class="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-primary group-hover:gap-2.5 transition-all">
                            Enter serial <IconArrowRight :size="16" :stroke="2.5" />
                        </span>
                    </div>
                </a>

                <!-- Staff sign in -->
                <Link
                    v-if="canLogin"
                    :href="route('login')"
                    class="group relative overflow-hidden rounded-ral border border-line bg-navy-900 p-7 text-white shadow-card transition duration-200 hover:-translate-y-1 hover:shadow-lift"
                >
                    <div class="absolute -right-8 -top-8 h-28 w-28 rounded-full bg-primary/20 transition-transform duration-300 group-hover:scale-150"></div>
                    <div class="relative">
                        <div class="grid h-12 w-12 place-items-center rounded-ra bg-primary text-white">
                            <IconLock :size="24" :stroke="2" />
                        </div>
                        <h2 class="mt-5 text-lg font-bold text-white">Staff sign in</h2>
                        <p class="mt-1.5 text-sm leading-relaxed text-primary-300/80">Manage clients, service records, payments and reports from your dashboard.</p>
                        <span class="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-primary-300 group-hover:gap-2.5 transition-all">
                            Sign in <IconArrowRight :size="16" :stroke="2.5" />
                        </span>
                    </div>
                </Link>
            </div>

            <footer class="mt-12 text-center text-xs text-ink-muted">© Saifzz Aircond Services · Powered by MK Technologies</footer>
        </section>
    </div>
</template>
