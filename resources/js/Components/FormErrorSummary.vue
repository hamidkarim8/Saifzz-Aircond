<script setup>
import { computed } from 'vue';

// Pass an Inertia form `errors` object. Renders a summary box when non-empty.
const props = defineProps({ errors: { type: Object, default: () => ({}) } });
const list = computed(() => Object.values(props.errors).filter(Boolean));
</script>

<template>
    <div v-if="list.length" class="mb-4 rounded-ra border border-danger/30 bg-danger-bg px-4 py-3 text-sm text-danger">
        <div class="mb-1 font-semibold">Please fix the following:</div>
        <ul class="list-inside list-disc space-y-0.5">
            <li v-for="(msg, i) in list" :key="i" class="break-words">{{ msg }}</li>
        </ul>
    </div>
</template>
