/** Référentiel Contacts (ADR 0060, lot 2) — données transmises par le serveur, déjà filtrées. */

export type CodeFonction =
    | 'client'
    | 'fournisseur'
    | 'prestataire'
    | 'proprietaire'
    | 'parrain'
    | 'livreur'
    | 'employe'
    | 'interlocuteur';

export interface FonctionContact {
    fonction: CodeFonction;
    id: string;
    actif: boolean | null;
    sous_categorie: string | null;
    sous_categorie_libelle: string | null;
    reference: string | null;
    libelle: string;
    lien: string | null;
    entreprise_tierce_id?: string;
}

export interface OptionFiltre {
    value: string;
    label: string;
}

export interface OptionsFiltres {
    fonctions: OptionFiltre[];
    sous_categories: OptionFiltre[];
}

export interface Pagination<T> {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

export interface PersonneListe {
    id: string;
    nom: string;
    telephone: string | null;
    code_phone_pays: string | null;
    ville: string | null;
    quartier: string | null;
    fonctions: FonctionContact[];
}

export interface EntrepriseListe {
    id: string;
    raison_sociale: string;
    nom_commercial: string | null;
    telephone: string | null;
    code_phone_pays: string | null;
    ville: string | null;
    is_active: boolean;
    interlocuteur_principal: string | null;
    fonctions: FonctionContact[];
}

interface Coordonnees {
    id: string;
    telephone: string | null;
    telephone_secondaire: string | null;
    code_phone_pays: string | null;
    email: string | null;
    pays: string | null;
    ville: string | null;
    quartier: string | null;
    adresse: string | null;
    latitude: string | null;
    longitude: string | null;
}

export interface PersonneFiche extends Coordonnees {
    nom: string | null;
    prenom: string | null;
    nom_complet: string | null;
    surnom: string | null;
    nom_affiche: string;
}

export interface EntrepriseFiche extends Coordonnees {
    raison_sociale: string;
    nom_commercial: string | null;
    identifiant_fiscal: string | null;
    rccm: string | null;
    is_active: boolean;
}

export interface EntrepriseRepresentee {
    id: string;
    entreprise_id: string;
    raison_sociale: string | null;
    fonction: string | null;
    est_principal: boolean;
    is_active: boolean;
}

export interface InterlocuteurFiche {
    id: string;
    personne_id: string;
    nom: string;
    telephone: string | null;
    code_phone_pays: string | null;
    email: string | null;
    fonction: string | null;
    est_principal: boolean;
    is_active: boolean;
    desactive_le: string | null;
    notes: string | null;
}
