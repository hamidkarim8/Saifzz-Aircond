<script setup>
defineProps({
    name: { type: String, default: '' },
    address: { type: String, default: '' },
    phone: { type: String, default: '' },
    ssm: { type: String, default: '' },
});
</script>

<template>
    <!-- Static mock that mirrors resources/views/documents/layout.blade.php (invoice). -->
    <div class="mx-auto max-w-[420px] rounded-[10px] border border-line bg-white p-6 text-[12px] text-ink shadow-card">
        <!-- Header -->
        <div class="border-b-2 border-navy-800 pb-4 text-center">
            <img src="/img/logo-256.png" alt="" class="mx-auto mb-2.5 h-16 w-16 rounded-full object-cover" />
            <div class="text-[19px] font-bold tracking-[0.3px] text-navy-800">{{ name || 'Business Name' }}</div>
            <div class="mt-1 text-[10.5px] leading-relaxed text-ink-soft">
                {{ phone || 'Phone number' }}
                <template v-if="ssm"><br />SSM: {{ ssm }}</template>
            </div>
            <div class="mt-2.5 text-[14px] font-bold uppercase tracking-wide text-primary">Invoice</div>
        </div>

        <!-- Meta -->
        <table class="mt-3 w-full border-collapse">
            <tbody>
                <tr>
                    <td class="py-[3.5px] text-ink-soft">Invoice No.</td>
                    <td class="py-[3.5px] text-right font-mono font-semibold text-ink">INV-20260619-001</td>
                </tr>
                <tr>
                    <td class="py-[3.5px] text-ink-soft">Date</td>
                    <td class="py-[3.5px] text-right font-semibold text-ink">19 Jun 2026</td>
                </tr>
            </tbody>
        </table>

        <hr class="my-3 border-t border-dashed border-line" />

        <!-- Bill to -->
        <div class="mb-2 text-[9.5px] font-bold uppercase tracking-wider text-ink-soft">Bill to</div>
        <div class="font-semibold text-navy-800">Sample Customer</div>
        <div class="text-[10.5px] leading-relaxed text-ink-soft">No. 1, Jalan Contoh, KL</div>

        <hr class="my-3 border-t border-dashed border-line" />

        <!-- Service line -->
        <div class="mb-2 text-[9.5px] font-bold uppercase tracking-wider text-ink-soft">Services</div>
        <div class="rounded-[7px] border border-line bg-surface p-3">
            <div class="mb-1.5 font-bold text-navy-800">Aircond Service · Sample</div>
            <div class="flex justify-between text-[11px] text-ink-soft"><span>Units × 1</span><span>RM 120.00</span></div>
        </div>

        <!-- Total -->
        <div class="mt-2 flex items-center justify-between rounded-md bg-navy-800 px-3.5 py-3 text-white">
            <span class="text-[10.5px] font-bold uppercase tracking-wide">Total</span>
            <span class="font-mono text-[21px] font-extrabold">RM 120.00</span>
        </div>

        <!-- Status + footer -->
        <div class="mt-3 text-center">
            <span class="inline-block rounded-full bg-amber-100 px-2.5 py-0.5 text-[10px] font-bold text-amber-800">PENDING</span>
        </div>
        <div class="mt-3 border-t border-dashed border-line pt-2.5 text-center text-[10.5px] text-ink-soft">
            Thank you for your business.
        </div>
    </div>
</template>
