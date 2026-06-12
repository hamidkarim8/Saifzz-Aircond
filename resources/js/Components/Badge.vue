<script setup>
import { computed } from 'vue';
import { VARIANT_CLASS } from '@/lib/badges';

const props = defineProps({
    variant: { type: String, default: 'gray' },
});
const cls = computed(() => VARIANT_CLASS[props.variant] ?? VARIANT_CLASS.gray);
</script>

<template>
    <span :class="cls" class="inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold">
        <slot />
    </span>
</template>
