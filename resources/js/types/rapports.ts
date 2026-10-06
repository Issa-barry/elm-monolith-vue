// Données du rapport d'activité / de « Ma situation » (RapportActiviteService, docs/rapports.md).

export interface LigneFacture {
    id: string;
    reference: string;
    date: string;
    client: string | null;
    agent: string | null;
    /** « Créée à » : agence de la commande. */
    site_nom: string | null;
    /** « Encaissée à » : agence(s) où ses encaissements ont été reçus (ADR 0012), null sans encaissement. */
    encaisse_a: string | null;
    montant: number;
    encaisse: number;
    reste: number;
    statut: string;
    statut_label: string;
    anciennete_jours?: number;
}

export interface LigneEncaissement {
    id: string;
    date_encaissement: string;
    saisi_le: string | null;
    saisie_differee: boolean;
    montant: number;
    mode_paiement: string;
    operateur_mobile_money: string | null;
    moyen_libelle: string;
    reference_paiement: string | null;
    facture_id: string;
    facture_reference: string;
    client: string | null;
    agent: string | null;
    /** « Créée à » : agence de la commande. */
    site_nom: string | null;
    /** « Encaissée à » : agence qui a reçu l'argent (ADR 0012). */
    encaisse_a: string | null;
    /** Encaissé pour une commande d'une autre agence : à reverser. */
    pour_autre_agence: boolean;
}

export type AnomalieMobileMoney =
    | 'reference_absente'
    | 'reference_dupliquee'
    | 'anterieure_obligation';

export interface LigneMobileMoney extends LigneEncaissement {
    anomalie: AnomalieMobileMoney | null;
    autres_utilisations: {
        id: string;
        facture_reference: string;
        date_encaissement: string;
    }[];
    autres_hors_perimetre: number;
}

export interface MouvementCaisse {
    categorie: string;
    libelle: string;
    entrees: number;
    sorties: number;
    nombre: number;
}

export interface VersementCaisse {
    id: string;
    reference: string;
    montant: number;
    statut: string;
    statut_label: string;
    date_envoi: string;
    date_reception: string | null;
}

export interface EcritureCaisse {
    piece_id: string;
    numero: string;
    date: string;
    libelle: string;
    categorie: string;
    categorie_libelle: string;
    entree: number;
    sortie: number;
    solde: number;
}

/** FicheCaisseService::pour() — une caisse dédiée sur une période. */
export interface FicheCaisse {
    caisse: {
        id: string;
        libelle: string;
        actif: boolean;
        site_id: string;
        site_nom: string | null;
        agent_id: string | null;
        agent_nom: string | null;
    };
    solde_debut: number;
    solde_fin: number;
    total_entrees: number;
    total_sorties: number;
    mouvements: MouvementCaisse[];
    solde_actuel: number;
    en_cours: { nombre: number; montant: number };
    contestes: { nombre: number; montant: number };
    versements_periode: VersementCaisse[];
    dernier_versement: (VersementCaisse & { anciennete_jours: number }) | null;
    ecritures: EcritureCaisse[] | null;
}

export interface RapportActivite {
    ventes: {
        resume: {
            nombre: number;
            facture: number;
            encaisse: number;
            reste: number;
            annulees_nombre: number;
            annulees_montant: number;
        };
        lignes: LigneFacture[];
        total_lignes: number;
    };
    encaissements: {
        resume: {
            nombre: number;
            montant: number;
            pour_autres_agences_nombre: number;
            pour_autres_agences_montant: number;
        };
        par_moyen: {
            cle: string;
            libelle: string;
            mode_paiement: string;
            nombre: number;
            montant: number;
        }[];
        lignes: LigneEncaissement[];
        total_lignes: number;
    };
    creances: {
        resume: {
            nombre: number;
            impayees: number;
            partielles: number;
            facture: number;
            reste: number;
            plus_ancienne: string | null;
        };
        lignes: LigneFacture[];
        total_lignes: number;
    };
    mobile_money: {
        resume: {
            nombre: number;
            montant: number;
            reference_absente: number;
            reference_dupliquee: number;
            anterieure_obligation: number;
        };
        par_operateur: {
            operateur: string | null;
            libelle: string;
            nombre: number;
            montant: number;
        }[];
        lignes: LigneMobileMoney[];
        total_lignes: number;
    };
    caisse: {
        aucune_caisse: boolean;
        detail: boolean;
        resume: {
            solde_debut: number;
            entrees: number;
            sorties: number;
            solde_fin: number;
            solde_actuel: number;
            en_cours_montant: number;
            en_cours_nombre: number;
            contestes_nombre: number;
        };
        fiches: FicheCaisse[];
    };
}
