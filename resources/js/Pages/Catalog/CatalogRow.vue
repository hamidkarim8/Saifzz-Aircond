<script setup>
import { computed } from 'vue';
import { IconGripVertical, IconRepeat } from '@tabler/icons-vue';

const props = defineProps({
    type: { type: Object, required: true },
    // When set, a drag handle is shown and wired to this Pointer-down handler.
    handleDown: { type: Function, default: null },
});

const MODE_META = {
    flat:      { label: 'Flat rate', class: 'text-blue-700' },
    hp_tiered: { label: 'HP-tiered', class: 'text-violet-700' },
    flexible:  { label: 'Flexible',  class: 'text-amber-700' },
};
const mode = computed(() => MODE_META[props.type.pricing_mode] ?? MODE_META.flat);

const fees = computed(() => props.type.fees ?? []);

// hp_tiered fees grouped by unit type, tiers sorted by HP.
const groups = computed(() => {
    if (props.type.pricing_mode !== 'hp_tiered') return [];
    const map = {};
    for (const fee of fees.value) (map[fee.unit_type] ??= []).push(fee);
    return Object.entries(map).map(([unit_type, rows]) => ({
        unit_type,
        rows: [...rows].sort((a, b) => Number(a.hp_value) - Number(b.hp_value)),
    }));
});

const money = (v) => (Number.isInteger(Number(v)) ? `RM ${Number(v)}` : `RM ${Number(v).toFixed(2)}`);
</script>

<template>
    <div class="group flex flex-col gap-4 px-5 py-5 transition hover:bg-surface-muted/40 sm:flex-row sm:gap-8">
        <!-- Identity -->
        <div class="flex items-start gap-2.5 sm:w-60 sm:shrink-0">
            <button
                v-if="handleDown"
                type="button"
                class="mt-0.5 shrink-0 cursor-grab touch-none text-ink-muted opacity-0 transition hover:text-ink active:cursor-grabbing group-hover:opacity-100 sm:opacity-40"
                title="Drag to reorder"
                @pointerdown="handleDown"
            >
                <IconGripVertical class="h-5 w-5" />
            </button>
            <div class="min-w-0">
                <h3 class="text-lg font-semibold leading-tight text-navy-900">{{ type.name }}</h3>
                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                    <span class="font-semibold uppercase tracking-wide" :class="mode.class">{{ mode.label }}</span>
                    <span v-if="type.requires_next_service" class="inline-flex items-center gap-1 text-ink-soft">
                        <IconRepeat class="h-3.5 w-3.5" /> Next service tracked
                    </span>
                </div>
            </div>
        </div>

        <!-- Pricing -->
        <div class="min-w-0 flex-1">
            <!-- flexible -->
            <p v-if="type.pricing_mode === 'flexible'" class="text-sm text-ink-soft">
                Quoted per job — price set on site.
            </p>

            <!-- no fees -->
            <p v-else-if="!fees.length" class="text-sm text-ink-muted">No pricing configured yet.</p>

            <!-- flat: a chip per unit type -->
            <div v-else-if="type.pricing_mode === 'flat'" class="flex flex-wrap gap-2">
                <div
                    v-for="fee in fees"
                    :key="fee.id"
                    class="inline-flex items-baseline gap-2 rounded-ra border border-line bg-surface px-3 py-1.5"
                >
                    <span class="text-sm text-ink-soft">{{ fee.unit_type }}</span>
                    <span class="font-mono text-sm font-semibold text-navy-900">{{ money(fee.price) }}</span>
                </div>
            </div>

            <!-- hp_tiered: grouped per unit type, HP→price chips -->
            <div v-else class="space-y-3">
                <div v-for="group in groups" :key="group.unit_type">
                    <p class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ group.unit_type }}</p>
                    <div class="flex flex-wrap gap-2">
                        <div
                            v-for="fee in group.rows"
                            :key="fee.id"
                            class="inline-flex items-baseline gap-2 rounded-ra border border-line bg-surface px-3 py-1.5"
                        >
                            <span class="text-sm text-ink-soft">{{ Number(fee.hp_value).toFixed(1) }} HP</span>
                            <span class="font-mono text-sm font-semibold text-navy-900">{{ money(fee.price) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
