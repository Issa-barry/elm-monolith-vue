<script setup lang="ts">
import { flattenCategorieTree } from '@/lib/categorieTree';
import { Plus } from 'lucide-vue-next';
import Dropdown from 'primevue/dropdown';
import { computed, ref } from 'vue';
import CreateCategorieModal from './CreateCategorieModal.vue';

export interface Categorie {
    id: string;
    nom: string;
    parent_id: string | null;
}

const props = defineProps<{
    modelValue: string | null;
    categories: Categorie[];
    invalid?: boolean;
}>();

const emit = defineEmits<{
    'update:modelValue': [string | null];
}>();

const showCreateModal = ref(false);

const flatOptions = computed(() => flattenCategorieTree(props.categories));

function onCreated(categorieId: string) {
    emit('update:modelValue', categorieId);
    showCreateModal.value = false;
}
</script>

<template>
    <div>
        <Dropdown
            :model-value="modelValue"
            @update:model-value="emit('update:modelValue', $event)"
            :options="flatOptions"
            option-label="label"
            option-value="id"
            filter
            filter-placeholder="Rechercher une catégorie…"
            show-clear
            placeholder="Aucune catégorie"
            class="w-full"
            :class="{ 'p-invalid': invalid }"
        >
            <template #option="{ option }">
                <span :style="{ paddingLeft: `${option.depth * 16}px` }">{{
                    option.displayLabel
                }}</span>
            </template>
            <template #empty>
                <div
                    class="px-3 py-3 text-center text-sm text-muted-foreground"
                >
                    Aucune catégorie créée
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
                        Créer une catégorie
                    </button>
                </div>
            </template>
        </Dropdown>

        <CreateCategorieModal
            v-model:visible="showCreateModal"
            :categories="flatOptions"
            @created="onCreated"
        />
    </div>
</template>
