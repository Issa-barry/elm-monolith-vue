<script setup lang="ts">
import { drapeauUrl } from '@/lib/pays';
import Select from 'primevue/select';
import { computed } from 'vue';

/**
 * Nationalité du salarié : liste fournie par le serveur (App\Support\Rh\Nationalites), recherche
 * sans tenir compte des accents, drapeau comme simple repère. La valeur est le libellé
 * (« Guinéenne »), jamais le pays ni le code.
 */
export interface NationaliteOption {
    value: string;
    label: string;
    code: string | null;
}

const props = defineProps<{
    options: NationaliteOption[];
    inputId?: string;
    invalid?: boolean;
}>();

const modele = defineModel<string>({ required: true });

const valeur = computed({
    get: () => modele.value || null,
    set: (v: string | null) => (modele.value = v ?? ''),
});

const parValeur = computed(
    () => new Map(props.options.map((o) => [o.value, o])),
);
</script>

<template>
    <Select
        v-model="valeur"
        :input-id="inputId"
        :options="options"
        option-label="label"
        option-value="value"
        filter
        filter-placeholder="Rechercher une nationalité"
        empty-filter-message="Aucune nationalité trouvée"
        reset-filter-on-hide
        show-clear
        placeholder="— Non renseignée —"
        :invalid="invalid"
        class="w-full"
        data-testid="nationalite-select"
    >
        <template #value="{ value, placeholder }">
            <span v-if="value" class="flex items-center gap-2">
                <img
                    v-if="parValeur.get(value)?.code"
                    :src="drapeauUrl(parValeur.get(value)!.code!)"
                    alt=""
                    class="h-[15px] w-5 shrink-0 rounded-sm object-cover"
                />
                {{ value }}
            </span>
            <span v-else class="text-muted-foreground">{{ placeholder }}</span>
        </template>
        <template #option="{ option }">
            <span class="flex items-center gap-2">
                <img
                    v-if="option.code"
                    :src="drapeauUrl(option.code)"
                    alt=""
                    class="h-[15px] w-5 shrink-0 rounded-sm object-cover"
                    loading="lazy"
                />
                <span v-else class="w-5 shrink-0" />
                {{ option.label }}
            </span>
        </template>
    </Select>
</template>
