/**
 * Reflète côté client ce que VehiculeCommandeContextResolver calcule côté
 * serveur (source de vérité) : mode de tarification (prix_vente/prix_usine)
 * et éligibilité aux commissions — deux notions indépendantes.
 *
 * Règle : un véhicule de flotte gérée (toujours livraison_vente=true dans ce picker,
 * cf. CommandeVenteController::vehiculesActifs()) facture toujours au prix de vente
 * plein et est toujours éligible aux commissions. Sans véhicule, seul un client
 * Externe facture à prix usine (jamais de commission — aucun véhicule de flotte
 * impliqué). Partagé entre Ventes/Create.vue et Ventes/Edit.vue pour ne pas dupliquer
 * ce calcul (et le laisser diverger silencieusement entre les deux pages).
 */
import { computed, type ComputedRef } from 'vue';

export interface ClientTarificationOption {
    id: number;
    type?: 'externe' | 'revendeur' | 'distributeur';
}

export function useVehiculeCommandeTarification<T extends { id: number }>(
    getVehicules: () => T[],
    getVehiculeId: () => number | null,
    getClients?: () => ClientTarificationOption[],
    getClientId?: () => number | null,
): {
    modeTarification: ComputedRef<'prix_vente' | 'prix_usine'>;
    commissionEligible: ComputedRef<boolean>;
    natureOperationParDefaut: ComputedRef<'vente_standard' | 'distribution_client'>;
} {
    const selectedVehicule = computed<T | null>(() => {
        const id = getVehiculeId();

        return id === null
            ? null
            : (getVehicules().find((v) => v.id === id) ?? null);
    });

    const selectedClient = computed<ClientTarificationOption | null>(() => {
        if (!getClients || !getClientId) return null;
        const id = getClientId();

        return id === null
            ? null
            : (getClients().find((c) => c.id === id) ?? null);
    });

    const modeTarification = computed<'prix_vente' | 'prix_usine'>(() => {
        if (selectedVehicule.value) return 'prix_vente';

        return selectedClient.value?.type === 'externe'
            ? 'prix_usine'
            : 'prix_vente';
    });

    // Aucun véhicule de flotte impliqué => jamais de commission, quel que soit le
    // client (CommissionGenerator exige un véhicule de toute façon).
    const commissionEligible = computed<boolean>(
        () => !!selectedVehicule.value,
    );

    // Miroir de NatureOperation::deriverParDefaut() (backend, seul juge à l'enregistrement) :
    // distribution_client seulement si le client est distributeur ET qu'un véhicule de flotte
    // assure la livraison — sans véhicule, aucune équipe à commissionner, vente standard.
    const natureOperationParDefaut = computed<
        'vente_standard' | 'distribution_client'
    >(() =>
        selectedClient.value?.type === 'distributeur' && selectedVehicule.value
            ? 'distribution_client'
            : 'vente_standard',
    );

    return { modeTarification, commissionEligible, natureOperationParDefaut };
}
