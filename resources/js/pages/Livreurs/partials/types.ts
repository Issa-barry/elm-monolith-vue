export interface EquipeItem {
    id: string;
    vehicule_nom: string;
    role: string;
}

export interface LivreurData {
    id: string;
    // Identité civile jamais affichée côté Eau La Maman — voir
    // LivreurController. nom_complet est facultatif (surnom possible).
    nom_complet: string | null;
    telephone: string | null;
    is_active: boolean;
    has_account: boolean;
    enregistre_le: string | null;
    equipes: EquipeItem[];
}

export interface FicheCapacite {
    categorie_id: string;
    categorie_nom: string;
    capacite_max: number;
}

export interface FicheEquipe {
    id: string;
    is_active: boolean;
    role: string;
    rejoint_le: string | null;
    vehicule: {
        id: string;
        nom: string;
        immatriculation: string | null;
        type_label: string | null;
        site_nom: string | null;
        is_active: boolean;
        capacites: FicheCapacite[];
        url: string | null;
    } | null;
    coequipiers: {
        id: string;
        nom: string;
        role: string;
        url: string | null;
    }[];
}

export interface FicheCommissions {
    kpis: {
        total_genere: number;
        en_attente_periode: number;
        payable: number;
        deja_paye: number;
    };
    recentes: {
        id: string | null;
        reference: string | null;
        date: string | null;
        processus_label: string;
        montant: number;
        reste: number;
        statut: string | null;
        statut_label: string | null;
    }[];
    detail_url: string;
}

export interface FicheFactures {
    totaux: {
        nb: number;
        montant: number;
        a_encaisser: number;
    };
    recentes: {
        id: string;
        reference: string;
        client_nom: string | null;
        vehicule_nom: string | null;
        date: string | null;
        montant_net: number;
        montant_restant: number;
        statut: string | null;
        statut_label: string | null;
        url: string | null;
    }[];
    liste_url: string;
}

export interface FicheLivreur {
    equipes: FicheEquipe[];
    commissions: FicheCommissions | null;
    factures: FicheFactures | null;
}
