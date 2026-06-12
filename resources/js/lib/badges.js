// Single source of truth for pill colors.
export const VARIANT_CLASS = {
    blue: 'bg-primary-50 text-primary-hover',
    green: 'bg-ok-bg text-ok',
    amber: 'bg-warn-bg text-warn',
    red: 'bg-danger-bg text-danger',
    gray: 'bg-surface-muted text-ink-soft',
    indigo: 'bg-invoice-bg text-invoice',
    purple: 'bg-[#EDE9FE] text-[#5B21B6]',
};

export const SERVICE_TYPE_VARIANT = {
    Cleaning: 'blue',
    'Gas Top-Up': 'amber',
    Repair: 'gray',
    Installation: 'indigo',
    Troubleshoot: 'purple',
};

export const STATUS_VARIANT = {
    Paid: 'green', Confirmed: 'green', Done: 'green', Active: 'green',
    Pending: 'amber',
    Failed: 'red', Cancelled: 'red',
};

export const serviceVariant = (t) => SERVICE_TYPE_VARIANT[t] ?? 'gray';
export const statusVariant = (s) => STATUS_VARIANT[s] ?? 'gray';
