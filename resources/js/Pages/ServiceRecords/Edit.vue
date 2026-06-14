<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/Card.vue';
import Badge from '@/Components/Badge.vue';
import { serviceVariant } from '@/lib/badges';

const page = usePage();
const canCollectCash = page.props.auth?.can?.collect_payment ?? false;

const props = defineProps({
    visit: Object,
    technicians: { type: Array, default: null },
});

const form = useForm({
    visit_date: props.visit.visit_date?.slice(0, 10) ?? '',
    warranty_months: props.visit.warranty_months ?? 0,
    payment_method: props.visit.transaction?.method ?? (canCollectCash ? 'Cash' : 'DuitNow QR'),
    technician_id: props.visit.technician_id ?? null,
});

const warrantyEnd = computed(() => {
    if (!form.warranty_months || !form.visit_date) return null;
    const d = new Date(form.visit_date);
    d.setMonth(d.getMonth() + Number(form.warranty_months));
    return d.toLocaleDateString('en-MY', { day: 'numeric', month: 'short', year: 'numeric' });
});

const money = (v) => 'RM ' + Number(v ?? 0).toFixed(2);

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

        <form class="mx-auto max-w-3xl space-y-5 pb-16" @submit.prevent="submit">
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
                    </div>
                </div>
            </Card>

            <!-- Services (read-only) -->
            <Card title="Services (read-only)">
                <div class="-mx-4 -mt-4">
                    <ul class="divide-y divide-line">
                        <li v-for="l in visit.lines" :key="l.id" class="flex items-center justify-between px-5 py-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <Badge :variant="serviceVariant(l.service_type)">{{ l.service_type }}</Badge>
                                <span v-if="l.unit_type" class="text-sm text-ink-soft">{{ l.unit_type }}</span>
                                <span v-if="l.gas_option" class="text-sm text-ink-soft">{{ l.gas_option }}</span>
                                <span class="text-xs text-ink-muted">× {{ l.units }}</span>
                            </div>
                            <span class="shrink-0 font-mono text-sm font-semibold text-navy-800">{{ money(l.subtotal) }}</span>
                        </li>
                    </ul>
                    <div class="flex items-center justify-between border-t border-line bg-surface-muted px-5 py-3">
                        <span class="text-sm font-semibold text-ink-soft">Total</span>
                        <span class="font-mono text-base font-bold text-navy-800">{{ money(visit.total_amount) }}</span>
                    </div>
                </div>
            </Card>

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
                <p v-if="form.errors.payment_method" class="mt-2 text-sm text-danger">{{ form.errors.payment_method }}</p>
            </Card>

            <div class="flex items-center justify-end gap-3 pt-2">
                <Link :href="route('service-records.show', visit.id)" class="text-sm font-medium text-ink-soft hover:text-ink transition">Cancel</Link>
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-ra bg-primary px-6 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover disabled:opacity-60"
                >
                    Save changes
                </button>
            </div>
        </form>
    </AdminLayout>
</template>
