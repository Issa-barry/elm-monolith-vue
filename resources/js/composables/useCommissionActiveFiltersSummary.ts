import type {
    AgenceOption,
    CommissionGlobalFiltersValue,
    CommissionVehiculeInfo,
    PeriodeOption,
} from '@/types/commission';
import { computed, type Ref } from 'vue';

interface Options {
    filters: Ref<CommissionGlobalFiltersValue>;
    periodesDisponibles: PeriodeOption[];
    vehiculesDisponibles: CommissionVehiculeInfo[];
    agencesDisponibles: AgenceOption[];
}

/**
 * Résumé lisible des filtres globaux actifs ("Juin 2026 • Baba Ousou VN-001-GN"),
 * affiché sous le nom du bénéficiaire pour expliquer pourquoi les chiffres ont
 * changé. Partagé par les 3 pages détail Commission pour rester identique.
 */
export function useCommissionActiveFiltersSummary(options: Options) {
    return computed(() => {
        const {
            filters,
            periodesDisponibles,
            vehiculesDisponibles,
            agencesDisponibles,
        } = options;
        const parts: string[] = [];

        if (filters.value.periode) {
            const label = periodesDisponibles.find(
                (p) => p.code === filters.value.periode,
            )?.label;
            if (label) parts.push(label);
        }

        if (filters.value.vehicule_ids.length > 0) {
            const selected = new Set(filters.value.vehicule_ids.map(String));
            const labels = vehiculesDisponibles
                .filter((v) => v.id && selected.has(String(v.id)))
                .map((v) =>
                    [v.nom, v.immatriculation].filter(Boolean).join(' '),
                );
            if (labels.length > 0) parts.push(labels.join(', '));
        }

        if (filters.value.site_ids.length > 0) {
            const selected = new Set(filters.value.site_ids.map(String));
            const labels = agencesDisponibles
                .filter((s) => selected.has(String(s.id)))
                .map((s) => s.nom);
            if (labels.length > 0) parts.push(labels.join(', '));
        }

        return parts.join(' • ');
    });
}
