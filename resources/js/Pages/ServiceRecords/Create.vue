<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/Card.vue';
import FormErrorSummary from '@/Components/FormErrorSummary.vue';
import ClientPicker from './Partials/ClientPicker.vue';
import ServiceLineCard from './Partials/ServiceLineCard.vue';

const page = usePage();
const canCollectCash = page.props.auth?.can?.collect_payment ?? false;

const props = defineProps({
    serviceTypes: Array,
    presetClient: { type: Object, default: null },
    presetClientUnits: { type: Array, default: () => [] },
    presetTechnicianId: { type: Number, default: null },
    presetAppointmentId: { type: Number, default: null },
    technicians: { type: Array, default: null },
});

const clientUnits = ref(props.presetClientUnits);

const blankLine = () => ({
    unit_id: null, service_type: '', unit_type: null, hp_value: null, repair_desc: '',
    units: 1, rate: '', discount: 0, next_service_date: null, notes: '',
});

const form = useForm({
    client_mode: 'existing',
    client_id: null,
    new_client: { name: '', phone: '', address: '' },
    visit_date: new Date().toISOString().slice(0, 10),
    warranty_months: 0,
    payment_method: canCollectCash ? 'Cash' : 'DuitNow QR',
    technician_id: props.presetTechnicianId ?? null,
    appointment_id: props.presetAppointmentId ?? null,
    lines: [blankLine()],
});

// Fetch units when client changes.
watch(() => form.client_id, (clientId) => {
    if (!clientId) { clientUnits.value = []; return; }
    fetch(route('clients.units.index', clientId), {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
        .then(r => r.ok ? r.json() : Promise.resolve([]))
        .then(units => { clientUnits.value = Array.isArray(units) ? units : []; })
        .catch(() => { clientUnits.value = []; });
});

const addLine = () => form.lines.push(blankLine());
const removeLine = (i) => form.lines.splice(i, 1);

const addLinesForAllUnits = () => {
    clientUnits.value.forEach(unit => {
        if (form.lines.some(l => l.unit_id === unit.id)) return;
        form.lines.push({
            unit_id: unit.id,
            service_type: '',
            unit_type: unit.unit_type,
            hp_value: null,
            repair_desc: '',
            units: 1,
            rate: '',
            discount: 0,
            next_service_date: null,
            notes: '',
        });
    });
};

const lineSubtotal = (l) => {
    const units = l.unit_id ? 1 : (Number(l.units) || 0);
    return Math.max(0, (Number(l.rate) || 0) * units - (Number(l.discount) || 0));
};
const grandTotal = computed(() => form.lines.reduce((s, l) => s + lineSubtotal(l), 0));
const money = (v) => 'RM ' + Number(v).toFixed(2);

const warrantyEnd = computed(() => {
    if (!form.warranty_months || !form.visit_date) return null;
    const d = new Date(form.visit_date);
    d.setMonth(d.getMonth() + Number(form.warranty_months));
    return d.toLocaleDateString('en-MY', { day: 'numeric', month: 'short', year: 'numeric' });
});

const totalServices = computed(() => form.lines.filter(l => l.service_type).length);
const totalUnits = computed(() => form.lines.reduce((s, l) => s + (l.unit_id ? 1 : (Number(l.units) || 0)), 0));

const submit = () => form.post(route('service-records.store'));
</script>

<template>
    <Head title="New service record" />

    <AdminLayout>
        <template #header>
            <h1 class="text-base font-bold text-navy-800">New service record</h1>
        </template>

        <form class="mx-auto max-w-3xl space-y-5 pb-32" @submit.prevent="submit">

            <FormErrorSummary :errors="form.errors" />

            <!-- Client -->
            <ClientPicker :form="form" :preset-client="presetClient" />

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
                        <p v-if="form.errors.technician_id" class="mt-1 text-sm text-danger">{{ form.errors.technician_id }}</p>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Warranty (months)</label>
                        <select v-model.number="form.warranty_months" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary">
                            <option v-for="m in [0,1,2,3,4,5,6]" :key="m" :value="m">{{ m === 0 ? 'No warranty' : m + ' month' + (m > 1 ? 's' : '') }}</option>
                        </select>
                        <p v-if="warrantyEnd" class="mt-1 text-xs text-ok">Covered until {{ warrantyEnd }}</p>
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
                <!-- "Add line for each unit" — HIDDEN pending requirement discussion (see docs/UNITS-TODO.md). Re-enable: restore v-if="clientUnits.length". -->
                <button
                    v-if="false"
                    type="button"
                    class="flex w-full items-center justify-center gap-2 rounded-ral border-2 border-dashed border-primary/40 py-3 text-sm font-semibold text-primary transition hover:border-primary hover:bg-primary-50"
                    @click="addLinesForAllUnits"
                >
                    + Add line for each unit ({{ clientUnits.length }})
                </button>
            </div>

            <!-- Payment method -->
            <Card title="Payment method">
                <div class="grid gap-3" :class="canCollectCash ? 'grid-cols-2' : 'grid-cols-1'">
                    <label
                        v-if="canCollectCash"
                        class="flex cursor-pointer items-center gap-3 rounded-ra border px-4 py-3 transition"
                        :class="form.payment_method === 'Cash' ? 'border-primary bg-primary-50 shadow-card' : 'border-line hover:border-primary/40'"
                    >
                        <input v-model="form.payment_method" type="radio" value="Cash" class="text-primary focus:ring-primary" />
                        <span class="font-semibold text-ink">Cash</span>
                    </label>
                    <label
                        class="flex cursor-pointer items-center gap-3 rounded-ra border px-4 py-3 transition"
                        :class="form.payment_method === 'DuitNow QR' ? 'border-primary bg-primary-50 shadow-card' : 'border-line hover:border-primary/40'"
                    >
                        <input v-model="form.payment_method" type="radio" value="DuitNow QR" class="text-primary focus:ring-primary" />
                        <span class="font-semibold text-ink">DuitNow QR</span>
                    </label>
                </div>
            </Card>
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
                    <Link :href="route('service-records.index')" class="text-sm font-medium text-navy-300 hover:text-white transition">Cancel</Link>
                    <button
                        type="button"
                        :disabled="form.processing"
                        class="rounded-ra bg-primary px-6 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover disabled:opacity-60"
                        @click="submit"
                    >
                        Create record
                    </button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
