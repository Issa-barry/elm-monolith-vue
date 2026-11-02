export interface ResultatLots {
    /** Nombre d'éléments des lots envoyés avec succès. */
    traites: number;
    /** Message de l'erreur qui a interrompu l'envoi, null si tout est passé. */
    erreur: string | null;
}

/**
 * Envoie les éléments en lots successifs, un à la fois, et s'arrête au premier échec : les lots
 * déjà passés restent acquis, le reste peut être renvoyé.
 */
export async function traiterParLots<T>(
    elements: T[],
    tailleLot: number,
    envoyer: (lot: T[]) => Promise<unknown>,
    surProgression?: (traites: number) => void,
): Promise<ResultatLots> {
    let traites = 0;
    try {
        for (let i = 0; i < elements.length; i += tailleLot) {
            const lot = elements.slice(i, i + tailleLot);
            await envoyer(lot);
            traites += lot.length;
            surProgression?.(traites);
        }
    } catch (e) {
        return {
            traites,
            erreur: e instanceof Error ? e.message : 'Une erreur est survenue.',
        };
    }
    return { traites, erreur: null };
}
