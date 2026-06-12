import { watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { toast } from '@/lib/swal';

// Call once inside a layout setup(). Routes Inertia flash messages to toasts.
export function useFlashToast() {
    const page = usePage();
    watch(
        () => page.props.flash,
        (flash) => {
            if (flash?.success) toast.success(flash.success);
            if (flash?.error) toast.error(flash.error);
        },
        { immediate: true, deep: true },
    );
}
