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
    show_on_website: boolean;
    status: ServiceAvailability;
    /** A decimal string such as "8000.00"; money is never a float. */
    default_fee: string | null;
    has_documents: boolean;
    is_job_search: boolean;
    sub_services_count?: number;
    /** Filled in once client services and payments exist (Phase 2 step 6). */
    stats?: {
        total_clients: number;
        in_progress: number;
        complete: number;
        total_received: string;
    };
};

export type ParentOption = Pick<Service, 'id' | 'title' | 'title_my'>;
