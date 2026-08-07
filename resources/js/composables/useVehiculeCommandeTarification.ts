/**
 * Reflète côté client ce que VehiculeCommandeContextResolver calcule côté
 * serveur (source de vérité) : mode de tarification (prix_vente/prix_usine)
 * et éligibilité aux commissions — deux notions indépendantes dérivées du
 * véhicule sélectionné, jamais recalculées l'une à partir de l'autre.
 * Partagé entre Ventes/Create.vue et Ventes/Edit.vue pour ne pas dupliquer
 * ce calcul (et le laisser diverger silencieusement entre les deux pages).
 */
import { computed, type ComputedRef } from 'vue';

export interface VehiculeTarificationOption {
    id: number;
    pris_en_charge_par_usine: boolean;
    commission_eligible: boolean;
}

export function useVehiculeCommandeTarification<
    T extends VehiculeTarificationOption,
>(
    getVehicules: () => T[],
    getVehiculeId: () => number | null,
): {
    modeTarification: ComputedRef<'prix_vente' | 'prix_usine'>;
    commissionEligible: ComputedRef<boolean>;
} {
    const selectedVehicule = computed<T | null>(() => {
        const id = getVehiculeId();

        return id === null
            ? null
            : (getVehicules().find((v) => v.id === id) ?? null);
    });

    const modeTarification = computed<'prix_vente' | 'prix_usine'>(() =>
        selectedVehicule.value &&
        !selectedVehicule.value.pris_en_charge_par_usine
            ? 'prix_usine'
            : 'prix_vente',
    );

    const commissionEligible = computed<boolean>(() =>
        selectedVehicule.value
            ? selectedVehicule.value.commission_eligible
            : true,
    );

    return { modeTarification, commissionEligible };
}
