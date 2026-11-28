/**
 * Cartes d'indicateurs des tableaux de bord sans évolution ni mini-courbe (Stat-Stock, Stat-RH),
 * affichées par LignesIndicateursApollo.vue.
 */

/** Ton du détail : neutre (gris) ou avertissement (ambre, standard A8 : rien n'est bloqué). */
export type TonIndicateur = 'neutre' | 'avertissement';

export interface CarteIndicateur {
    /** Identifiant stable : `data-testid="kpi-<id>"`. */
    id: string;
    titre: string;
    /** Définition métier, en infobulle du titre. */
    definition: string;
    /** Icône PrimeIcons, sans le préfixe `pi` (ex. `pi-users`). */
    icone: string;
    valeur: string;
    /** Une information courte, sur une ligne. */
    detail: string;
    ton?: TonIndicateur;
}

export interface LigneIndicateurs {
    /** Identifiant stable : `data-testid="<prefixe>-ligne-<id>"`. */
    id: string;
    titre: string;
    /** Précision affichée après le titre (« à ce jour », article choisi…). */
    precision: string;
    cartes: CarteIndicateur[];
}
