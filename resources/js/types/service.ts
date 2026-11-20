import type { ServiceAvailability } from '@/lib/status';

export type Service = {
    id: number;
    parent_id: number | null;
    parent?: {
        id: number;
        title: string | null;
        title_my: string | null;
    } | null;
    title: string | null;
    title_my: string | null;
    public_description: string | null;
    public_description_my: string | null;
    /** A Lucide name from the allowed list (lib/serviceIcons.ts), or null: the fallback icon is shown. */
    icon: string | null;
    show_on_website: boolean;
    status: ServiceAvailability;
    /** A decimal string such as "8000.00"; money is never a float. */
    default_fee: string | null;
    has_documents: boolean;
    is_job_search: boolean;
    sub_services_count?: number;
};

/** Worked out from client services and payments. total_received is what the agency kept (received minus refunds). */
export type ServiceStats = {
    total_clients: number;
    in_progress: number;
    complete: number;
    cancelled: number;
    /** A decimal string such as "3000.00"; money is never a float. */
    total_received: string;
};

export type ServiceBreakdownRow = {
    /** Null is the "main service only" row. */
    sub_service: Pick<Service, 'id' | 'title' | 'title_my'> | null;
    stats: ServiceStats;
};

export type ServiceFilterOption = Pick<Service, 'id' | 'title' | 'title_my'> & {
    sub_services: Pick<Service, 'id' | 'title' | 'title_my'>[];
};

export type ParentOption = Pick<Service, 'id' | 'title' | 'title_my'>;
