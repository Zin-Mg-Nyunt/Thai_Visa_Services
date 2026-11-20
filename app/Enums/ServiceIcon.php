<?php

namespace App\Enums;

/**
 * The icons a service can have: the only Lucide icon names that may be stored in `services.icon` and rendered.
 *
 * This is the single allowlist on the server. The browser has the same names in resources/js/lib/serviceIcons.ts, each mapped
 * to its imported Lucide component, so a stored name can only ever render one of these components; a test keeps the two lists
 * equal. An icon is chosen per service by the admin (or set by the seeder); it is never worked out from the service's name.
 */
enum ServiceIcon: string
{
    case BookUser = 'book-user';
    case FileBadge = 'file-badge';
    case IdCard = 'id-card';
    case IdCardLanyard = 'id-card-lanyard';
    case FileCheck = 'file-check';
    case FileText = 'file-text';
    case Files = 'files';
    case ScrollText = 'scroll-text';
    case Stamp = 'stamp';
    case BadgeCheck = 'badge-check';
    case ClipboardCheck = 'clipboard-check';
    case ShieldCheck = 'shield-check';
    case CalendarClock = 'calendar-clock';
    case CalendarCheck = 'calendar-check';
    case Handshake = 'handshake';
    case BriefcaseBusiness = 'briefcase-business';
    case UserRoundSearch = 'user-round-search';
    case Users = 'users';
    case UserCheck = 'user-check';
    case Plane = 'plane';
    case Globe = 'globe';
    case Landmark = 'landmark';
    case House = 'house';
    case GraduationCap = 'graduation-cap';
    case Car = 'car';

    /**
     * The icon shown for a service with no icon, or with a stored value that is not in this list, and the one a new service
     * starts with in the form. It is the icon every service card showed before icons could be chosen.
     */
    public const FALLBACK = self::BriefcaseBusiness;

    /**
     * The stored value if it is an allowed icon name, otherwise null (the caller then shows the fallback).
     */
    public static function valueOrNull(?string $value): ?string
    {
        return $value === null ? null : self::tryFrom($value)?->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $icon): string => $icon->value, self::cases());
    }
}
