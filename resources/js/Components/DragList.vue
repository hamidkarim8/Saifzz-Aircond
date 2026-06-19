<script setup>
// Dependency-free drag-to-reorder list. Works with mouse and touch via Pointer
// Events. Target slot index is chosen by the nearest item centre, so it handles
// both single-column lists and multi-column card grids.
//
// Usage:
//   <DragList v-model="items" item-key="id" @reorder="onReorder">
//     <template #item="{ item, dragging, handleDown }">
//       <button @pointerdown="handleDown" style="touch-action:none">⠿</button>
//       …row content…
//     </template>
//   </DragList>
//
// @reorder emits the new array of item keys (after a drop) — POST that to persist.
import { ref } from 'vue';

const props = defineProps({
    modelValue: { type: Array, required: true },
    itemKey: { type: String, default: 'id' },
});
const emit = defineEmits(['update:modelValue', 'reorder']);

const dragIndex = ref(null);
const containerRef = ref(null);

function onPointerDown(e, index) {
    // Ignore non-primary mouse buttons; allow touch/pen (button === 0 or -1).
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    dragIndex.value = index;
    try { e.target.setPointerCapture?.(e.pointerId); } catch { /* noop */ }
    window.addEventListener('pointermove', onPointerMove);
    window.addEventListener('pointerup', onPointerUp);
    window.addEventListener('pointercancel', onPointerUp);
}

function onPointerMove(e) {
    if (dragIndex.value === null || !containerRef.value) return;
    const rows = [...containerRef.value.querySelectorAll('[data-drag-row]')];
    if (!rows.length) return;

    // Nearest item centre to the pointer becomes the drop target.
    let target = dragIndex.value;
    let bestDist = Infinity;
    rows.forEach((el, i) => {
        const r = el.getBoundingClientRect();
        const cx = r.left + r.width / 2;
        const cy = r.top + r.height / 2;
        const d = (e.clientX - cx) ** 2 + (e.clientY - cy) ** 2;
        if (d < bestDist) { bestDist = d; target = i; }
    });

    if (target !== dragIndex.value) {
        const list = [...props.modelValue];
        const [moved] = list.splice(dragIndex.value, 1);
        list.splice(target, 0, moved);
        dragIndex.value = target;
        emit('update:modelValue', list);
    }
}

function onPointerUp() {
    window.removeEventListener('pointermove', onPointerMove);
    window.removeEventListener('pointerup', onPointerUp);
    window.removeEventListener('pointercancel', onPointerUp);
    if (dragIndex.value === null) return;
    dragIndex.value = null;
    emit('reorder', props.modelValue.map((it) => it[props.itemKey]));
}
</script>

<template>
    <div ref="containerRef">
        <div
            v-for="(item, index) in modelValue"
            :key="item[itemKey]"
            data-drag-row
            :class="dragIndex === index ? 'opacity-50' : ''"
        >
            <slot
                name="item"
                :item="item"
                :index="index"
                :dragging="dragIndex === index"
                :handle-down="(e) => onPointerDown(e, index)"
            />
        </div>
    </div>
</template>
