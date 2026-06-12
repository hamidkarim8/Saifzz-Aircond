<script setup>
import { computed } from 'vue';
import { IconShieldCheck, IconShieldHalf, IconShieldOff } from '@tabler/icons-vue';

// state: 'active' | 'expiring' | 'expired' | 'none'; label: text to show.
const props = defineProps({
    state: { type: String, default: 'none' },
    label: { type: String, default: '' },
});

const map = {
    active: { cls: 'bg-ok-bg text-ok', icon: IconShieldCheck },
    expiring: { cls: 'bg-warn-bg text-warn', icon: IconShieldHalf },
    expired: { cls: 'bg-surface-muted text-ink-soft', icon: IconShieldOff },
    none: { cls: 'bg-surface-muted text-ink-soft', icon: IconShieldOff },
};
const cfg = computed(() => map[props.state] ?? map.none);
const text = computed(() => props.label || (props.state === 'none' ? 'No warranty' : ''));
</script>

<template>
    <span :class="cfg.cls" class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold">
        <component :is="cfg.icon" :size="13" :stroke="2" />
        {{ text }}
    </span>
</template>
