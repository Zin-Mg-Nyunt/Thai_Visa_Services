import type { ClientDocument } from './document';

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

type RemarkBase = {
    id: number;
    body: string | null;
    author: string | null;
    created_at: string | null;
};

/**
 * A remark on a client's service. A note has the admin's text. The app's refund and cancel notes have their
 * numbers (decimal strings), so the page writes them in the selected language.
 */
export type ClientServiceRemark =
    | (RemarkBase & { kind: 'note'; details: null })
    | (RemarkBase & {
          kind: 'refund';
          details: {
              from_fee: string;
              to_fee: string;
              received: string;
              refund: string;
          };
      })
    | (RemarkBase & {
          kind: 'cancel';
          details: {
              received: string;
              refund: string;
              cancel_refund: string;
              kept: string;
          };
      });

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
    /** Set from fee changes. */
    refund_amount: string;
    /** Entered when the service is cancelled; never more than kept. */
    cancel_refund_amount: string;
    cancelled_at: string | null;
    /** True for a service that takes documents, such as Work Permit; sent on the client page. */
    accepts_documents?: boolean;
    /** Newest first; sent on the client page. */
    documents?: ClientDocument[];
    /** Newest first; sent on the client page and the edit page. */
    remarks?: ClientServiceRemark[];
    /** Worked out on the server from the payments; only sent on the client page. */
    received?: string;
    remaining?: string;
    /** Received minus the refund and the cancel refund: what the agency keeps. */
    kept?: string;
    payment_status?: PaymentStatusValue;
    payments?: Payment[];
};

export type SubServiceOption = ServiceTitles & { default_fee: string | null };

export type ServiceOption = SubServiceOption & {
    sub_services: SubServiceOption[];
};
