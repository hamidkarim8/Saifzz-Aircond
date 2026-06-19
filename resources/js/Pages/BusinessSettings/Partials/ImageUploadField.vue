<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { IconUpload, IconX, IconPhoto } from '@tabler/icons-vue';

const props = defineProps({
    modelValue: { type: [Object, null], default: null }, // File | null
});
const emit = defineEmits(['update:modelValue']);

const input = ref(null);
const previewUrl = ref(null);

const fileName = computed(() => props.modelValue?.name ?? null);

function setPreview(file) {
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = file ? URL.createObjectURL(file) : null;
}

watch(() => props.modelValue, (file) => setPreview(file));
onBeforeUnmount(() => { if (previewUrl.value) URL.revokeObjectURL(previewUrl.value); });

function onPick(e) {
    emit('update:modelValue', e.target.files[0] ?? null);
}
function clear() {
    if (input.value) input.value.value = '';
    emit('update:modelValue', null);
}
</script>

<template>
    <div>
        <input ref="input" type="file" accept="image/*" class="hidden" @change="onPick" />

        <div class="flex items-center gap-3">
            <button
                type="button"
                class="inline-flex items-center gap-2 rounded-ra border border-line bg-surface px-3.5 py-2 text-sm font-semibold text-ink shadow-card transition hover:bg-surface-muted"
                @click="input?.click()"
            >
                <IconUpload class="h-4 w-4" />
                {{ fileName ? 'Change image' : 'Choose image' }}
            </button>
            <span class="min-w-0 truncate text-xs text-ink-soft">{{ fileName ?? 'PNG / JPG, max 2MB' }}</span>
            <button v-if="fileName" type="button" class="text-ink-soft hover:text-danger" title="Remove" @click="clear">
                <IconX class="h-4 w-4" />
            </button>
        </div>

        <!-- Selected-image thumbnail (before save) -->
        <div v-if="previewUrl" class="mt-3 inline-flex items-center gap-3 rounded-ra border border-line bg-surface p-2">
            <img :src="previewUrl" alt="Selected QR preview" class="h-20 w-20 rounded object-contain" />
            <span class="text-xs text-ink-soft">
                <span class="inline-flex items-center gap-1 font-medium text-ink"><IconPhoto class="h-3.5 w-3.5" /> New image</span>
                <br />Saved when you submit.
            </span>
        </div>
    </div>
</template>
