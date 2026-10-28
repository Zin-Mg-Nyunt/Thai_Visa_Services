export type ServiceStatus = 'inProgress' | 'complete' | 'cancel';
export type PaymentStatus = 'unpaid' | 'deposit' | 'fullyPaid';
export type JobOrderStatus = 'open' | 'done' | 'cancelled';

export type StatusKind = 'serviceStatus' | 'paymentStatus' | 'jobOrderStatus';

export type StatusTone = 'pending' | 'success' | 'danger' | 'neutral';

export const statusTones: Record<StatusKind, Record<string, StatusTone>> = {
    serviceStatus: {
        inProgress: 'pending',
        complete: 'success',
        cancel: 'danger',
    },
    paymentStatus: {
        unpaid: 'danger',
        deposit: 'pending',
        fullyPaid: 'success',
    },
    jobOrderStatus: {
        open: 'pending',
        done: 'success',
        cancelled: 'neutral',
    },
};

export const toneClasses: Record<StatusTone, string> = {
    pending: 'border-transparent bg-warning text-warning-foreground',
    success: 'border-transparent bg-mint text-brand-dark',
    danger: 'border-transparent bg-destructive/10 text-destructive',
    neutral: 'border-transparent bg-muted text-muted-foreground',
};
