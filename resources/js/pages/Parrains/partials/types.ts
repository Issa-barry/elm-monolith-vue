export interface ParrainIdentite {
    id: string;
    nom_complet: string;
    surnom: string | null;
    telephone: string | null;
    code_phone_pays: string | null;
    email: string | null;
    ville: string | null;
    pays: string | null;
    adresse: string | null;
    is_active: boolean;
    cree_le: string | null;
}

export interface ParrainListe extends ParrainIdentite {
    vehicules_count: number;
    compte: { nom: string | null; telephone: string | null } | null;
    reste_a_payer: number | null;
}

/** Pourquoi une fiche sans compte n'a pas été reliée automatiquement (ADR 0056). */
export interface RattachementAutomatique {
    statut: 'aucune' | 'ambigue' | 'certaine';
    motif: string | null;
}

export interface CompteRattache {
    id: string;
    nom: string | null;
    telephone: string | null;
    email: string | null;
    inscrit_le: string | null;
    roles: string[];
    actif: boolean;
}

/** Compte proposé : identité vide s'il appartient à une autre organisation, seul le motif reste. */
export interface CompteCandidat {
    id: string | null;
    nom: string | null;
    telephone: string | null;
    email: string | null;
    inscrit_le: string | null;
    roles: string[];
    motif_refus: string | null;
}

export interface EvenementCompte {
    id: string;
    evenement: string;
    date: string | null;
    auteur: string | null;
    compte_nom: string | null;
    compte_telephone: string | null;
}

export interface VehiculeParraine {
    id: string;
    nom: string;
    immatriculation: string | null;
    site_nom: string | null;
    is_active: boolean;
    part: string | null;
    url: string | null;
}

export interface FicheCommission {
    id: string;
    reference: string;
    periode: string | null;
    site_nom: string | null;
    montant_net: number;
    montant_paye: number;
    montant_restant: number;
    display_status: string;
    display_label: string;
    url: string;
}

export interface PaiementParrain {
    id: string;
    date: string | null;
    montant: number;
    mode_paiement: string | null;
    reference_paiement: string | null;
    support: string | null;
    fiche_reference: string | null;
}

export interface CommissionsParrain {
    total_net: number;
    total_paye: number;
    total_restant: number;
    nb_fiches: number;
    fiches: FicheCommission[];
    paiements: PaiementParrain[];
    toutes_les_fiches_url: string;
}

const LIBELLES_ROLES: Record<string, string> = {
    client: 'Client',
    proprietaire: 'Propriétaire',
    livreur: 'Livreur',
};

export function libelleRole(role: string): string {
    return LIBELLES_ROLES[role] ?? role;
}
