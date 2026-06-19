<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/Card.vue';
import FormErrorSummary from '@/Components/FormErrorSummary.vue';
import ServiceLineCard from './Partials/ServiceLineCard.vue';
import { IconStar } from '@tabler/icons-vue';

const page = usePage();

const props = defineProps({
    visit: Object,
    technicians: { type: Array, default: null },
    serviceTypes: Array,
    clientUnits: { type: Array, default: () => [] },
});

const blankLine = () => ({
    unit_id: null, service_type: '', unit_type: null, hp_value: null, repair_desc: '',
    units: 1, rate: '', discount: 0, next_service_date: null, notes: '',
});

// Map persisted lines back to the editor shape (coerce decimals to numbers).
const seedLine = (l) => ({
    unit_id: l.unit_id ?? null,
    service_type: l.service_type ?? '',
    unit_type: l.unit_type ?? null,
    hp_value: l.hp_value != null ? Number(l.hp_value) : null,
    repair_desc: l.repair_desc ?? '',
    units: Number(l.units) || 1,
    rate: l.rate != null ? Number(l.rate) : '',
    discount: Number(l.discount) || 0,
    next_service_date: l.next_service_date ? String(l.next_service_date).slice(0, 10) : null,
    notes: l.notes ?? '',
});

const form = useForm({
    visit_date: props.visit.visit_date?.slice(0, 10) ?? '',
    warranty_months: props.visit.warranty_months ?? 0,
    technician_id: props.visit.technician_id ?? null,
    lines: (props.visit.lines?.length ? props.visit.lines.map(seedLine) : [blankLine()]),
});

const addLine = () => form.lines.push(blankLine());
const removeLine = (i) => form.lines.splice(i, 1);

const lineSubtotal = (l) => {
    const units = l.unit_id ? 1 : (Number(l.units) || 0);
    return Math.max(0, (Number(l.rate) || 0) * units - (Number(l.discount) || 0));
};
const grandTotal = computed(() => form.lines.reduce((s, l) => s + lineSubtotal(l), 0));
const totalServices = computed(() => form.lines.filter(l => l.service_type).length);
const totalUnits = computed(() => form.lines.reduce((s, l) => s + (l.unit_id ? 1 : (Number(l.units) || 0)), 0));
const money = (v) => 'RM ' + Number(v ?? 0).toFixed(2);

const warrantyEnd = computed(() => {
    if (!form.warranty_months || !form.visit_date) return null;
    const d = new Date(form.visit_date);
    d.setMonth(d.getMonth() + Number(form.warranty_months));
    return d.toLocaleDateString('en-MY', { day: 'numeric', month: 'short', year: 'numeric' });
});

// Google-review warranty bonus: toggle adds +1 month (capped at 6), toggling off removes it.
// A manual dropdown change clears the bonus state so we never subtract from a hand-set value.
const reviewBonus = ref(false);
const atWarrantyCap = computed(() => Number(form.warranty_months) >= 6);
let suppressBonusWatch = false;
const toggleReviewBonus = () => {
    if (!reviewBonus.value) {
        if (atWarrantyCap.value) return;
        suppressBonusWatch = true;
        form.warranty_months = Number(form.warranty_months) + 1;
        reviewBonus.value = true;
    } else {
        suppressBonusWatch = true;
        form.warranty_months = Math.max(0, Number(form.warranty_months) - 1);
        reviewBonus.value = false;
    }
};
watch(() => form.warranty_months, () => {
    if (suppressBonusWatch) { suppressBonusWatch = false; return; }
    reviewBonus.value = false;
});

const submit = () => form.patch(route('service-records.update', props.visit.id));
</script>

<template>
    <Head title="Edit service record" />

    <AdminLayout>
        <template #header>
            <div class="flex min-w-0 items-center justify-between gap-4">
                <h1 class="truncate text-base font-bold text-navy-800">Edit service record</h1>
                <Link :href="route('service-records.show', visit.id)" class="shrink-0 text-sm font-medium text-ink-soft hover:text-ink transition">← Back</Link>
            </div>
        </template>

        <form class="mx-auto max-w-3xl space-y-5 pb-32" @submit.prevent="submit">

            <FormErrorSummary :errors="form.errors" />

            <!-- Client (read-only) -->
            <Card title="Client">
                <div class="flex items-center gap-3">
                    <span class="font-semibold text-ink">{{ visit.client?.name ?? '—' }}</span>
                    <span class="font-mono text-xs text-primary">#{{ visit.client?.serial_no }}</span>
                </div>
            </Card>

            <!-- Visit meta -->
            <Card title="Visit details">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Visit date</label>
                        <input v-model="form.visit_date" type="date" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary" />
                        <p v-if="form.errors.visit_date" class="mt-1 text-sm text-danger">{{ form.errors.visit_date }}</p>
                    </div>
                    <div v-if="technicians">
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Technician</label>
                        <select v-model="form.technician_id" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary">
                            <option :value="null">{{ page.props.auth?.user?.name ?? '— Me —' }}</option>
                            <option v-for="t in technicians" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Warranty (months)</label>
                        <select v-model.number="form.warranty_months" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary">
                            <option v-for="m in [0,1,2,3,4,5,6]" :key="m" :value="m">{{ m === 0 ? 'No warranty' : m + ' month' + (m > 1 ? 's' : '') }}</option>
                        </select>
                        <p v-if="warrantyEnd" class="mt-1 text-xs text-ok">Covered until {{ warrantyEnd }}</p>
                        <button
                            type="button"
                            :disabled="!reviewBonus && atWarrantyCap"
                            class="mt-2 inline-flex w-full items-center justify-center gap-1.5 rounded-ra border px-3 py-2 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-50"
                            :class="reviewBonus ? 'border-ok bg-ok-bg text-ok' : 'border-line bg-surface text-ink-soft hover:border-primary hover:text-primary'"
                            @click="toggleReviewBonus"
                        >
                            <IconStar :size="14" :stroke="2" />
                            {{ reviewBonus ? 'Review bonus applied · +1 month' : 'Customer left a Google review · +1 month' }}
                        </button>
                        <p v-if="!reviewBonus && atWarrantyCap" class="mt-1 text-xs text-ink-soft">Max warranty is 6 months.</p>
                    </div>
                </div>
            </Card>

            <!-- Service lines -->
            <div class="space-y-3">
                <div class="flex items-center justify-between px-0.5">
                    <h2 class="text-sm font-bold uppercase tracking-wide text-ink-soft">Services</h2>
                    <span v-if="form.errors.lines" class="text-sm text-danger">{{ form.errors.lines }}</span>
                </div>
                <ServiceLineCard
                    v-for="(line, i) in form.lines"
                    :key="i"
                    :line="line"
                    :index="i"
                    :service-types="serviceTypes"
                    :client-units="clientUnits"
                    :errors="form.errors"
                    :removable="form.lines.length > 1"
                    :visit-date="form.visit_date"
                    @remove="removeLine(i)"
                />
                <button
                    type="button"
                    class="flex w-full items-center justify-center gap-2 rounded-ral border-2 border-dashed border-line py-3.5 text-sm font-semibold text-ink-soft transition hover:border-primary hover:bg-primary-50 hover:text-primary"
                    @click="addLine"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg>
                    Add another service
                </button>
            </div>

        </form>

        <!-- Sticky total bar (navy) -->
        <div class="fixed inset-x-0 bottom-0 z-30 border-t border-navy-900/60 bg-navy-800 lg:pl-64">
            <div class="mx-auto flex max-w-3xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
                <div class="flex items-center gap-5">
                    <div>
                        <div class="text-xs uppercase tracking-widest text-navy-300">Grand total</div>
                        <div class="font-mono text-2xl font-bold text-white">{{ money(grandTotal) }}</div>
                    </div>
                    <div v-if="totalServices > 0" class="hidden sm:block border-l border-navy-600 pl-5">
                        <div class="text-xs text-navy-300">{{ totalServices }} service{{ totalServices !== 1 ? 's' : '' }}</div>
                        <div class="text-xs text-navy-300">{{ totalUnits }} unit{{ totalUnits !== 1 ? 's' : '' }}</div>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <Link :href="route('service-records.show', visit.id)" class="text-sm font-medium text-navy-300 hover:text-white transition">Cancel</Link>
                    <button
                        type="button"
                        :disabled="form.processing"
                        class="rounded-ra bg-primary px-6 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover disabled:opacity-60"
                        @click="submit"
                    >
                        Save changes
                    </button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
