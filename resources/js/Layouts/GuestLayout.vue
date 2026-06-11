<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="flex min-h-screen flex-col items-center justify-center bg-appbg px-4 py-10">
        <Link href="/" class="mb-6 flex items-center gap-3">
            <div class="grid h-11 w-11 place-items-center rounded-ra bg-primary text-lg font-bold text-white">S</div>
            <div class="leading-tight">
                <div class="text-lg font-bold tracking-tight text-navy-900">Saifzz</div>
                <div class="font-mono text-[10px] uppercase tracking-widest text-ink-muted">Aircond</div>
            </div>
        </Link>

        <div class="w-full max-w-md overflow-hidden rounded-ral border border-line bg-surface p-6 shadow-card sm:p-8">
            <slot />
        </div>

        <p class="mt-6 text-xs text-ink-muted">Staff access · © Saifzz Aircond</p>
    </div>
</template>
