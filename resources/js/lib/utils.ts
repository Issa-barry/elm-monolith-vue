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

type PhonePattern = number[];

interface PhoneRule {
    countryCode: string;
    byLength: Record<number, PhonePattern>;
}

const PHONE_RULES: PhoneRule[] = [
    { countryCode: '224', byLength: { 9: [3, 2, 2, 2] } }, // Guinée
    { countryCode: '245', byLength: { 7: [3, 2, 2] } }, // Guinée-Bissau
    { countryCode: '221', byLength: { 9: [2, 3, 2, 2] } }, // Sénégal
    { countryCode: '223', byLength: { 8: [2, 2, 2, 2] } }, // Mali
    { countryCode: '225', byLength: { 10: [2, 2, 2, 2, 2] } }, // Côte d'Ivoire
    { countryCode: '231', byLength: { 8: [2, 3, 3] } }, // Liberia
    { countryCode: '232', byLength: { 8: [2, 3, 3] } }, // Sierra Leone
    { countryCode: '971', byLength: { 9: [2, 3, 4] } }, // Émirats arabes unis
    {
        countryCode: '33',
        byLength: { 9: [1, 2, 2, 2, 2], 10: [2, 2, 2, 2, 2] },
    }, // France
    { countryCode: '86', byLength: { 11: [3, 4, 4] } }, // Chine
    { countryCode: '91', byLength: { 10: [5, 5] } }, // Inde
];

const COMMON_LOCAL_PATTERNS: Record<number, PhonePattern> = {
    7: [3, 2, 2],
    8: [2, 2, 2, 2],
    9: [3, 2, 2, 2],
    10: [2, 2, 2, 2, 2],
    11: [3, 4, 4],
};

function groupDigits(digits: string, pattern?: PhonePattern) {
    if (!digits) return '';

    if (!pattern || pattern.length === 0) {
        return digits.replace(/(\d{3})(?=\d)/g, '$1 ').trim();
    }

    const chunks: string[] = [];
    let cursor = 0;

    for (const size of pattern) {
        if (cursor >= digits.length) break;
        chunks.push(digits.slice(cursor, cursor + size));
        cursor += size;
    }

    if (cursor < digits.length) {
        chunks.push(digits.slice(cursor));
    }

    return chunks.filter(Boolean).join(' ');
}

function sanitizeCountryCode(value: string | null | undefined) {
    if (!value) return null;
    const digits = value.replace(/\D/g, '');
    return digits || null;
}

function detectPhoneRule(digits: string) {
    return PHONE_RULES.find((rule) => digits.startsWith(rule.countryCode));
}

function formatLocalDigits(digits: string, rule?: PhoneRule) {
    const pattern =
        rule?.byLength[digits.length] ?? COMMON_LOCAL_PATTERNS[digits.length];
    return groupDigits(digits, pattern);
}

function normalizeLocalDigitsWithRule(localDigits: string, rule?: PhoneRule) {
    if (!rule) return localDigits;
    if (rule.byLength[localDigits.length]) return localDigits;

    if (localDigits.startsWith('0')) {
        const withoutLeadingZero = localDigits.slice(1);
        if (rule.byLength[withoutLeadingZero.length]) {
            return withoutLeadingZero;
        }
    }

    return localDigits;
}

export function formatPhoneDisplay(
    value: string | null | undefined,
    defaultCountryDialCode?: string | null,
) {
    if (!value) return '\u2014';

    const raw = value.trim();
    if (!raw) return '\u2014';

    const normalized = raw.startsWith('00') ? `+${raw.slice(2)}` : raw;
    const hasPlusPrefix = normalized.startsWith('+');
    const digits = normalized.replace(/\D/g, '');

    if (!digits) return raw;

    if (hasPlusPrefix) {
        const rule = detectPhoneRule(digits);
        if (!rule) return `+${groupDigits(digits)}`;

        const localDigits = digits.slice(rule.countryCode.length);
        if (!localDigits) return `+${rule.countryCode}`;
        return `+${rule.countryCode} ${formatLocalDigits(localDigits, rule)}`;
    }

    const countryCode = sanitizeCountryCode(defaultCountryDialCode);
    if (countryCode) {
        const rule = PHONE_RULES.find(
            (item) => item.countryCode === countryCode,
        );
        const localDigits = normalizeLocalDigitsWithRule(digits, rule);

        if (rule) {
            return `+${rule.countryCode} ${formatLocalDigits(localDigits, rule)}`;
        }

        return `+${countryCode} ${groupDigits(localDigits)}`;
    }

    return formatLocalDigits(digits);
}

export function phoneToTelHref(value: string | null | undefined) {
    if (!value) return '';

    const raw = value.trim();
    if (!raw) return '';

    const normalized = raw.startsWith('00') ? `+${raw.slice(2)}` : raw;
    const digits = normalized.replace(/\D/g, '');
    if (!digits) return `tel:${raw}`;

    return normalized.startsWith('+') ? `tel:+${digits}` : `tel:${digits}`;
}
