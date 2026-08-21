<script setup lang="ts">
import { Plus } from 'lucide-vue-next';
import Dropdown from 'primevue/dropdown';
import { ref } from 'vue';
import CreateFonctionRhModal from './CreateFonctionRhModal.vue';

export interface FonctionRhOption {
    value: string;
    label: string;
}

defineProps<{
    modelValue: string | null;
    fonctions: FonctionRhOption[];
    invalid?: boolean;
    placeholder?: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [string | null];
}>();

const showCreateModal = ref(false);

function onCreated(fonctionRhId: string) {
    emit('update:modelValue', fonctionRhId);
    showCreateModal.value = false;
}
</script>

<template>
    <div>
        <Dropdown
            :model-value="modelValue"
            @update:model-value="emit('update:modelValue', $event)"
            :options="fonctions"
            option-label="label"
            option-value="value"
            filter
            filter-placeholder="Rechercher une fonction…"
            show-clear
            :placeholder="placeholder ?? 'Aucune fonction'"
            class="w-full"
            :class="{ 'p-invalid': invalid }"
        >
            <template #empty>
                <div
                    class="px-3 py-3 text-center text-sm text-muted-foreground"
                >
                    Aucune fonction RH créée
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
                        Créer une fonction
                    </button>
                </div>
            </template>
        </Dropdown>

        <CreateFonctionRhModal
            v-model:visible="showCreateModal"
            @created="onCreated"
        />
    </div>
</template>
