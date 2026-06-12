<script setup>
import { ref, watch, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    open: Boolean,
    appointment: { type: Object, default: null }, // null = new
    serviceTypes: { type: Array, default: () => [] },
    presetClient: { type: Object, default: null },
    technicians:  { type: Array, default: null },
});
const emit = defineEmits(['close']);

const isEdit = computed(() => !!props.appointment);

const form = useForm({
    client_id: null,
    date: '',
    time: '',
    service_type: '',
    units: '',
    amount: '',
    phone: '',
    address: '',
    notes: '',
    technician_id: null,
});

// Chosen existing client (display + prefill); appointments may also be booked client-less.
const chosen = ref(null);
const query = ref('');
const results = ref([]);
const searching = ref(false);

const applyClient = (c) => {
    chosen.value = c;
    form.client_id = c?.id ?? null;
    if (c?.phone) form.phone = c.phone;
    if (c?.address) form.address = c.address;
};
const clearClient = () => { chosen.value = null; form.client_id = null; };

let timer = null;
watch(query, (q) => {
    clearTimeout(timer);
    if (!q || q.length < 2) { results.value = []; return; }
    timer = setTimeout(async () => {
        searching.value = true;
        try {
            const { data } = await window.axios.get(route('clients.lookup'), { params: { q } });
            results.value = data;
        } finally {
            searching.value = false;
        }
    }, 300);
});

const choose = (c) => { applyClient(c); results.value = []; query.value = ''; };

// (Re)hydrate whenever the modal opens.
watch(() => props.open, (open) => {
    if (!open) return;
    form.clearErrors();
    query.value = '';
    results.value = [];

    if (props.appointment) {
        const a = props.appointment;
        form.client_id = a.client_id ?? null;
        form.date = (a.datetime ?? '').slice(0, 10);   // 'YYYY-MM-DD' — slice avoids tz drift
        form.time = (a.datetime ?? '').slice(11, 16);  // 'HH:MM'
        form.service_type = a.service_type ?? '';
        form.units = a.units ?? '';
        form.amount = a.amount ?? '';
        form.phone = a.phone ?? '';
        form.address = a.address ?? '';
        form.notes = a.notes ?? '';
        form.technician_id = a.technician_id ?? null;
        chosen.value = a.client ?? null;
    } else {
        form.reset();
        chosen.value = null;
        if (props.presetClient) applyClient(props.presetClient);
    }
});

const submit = () => {
    const opts = { onSuccess: () => emit('close'), preserveScroll: true };
    if (isEdit.value) {
        form.put(route('appointments.update', props.appointment.id), opts);
    } else {
        form.post(route('appointments.store'), opts);
    }
};
</script>

<template>
    <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0" leave-active-class="transition duration-150" leave-to-class="opacity-0">
        <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center bg-navy-900/60 p-0 backdrop-blur-sm sm:items-center sm:p-4" @click.self="emit('close')">
            <div class="max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-t-rax bg-surface p-6 shadow-lift sm:rounded-rax">
                <h3 class="text-lg font-bold text-navy-800">{{ isEdit ? 'Edit appointment' : 'New appointment' }}</h3>
                <p class="mt-1 text-sm text-ink-soft">Pick an existing client to pre-fill details, or enter them manually for a new lead.</p>

                <form class="mt-5 space-y-4" @submit.prevent="submit">
                    <!-- Form error summary -->
                    <div v-if="Object.keys(form.errors).length" class="rounded-ra border border-danger/30 bg-danger-bg px-4 py-3 text-sm text-danger">
                        Please fix the errors below before saving.
                    </div>

                    <!-- Client (optional) -->
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Client <span class="font-normal text-ink-muted">(optional)</span></label>
                        <div v-if="chosen" class="flex items-center justify-between rounded-ra border border-primary/30 bg-primary-50 px-4 py-2.5">
                            <div class="min-w-0">
                                <div class="truncate font-semibold text-ink">{{ chosen.name }} <span class="ml-1 font-mono text-sm text-primary">#{{ chosen.serial_no }}</span></div>
                            </div>
                            <button type="button" class="text-sm font-medium text-ink-soft hover:text-danger" @click="clearClient">Change</button>
                        </div>
                        <div v-else class="relative">
                            <input v-model="query" type="search" placeholder="Search name, serial or phone…" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary" />
                            <ul v-if="results.length" class="absolute z-10 mt-1 w-full overflow-hidden rounded-ra border border-line bg-surface shadow-lift">
                                <li v-for="c in results" :key="c.id">
                                    <button type="button" class="flex w-full items-center justify-between px-4 py-2.5 text-left text-sm hover:bg-surface-muted" @click="choose(c)">
                                        <span class="font-medium text-ink">{{ c.name }}</span>
                                        <span class="font-mono text-xs text-primary">#{{ c.serial_no }}</span>
                                    </button>
                                </li>
                            </ul>
                            <p v-if="searching" class="mt-1 text-xs text-ink-muted">Searching…</p>
                        </div>
                    </div>

                    <!-- Date + time -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1.5 block text-sm font-semibold text-ink">Date</label>
                            <input v-model="form.date" type="date" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary" />
                            <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-semibold text-ink">Time</label>
                            <input v-model="form.time" type="time" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary" />
                            <p v-if="form.errors.time" class="mt-1 text-sm text-danger">{{ form.errors.time }}</p>
                        </div>
                    </div>

                    <!-- Service + units + amount -->
                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2">
                            <label class="mb-1.5 block text-sm font-semibold text-ink">Service type</label>
                            <select v-model="form.service_type" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary">
                                <option value="" disabled>Choose…</option>
                                <option v-for="t in serviceTypes" :key="t" :value="t">{{ t }}</option>
                            </select>
                            <p v-if="form.errors.service_type" class="mt-1 text-sm text-danger">{{ form.errors.service_type }}</p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-semibold text-ink">Units <span class="font-normal text-ink-muted">(est.)</span></label>
                            <input v-model="form.units" type="number" min="1" inputmode="numeric" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary" />
                            <p v-if="form.errors.units" class="mt-1 text-sm text-danger">{{ form.errors.units }}</p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-semibold text-ink">Amount <span class="font-normal text-ink-muted">(est. RM)</span></label>
                            <input v-model="form.amount" type="number" step="0.01" min="0" inputmode="decimal" class="w-full rounded-ra border-line bg-surface font-mono text-ink shadow-card focus:border-primary focus:ring-primary" />
                            <p v-if="form.errors.amount" class="mt-1 text-sm text-danger">{{ form.errors.amount }}</p>
                        </div>
                    </div>

                    <!-- Phone -->
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Phone</label>
                        <input v-model="form.phone" type="tel" inputmode="tel" placeholder="012-3456789" class="w-full rounded-ra border-line bg-surface font-mono text-ink shadow-card focus:border-primary focus:ring-primary" />
                        <p v-if="form.errors.phone" class="mt-1 text-sm text-danger">{{ form.errors.phone }}</p>
                    </div>

                    <!-- Address -->
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Address</label>
                        <textarea v-model="form.address" rows="2" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary" />
                        <p v-if="form.errors.address" class="mt-1 text-sm text-danger">{{ form.errors.address }}</p>
                    </div>

                    <!-- Notes -->
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Notes <span class="font-normal text-ink-muted">(optional)</span></label>
                        <textarea v-model="form.notes" rows="2" placeholder="Optional notes…" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary" />
                        <p v-if="form.errors.notes" class="mt-1 text-sm text-danger">{{ form.errors.notes }}</p>
                    </div>

                    <!-- Technician (all-data users only) -->
                    <div v-if="technicians">
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Technician</label>
                        <select v-model="form.technician_id" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary">
                            <option :value="null">— Unassigned —</option>
                            <option v-for="t in technicians" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                        <p v-if="form.errors.technician_id" class="mt-1 text-sm text-danger">{{ form.errors.technician_id }}</p>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button type="button" class="text-sm font-medium text-ink-soft hover:text-ink" @click="emit('close')">Cancel</button>
                        <button type="submit" :disabled="form.processing" class="rounded-ra bg-primary px-5 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover disabled:opacity-60">
                            {{ isEdit ? 'Save' : 'Schedule' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </Transition>
</template>
