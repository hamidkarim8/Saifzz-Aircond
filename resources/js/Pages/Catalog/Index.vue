<script setup>
import { computed, ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/Card.vue';
import Badge from '@/Components/Badge.vue';
import { IconSearch } from '@tabler/icons-vue';
import { serviceVariant } from '@/lib/badges';

const props = defineProps({
    serviceTypes: Array,
    feeGroups: { type: Object, default: () => ({}) },
    modes: Array,
});

const search = ref('');

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.serviceTypes;
    return props.serviceTypes.filter((t) => t.name.toLowerCase().includes(q));
});

function feesFor(typeName) {
    return props.feeGroups[typeName] ?? [];
}

function formatRate(fee) {
    if (fee.pricing_mode === 'flexible') return 'Varies';
    return fee.rate != null ? `RM ${Number(fee.rate).toFixed(2)}` : '—';
}

function modeLabel(mode) {
    if (mode === 'fixed_per_unit') return 'Per unit';
    if (mode === 'flexible') return 'Flexible';
    return mode;
}
</script>

<template>
    <AdminLayout title="Catalog">
        <div class="mx-auto max-w-5xl space-y-6 p-4 sm:p-6">
            <!-- Header -->
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h1 class="text-xl font-semibold text-navy-900">Service Catalog</h1>
                <!-- Search -->
                <div class="relative w-full sm:w-64">
                    <IconSearch class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search services…"
                        class="w-full rounded-ra border border-gray-200 bg-white py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    />
                </div>
            </div>

            <!-- Empty state (no service types at all) -->
            <div v-if="serviceTypes.length === 0" class="py-16 text-center text-sm text-gray-400">
                No services configured yet.
            </div>

            <!-- No match from search -->
            <div v-else-if="filtered.length === 0" class="py-16 text-center text-sm text-gray-400">
                No services match "{{ search }}".
            </div>

            <!-- Grid -->
            <div v-else class="grid gap-4 sm:grid-cols-2">
                <Card v-for="type in filtered" :key="type.id" class="flex flex-col gap-3 p-4">
                    <!-- Type header -->
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-base font-semibold text-navy-900">{{ type.name }}</span>
                        <Badge v-if="type.requires_next_service" :variant="serviceVariant(type.name)" class="text-xs">
                            Next service tracked
                        </Badge>
                    </div>

                    <!-- Fee list -->
                    <div v-if="feesFor(type.name).length > 0" class="divide-y divide-gray-100 rounded-ra border border-gray-100">
                        <div
                            v-for="fee in feesFor(type.name)"
                            :key="fee.id"
                            class="flex items-center justify-between gap-2 px-3 py-2 text-sm"
                        >
                            <span class="text-gray-700">{{ fee.option || type.name }}</span>
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-gray-400">{{ modeLabel(fee.pricing_mode) }}</span>
                                <span class="font-semibold text-navy-900">{{ formatRate(fee) }}</span>
                            </div>
                        </div>
                    </div>
                    <p v-else class="text-xs text-gray-400">No pricing configured.</p>
                </Card>
            </div>
        </div>
    </AdminLayout>
</template>
