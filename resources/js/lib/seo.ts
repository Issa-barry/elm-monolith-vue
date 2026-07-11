import type { SeoDefaults } from '@/types/seo';

export function absoluteUrl(baseUrl: string, path: string): string {
    if (/^https?:\/\//.test(path)) {
        return path;
    }

    return `${baseUrl}${path.startsWith('/') ? path : `/${path}`}`;
}

export function buildMetaTitle(title: string, siteName: string): string {
    return title === siteName ? title : `${title} | ${siteName}`;
}

export function buildOrganizationJsonLd(
    defaults: SeoDefaults,
): Record<string, unknown> {
    const { organization, baseUrl } = defaults;

    return {
        '@context': 'https://schema.org',
        '@type': 'Organization',
        name: organization.name,
        legalName: organization.legal_name,
        url: baseUrl,
        logo: absoluteUrl(baseUrl, organization.logo),
        email: organization.email,
        telephone: organization.phone,
        address: {
            '@type': 'PostalAddress',
            addressLocality: organization.address_locality,
            addressCountry: organization.address_country,
        },
    };
}

export function buildWebsiteJsonLd(
    defaults: SeoDefaults,
): Record<string, unknown> {
    return {
        '@context': 'https://schema.org',
        '@type': 'WebSite',
        name: defaults.siteName,
        url: defaults.baseUrl,
        inLanguage: defaults.locale,
    };
}

export function buildLocalBusinessJsonLd(
    defaults: SeoDefaults,
): Record<string, unknown> {
    const { organization, baseUrl } = defaults;

    return {
        '@context': 'https://schema.org',
        '@type': 'LocalBusiness',
        name: organization.name,
        url: baseUrl,
        image: absoluteUrl(baseUrl, defaults.defaultImage),
        telephone: organization.phone,
        email: organization.email,
        address: {
            '@type': 'PostalAddress',
            addressLocality: organization.address_locality,
            addressCountry: organization.address_country,
        },
        areaServed: organization.area_served.map((name) => ({
            '@type': 'City',
            name,
        })),
    };
}
