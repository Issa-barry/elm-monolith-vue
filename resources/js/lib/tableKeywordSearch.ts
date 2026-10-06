/**
 * Recherche par mot-clé sur les lignes déjà chargées d'un tableau — équivalent du
 * `globalFilter` + `globalFilterFields` (correspondance « contient ») de la DataTable
 * PrimeVue. Ne filtre que l'affichage : KPI, exports et filtres serveur restent inchangés.
 */

type SearchableValue = string | number | null | undefined;

function normaliser(texte: string): string {
    return texte.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase().trim();
}

function chiffres(texte: string): string {
    return texte.replace(/\D/g, '');
}

/**
 * Chaque ligne est retenue si au moins une de ses valeurs contient le mot-clé (casse et
 * accents ignorés). Un mot-clé purement numérique est aussi comparé sans séparateurs,
 * pour qu'un montant (« 642 000 ») ou un téléphone (« 629331 ») se trouve tel qu'il
 * est affiché.
 */
export function filtrerParMotCle<T>(
    lignes: T[],
    motCle: string,
    valeurs: (ligne: T) => SearchableValue[],
): T[] {
    const requete = normaliser(motCle);
    if (!requete) return lignes;

    const requeteChiffres = /^[\d\s.,+]+$/.test(requete)
        ? chiffres(requete)
        : '';

    return lignes.filter((ligne) =>
        valeurs(ligne).some((valeur) => {
            if (valeur === null || valeur === undefined || valeur === '') {
                return false;
            }
            const texte = String(valeur);
            if (normaliser(texte).includes(requete)) return true;
            return (
                requeteChiffres !== '' &&
                chiffres(texte).includes(requeteChiffres)
            );
        }),
    );
}
