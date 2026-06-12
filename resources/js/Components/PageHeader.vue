<script setup>
defineProps({ title: String, subtitle: { type: String, default: '' } });
</script>

<template>
    <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-lg font-bold tracking-tight text-navy-800">{{ title }}</h1>
            <p v-if="subtitle" class="mt-0.5 text-sm text-ink-soft">{{ subtitle }}</p>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2"><slot name="actions" /></div>
    </div>
</template>
