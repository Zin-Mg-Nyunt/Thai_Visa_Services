export type ClientServiceStatusValue = 'in_progress' | 'complete' | 'cancel';

type ServiceTitles = {
    id: number;
    title: string | null;
    title_my: string | null;
};

export type PaymentStatusValue = 'unpaid' | 'deposit' | 'fully_paid';

export type Payment = {
    id: number;
    /** A decimal string such as "1000.00". */
    amount: string;
    paid_at: string;
    method: 'cash';
    note: string | null;
};

export type ClientService = {
    id: number;
    client_id: number;
    service_id: number;
    sub_service_id: number | null;
    service?: ServiceTitles;
    sub_service?: ServiceTitles | null;
    status: ClientServiceStatusValue;
    start_date: string | null;
    end_date: string | null;
    /** Decimal strings such as "8000.00"; money is never a float. */
    fee: string;
    refund_amount: string;
    remarks: string | null;
    /** Worked out on the server from the payments; only sent on the client page. */
    received?: string;
    remaining?: string;
    payment_status?: PaymentStatusValue;
    payments?: Payment[];
};

export type SubServiceOption = ServiceTitles & { default_fee: string | null };

export type ServiceOption = SubServiceOption & {
    sub_services: SubServiceOption[];
};
