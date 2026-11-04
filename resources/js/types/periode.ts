/**
 * Choix d'une période, fourni par le serveur (App\Support\Periodes\Periode, ADR 0057). Les bornes
 * sont présentes quand l'écran filtre des données déjà chargées : le navigateur ne calcule jamais
 * une date de période avec sa propre horloge.
 */
export interface PeriodeOption {
    value: string;
    label: string;
    date_debut?: string | null;
    date_fin?: string | null;
}
