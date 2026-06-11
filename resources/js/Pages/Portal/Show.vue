<template>
    <div>
        <!-- Portal Account — placeholder (UI in a later task) -->
        <slot />
    </div>
</template>

<script setup>
defineProps({
    client: Object,
    visits: Array,
    next_service_date: String,
    business: Object,
});
</script>
