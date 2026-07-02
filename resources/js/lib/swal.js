import Swal from 'sweetalert2';

// Navy-themed instance — classes are plain Tailwind so output matches the app.
const base = Swal.mixin({
    buttonsStyling: false,
    customClass: {
        popup: 'rounded-rax font-sans',
        title: 'text-navy-800 text-lg font-bold',
        htmlContainer: 'text-ink-soft text-sm',
        confirmButton:
            'inline-flex items-center gap-2 rounded-ra bg-primary px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-hover',
        cancelButton:
            'inline-flex items-center gap-2 rounded-ra border border-line bg-surface px-4 py-2.5 text-sm font-semibold text-ink hover:bg-surface-muted',
        actions: 'gap-3',
    },
});

const toastInstance = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 2500,
    timerProgressBar: true,
    customClass: { popup: 'rounded-ral font-sans text-sm shadow-lift' },
});

export const toast = {
    success: (title) => toastInstance.fire({ icon: 'success', title }),
    error: (title) => toastInstance.fire({ icon: 'error', title }),
    info: (title) => toastInstance.fire({ icon: 'info', title }),
};

// Returns a Promise<boolean> — true if the user confirmed.
export async function confirmDanger({ title, body = '', confirmText = 'Confirm' }) {
    const r = await base.fire({
        icon: 'warning',
        title,
        html: body,
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: 'Cancel',
        customClass: {
            popup: 'rounded-rax font-sans',
            title: 'text-navy-800 text-lg font-bold',
            htmlContainer: 'text-ink-soft text-sm',
            confirmButton:
                'inline-flex items-center gap-2 rounded-ra bg-danger px-4 py-2.5 text-sm font-semibold text-white hover:opacity-90',
            cancelButton:
                'inline-flex items-center gap-2 rounded-ra border border-line bg-surface px-4 py-2.5 text-sm font-semibold text-ink hover:bg-surface-muted',
            actions: 'gap-3',
        },
    });
    return r.isConfirmed;
}

export async function confirmAction({ title, body = '', confirmText = 'Confirm' }) {
    const r = await base.fire({
        title,
        html: body,
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: 'Cancel',
    });
    return r.isConfirmed;
}

// Danger-styled confirm with a required reason textarea.
// Returns Promise<string|null> — the trimmed reason if confirmed, else null.
export async function confirmWithReason({ title, body = '', confirmText = 'Confirm', inputLabel = 'Reason' }) {
    const r = await base.fire({
        icon: 'warning',
        title,
        html: body,
        input: 'textarea',
        inputLabel,
        inputPlaceholder: 'Why?',
        inputValidator: (value) => (!value || !value.trim() ? 'A reason is required.' : undefined),
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: 'Cancel',
        customClass: {
            popup: 'rounded-rax font-sans',
            title: 'text-navy-800 text-lg font-bold',
            htmlContainer: 'text-ink-soft text-sm',
            confirmButton:
                'inline-flex items-center gap-2 rounded-ra bg-danger px-4 py-2.5 text-sm font-semibold text-white hover:opacity-90',
            cancelButton:
                'inline-flex items-center gap-2 rounded-ra border border-line bg-surface px-4 py-2.5 text-sm font-semibold text-ink hover:bg-surface-muted',
            actions: 'gap-3',
        },
    });
    return r.isConfirmed ? r.value.trim() : null;
}
