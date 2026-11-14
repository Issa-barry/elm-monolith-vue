// Données du rapport d'activité / de « Ma situation » (RapportActiviteService, docs/rapports.md).

export interface LigneFacture {
    id: string;
    reference: string;
    date: string;
    client: string | null;
    agent: string | null;
    /** Véhicule de la commande, null pour une vente sans véhicule. */
    vehicule_nom: string | null;
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
        /** `caisse` (espèces) ou `mobile_money` (compte Mobile Money de l'agent, ADR 0027). */
        type?: string;
        operateur_label?: string | null;
    };
    solde_debut: number;
    solde_fin: number;
    total_entrees: number;
    total_sorties: number;
    mouvements: MouvementCaisse[];
    solde_actuel: number;
    en_cours: { nombre: number; montant: number };
    contestes: { nombre: number; montant: number };
    /** Versements dont l'agence a confirmé la réception pendant la période (remis, ADR 0064). */
    recus_periode?: { nombre: number; montant: number };
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
    caisse: SyntheseSupportsAgents;
    /** Comptes Mobile Money des agents (ADR 0027) : même synthèse que les caisses. */
    comptes_mobile_money?: SyntheseSupportsAgents;
    /** Colonnes d'encaissement communes aux rapports : Espèces, Orange Money, puis tout moyen utilisé. */
    moyens: MoyenColonne[];
    /** Charges saisies par les agents du périmètre (ChargesRapportService). */
    charges: {
        resume: TotauxCharges;
        lignes: LigneCharge[];
        total_lignes: number;
    };
    /** Remise des agents à leur agence (ADR 0064) ; null sans caisse ni compte Mobile Money. */
    remise: RemiseAgent | null;
    /** Une ligne par agent (rapport d'activité seulement ; null dans « Ma situation »). */
    agents: { lignes: LigneAgent[]; totaux: TotauxAgents } | null;
}

export interface MoyenColonne {
    cle: string;
    libelle: string;
}

export interface TotauxCharges {
    charges_saisies: number;
    charges_payees: number;
    charges_payees_nombre: number;
    charges_en_attente: number;
    charges_en_attente_nombre: number;
    charges_avances_tiers: number;
}

export interface LigneCharge {
    id: string;
    date: string;
    type: string | null;
    categorie: string | null;
    avance_tiers: boolean;
    commentaire: string | null;
    site_nom: string | null;
    agent: string | null;
    montant: number;
    statut: string;
    statut_label: string;
}

export interface RemiseAgent {
    remis: number;
    en_transit: number;
    reste_a_remettre: number;
    /** Agence(s) de rattachement : agence de la caisse / du compte Mobile Money. */
    agences: string[];
    derniere_remise: string | null;
    statut: string;
    statut_label: string;
}

export interface LigneAgent extends TotauxCharges {
    agent_id: string | null;
    agent: string;
    agences: string[];
    ventes_nombre: number;
    ventes_montant: number;
    par_moyen: Record<string, { cle: string; montant: number; nombre: number }>;
    encaisse_nombre: number;
    encaisse_montant: number;
    pour_autres_agences: number;
    dettes_nombre: number;
    dettes_montant: number;
    remise: RemiseAgent | null;
}

export interface TotauxAgents {
    ventes_nombre: number;
    ventes_montant: number;
    par_moyen: Record<string, number>;
    encaisse_montant: number;
    pour_autres_agences: number;
    dettes_montant: number;
    charges_saisies: number;
    charges_en_attente: number;
    remis: number;
    en_transit: number;
    reste_a_remettre: number;
}

/** RapportActiviteService::caisse() / comptesMobileMoney() — supports dédiés aux agents. */
export interface SyntheseSupportsAgents {
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
}
