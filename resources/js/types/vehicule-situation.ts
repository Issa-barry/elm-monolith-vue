export type SituationPeriode = 'all' | 'month' | 'year';

export interface SituationProduitVendu {
    variante_id: string;
    libelle: string | null;
    quantite: number;
    montant: number;
}

export type SituationPaiementCode = 'paye' | 'partiel' | 'du';

export interface SituationPaiementCategorie {
    code: SituationPaiementCode;
    label: string;
    montant: number;
    pourcentage_montant: number;
    nb_ventes: number;
    pourcentage_ventes: number;
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
    periode_debut: string | null;
    periode_fin: string | null;
}
