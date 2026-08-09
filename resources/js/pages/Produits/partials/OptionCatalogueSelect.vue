<script setup lang="ts">
import { Plus } from 'lucide-vue-next';
import Dropdown from 'primevue/dropdown';
import { computed, ref } from 'vue';
import CreateOptionCatalogueModal from './CreateOptionCatalogueModal.vue';

export interface OptionCatalogueValeur {
    id: string;
    valeur: string;
    hex?: string | null;
}

export interface OptionCatalogue {
    id: string;
    nom: string;
    valeurs: OptionCatalogueValeur[];
}

const props = defineProps<{
    modelValue: string | null;
    optionsCatalogue: OptionCatalogue[];
    invalid?: boolean;
}>();

// Les options les plus fréquentes remontent en tête de liste (le champ filtre du Dropdown
// couvre le reste) — évite de noyer "Couleur"/"Taille" au milieu d'une vingtaine d'options
// système (dimensions, connectivité...) pour un usage courant type habillement.
const ORDRE_PRIORITAIRE = ['Couleur', 'Taille', 'Pointure', 'Matière', 'Volume', 'Poids'];

const optionsTriees = computed(() => {
    const priorite = (nom: string) => {
        const i = ORDRE_PRIORITAIRE.indexOf(nom);

        return i === -1 ? ORDRE_PRIORITAIRE.length : i;
    };

    return [...props.optionsCatalogue].sort(
        (a, b) => priorite(a.nom) - priorite(b.nom) || a.nom.localeCompare(b.nom),
    );
});

const emit = defineEmits<{
    'update:modelValue': [string | null];
    /** Émis en plus de update:modelValue avec l'objet complet — pratique pour l'appelant qui
     * a besoin des valeurs proposées sans devoir refaire une recherche par id. */
    selected: [OptionCatalogue | null];
}>();

const showCreateModal = ref(false);

function onChange(id: string | null) {
    emit('update:modelValue', id);
    emit(
        'selected',
        props.optionsCatalogue.find((o) => o.id === id) ?? null,
    );
}

function onCreated(id: string) {
    showCreateModal.value = false;
    // props.optionsCatalogue est déjà rafraîchi par Inertia au moment où onSuccess (dans la
    // modale) déclenche cet événement — même schéma éprouvé que CategorieSelect/CreateCategorieModal.
    onChange(id);
}
</script>

<template>
    <div>
        <Dropdown
            :model-value="modelValue"
            @update:model-value="onChange"
            :options="optionsTriees"
            option-label="nom"
            option-value="id"
            filter
            filter-placeholder="Rechercher une option…"
            show-clear
            placeholder="Choisir une option (Couleur, Taille…)"
            class="w-full"
            :class="{ 'p-invalid': invalid }"
        >
            <template #empty>
                <div
                    class="px-3 py-3 text-center text-sm text-muted-foreground"
                >
                    Aucune option créée
                </div>
            </template>
            <template #footer>
                <div class="border-t p-1">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-primary hover:bg-muted"
                        @click="showCreateModal = true"
                    >
                        <Plus class="h-4 w-4" />
                        Créer une option
                    </button>
                </div>
            </template>
        </Dropdown>

        <CreateOptionCatalogueModal
            v-model:visible="showCreateModal"
            @created="onCreated"
        />
    </div>
</template>
