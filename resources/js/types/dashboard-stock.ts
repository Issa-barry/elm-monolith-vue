/**
 * Tableau de bord du stock, Stat-Stock (App\Services\Stock\TableauDeBordStockService) : toutes les
 * quantités sont calculées par le serveur.
 *
 * Les quantités ne s'additionnent jamais entre articles (pack 350 ml, pack 1,5 L, sachets… : aucune
 * unité commune) : l'état, les flux, le graphique et le stock par site portent sur l'article choisi ;
 * la synthèse présente chaque article sans total.
 */

/** Une variante active d'un produit dont le type gère le stock. */
export interface ArticleStock {
    value: string;
    label: string;
    produit_id: string;
}

/** État actuel de l'article sur les agences : disponible = physique − réservé. */
export interface EtatStock {
    physique: number;
    reservee: number;
    disponible: number;
    en_transit: number;
    transferts_en_transit: number;
}

/** Couples article × site à signaler : disponibles sur le site ET alerte active (badges). */
export interface CompteurAlertesStock {
    total: number;
    ruptures: number;
    faibles: number;
    negatifs: number;
}

/** Flux de l'article sur la période (date métier), hors mouvements annulés. */
export interface FluxPeriodeStock {
    entrees: number;
    sorties: number;
    pertes_casses: number;
    ecarts_manquants: number;
    ecarts_surplus: number;
}

export interface SerieFluxStock {
    motif: string;
    label: string;
    sens: 'entree' | 'sortie';
    /** Une valeur par tranche ; les sorties sont négatives. */
    valeurs: number[];
}

export interface EvolutionStock {
    granularite: 'jour' | 'mois';
    tranches: { cle: string; label: string }[];
    series: SerieFluxStock[];
}

export interface SiteStock {
    id: string;
    label: string;
    physique: number;
    reservee: number;
    disponible: number;
    disponible_sur_site: boolean;
}

export interface LigneSyntheseStock {
    id: string;
    label: string;
    physique: number;
    reservee: number;
    disponible: number;
    en_transit: number;
    entrees: number;
    sorties: number;
    pertes_casses: number;
    ecarts_manquants: number;
}

/** Couple article × site disponible sur le site, statut autre que Disponible. */
export interface LigneAlerteStock {
    produit_id: string;
    variante_id: string;
    article: string;
    site_id: string;
    site: string;
    physique: number;
    reservee: number;
    disponible: number;
    seuil: number;
    statut: 'rupture' | 'stock_faible' | 'stock_negatif';
    statut_label: string;
    alerte_active: boolean;
}
