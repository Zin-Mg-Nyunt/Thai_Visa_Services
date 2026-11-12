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
    /** Only when the workers were counted. */
    workers_count?: number;
    /** True when there are more workers than the company asked for: a warning, never a block. */
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
