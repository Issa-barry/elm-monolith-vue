/** Choix « Autre » du lien avec le contact d'urgence (App\Support\Rh\LiensContactUrgence::AUTRE). */
export const LIEN_AUTRE = 'Autre';

/**
 * Lien enregistré → champs du formulaire : une valeur hors liste (ancienne saisie libre ou
 * précision d'« Autre ») se relit comme « Autre » avec cette valeur pour précision.
 */
export function lienVersFormulaire(
    lien: string | null,
    options: { value: string }[],
): { contact_urgence_lien: string; contact_urgence_lien_precision: string } {
    if (!lien) {
        return { contact_urgence_lien: '', contact_urgence_lien_precision: '' };
    }
    const connu = options.some(
        (o) => o.value === lien && o.value !== LIEN_AUTRE,
    );

    return connu
        ? { contact_urgence_lien: lien, contact_urgence_lien_precision: '' }
        : {
              contact_urgence_lien: LIEN_AUTRE,
              contact_urgence_lien_precision: lien,
          };
}
