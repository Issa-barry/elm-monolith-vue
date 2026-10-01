export type SituationPeriodeCle =
    | 'tout'
    | 'aujourd_hui'
    | 'hier'
    | 'cette_semaine'
    | 'semaine_precedente'
    | 'ce_mois'
    | 'mois_precedent'
    | 'cette_annee'
    | 'annee_precedente'
    | 'personnalisee';

/** Période unique de l'onglet Situation, résolue côté serveur (SituationPeriode). */
export interface SituationPeriode {
    cle: SituationPeriodeCle;
    date_debut: string | null;
    date_fin: string | null;
    options: { value: SituationPeriodeCle; label: string }[];
}

export interface SituationProduitVendu {
    variante_id: string;
    libelle: string | null;
    quantite: number;
    montant: number;
}

export type SituationPaiementCode = 'paye' | 'partiel' | 'impaye';

export interface SituationPaiementCategorie {
    code: SituationPaiementCode;
    label: string;
    montant: number;
    pourcentage_montant: number;
    nb_ventes: number;
    reste_a_encaisser: number;
}

export interface SituationPaiements {
    total_montant: number;
    total_ventes: number;
    repartition: SituationPaiementCategorie[];
}

export interface SituationVentesData {
    kpis: {
        ca_vendu: number;
        encaisse: number;
        reste_du: number;
        nb_ventes: number;
    };
    produits: SituationProduitVendu[];
    paiements: SituationPaiements;
}
