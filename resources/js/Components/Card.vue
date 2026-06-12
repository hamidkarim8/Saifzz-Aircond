<script setup>
defineProps({ title: { type: String, default: '' } });
</script>

<template>
    <div class="overflow-hidden rounded-ral border border-line bg-surface shadow-card">
        <div v-if="title || $slots.title || $slots.actions" class="flex items-center justify-between border-b border-line px-4 py-3.5">
            <div class="flex items-center gap-2 text-sm font-bold text-ink">
                <slot name="title">{{ title }}</slot>
            </div>
            <div v-if="$slots.actions"><slot name="actions" /></div>
        </div>
        <div class="p-4"><slot /></div>
    </div>
</template>
