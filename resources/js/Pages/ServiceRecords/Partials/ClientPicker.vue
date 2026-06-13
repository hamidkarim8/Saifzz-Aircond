<script setup>
import { ref, watch } from 'vue';
import InputError from '@/Components/InputError.vue';

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
    <!-- overflow-visible (not Card component) so the client-search dropdown escapes the card boundary -->
    <div class="rounded-ral border border-line bg-surface shadow-card">
        <div class="flex items-center border-b border-line px-4 py-3.5 text-sm font-bold text-ink">Client</div>
        <div class="p-4">
        <!-- Mode toggle: two selectable option cards -->
        <div class="mb-5 grid grid-cols-2 gap-3">
            <button
                type="button"
                class="flex flex-col items-start gap-1 rounded-ral border-2 px-4 py-3.5 text-left transition"
                :class="form.client_mode === 'existing'
                    ? 'border-primary bg-primary-50 shadow-card'
                    : 'border-line bg-surface hover:border-primary/40'"
                @click="setMode('existing')"
            >
                <span class="flex items-center gap-2">
                    <span
                        class="flex h-4 w-4 items-center justify-center rounded-full border-2 transition"
                        :class="form.client_mode === 'existing' ? 'border-primary' : 'border-line'"
                    >
                        <span v-if="form.client_mode === 'existing'" class="h-2 w-2 rounded-full bg-primary" />
                    </span>
                    <span class="text-sm font-semibold" :class="form.client_mode === 'existing' ? 'text-primary' : 'text-ink'">Existing client</span>
                </span>
                <span class="text-xs text-ink-soft pl-6">Search by name, serial or phone</span>
            </button>
            <button
                type="button"
                class="flex flex-col items-start gap-1 rounded-ral border-2 px-4 py-3.5 text-left transition"
                :class="form.client_mode === 'new'
                    ? 'border-primary bg-primary-50 shadow-card'
                    : 'border-line bg-surface hover:border-primary/40'"
                @click="setMode('new')"
            >
                <span class="flex items-center gap-2">
                    <span
                        class="flex h-4 w-4 items-center justify-center rounded-full border-2 transition"
                        :class="form.client_mode === 'new' ? 'border-primary' : 'border-line'"
                    >
                        <span v-if="form.client_mode === 'new'" class="h-2 w-2 rounded-full bg-primary" />
                    </span>
                    <span class="text-sm font-semibold" :class="form.client_mode === 'new' ? 'text-primary' : 'text-ink'">New client</span>
                </span>
                <span class="text-xs text-ink-soft pl-6">Register during this visit</span>
            </button>
        </div>

        <!-- Existing client search/selection -->
        <div v-if="form.client_mode === 'existing'">
            <div v-if="chosen" class="flex items-center justify-between rounded-ral border border-primary/30 bg-primary-50 px-4 py-3 shadow-card">
                <div>
                    <div class="font-semibold text-ink">
                        {{ chosen.name }}
                        <span class="ml-1.5 font-mono text-sm text-primary">#{{ chosen.serial_no }}</span>
                    </div>
                    <div class="mt-0.5 font-mono text-sm text-ink-soft">{{ chosen.phone }}</div>
                </div>
                <button type="button" class="text-sm font-medium text-ink-soft hover:text-danger transition" @click="clearChoice">Change</button>
            </div>
            <div v-else class="relative">
                <input
                    v-model="query"
                    type="search"
                    placeholder="Search name, serial or phone…"
                    class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary"
                />
                <ul v-if="results.length" class="absolute z-10 mt-1 w-full overflow-hidden rounded-ral border border-line bg-surface shadow-lift">
                    <li v-for="c in results" :key="c.id">
                        <button type="button" class="flex w-full items-center justify-between px-4 py-2.5 text-left text-sm hover:bg-surface-muted transition" @click="choose(c)">
                            <div>
                                <span class="font-medium text-ink">{{ c.name }}</span>
                                <span class="ml-1.5 font-mono text-xs text-ink-soft">{{ c.phone }}</span>
                            </div>
                            <span class="font-mono text-xs text-primary">#{{ c.serial_no }}</span>
                        </button>
                    </li>
                </ul>
                <p v-if="searching" class="mt-1.5 text-xs text-ink-muted">Searching…</p>
            </div>
            <InputError :message="form.errors.client_id" />
        </div>

        <!-- New client fields -->
        <div v-else class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="mb-1.5 block text-sm font-semibold text-ink">Full name</label>
                <input v-model="form.new_client.name" type="text" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary" />
                <InputError :message="form.errors['new_client.name']" />
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-semibold text-ink">Phone</label>
                <input v-model="form.new_client.phone" type="tel" inputmode="tel" placeholder="012-3456789" class="w-full rounded-ra border-line bg-surface font-mono text-ink shadow-card focus:border-primary focus:ring-primary" />
                <InputError :message="form.errors['new_client.phone']" />
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-semibold text-ink">Address</label>
                <input v-model="form.new_client.address" type="text" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary" />
                <InputError :message="form.errors['new_client.address']" />
            </div>
        </div>
        </div>
    </div>
</template>
