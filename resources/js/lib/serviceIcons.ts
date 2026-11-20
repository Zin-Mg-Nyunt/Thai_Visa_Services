import {
    BadgeCheck,
    BookUser,
    BriefcaseBusiness,
    Car,
    CalendarCheck,
    CalendarClock,
    ClipboardCheck,
    FileBadge,
    FileCheck,
    Files,
    FileText,
    Globe,
    GraduationCap,
    Handshake,
    House,
    IdCard,
    IdCardLanyard,
    Landmark,
    Plane,
    ScrollText,
    ShieldCheck,
    Stamp,
    UserCheck,
    UserRoundSearch,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';

/**
 * The icons a service can have, by Lucide name, each mapped to its imported component. It is the same list as the server's
 * allowlist (app/Enums/ServiceIcon.php; a test keeps them equal), in the order the picker shows them. A name is only ever looked
 * up in this map, so a stored value can never render any other component.
 */
export const SERVICE_ICONS = {
    'book-user': BookUser,
    'file-badge': FileBadge,
    'id-card': IdCard,
    'id-card-lanyard': IdCardLanyard,
    'file-check': FileCheck,
    'file-text': FileText,
    files: Files,
    'scroll-text': ScrollText,
    stamp: Stamp,
    'badge-check': BadgeCheck,
    'clipboard-check': ClipboardCheck,
    'shield-check': ShieldCheck,
    'calendar-clock': CalendarClock,
    'calendar-check': CalendarCheck,
    handshake: Handshake,
    'briefcase-business': BriefcaseBusiness,
    'user-round-search': UserRoundSearch,
    users: Users,
    'user-check': UserCheck,
    plane: Plane,
    globe: Globe,
    landmark: Landmark,
    house: House,
    'graduation-cap': GraduationCap,
    car: Car,
} as const satisfies Record<string, Component>;

export type ServiceIconName = keyof typeof SERVICE_ICONS;

export const SERVICE_ICON_NAMES = Object.keys(
    SERVICE_ICONS,
) as ServiceIconName[];

/** Shown for a service with no icon or an unknown one, and the icon a new service starts with (ServiceIcon::FALLBACK). */
export const FALLBACK_SERVICE_ICON: ServiceIconName = 'briefcase-business';

export function isServiceIconName(value: unknown): value is ServiceIconName {
    return typeof value === 'string' && Object.hasOwn(SERVICE_ICONS, value);
}

/** The allowed name to show for a stored value: the value itself when it is in the list, else the fallback. */
export function serviceIconName(
    value: string | null | undefined,
): ServiceIconName {
    return isServiceIconName(value) ? value : FALLBACK_SERVICE_ICON;
}
