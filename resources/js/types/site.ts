/** The website settings the public pages use. Only public values: names and public image addresses (no storage keys). */
export type PublicSite = {
    agency_name: string | null;
    agency_name_my: string | null;
    logo_url: string | null;
    favicon_url: string | null;
    hero_image_url: string | null;
};
