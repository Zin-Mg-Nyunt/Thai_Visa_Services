import type { Service } from './service';

type ServiceName = Pick<Service, 'id' | 'title' | 'title_my'>;

export type DashboardRangeKey =
    | 'this_month'
    | 'last_month'
    | 'this_year'
    | 'custom';

export type DashboardRange = {
    key: DashboardRangeKey;
    /** Y-m-d dates. */
    from: string;
    to: string;
};

/** Every money value is a decimal string such as "1245000.00"; money is never a float. */
export type BusinessReport = {
    /** Money kept in the range: received minus refunds. */
    received: string;
    refunded: string;
    /** The balance still owed today; it does not depend on the range. */
    outstanding: string;
    /** Kept by Job Search services and attributed to no job order. Already part of `received`; today's figure. */
    unassigned_kept: string;
    active_clients: number;
    months: { month: string; received: string; height_percent: number }[];
    by_service: {
        service: ServiceName & { is_inactive: boolean };
        received: string;
        share_percent: number;
        sub_services: { sub_service: ServiceName | null; received: string }[];
    }[];
};
