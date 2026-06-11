<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import PortalLayout from './PortalLayout.vue';

defineProps({ business: Object });

const form = useForm({ serial: '', phone4: '' });

const submit = () => form.post(route('portal.authenticate'));
</script>

<template>
    <Head title="Client Portal" />

    <PortalLayout :business="business">
        <div class="rounded-ral border border-line bg-surface p-6 shadow-card">
            <h1 class="text-lg font-bold text-navy-800">View your service history</h1>
            <p class="mt-1 text-sm text-ink-soft">
                Enter the 6-digit serial from your sticker and the last 4 digits of your phone number.
            </p>

            <form class="mt-5 space-y-4" @submit.prevent="submit">
                <div>
                    <label class="block text-sm font-semibold text-ink-soft" for="serial">Serial number</label>
                    <input
                        id="serial"
                        v-model="form.serial"
                        inputmode="numeric"
                        maxlength="6"
                        placeholder="000148"
                        class="mt-1 w-full rounded-ra border border-line px-3 py-2.5 font-mono tracking-widest focus:border-primary focus:ring-primary"
                    />
                </div>
                <div>
                    <label class="block text-sm font-semibold text-ink-soft" for="phone4">Phone (last 4 digits)</label>
                    <input
                        id="phone4"
                        v-model="form.phone4"
                        inputmode="numeric"
                        maxlength="4"
                        placeholder="6789"
                        class="mt-1 w-full rounded-ra border border-line px-3 py-2.5 font-mono tracking-widest focus:border-primary focus:ring-primary"
                    />
                </div>

                <p v-if="form.errors.serial" class="text-sm font-medium text-danger">{{ form.errors.serial }}</p>
                <p v-else-if="form.errors.phone4" class="text-sm font-medium text-danger">{{ form.errors.phone4 }}</p>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full rounded-ra bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60"
                >View my history</button>
            </form>
        </div>
    </PortalLayout>
</template>
