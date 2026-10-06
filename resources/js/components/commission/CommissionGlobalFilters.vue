<script setup lang="ts">
import FilterMultiSelect from '@/components/filters/FilterMultiSelect.vue';
import { Button } from '@/components/ui/button';
import type {
    AgenceOption,
    CommissionGlobalFiltersValue,
    CommissionProcessusOption,
    CommissionVehiculeInfo,
    PeriodeOption,
} from '@/types/commission';
import { RotateCcw, SlidersHorizontal } from 'lucide-vue-next';
import { computed } from 'vue';
import CommissionPeriodSelect from './CommissionPeriodSelect.vue';
import CommissionProcessusSelect from './CommissionProcessusSelect.vue';
import CommissionVehiculeSelect from './CommissionVehiculeSelect.vue';

const props = defineProps<{
    filters: CommissionGlobalFiltersValue;
    periodesDisponibles: PeriodeOption[];
    vehiculesDisponibles: CommissionVehiculeInfo[];
    agencesDisponibles: AgenceOption[];
    // Optionnel : n'affiche le sélecteur de processus que sur les pages qui le fournissent
    // (fiche détail bénéficiaire) — absent partout ailleurs (Logistique/Propriétaire/
    // Consultant/Site/Cashback/Salaire), qui n'ont pas demandé cette ventilation.
    processusOptions?: CommissionProcessusOption[];
}>();

const emit = defineEmits<{
    (e: 'update:periode', value: string): void;
    (e: 'update:vehicule', value: (string | number)[]): void;
    (e: 'update:agence', value: (string | number)[]): void;
    (e: 'reset'): void;
    (e: 'change', value: CommissionGlobalFiltersValue): void;
}>();

const vehiculeOptions = computed(() =>
    props.vehiculesDisponibles
        .filter((v) => v.id)
        .map((v) => ({
            value: v.id as string,
            label:
                [v.nom, v.immatriculation].filter(Boolean).join(' — ') || '—',
        })),
);

const agenceOptions = computed(() =>
    props.agencesDisponibles.map((s) => ({ value: s.id, label: s.nom })),
);

const hasActiveFilters = computed(
    () =>
        props.filters.periode !== '' ||
        props.filters.vehicule_ids.length > 0 ||
        props.filters.site_ids.length > 0 ||
        !!props.filters.processus,
);

function emitChange(next: Partial<CommissionGlobalFiltersValue>) {
    emit('change', { ...props.filters, ...next });
}

function onPeriodeChange(value: string) {
    emit('update:periode', value);
    emitChange({ periode: value });
}

function onVehiculeChange(value: (string | number)[]) {
    emit('update:vehicule', value);
    emitChange({ vehicule_ids: value });
}

function onAgenceChange(value: (string | number)[]) {
    emit('update:agence', value);
    emitChange({ site_ids: value });
}

function onProcessusChange(value: string) {
    emitChange({ processus: value });
}

function reset() {
    emit('reset');
    emitChange({
        periode: '',
        vehicule_ids: [],
        site_ids: [],
        ...(props.processusOptions ? { processus: '' } : {}),
    });
}
</script>

<template>
    <div
        class="rounded-xl border bg-card p-3 shadow-sm"
        data-testid="commission-global-filters"
    >
        <div class="mb-2.5 flex items-center justify-between gap-3 px-0.5">
            <p
                class="inline-flex items-center gap-1.5 text-xs font-semibold text-muted-foreground"
            >
                <SlidersHorizontal class="h-3.5 w-3.5" />
                Affiner les résultats
            </p>
            <Button
                v-if="hasActiveFilters"
                variant="ghost"
                size="sm"
                class="h-7 px-2 text-xs"
                data-testid="commission-filters-reset"
                @click="reset"
            >
                <RotateCcw class="mr-1.5 h-3.5 w-3.5" />
                Réinitialiser
            </Button>
        </div>

        <div
            class="grid grid-cols-1 gap-2 sm:grid-cols-2"
            :class="
                processusOptions && processusOptions.length
                    ? 'xl:grid-cols-4'
                    : 'xl:grid-cols-3'
            "
        >
            <div class="min-w-0" data-testid="commission-filters-periode">
                <CommissionPeriodSelect
                    :model-value="filters.periode"
                    :periodes-disponibles="periodesDisponibles"
                    class="sm:!w-full"
                    @update:model-value="onPeriodeChange"
                />
            </div>
            <div class="min-w-0" data-testid="commission-filters-vehicule">
                <CommissionVehiculeSelect
                    :model-value="filters.vehicule_ids"
                    :options="vehiculeOptions"
                    class="sm:!w-full"
                    @update:model-value="onVehiculeChange"
                />
            </div>
            <div class="min-w-0" data-testid="commission-filters-agence">
                <FilterMultiSelect
                    :model-value="filters.site_ids"
                    :options="agenceOptions"
                    placeholder="Toutes les agences"
                    class="w-full text-sm"
                    @update:model-value="onAgenceChange"
                />
            </div>
            <div
                v-if="processusOptions && processusOptions.length"
                class="min-w-0"
                data-testid="commission-filters-processus"
            >
                <CommissionProcessusSelect
                    :model-value="filters.processus ?? ''"
                    :options="processusOptions"
                    class="sm:!w-full"
                    @update:model-value="onProcessusChange"
                />
            </div>
        </div>
    </div>
</template>
