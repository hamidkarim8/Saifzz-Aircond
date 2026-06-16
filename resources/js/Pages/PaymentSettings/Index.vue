<script setup>
defineProps({
    isConfigured: Boolean,
    portalKeyHint: String,
});
</script>

<template>
    <div>
        <!-- Payment Settings — full UI built in Task 4 -->
        <p v-if="isConfigured">Gateway configured (ending {{ portalKeyHint }})</p>
        <p v-else>No gateway configured.</p>
    </div>
</template>
