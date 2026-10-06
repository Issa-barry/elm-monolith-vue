export interface StockRow {
    produit_id: string;
    produit_nom: string;
    image_url: string | null;
    categorie_id: string | null;
    categorie_nom: string | null;
    variante_id: string;
    variante_libelle: string;
    is_default: boolean;
    sku: string;
    site_id: string;
    site_nom: string;
    site_code: string | null;
    /** Disponible = physique − réservé − bloqué (bloqué pas encore implémenté). */
    qte_disponible: number;
    qte_physique: number;
    qte_engagee: number;
    /** null = non implémenté côté backend — jamais une fausse valeur à 0. */
    qte_bloquee: number | null;
    qte_entrante: number | null;
    seuil_effectif: number;
    disponible_sur_site: boolean;
    statut: 'disponible' | 'stock_faible' | 'rupture' | 'stock_negatif';
    statut_label: string;
    dernier_mouvement: {
        type: 'entree' | 'sortie';
        quantite: number;
        motif_label: string | null;
        /** Date métier de l'opération (jamais l'horodatage technique de création). */
        date: string;
    } | null;
    can_ajuster: boolean;
}
