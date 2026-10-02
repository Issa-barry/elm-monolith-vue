/**
 * Sélection du pool de véhicules proposé à la saisie selon le type de client — règle métier
 * distribution client du 31/08/2026 : dès qu'un client DISTRIBUTEUR est sélectionné, seuls les
 * véhicules autorisés pour l'usage logistique (Vehicule::livraison_logistique = true) doivent
 * être proposables, jamais les véhicules vente-only (et inversement pour tout autre type de
 * client). Extrait en fonctions pures pour rester testable indépendamment du montage de
 * Ventes/Create.vue (AutoComplete PrimeVue, fetch de solvabilité, etc.).
 */

export type ClientTypePourPool =
    | 'externe'
    | 'revendeur'
    | 'distributeur'
    | 'grossiste'
    | undefined
    | null;

/**
 * Retourne la liste de véhicules à proposer à la saisie pour ce type de client — jamais un
 * mélange des deux pools, jamais un filtre visuel sur la liste complète (cf. décision produit :
 * une liste filtrée proprement plutôt que des options désactivées, qui rendrait la liste confuse).
 */
export function poolVehiculesPourClient<T>(
    clientType: ClientTypePourPool,
    vehiculesVente: T[],
    vehiculesDistribution: T[],
): T[] {
    return clientType === 'distributeur'
        ? vehiculesDistribution
        : vehiculesVente;
}

/**
 * Un véhicule déjà sélectionné doit être désélectionné dès qu'il n'appartient plus au pool
 * courant (changement de type de client, ou bascule manuelle de la nature de l'opération) —
 * jamais laissé sélectionné silencieusement avec un usage non autorisé. Aucun véhicule
 * sélectionné (id null) n'a jamais besoin d'être invalidé.
 */
export function vehiculeEstDansPool<T extends { id: number }>(
    vehiculeId: number | null,
    pool: T[],
): boolean {
    if (vehiculeId === null) {
        return true;
    }

    return pool.some((v) => v.id === vehiculeId);
}
