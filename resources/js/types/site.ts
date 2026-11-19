/**
 * The website settings the public pages use. Only public values: names, public image addresses, and (once the website is published)
 * the admin's texts and contact details. No storage keys. A text that is empty is null; before publishing the texts are all null.
 */
export type PublicSite = {
    agency_name: string | null;
    agency_name_my: string | null;
    logo_url: string | null;
    favicon_url: string | null;
    hero_image_url: string | null;
    hero_heading: string | null;
    hero_heading_my: string | null;
    hero_description: string | null;
    hero_description_my: string | null;
    overview: string | null;
    overview_my: string | null;
    experience: string | null;
    experience_my: string | null;
    mission: string | null;
    mission_my: string | null;
    how_we_help: string | null;
    how_we_help_my: string | null;
    address: string | null;
    address_my: string | null;
    phone: string | null;
    /** The phone number cut down to digits and a leading plus, for a `tel:` link. */
    phone_tel: string | null;
    email: string | null;
    /** Only http or https addresses, by network name. */
    social_links: Record<string, string>;
};
