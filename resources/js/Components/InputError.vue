<script setup>
defineProps({ message: { type: String, default: '' } });
</script>

<template>
    <p v-show="message" class="mt-1 flex items-start gap-1 break-words text-xs font-medium text-danger">
        <svg class="mt-0.5 h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><path d="M12 8v4M12 16h.01" stroke-linecap="round" /></svg>
        <span>{{ message }}</span>
    </p>
</template>
