<script setup>
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/Card.vue';
import ImageUploadField from './Partials/ImageUploadField.vue';

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
    qrUrl: { type: String, default: null },
    paymentQrUrl: { type: String, default: null },
    payment: { type: Object, default: () => ({}) },
});

const activeTab = ref('identity');

const idForm = useForm({
    business_name: props.settings.business_name ?? '',
    phone: props.settings.phone ?? '',
    ssm_no: props.settings.ssm_no ?? '',
});
const saveIdentity = () => idForm.put(route('business-settings.update'), { preserveScroll: true });

// Live preview: render the REAL invoice/receipt Blade in an iframe, refreshed
// (debounced) as identity fields change. Single source of truth — can't drift.
const previewType = ref('invoice');
const previewSrc = ref('');
const buildPreview = () => {
    const q = new URLSearchParams({
        name: idForm.business_name ?? '',
        phone: idForm.phone ?? '',
        ssm: idForm.ssm_no ?? '',
    });
    previewSrc.value = `${route('business-settings.preview', { type: previewType.value })}?${q.toString()}`;
};
let previewTimer;
watch(
    [() => idForm.business_name, () => idForm.phone, () => idForm.ssm_no, previewType],
    () => { clearTimeout(previewTimer); previewTimer = setTimeout(buildPreview, 400); },
    { immediate: true },
);

const reviewForm = useForm({
    google_review_url: props.settings.google_review_url ?? '',
    google_review_qr: null,
});
const saveReview = () => reviewForm.put(route('business-settings.update'), {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => { reviewForm.google_review_qr = null; },
});

const payForm = useForm({ api_token: '', portal_key: '', api_secret: '' });
const savePayment = () => payForm.put(route('payment-settings.update'), {
    preserveScroll: true,
    onSuccess: () => payForm.reset(),
});

const manualQrForm = useForm({ payment_qr: null });
const saveManualQr = () => manualQrForm.put(route('business-settings.update'), {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => { manualQrForm.payment_qr = null; },
});

const tabs = [
    { id: 'identity', label: 'Identity' },
    { id: 'review', label: 'Google Review' },
    { id: 'payment', label: 'Payment' },
];
const inputClass = 'w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary';
</script>

<template>
    <AdminLayout>
        <template #header>
            <h1 class="text-base font-bold text-navy-800">Business Settings</h1>
        </template>

        <div class="mb-5 flex gap-1 border-b border-line">
            <button
                v-for="t in tabs" :key="t.id"
                class="border-b-2 px-4 py-2 text-sm font-semibold transition"
                :class="activeTab === t.id ? 'border-primary text-primary' : 'border-transparent text-ink-soft hover:text-ink'"
                @click="activeTab = t.id"
            >{{ t.label }}</button>
        </div>

        <div v-if="activeTab === 'identity'" class="grid gap-6 lg:grid-cols-2">
            <Card title="Business identity">
                <form class="space-y-4" @submit.prevent="saveIdentity">
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Business name</label>
                        <input v-model="idForm.business_name" :class="inputClass" placeholder="Saifzz Aircond Services" />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Phone number</label>
                        <input v-model="idForm.phone" :class="inputClass" placeholder="012-9876543" />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">SSM registration no.</label>
                        <input v-model="idForm.ssm_no" :class="inputClass" placeholder="202603093151 (003839732-K)" />
                    </div>
                    <button type="submit" :disabled="idForm.processing"
                        class="inline-flex items-center rounded-ra bg-primary px-4 py-2 text-sm font-semibold text-white shadow-card hover:bg-primary-hover disabled:opacity-60">
                        {{ idForm.processing ? 'Saving…' : 'Save identity' }}
                    </button>
                </form>
            </Card>
            <Card title="Live document preview">
                <template #actions>
                    <div class="inline-flex rounded-ra border border-line p-0.5 text-xs font-semibold">
                        <button type="button"
                            class="rounded-[6px] px-3 py-1 transition"
                            :class="previewType === 'invoice' ? 'bg-primary text-white' : 'text-ink-soft hover:text-ink'"
                            @click="previewType = 'invoice'">Invoice</button>
                        <button type="button"
                            class="rounded-[6px] px-3 py-1 transition"
                            :class="previewType === 'receipt' ? 'bg-primary text-white' : 'text-ink-soft hover:text-ink'"
                            @click="previewType = 'receipt'">Receipt</button>
                    </div>
                </template>
                <p class="mb-3 text-xs text-ink-soft">Exact template the customer receives — sample data, your live identity.</p>
                <iframe :src="previewSrc" title="Document preview"
                    class="h-[640px] w-full rounded-ra border border-line bg-[#f0f4f8]"></iframe>
            </Card>
        </div>

        <div v-else-if="activeTab === 'review'" class="grid gap-6 lg:grid-cols-2">
            <Card title="Google Review QR">
                <form class="space-y-4" @submit.prevent="saveReview">
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Review link (optional)</label>
                        <input v-model="reviewForm.google_review_url" :class="inputClass" placeholder="https://g.page/r/..." />
                        <p v-if="reviewForm.errors.google_review_url" class="mt-1 text-xs text-danger">{{ reviewForm.errors.google_review_url }}</p>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">QR image</label>
                        <ImageUploadField v-model="reviewForm.google_review_qr" />
                        <p v-if="reviewForm.errors.google_review_qr" class="mt-1 text-xs text-danger">{{ reviewForm.errors.google_review_qr }}</p>
                    </div>
                    <button type="submit" :disabled="reviewForm.processing"
                        class="inline-flex items-center rounded-ra bg-primary px-4 py-2 text-sm font-semibold text-white shadow-card hover:bg-primary-hover disabled:opacity-60">
                        {{ reviewForm.processing ? 'Saving…' : 'Save Google Review' }}
                    </button>
                </form>
            </Card>
            <div>
                <div class="mb-2 text-sm font-semibold text-ink-soft">Current QR</div>
                <div class="grid place-items-center rounded-ral border border-line bg-white p-6">
                    <img v-if="qrUrl" :src="qrUrl" alt="Google Review QR" class="h-48 w-48 object-contain" />
                    <span v-else class="text-sm text-ink-soft">No QR uploaded yet.</span>
                </div>
            </div>
        </div>

        <div v-else>
            <div class="mb-6 flex items-center gap-3 rounded-ra border px-4 py-3 text-sm font-medium"
                :class="payment.isConfigured ? 'border-green-300 bg-green-50 text-ok' : 'border-yellow-300 bg-yellow-50 text-yellow-700'">
                <span v-if="payment.isConfigured">Gateway configured ✓ — DuitNow QR payments are live.</span>
                <span v-else>Gateway not configured — payments will use test mode.</span>
            </div>
            <Card title="Manual QR (DuitNow)" class="mb-6">
                <p class="mb-5 text-sm text-ink-soft">Upload your DuitNow / bank QR. Admins can show this at payment collection; once the customer transfers, confirm receipt manually.</p>
                <div class="grid gap-6 lg:grid-cols-2">
                    <form class="space-y-4" @submit.prevent="saveManualQr">
                        <div>
                            <label class="mb-1.5 block text-sm font-semibold text-ink">QR image</label>
                            <ImageUploadField v-model="manualQrForm.payment_qr" />
                            <p v-if="manualQrForm.errors.payment_qr" class="mt-1 text-xs text-danger">{{ manualQrForm.errors.payment_qr }}</p>
                        </div>
                        <button type="submit" :disabled="manualQrForm.processing"
                            class="inline-flex items-center rounded-ra bg-primary px-4 py-2 text-sm font-semibold text-white shadow-card hover:bg-primary-hover disabled:opacity-60">
                            {{ manualQrForm.processing ? 'Saving…' : 'Save Manual QR' }}
                        </button>
                    </form>
                    <div>
                        <div class="mb-2 text-sm font-semibold text-ink-soft">Current QR</div>
                        <div class="grid place-items-center rounded-ral border border-line bg-white p-6">
                            <img v-if="paymentQrUrl" :src="paymentQrUrl" alt="Manual payment QR" class="h-48 w-48 object-contain" />
                            <span v-else class="text-sm text-ink-soft">No QR uploaded yet.</span>
                        </div>
                    </div>
                </div>
            </Card>
            <Card title="BayarCash Credentials">
                <p class="mb-5 text-sm text-ink-soft">Leave a field blank to keep the existing value. Credentials are encrypted at rest.</p>
                <form class="space-y-4" @submit.prevent="savePayment">
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">API Token</label>
                        <input v-model="payForm.api_token" type="password" autocomplete="off" :class="inputClass" :placeholder="payment.isConfigured ? '••••••••' : 'Enter API Token'" />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Portal Key</label>
                        <input v-model="payForm.portal_key" type="password" autocomplete="off" :class="inputClass" :placeholder="payment.isConfigured && payment.portalKeyHint ? `•••••••• (ending …${payment.portalKeyHint})` : 'Enter Portal Key'" />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">API Secret</label>
                        <input v-model="payForm.api_secret" type="password" autocomplete="off" :class="inputClass" :placeholder="payment.isConfigured ? '••••••••' : 'Enter API Secret'" />
                    </div>
                    <button type="submit" :disabled="payForm.processing"
                        class="inline-flex items-center rounded-ra bg-primary px-4 py-2 text-sm font-semibold text-white shadow-card hover:bg-primary-hover disabled:opacity-60">
                        {{ payForm.processing ? 'Saving…' : 'Save credentials' }}
                    </button>
                </form>
            </Card>
        </div>
    </AdminLayout>
</template>
