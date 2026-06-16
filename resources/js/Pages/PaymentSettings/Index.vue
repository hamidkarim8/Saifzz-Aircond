<script setup>
import { useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
    isConfigured: Boolean,
    portalKeyHint: { type: String, default: null },
});

const form = useForm({
    api_token: '',
    portal_key: '',
    api_secret: '',
});

const submit = () => form.put(route('payment-settings.update'), {
    onSuccess: () => form.reset(),
});
</script>

<template>
    <AdminLayout>
        <template #header>
            <h1 class="text-base font-bold text-navy-800">Payment Settings</h1>
        </template>

        <div
            class="mb-6 flex items-center gap-3 rounded-ra border px-4 py-3 text-sm font-medium"
            :class="isConfigured
                ? 'border-green-300 bg-green-50 text-ok'
                : 'border-yellow-300 bg-yellow-50 text-yellow-700'"
        >
            <span v-if="isConfigured">Gateway configured ✓ — DuitNow QR payments are live.</span>
            <span v-else>Gateway not configured — payments will use test mode.</span>
        </div>

        <Card title="BayarCash Credentials">
            <p class="mb-5 text-sm text-ink-soft">
                Leave a field blank to keep the existing value.
                Credentials are encrypted at rest and never displayed after saving.
            </p>

            <form class="space-y-4" @submit.prevent="submit">
                <div>
                    <label class="mb-1.5 block text-sm font-semibold text-ink">API Token</label>
                    <input
                        v-model="form.api_token"
                        type="password"
                        autocomplete="off"
                        class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :placeholder="isConfigured ? '••••••••' : 'Enter API Token'"
                    />
                    <p v-if="form.errors.api_token" class="mt-1 text-xs text-danger">{{ form.errors.api_token }}</p>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-semibold text-ink">Portal Key</label>
                    <input
                        v-model="form.portal_key"
                        type="password"
                        autocomplete="off"
                        class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :placeholder="isConfigured && portalKeyHint ? `•••••••• (ending …${portalKeyHint})` : 'Enter Portal Key'"
                    />
                    <p v-if="form.errors.portal_key" class="mt-1 text-xs text-danger">{{ form.errors.portal_key }}</p>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-semibold text-ink">API Secret</label>
                    <input
                        v-model="form.api_secret"
                        type="password"
                        autocomplete="off"
                        class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :placeholder="isConfigured ? '••••••••' : 'Enter API Secret'"
                    />
                    <p v-if="form.errors.api_secret" class="mt-1 text-xs text-danger">{{ form.errors.api_secret }}</p>
                </div>

                <div class="pt-2">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-2 rounded-ra bg-primary px-4 py-2 text-sm font-semibold text-white shadow-card hover:bg-primary-hover disabled:opacity-60"
                    >
                        {{ form.processing ? 'Saving…' : 'Save credentials' }}
                    </button>
                </div>
            </form>
        </Card>
    </AdminLayout>
</template>
