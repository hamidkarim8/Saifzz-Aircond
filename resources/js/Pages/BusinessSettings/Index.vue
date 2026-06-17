<script setup>
import { useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
    settings: {
        type: Object,
        default: () => ({}),
    },
    qrUrl: { type: String, default: null },
    payment: {
        type: Object,
        default: () => ({ isConfigured: false, portalKeyHint: null }),
    },
});

const form = useForm({
    business_name: props.settings.business_name ?? '',
    address: props.settings.address ?? '',
    phone: props.settings.phone ?? '',
    ssm_no: props.settings.ssm_no ?? '',
    google_review_url: props.settings.google_review_url ?? '',
    google_review_qr: null,
});

const paymentForm = useForm({
    api_token: '',
    portal_key: '',
    api_secret: '',
});

const submitIdentity = () => form.put(route('business-settings.update'), {
    forceFormData: true,
    onSuccess: () => form.google_review_qr = null,
});

const submitPayment = () => paymentForm.put(route('payment-settings.update'), {
    onSuccess: () => paymentForm.reset(),
});
</script>

<template>
    <AdminLayout>
        <template #header>
            <h1 class="text-base font-bold text-navy-800">Business Settings</h1>
        </template>

        <!-- Business Identity -->
        <Card title="Business Identity" class="mb-6">
            <form class="space-y-4" @submit.prevent="submitIdentity">
                <div>
                    <label class="mb-1.5 block text-sm font-semibold text-ink">Business Name</label>
                    <input
                        v-model="form.business_name"
                        type="text"
                        class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        placeholder="e.g. Saifzz Aircond Sdn Bhd"
                    />
                    <p v-if="form.errors.business_name" class="mt-1 text-xs text-danger">{{ form.errors.business_name }}</p>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-semibold text-ink">Address</label>
                    <textarea
                        v-model="form.address"
                        rows="3"
                        class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        placeholder="Full business address"
                    />
                    <p v-if="form.errors.address" class="mt-1 text-xs text-danger">{{ form.errors.address }}</p>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-semibold text-ink">Phone</label>
                    <input
                        v-model="form.phone"
                        type="text"
                        class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        placeholder="e.g. 011-12345678"
                    />
                    <p v-if="form.errors.phone" class="mt-1 text-xs text-danger">{{ form.errors.phone }}</p>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-semibold text-ink">SSM Registration No.</label>
                    <input
                        v-model="form.ssm_no"
                        type="text"
                        class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        placeholder="e.g. 202603093151 (003839732-K)"
                    />
                    <p v-if="form.errors.ssm_no" class="mt-1 text-xs text-danger">{{ form.errors.ssm_no }}</p>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-semibold text-ink">Google Review URL</label>
                    <input
                        v-model="form.google_review_url"
                        type="url"
                        class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        placeholder="https://g.page/r/..."
                    />
                    <p v-if="form.errors.google_review_url" class="mt-1 text-xs text-danger">{{ form.errors.google_review_url }}</p>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-semibold text-ink">Google Review QR Code</label>
                    <img v-if="qrUrl" :src="qrUrl" alt="Google Review QR" class="mb-2 h-32 w-32 rounded border border-line object-contain" />
                    <input
                        type="file"
                        accept="image/*"
                        class="text-sm text-ink"
                        @change="form.google_review_qr = $event.target.files[0]"
                    />
                    <p v-if="form.errors.google_review_qr" class="mt-1 text-xs text-danger">{{ form.errors.google_review_qr }}</p>
                </div>

                <div class="pt-2">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-2 rounded-ra bg-primary px-4 py-2 text-sm font-semibold text-white shadow-card hover:bg-primary-hover disabled:opacity-60"
                    >
                        {{ form.processing ? 'Saving…' : 'Save business info' }}
                    </button>
                </div>
            </form>
        </Card>

        <!-- Payment Gateway -->
        <Card title="BayarCash Credentials">
            <div
                class="mb-5 flex items-center gap-3 rounded-ra border px-4 py-3 text-sm font-medium"
                :class="payment.isConfigured
                    ? 'border-green-300 bg-green-50 text-ok'
                    : 'border-yellow-300 bg-yellow-50 text-yellow-700'"
            >
                <span v-if="payment.isConfigured">Gateway configured ✓ — DuitNow QR payments are live.</span>
                <span v-else>Gateway not configured — payments will use test mode.</span>
            </div>

            <p class="mb-5 text-sm text-ink-soft">
                Leave a field blank to keep the existing value.
                Credentials are encrypted at rest and never displayed after saving.
            </p>

            <form class="space-y-4" @submit.prevent="submitPayment">
                <div>
                    <label class="mb-1.5 block text-sm font-semibold text-ink">API Token</label>
                    <input
                        v-model="paymentForm.api_token"
                        type="password"
                        autocomplete="off"
                        class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :placeholder="payment.isConfigured ? '••••••••' : 'Enter API Token'"
                    />
                    <p v-if="paymentForm.errors.api_token" class="mt-1 text-xs text-danger">{{ paymentForm.errors.api_token }}</p>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-semibold text-ink">Portal Key</label>
                    <input
                        v-model="paymentForm.portal_key"
                        type="password"
                        autocomplete="off"
                        class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :placeholder="payment.isConfigured && payment.portalKeyHint ? `•••••••• (ending …${payment.portalKeyHint})` : 'Enter Portal Key'"
                    />
                    <p v-if="paymentForm.errors.portal_key" class="mt-1 text-xs text-danger">{{ paymentForm.errors.portal_key }}</p>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-semibold text-ink">API Secret</label>
                    <input
                        v-model="paymentForm.api_secret"
                        type="password"
                        autocomplete="off"
                        class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :placeholder="payment.isConfigured ? '••••••••' : 'Enter API Secret'"
                    />
                    <p v-if="paymentForm.errors.api_secret" class="mt-1 text-xs text-danger">{{ paymentForm.errors.api_secret }}</p>
                </div>

                <div class="pt-2">
                    <button
                        type="submit"
                        :disabled="paymentForm.processing"
                        class="inline-flex items-center gap-2 rounded-ra bg-primary px-4 py-2 text-sm font-semibold text-white shadow-card hover:bg-primary-hover disabled:opacity-60"
                    >
                        {{ paymentForm.processing ? 'Saving…' : 'Save credentials' }}
                    </button>
                </div>
            </form>
        </Card>
    </AdminLayout>
</template>
