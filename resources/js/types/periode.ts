/**
 * Choix d'une période, fourni par le serveur (App\Support\Periodes\Periode, ADR 0057) : le
 * navigateur ne calcule jamais une date de période avec sa propre horloge.
 */
export interface PeriodeOption {
    value: string;
    label: string;
}

/**
 * Période résolue par le serveur (`Periode::pourFront()` + `libelle()`) : clé choisie, bornes
 * incluses (null pour « Toutes les périodes »), choix proposés et libellé lisible.
 */
export interface PeriodeFiltre {
    cle: string;
    date_debut: string | null;
    date_fin: string | null;
    options: PeriodeOption[];
    /** Ex. « Ce mois (du 01/11/2026 au 30/11/2026) », « Du 01/10/2026 au 23/11/2026 ». */
    libelle: string;
}
