<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    form: { type: Object, required: true }, // useForm instance
    presetClient: { type: Object, default: null },
});

const chosen = ref(props.presetClient);
const query = ref('');
const results = ref([]);
const searching = ref(false);

if (props.presetClient) {
    props.form.client_mode = 'existing';
    props.form.client_id = props.presetClient.id;
}

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

const choose = (c) => {
    chosen.value = c;
    props.form.client_id = c.id;
    results.value = [];
    query.value = '';
};

const clearChoice = () => {
    chosen.value = null;
    props.form.client_id = null;
};

const setMode = (mode) => {
    props.form.client_mode = mode;
    props.form.clearErrors('client_id', 'new_client.name', 'new_client.phone', 'new_client.address');
};
</script>

<template>
    <div class="rounded-ral border border-line bg-surface p-5 shadow-card sm:p-6">
        <h2 class="mb-4 text-sm font-bold uppercase tracking-wide text-ink-soft">Client</h2>

        <!-- Mode toggle -->
        <div class="mb-4 inline-flex rounded-ra bg-surface-muted p-1">
            <button type="button" class="rounded-[7px] px-4 py-1.5 text-sm font-semibold transition" :class="form.client_mode === 'existing' ? 'bg-surface text-primary shadow-card' : 'text-ink-soft'" @click="setMode('existing')">Existing</button>
            <button type="button" class="rounded-[7px] px-4 py-1.5 text-sm font-semibold transition" :class="form.client_mode === 'new' ? 'bg-surface text-primary shadow-card' : 'text-ink-soft'" @click="setMode('new')">New client</button>
        </div>

        <!-- Existing -->
        <div v-if="form.client_mode === 'existing'">
            <div v-if="chosen" class="flex items-center justify-between rounded-ra border border-primary/30 bg-primary-50 px-4 py-3">
                <div>
                    <div class="font-semibold text-ink">{{ chosen.name }} <span class="ml-1 font-mono text-sm text-primary">#{{ chosen.serial_no }}</span></div>
                    <div class="font-mono text-sm text-ink-soft">{{ chosen.phone }}</div>
                </div>
                <button type="button" class="text-sm font-medium text-ink-soft hover:text-danger" @click="clearChoice">Change</button>
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
            <p v-if="form.errors.client_id" class="mt-1 text-sm text-danger">{{ form.errors.client_id }}</p>
        </div>

        <!-- New -->
        <div v-else class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="mb-1.5 block text-sm font-semibold text-ink">Full name</label>
                <input v-model="form.new_client.name" type="text" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary" />
                <p v-if="form.errors['new_client.name']" class="mt-1 text-sm text-danger">{{ form.errors['new_client.name'] }}</p>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-semibold text-ink">Phone</label>
                <input v-model="form.new_client.phone" type="tel" inputmode="tel" placeholder="012-3456789" class="w-full rounded-ra border-line bg-surface font-mono text-ink shadow-card focus:border-primary focus:ring-primary" />
                <p v-if="form.errors['new_client.phone']" class="mt-1 text-sm text-danger">{{ form.errors['new_client.phone'] }}</p>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-semibold text-ink">Address</label>
                <input v-model="form.new_client.address" type="text" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary" />
                <p v-if="form.errors['new_client.address']" class="mt-1 text-sm text-danger">{{ form.errors['new_client.address'] }}</p>
            </div>
        </div>
    </div>
</template>
