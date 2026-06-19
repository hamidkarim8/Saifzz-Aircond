<script setup>
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DragList from '@/Components/DragList.vue';
import CatalogRow from './CatalogRow.vue';
import { IconSearch, IconGripVertical } from '@tabler/icons-vue';

const props = defineProps({
    serviceTypes: Array,
});

const can = computed(() => usePage().props.auth?.can ?? {});
const canReorder = computed(() => !!can.value.manage_service_types);

const search = ref('');
const isSearching = computed(() => search.value.trim() !== '');

// Local reorderable copy; resync when the server returns fresh props.
const orderedTypes = ref([...props.serviceTypes]);
watch(() => props.serviceTypes, (t) => { orderedTypes.value = [...t]; });

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return orderedTypes.value;
    return orderedTypes.value.filter((t) => t.name.toLowerCase().includes(q));
});

// Reorder is only offered on the full, unfiltered list (dragging a filtered
// subset would map ambiguously onto the stored sequence).
const reorderMode = computed(() => canReorder.value && !isSearching.value);

function persistOrder(order) {
    router.put(route('service-types.reorder'), { order }, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <AdminLayout title="Catalog">
        <div class="mx-auto max-w-5xl space-y-6 p-4 sm:p-6">
            <!-- Header -->
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-navy-900">Service Catalog</h1>
                    <p class="mt-1 text-sm text-ink-soft">
                        <!-- {{ serviceTypes.length }} {{ serviceTypes.length === 1 ? 'service' : 'services' }} ·
                        rates customers are quoted. -->
                        <span v-if="reorderMode" class="inline-flex items-center gap-1">
                            Drag <IconGripVertical class="inline h-3.5 w-3.5" /> to reorder.
                        </span>
                    </p>
                </div>
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

            <!-- Empty / no-match -->
            <div v-if="serviceTypes.length === 0" class="rounded-ral border border-dashed border-line py-16 text-center text-sm text-ink-muted">
                No services configured yet.
            </div>
            <div v-else-if="filtered.length === 0" class="rounded-ral border border-dashed border-line py-16 text-center text-sm text-ink-muted">
                No services match "{{ search }}".
            </div>

            <!-- Rate sheet -->
            <div v-else class="overflow-hidden rounded-ral border border-line bg-surface shadow-card">
                <DragList
                    v-if="reorderMode"
                    v-model="orderedTypes"
                    item-key="id"
                    class="divide-y divide-line"
                    @reorder="persistOrder"
                >
                    <template #item="{ item, handleDown }">
                        <CatalogRow :type="item" :handle-down="handleDown" />
                    </template>
                </DragList>
                <div v-else class="divide-y divide-line">
                    <CatalogRow v-for="type in filtered" :key="type.id" :type="type" />
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
