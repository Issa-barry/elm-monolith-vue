/** En-tête lu par le serveur (App\Support\Paiements\OperationPaiement, ADR 0069). */
export const ENTETE_CLE_OPERATION = 'Idempotency-Key';

/** UUID v4 ; getRandomValues reste disponible hors HTTPS, contrairement à randomUUID. */
export function nouvelleCleOperation(): string {
    const octets = crypto.getRandomValues(new Uint8Array(16));
    octets[6] = (octets[6] & 0x0f) | 0x40;
    octets[8] = (octets[8] & 0x3f) | 0x80;
    const hex = Array.from(octets, (o) => o.toString(16).padStart(2, '0'));
    return [
        hex.slice(0, 4),
        hex.slice(4, 6),
        hex.slice(6, 8),
        hex.slice(8, 10),
        hex.slice(10, 16),
    ]
        .map((groupe) => groupe.join(''))
        .join('-');
}

/**
 * Clé d'idempotence d'une opération de paiement : la même tant que l'utilisateur renvoie les
 * mêmes paramètres (double clic, nouvelle tentative après une coupure), une nouvelle dès qu'il les
 * change. Le serveur restitue le résultat d'origine pour une clé rejouée et refuse une clé
 * réutilisée avec d'autres paramètres.
 */
export function useCleOperation() {
    let cle: string | null = null;
    let parametres: string | null = null;

    /** À appeler à l'ouverture de la fenêtre : nouvelle opération. */
    function reinitialiser() {
        cle = null;
        parametres = null;
    }

    function pour(valeurs: unknown): string {
        const empreinte = JSON.stringify(valeurs);
        if (cle === null || empreinte !== parametres) {
            cle = nouvelleCleOperation();
            parametres = empreinte;
        }
        return cle;
    }

    return { reinitialiser, pour };
}
