export type Gender = 'male' | 'female';

export type Client = {
    id: number;
    name: string;
    phone: string;
    birthday: string;
    gender: Gender;
    remarks: string | null;
    created_at: string | null;
};

export type ClientProfile = Pick<
    Client,
    'name' | 'phone' | 'birthday' | 'gender'
> & { photo_url: string | null };

export type PinAttempt = {
    id: number;
    ip_address: string;
    user_agent: string | null;
    caused_lock: boolean;
    created_at: string;
};

export type PaginatedClients = {
    data: Client[];
    links: { prev: string | null; next: string | null };
    meta: {
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
    };
};

/**
 * One of the client's own services in the portal. A cancelled service has its status only: the money fields are
 * missing. Money is a decimal string such as "8000.00"; money is never a float.
 */
export type ClientPortalService = {
    id: number;
    service: { title: string | null; title_my: string | null };
    sub_service: { title: string | null; title_my: string | null } | null;
    status: 'in_progress' | 'complete' | 'cancel';
    fee?: string;
    received?: string;
    remaining?: string;
    payment_status?: 'unpaid' | 'deposit' | 'fully_paid';
    /** Only for a service that takes documents (not Job Search, not cancelled). */
    documents?: ClientPortalDocument[];
};

/** A document as the client sees it: a title and whether it can be downloaded. Nothing else is sent. */
export type ClientPortalDocument = {
    id: number;
    title: string;
    unlocked: boolean;
};
