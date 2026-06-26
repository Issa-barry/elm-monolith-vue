import type { CommissionVehiculeInfo } from '@/types/commission';
import { computed, ref, type ComputedRef } from 'vue';

interface HasVehicule {
    vehicule?: CommissionVehiculeInfo | null;
}

function vehiculeKey(row: HasVehicule): string | null {
    return row.vehicule?.id ?? row.vehicule?.nom ?? null;
}

function vehiculeLabel(vehicule: HasVehicule['vehicule']): string {
    if (!vehicule || (!vehicule.nom && !vehicule.immatriculation)) return '—';
    return [vehicule.nom, vehicule.immatriculation].filter(Boolean).join(' — ');
}

/**
 * Filtre véhicule partagé par les tableaux détail commission (commandes/
 * transferts ET dépenses) : dérive les options depuis les lignes reçues,
 * garde la sélection, et renvoie les lignes filtrées — pour que chaque page
 * puisse placer le sélecteur où elle veut sans dupliquer la logique.
 */
export function useCommissionVehiculeFilter<T extends HasVehicule>(
    rows: ComputedRef<T[]>,
) {
    const vehiculeOptions = computed<{ value: string; label: string }[]>(() => {
        const seen = new Map<string, string>();
        for (const row of rows.value) {
            const key = vehiculeKey(row);
            if (key && !seen.has(key)) {
                seen.set(key, vehiculeLabel(row.vehicule));
            }
        }
        return Array.from(seen.entries())
            .map(([value, label]) => ({ value, label }))
            .sort((a, b) => a.label.localeCompare(b.label));
    });

    const selectedVehicules = ref<(string | number)[]>([]);

    const filteredRows = computed(() => {
        if (selectedVehicules.value.length === 0) return rows.value;
        const selected = new Set(selectedVehicules.value.map(String));
        return rows.value.filter((row) => {
            const key = vehiculeKey(row);
            return key !== null && selected.has(key);
        });
    });

    return { vehiculeOptions, selectedVehicules, filteredRows };
}
