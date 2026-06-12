<script setup>
import { useForm } from '@inertiajs/vue3';
import FormErrorSummary from '@/Components/FormErrorSummary.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    client: { type: Object, default: null },
});

const isEdit = !!props.client;

const form = useForm({
    name: props.client?.name ?? '',
    phone: props.client?.phone ?? '',
    address: props.client?.address ?? '',
});

const submit = () => {
    if (isEdit) {
        form.put(route('clients.update', props.client.id));
    } else {
        form.post(route('clients.store'));
    }
};
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <FormErrorSummary :errors="form.errors" />

        <div>
            <label class="mb-1.5 block text-sm font-semibold text-ink">Full name</label>
            <input
                v-model="form.name"
                type="text"
                class="w-full rounded-ra border border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary"
                placeholder="e.g. Zainab binti Ahmad"
            />
            <InputError :message="form.errors.name" />
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-semibold text-ink">Phone</label>
            <input
                v-model="form.phone"
                type="tel"
                inputmode="tel"
                class="w-full rounded-ra border border-line bg-surface font-mono text-ink shadow-card focus:border-primary focus:ring-primary"
                placeholder="012-3456789"
            />
            <InputError :message="form.errors.phone" />
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-semibold text-ink">Service address</label>
            <textarea
                v-model="form.address"
                rows="3"
                class="w-full rounded-ra border border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary"
                placeholder="Unit / street / city / postcode"
            />
            <InputError :message="form.errors.address" />
        </div>

        <div v-if="isEdit" class="rounded-ra bg-surface-muted px-4 py-3 text-sm text-ink-soft">
            Serial <span class="font-mono font-semibold text-ink">{{ client.serial_no }}</span> is permanent and cannot be changed.
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded-ra bg-primary px-5 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover disabled:opacity-60"
            >
                {{ isEdit ? 'Save changes' : 'Create client' }}
            </button>
            <slot name="cancel" />
        </div>
    </form>
</template>
