import { InertiaLinkProps } from '@inertiajs/vue3';
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function urlIsActive(
    urlToCheck: NonNullable<InertiaLinkProps['href']>,
    currentUrl: string,
) {
    return toUrl(urlToCheck) === currentUrl;
}

export function toUrl(href: NonNullable<InertiaLinkProps['href']>) {
    return typeof href === 'string' ? href : href?.url;
}

const PHONE_COUNTRY_CODES = ['224', '245', '221', '223', '225', '231', '232', '971', '33', '86', '91'];

function formatNationalPhone(digits: string) {
    if (digits.length === 9) {
        return `${digits.slice(0, 3)} ${digits.slice(3, 5)} ${digits.slice(5, 7)} ${digits.slice(7, 9)}`;
    }

    if (digits.length === 8) {
        return `${digits.slice(0, 2)} ${digits.slice(2, 4)} ${digits.slice(4, 6)} ${digits.slice(6, 8)}`;
    }

    if (digits.length === 10) {
        return `${digits.slice(0, 3)} ${digits.slice(3, 6)} ${digits.slice(6, 8)} ${digits.slice(8, 10)}`;
    }

    return digits.replace(/(\d{3})(?=\d)/g, '$1 ').trim();
}

export function formatPhoneDisplay(value: string | null | undefined) {
    if (!value) return '\u2014';

    const raw = value.trim();
    if (!raw) return '\u2014';

    const normalized = raw.startsWith('00') ? `+${raw.slice(2)}` : raw;
    const hasPlusPrefix = normalized.startsWith('+');
    const digits = normalized.replace(/\D/g, '');

    if (!digits) return raw;

    if (!hasPlusPrefix) {
        return formatNationalPhone(digits);
    }

    const countryCode = PHONE_COUNTRY_CODES.find((code) => digits.startsWith(code));
    if (!countryCode) {
        return `+${digits.replace(/(\d{3})(?=\d)/g, '$1 ').trim()}`;
    }

    const localNumber = digits.slice(countryCode.length);
    if (!localNumber) return `+${countryCode}`;

    return `+${countryCode} ${formatNationalPhone(localNumber)}`;
}
