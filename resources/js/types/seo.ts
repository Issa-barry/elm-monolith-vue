export interface SeoOrganization {
    name: string;
    legal_name: string;
    logo: string;
    phone: string;
    email: string;
    address_locality: string;
    address_country: string;
    area_served: string[];
}

export interface SeoDefaults {
    siteName: string;
    baseUrl: string;
    defaultImage: string;
    locale: string;
    twitterSite: string | null;
    organization: SeoOrganization;
}

export interface SeoHeadProps {
    title: string;
    description: string;
    image?: string;
    path?: string;
    canonical?: string;
    robots?: string;
    type?: string;
    /** Inclut le JSON-LD Organization + WebSite (une seule fois suffit, mais sans risque à répéter). */
    organization?: boolean;
    /** Inclut en plus le JSON-LD LocalBusiness (pages où la localisation est pertinente : accueil, contact). */
    localBusiness?: boolean;
}
