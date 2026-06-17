<script setup>
defineProps({
    name: { type: String, default: '' },
    address: { type: String, default: '' },
    phone: { type: String, default: '' },
    ssm: { type: String, default: '' },
});
</script>

<template>
    <div class="mx-auto max-w-[420px] rounded-[10px] border border-line bg-white p-6 text-[12px] text-ink shadow-card">
        <div class="border-b-2 border-navy-800 pb-4 text-center">
            <img src="/img/logo-256.png" alt="" class="mx-auto mb-2.5 h-16 w-16 rounded-full object-cover" />
            <div class="text-[19px] font-bold text-navy-800">{{ name || 'Business Name' }}</div>
            <div class="mt-1 text-[10.5px] leading-relaxed text-ink-soft">
                {{ address || 'Business address' }}<br />
                {{ phone || 'Phone number' }}
                <template v-if="ssm"><br />SSM: {{ ssm }}</template>
            </div>
            <div class="mt-2.5 text-[14px] font-bold uppercase tracking-wide text-primary">Invoice</div>
        </div>
        <div class="mt-3 rounded-md border border-line bg-surface p-3">
            <div class="font-bold text-navy-800">Aircond Service · Sample</div>
            <div class="mt-1 flex justify-between text-ink-soft"><span>Units × 1</span><span>RM 120.00</span></div>
        </div>
        <div class="mt-2 flex items-center justify-between rounded-md bg-navy-800 px-3.5 py-3 text-white">
            <span class="text-[10.5px] font-bold uppercase tracking-wide">Total</span>
            <span class="text-[21px] font-extrabold">RM 120.00</span>
        </div>
    </div>
</template>
