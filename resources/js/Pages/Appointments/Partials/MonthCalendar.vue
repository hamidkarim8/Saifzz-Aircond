<script setup>
import { computed } from 'vue';

const props = defineProps({
    month: { type: String, required: true },      // 'YYYY-MM'
    appointments: { type: Array, default: () => [] },
    selectedDay: { type: Number, default: null },  // 1..31 or null
});
const emit = defineEmits(['select', 'prev', 'next']);

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

const year = computed(() => Number(props.month.slice(0, 4)));
const monthIdx = computed(() => Number(props.month.slice(5, 7)) - 1); // 0-based

const title = computed(() => `${MONTHS[monthIdx.value]} ${year.value}`);

// Day-of-month → list of appointments on that day (local to the viewed month).
const byDay = computed(() => {
    const map = {};
    for (const a of props.appointments) {
        const d = new Date(a.datetime);
        if (d.getFullYear() === year.value && d.getMonth() === monthIdx.value) {
            (map[d.getDate()] ??= []).push(a);
        }
    }
    return map;
});

const leadingBlanks = computed(() => new Date(year.value, monthIdx.value, 1).getDay());
const daysInMonth = computed(() => new Date(year.value, monthIdx.value + 1, 0).getDate());
const days = computed(() => Array.from({ length: daysInMonth.value }, (_, i) => i + 1));

const today = new Date();
const isToday = (day) =>
    today.getFullYear() === year.value && today.getMonth() === monthIdx.value && today.getDate() === day;
</script>

<template>
    <div class="overflow-hidden rounded-ral border border-line bg-surface shadow-card">
        <header class="flex items-center justify-between border-b border-line px-5 py-3">
            <div class="font-bold text-navy-800">{{ title }}</div>
            <div class="flex items-center gap-1.5">
                <button class="grid h-8 w-8 place-items-center rounded-ra text-ink-soft hover:bg-surface-muted" aria-label="Previous month" @click="emit('prev')">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 18l-6-6 6-6" stroke-linecap="round" stroke-linejoin="round" /></svg>
                </button>
                <button class="grid h-8 w-8 place-items-center rounded-ra text-ink-soft hover:bg-surface-muted" aria-label="Next month" @click="emit('next')">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 18l6-6-6-6" stroke-linecap="round" stroke-linejoin="round" /></svg>
                </button>
            </div>
        </header>

        <div class="p-3 sm:p-4">
            <div class="grid grid-cols-7 gap-1 text-center">
                <div v-for="w in WEEKDAYS" :key="w" class="py-1.5 text-[11px] font-semibold uppercase tracking-wide text-ink-muted">{{ w }}</div>

                <div v-for="b in leadingBlanks" :key="'b' + b" />

                <button
                    v-for="day in days"
                    :key="day"
                    type="button"
                    class="relative aspect-square rounded-ra pb-3 text-sm font-medium transition"
                    :class="[
                        selectedDay === day ? 'bg-primary text-white shadow-card'
                            : byDay[day] ? 'bg-primary-50 text-primary hover:bg-primary-100'
                            : 'text-ink-soft hover:bg-surface-muted',
                        isToday(day) && selectedDay !== day ? 'ring-1 ring-primary' : '',
                    ]"
                    @click="emit('select', day)"
                >
                    {{ day }}
                    <span v-if="byDay[day]" class="absolute bottom-1 left-1/2 -translate-x-1/2">
                        <span
                            v-if="byDay[day].length === 1"
                            class="block h-2 w-2 rounded-full"
                            :class="selectedDay === day ? 'bg-white' : 'bg-primary'"
                        />
                        <span
                            v-else
                            class="flex h-3.5 min-w-3.5 items-center justify-center rounded-full px-0.5 text-[8px] font-bold leading-none tabular-nums"
                            :class="selectedDay === day ? 'bg-white text-primary' : 'bg-primary text-white'"
                        >{{ byDay[day].length }}</span>
                    </span>
                </button>
            </div>
        </div>
    </div>
</template>
