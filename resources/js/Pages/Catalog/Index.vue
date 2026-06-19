<script setup>
import { computed, ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/Card.vue';
import Badge from '@/Components/Badge.vue';
import { IconSearch } from '@tabler/icons-vue';
import { serviceVariant } from '@/lib/badges';

const props = defineProps({
    serviceTypes: Array,
});

const search = ref('');

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.serviceTypes;
    return props.serviceTypes.filter((t) => t.name.toLowerCase().includes(q));
});

/** Group hp_tiered fees by unit_type → [{unit_type, rows:[{hp_value,price}]}] */
function groupedFees(type) {
    if (type.pricing_mode !== 'hp_tiered') return null;
    const map = {};
    for (const fee of type.fees) {
        if (!map[fee.unit_type]) map[fee.unit_type] = [];
        map[fee.unit_type].push(fee);
    }
    return Object.entries(map).map(([unit_type, rows]) => ({ unit_type, rows }));
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

                    <!-- flexible -->
                    <p v-if="type.pricing_mode === 'flexible'" class="text-xs text-gray-400 italic">
                        Flexible pricing — set per job
                    </p>

                    <!-- flat: list each fee as unit_type — RM price -->
                    <template v-else-if="type.pricing_mode === 'flat'">
                        <div v-if="type.fees.length > 0" class="divide-y divide-gray-100 rounded-ra border border-gray-100">
                            <div
                                v-for="fee in type.fees"
                                :key="fee.id"
                                class="flex items-center justify-between gap-2 px-3 py-2 text-sm"
                            >
                                <span class="text-gray-700">{{ fee.unit_type }}</span>
                                <span class="font-semibold text-navy-900">RM {{ Number(fee.price).toFixed(2) }}</span>
                            </div>
                        </div>
                        <p v-else class="text-xs text-gray-400">No pricing configured.</p>
                    </template>

                    <!-- hp_tiered: group by unit_type, show X.X HP — RM price rows -->
                    <template v-else-if="type.pricing_mode === 'hp_tiered'">
                        <div v-if="type.fees.length > 0" class="space-y-3">
                            <div v-for="group in groupedFees(type)" :key="group.unit_type">
                                <p class="mb-1 text-xs font-medium text-gray-500">{{ group.unit_type }}</p>
                                <div class="divide-y divide-gray-100 rounded-ra border border-gray-100">
                                    <div
                                        v-for="fee in group.rows"
                                        :key="fee.id"
                                        class="flex items-center justify-between gap-2 px-3 py-2 text-sm"
                                    >
                                        <span class="text-gray-700">{{ Number(fee.hp_value).toFixed(1) }} HP</span>
                                        <span class="font-semibold text-navy-900">RM {{ Number(fee.price).toFixed(2) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p v-else class="text-xs text-gray-400">No pricing configured.</p>
                    </template>
                </Card>
            </div>
        </div>
    </AdminLayout>
</template>
