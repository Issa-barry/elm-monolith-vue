<script setup lang="ts">
import FilterMultiSelect from '@/components/filters/FilterMultiSelect.vue';
import { Button } from '@/components/ui/button';
import type {
    AgenceOption,
    CommissionGlobalFiltersValue,
    CommissionVehiculeInfo,
    PeriodeOption,
} from '@/types/commission';
import { computed } from 'vue';
import CommissionPeriodSelect from './CommissionPeriodSelect.vue';
import CommissionVehiculeSelect from './CommissionVehiculeSelect.vue';

const props = defineProps<{
    filters: CommissionGlobalFiltersValue;
    periodesDisponibles: PeriodeOption[];
    vehiculesDisponibles: CommissionVehiculeInfo[];
    agencesDisponibles: AgenceOption[];
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
        props.filters.site_ids.length > 0,
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

function reset() {
    emit('reset');
    emitChange({ periode: '', vehicule_ids: [], site_ids: [] });
}
</script>

<template>
    <div
        class="flex flex-col gap-2 rounded-xl border bg-card p-3 sm:flex-row sm:items-center sm:gap-3"
        data-testid="commission-global-filters"
    >
        <div class="w-full sm:w-64" data-testid="commission-filters-periode">
            <CommissionPeriodSelect
                :model-value="filters.periode"
                :periodes-disponibles="periodesDisponibles"
                @update:model-value="onPeriodeChange"
            />
        </div>
        <div data-testid="commission-filters-vehicule">
            <CommissionVehiculeSelect
                :model-value="filters.vehicule_ids"
                :options="vehiculeOptions"
                @update:model-value="onVehiculeChange"
            />
        </div>
        <div class="w-full sm:w-64" data-testid="commission-filters-agence">
            <FilterMultiSelect
                :model-value="filters.site_ids"
                :options="agenceOptions"
                placeholder="Toutes les agences"
                class="text-sm"
                @update:model-value="onAgenceChange"
            />
        </div>
        <Button
            v-if="hasActiveFilters"
            variant="ghost"
            size="sm"
            class="sm:ml-auto"
            data-testid="commission-filters-reset"
            @click="reset"
        >
            Réinitialiser
        </Button>
    </div>
</template>
