<script setup>
import { computed } from 'vue';

// variant drives the 3px top border + icon box color.
const props = defineProps({
    label: String,
    value: [String, Number],
    sub: { type: String, default: '' },
    subPositive: { type: Boolean, default: false },
    variant: { type: String, default: 'primary' }, // primary | ok | warn | danger
});

const bar = {
    primary: 'before:bg-primary', ok: 'before:bg-ok', warn: 'before:bg-warn', danger: 'before:bg-danger',
};
const iconBox = {
    primary: 'bg-primary-50 text-primary', ok: 'bg-ok-bg text-ok',
    warn: 'bg-warn-bg text-warn', danger: 'bg-danger-bg text-danger',
};
const barCls = computed(() => bar[props.variant] ?? bar.primary);
const iconCls = computed(() => iconBox[props.variant] ?? iconBox.primary);
</script>

<template>
    <div
        class="relative overflow-hidden rounded-ral border border-line bg-surface p-4 shadow-card
               before:absolute before:inset-x-0 before:top-0 before:h-[3px] before:content-['']"
        :class="barCls"
    >
        <div v-if="$slots.icon" class="mb-2.5 grid h-9 w-9 place-items-center rounded-ra" :class="iconCls">
            <slot name="icon" />
        </div>
        <div class="text-[11px] font-medium uppercase tracking-wide text-ink-soft">{{ label }}</div>
        <div class="mt-0.5 text-2xl font-bold leading-none text-ink">{{ value }}</div>
        <div v-if="sub" class="mt-1 text-xs" :class="subPositive ? 'font-semibold text-ok' : 'text-ink-muted'">{{ sub }}</div>
    </div>
</template>
