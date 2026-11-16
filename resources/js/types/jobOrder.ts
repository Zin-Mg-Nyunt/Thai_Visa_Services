export type JobOrderStatusValue = 'open' | 'done' | 'cancelled';

/** A company's hiring request. Manpower is what the company asked for, not a limit. */
export type JobOrder = {
    id: number;
    company_name: string;
    manpower: number;
    male_count: number;
    female_count: number;
    interview_date: string | null;
    start_date: string | null;
    status: JobOrderStatusValue;
    closed_at: string | null;
    notes: string | null;
    /** Workers whose person is not deleted and whose Job Search service is not cancelled. Only when counted. */
    active_workers_count?: number;
    /** Workers whose Job Search service was cancelled: kept in the order's history, counted apart. Only when counted. */
    cancelled_workers_count?: number;
    /** True when there are more active workers than the company asked for: a warning, never a block. */
    exceeds_manpower?: boolean;
    /** False once the order is closed: a closed order is frozen. */
    can_edit: boolean;
    created_at: string | null;
};

export type PaginatedJobOrders = {
    data: JobOrder[];
    links: { prev: string | null; next: string | null };
    meta: {
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
    };
};

export type WorkerCaseStatusValue =
    | 'applied'
    | 'interview'
    | 'hired'
    | 'rejected'
    | 'cancelled';

/** A worker on a job order, with the money of their Job Search record as decimal strings (never floats). */
export type JobOrderWorker = {
    id: number;
    client_id: number;
    name: string;
    phone: string;
    gender: 'male' | 'female';
    case_status: WorkerCaseStatusValue;
    service_status: 'in_progress' | 'complete' | 'cancel';
    fee: string;
    received: string;
    remaining: string;
    payment_status: 'unpaid' | 'deposit' | 'fully_paid';
};

/** The answer of the phone lookup in the Add worker dialog. */
export type WorkerLookup = {
    phone: string;
    client: {
        id: number;
        name: string;
        phone: string;
        birthday: string;
        gender: 'male' | 'female';
    } | null;
    on_this_order: boolean;
};
