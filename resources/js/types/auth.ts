/** Only these fields of the signed-in user are shared with the pages (HandleInertiaRequests). */
export type User = {
    id: number;
    name: string;
    email: string;
    role: 'superadmin';
    email_verified_at: string | null;
};

export type Auth = {
    user: User;
};
