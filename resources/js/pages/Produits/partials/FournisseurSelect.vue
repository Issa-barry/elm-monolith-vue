<script setup lang="ts">
import { usePermissions } from '@/composables/usePermissions';
import { Plus } from 'lucide-vue-next';
import Dropdown from 'primevue/dropdown';
import { ref } from 'vue';
import CreateFournisseurModal from './CreateFournisseurModal.vue';

export interface FournisseurOption {
    id: string;
    nom_complet: string;
    phone: string | null;
}

const props = defineProps<{
    modelValue: string | null;
    fournisseurs: FournisseurOption[];
    invalid?: boolean;
}>();

const emit = defineEmits<{
    'update:modelValue': [string | null];
}>();

const { can } = usePermissions();

const showCreateModal = ref(false);

function onCreated(fournisseurId: string) {
    emit('update:modelValue', fournisseurId);
    showCreateModal.value = false;
}
</script>

<template>
    <div>
        <Dropdown
            :model-value="modelValue"
            @update:model-value="emit('update:modelValue', $event)"
            :options="fournisseurs"
            option-label="nom_complet"
            option-value="id"
            filter
            filter-placeholder="Rechercher un fournisseur…"
            show-clear
            placeholder="Aucun fournisseur"
            class="w-full"
            :class="{ 'p-invalid': invalid }"
        >
            <template #value="{ value }">
                <span v-if="value">{{
                    fournisseurs.find((f) => f.id === value)?.nom_complet
                }}</span>
                <span v-else class="text-muted-foreground"
                    >Aucun fournisseur</span
                >
            </template>
            <template #option="{ option }">
                <div class="flex flex-col">
                    <span>{{ option.nom_complet }}</span>
                    <span
                        v-if="option.phone"
                        class="text-xs text-muted-foreground"
                        >{{ option.phone }}</span
                    >
                </div>
            </template>
            <template #empty>
                <div
                    class="px-3 py-3 text-center text-sm text-muted-foreground"
                >
                    Aucun fournisseur créé
                </div>
            </template>
            <template v-if="can('fournisseurs.create')" #footer>
                <div class="border-t p-1">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-primary hover:bg-muted"
                        @click="showCreateModal = true"
                    >
                        <Plus class="h-4 w-4" />
                        Créer un fournisseur
                    </button>
                </div>
            </template>
        </Dropdown>

        <CreateFournisseurModal
            v-model:visible="showCreateModal"
            @created="onCreated"
        />
    </div>
</template>
