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
>;

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
