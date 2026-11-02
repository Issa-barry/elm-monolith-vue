/**
 * Primes déjà attribuées à un salarié, pour les formulaires (fenêtre « Attribuer », « Nouveau
 * contrat ») : même règle que le refus serveur AffectationRemunerationService::
 * assertSansChevauchement() — le serveur reste l'autorité, ici on évite seulement de proposer un
 * doublon. Dates au format AAAA-MM-JJ : la comparaison de textes suit l'ordre des dates.
 */
export interface PrimeOccupee {
    type_id: string;
    libelle: string | null;
    date_debut: string;
    date_fin: string | null;
    a_valider: boolean;
}

export const SANS_FIN = '9999-12-31';

/** Premier et dernier jour d'un mois « AAAA-MM ». */
export function periodeDuMois(mois: string): [string, string] {
    const [annee, m] = mois.split('-').map(Number);
    const dernierJour = new Date(annee, m, 0).getDate();

    return [`${mois}-01`, `${mois}-${String(dernierJour).padStart(2, '0')}`];
}

/** Ce type occupe-t-il déjà la période ? Un type à cumul autorisé n'est jamais bloqué. */
export function primeDejaAttribuee(
    primes: PrimeOccupee[],
    typeId: string,
    cumulAutorise: boolean,
    du: string,
    au: string,
): boolean {
    if (cumulAutorise) return false;

    return primes.some(
        (p) =>
            p.type_id === typeId &&
            p.date_debut <= au &&
            (p.date_fin === null || p.date_fin >= du),
    );
}

/** Récapitulatif compact : primes en cours ou à venir, une fois chacune. */
export function libellesPrimesActuelles(
    primes: PrimeOccupee[],
    aujourdhui: string,
): string[] {
    const vues = new Map<string, string>();
    for (const p of primes) {
        if (p.date_fin !== null && p.date_fin < aujourdhui) continue;
        if (!vues.has(p.type_id)) {
            vues.set(
                p.type_id,
                `${p.libelle ?? 'Prime'}${p.a_valider ? ' (à valider)' : ''}`,
            );
        }
    }

    return [...vues.values()];
}

/**
 * Date d'effet proposée pour une prime d'un nouveau contrat : le début du contrat, ou le premier
 * jour modifiable si ce mois est figé.
 */
export function dateEffetProposee(
    debutContrat: string,
    premierJourModifiable: string | null,
): string {
    if (!debutContrat) return '';

    return premierJourModifiable && premierJourModifiable > debutContrat
        ? premierJourModifiable
        : debutContrat;
}

/**
 * Mois de versement proposé pour une prime ponctuelle : elle couvre le mois entier, donc un contrat
 * commencé en cours de mois propose le mois suivant (période d'emploi, AFF-003).
 */
export function moisVersementPropose(
    debutContrat: string,
    premierJourModifiable: string | null,
): string {
    const date = dateEffetProposee(debutContrat, premierJourModifiable);
    if (!date) return '';
    if (date.endsWith('-01')) return date.slice(0, 7);

    const [annee, mois] = date.split('-').map(Number);

    return mois === 12
        ? `${annee + 1}-01`
        : `${annee}-${String(mois + 1).padStart(2, '0')}`;
}
