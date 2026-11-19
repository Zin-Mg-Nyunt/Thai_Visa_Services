import { localized } from '@/composables/useLocale';
import type { PublicSite } from '@/types/site';

/**
 * The agency name the visitor sees: the name from the website settings in their language (or the other language), else the app name.
 */
export function agencyName(
    site: PublicSite | undefined,
    appName: string,
): string {
    return localized(site?.agency_name, site?.agency_name_my) || appName;
}

export type LandingService = {
    id: number;
    title: string | null;
    title_my: string | null;
    public_description: string | null;
    public_description_my: string | null;
};

/**
 * The service cards the landing page shows: each service in the visitor's language, leaving out one that has no title in either
 * language rather than showing an empty card.
 */
export function serviceCardsOf(services: LandingService[]) {
    return services
        .map((service) => ({
            id: service.id,
            title: localized(service.title, service.title_my),
            description: localized(
                service.public_description,
                service.public_description_my,
            ),
        }))
        .filter((service) => service.title !== '');
}

/** Whether there is anything to show in the Contact section: only the filled details count. */
export function hasContactDetails(site: PublicSite): boolean {
    return (
        site.phone_tel !== null ||
        site.email !== null ||
        localized(site.address, site.address_my) !== '' ||
        Object.keys(site.social_links).length > 0
    );
}

/**
 * The page sections the navigation bar links to, in page order. A section that is not on the page has no link, so a link never
 * leads nowhere. `labelKey` is the translation key of the link.
 */
export function navSections(
    services: LandingService[],
    site: PublicSite,
): { id: string; labelKey: string }[] {
    return [
        { id: 'about', labelKey: 'landing.nav.about', shown: true },
        {
            id: 'services',
            labelKey: 'landing.nav.services',
            shown: serviceCardsOf(services).length > 0,
        },
        {
            id: 'contact',
            labelKey: 'landing.nav.contact',
            shown: hasContactDetails(site),
        },
    ]
        .filter((section) => section.shown)
        .map(({ id, labelKey }) => ({ id, labelKey }));
}
