export type ClientServiceStatusValue = 'in_progress' | 'complete' | 'cancel';

type ServiceTitles = {
    id: number;
    title: string | null;
    title_my: string | null;
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
};

export type SubServiceOption = ServiceTitles & { default_fee: string | null };

export type ServiceOption = SubServiceOption & {
    sub_services: SubServiceOption[];
};
