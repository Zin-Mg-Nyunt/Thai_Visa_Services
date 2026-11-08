/** A file on a client's service, as the admin sees it. The storage key is never sent to the page. */
export type ClientDocument = {
    id: number;
    title: string;
    mime: string;
    /** Bytes. */
    size: number;
    /** The admin's per-document release. */
    client_can_download: boolean;
    /** Unlocked for the client: released, or unlocked because the service is fully paid. */
    unlocked: boolean;
    created_at: string | null;
};
