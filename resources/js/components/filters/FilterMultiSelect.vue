<script setup lang="ts">
import MultiSelect from 'primevue/multiselect';
import { computed } from 'vue';

export interface MultiSelectOption {
    value: string | number;
    label: string;
}

const props = withDefaults(
    defineProps<{
        options: MultiSelectOption[];
        placeholder?: string;
        disabled?: boolean;
        // Quand true, un model vide = "tous sélectionnés" (admin : toutes les agences)
        emptyMeansAll?: boolean;
        // Pour DataFilters field.type === 'select' : au plus UNE valeur cochée à la fois. Sans
        // cette contrainte, PrimeVue MultiSelect laisse cocher plusieurs cases (l'utilisateur peut
        // cocher "Tous"/plusieurs statuts), mais DataFilters::buildParams() n'envoie jamais que la
        // 1ère valeur du tableau au serveur — l'utilisateur croit avoir élargi le filtre alors que
        // le résultat reste silencieusement identique à un seul choix (régression constatée le
        // 02/09/2026 sur le filtre Processus : cocher Tous/Vente/Distribution/Transfert envoyait
        // quand même ?processus=vente). Cocher une nouvelle valeur remplace l'ancienne au lieu de
        // s'y ajouter ; l'en-tête générique "Tous" (qui coche tout, sémantiquement incohérent en
        // single-select) est masqué.
        singleSelect?: boolean;
    }>(),
    { placeholder: 'Tous', disabled: false, emptyMeansAll: false, singleSelect: false },
);

const model = defineModel<(string | number)[]>({ default: () => [] });

const allValues = computed(() => props.options.map((o) => String(o.value)));

const isAllSelected = computed(() => {
    const vals = (model.value ?? []).map(String);
    if (props.emptyMeansAll && vals.length === 0) return true;
    return (
        allValues.value.length > 0 &&
        allValues.value.every((v) => vals.includes(v))
    );
});

function toggleAll() {
    if (props.disabled) return;
    if (props.emptyMeansAll) {
        // En mode emptyMeansAll : vide = Tous. Clic Tous depuis une sélection → vide.
        // Clic Tous depuis vide → sélection explicite de tout (pour décocher ensuite).
        model.value = (model.value ?? []).length === 0 ? allValues.value : [];
    } else {
        model.value = isAllSelected.value ? [] : allValues.value;
    }
}

function handleChange(newVal: (string | number)[]) {
    if (props.disabled) return;

    if (props.singleSelect) {
        // Ne garde jamais plus d'une valeur : celle qui vient d'être cochée remplace l'ancienne
        // (comportement radio via des cases à cocher). Décocher l'unique valeur cochée vide le
        // filtre, exactement comme un select classique sans valeur choisie.
        if (newVal.length === 0) {
            model.value = [];

            return;
        }
        const previous = (model.value ?? []).map(String);
        const newlyChecked = newVal.find((v) => !previous.includes(String(v)));
        model.value = [newlyChecked ?? newVal[newVal.length - 1]];

        return;
    }

    model.value = newVal;
}
</script>

<template>
    <MultiSelect
        :model-value="model"
        :options="options"
        option-label="label"
        option-value="value"
        :placeholder="placeholder"
        class="w-full"
        fluid
        display="chip"
        :show-toggle-all="false"
        append-to="self"
        :disabled="disabled"
        :pt="{
            root: { class: 'h-9' },
            labelContainer: { class: 'flex items-center overflow-hidden' },
            label: { class: 'truncate' },
        }"
        @update:model-value="handleChange"
    >
        <!-- "Tous" (cocher toutes les options à la fois) n'a pas de sens pour un champ
        single-select (field.type === 'select') : cet en-tête générique de sélection multiple est
        donc masqué dans ce mode, jamais affiché à côté d'options qui ne peuvent être cochées
        qu'une à la fois. -->
        <template v-if="!singleSelect" #header>
            <div
                class="flex items-center gap-2 border-b px-3 py-2"
                :class="
                    disabled
                        ? 'cursor-default opacity-50'
                        : 'cursor-pointer hover:bg-muted/50'
                "
                @click.stop="toggleAll"
            >
                <div
                    class="flex h-4 w-4 shrink-0 items-center justify-center rounded border"
                    :class="
                        isAllSelected
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-input bg-background'
                    "
                >
                    <svg
                        v-if="isAllSelected"
                        class="h-3 w-3"
                        viewBox="0 0 12 12"
                        fill="none"
                    >
                        <path
                            d="M2 6l3 3 5-5"
                            stroke="currentColor"
                            stroke-width="1.5"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                </div>
                <span class="text-sm font-medium">Tous</span>
            </div>
        </template>
    </MultiSelect>
</template>
