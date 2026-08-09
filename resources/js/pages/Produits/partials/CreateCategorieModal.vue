<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { type FlatCategorieOption } from '@/lib/categorieTree';
import { useForm, usePage } from '@inertiajs/vue3';
import { Layers } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputText from 'primevue/inputtext';
import Textarea from 'primevue/textarea';
import { computed } from 'vue';

const props = defineProps<{
    visible: boolean;
    /** Catégories déjà aplaties (avec chemin complet) par l'appelant — réutilisées ici pour
     * le choix du parent, afin de ne pas dupliquer la logique d'aplatissement. */
    categories: FlatCategorieOption[];
}>();

const emit = defineEmits<{
    'update:visible': [boolean];
    /** Émis avec l'id de la catégorie tout juste créée, pour sélection automatique. */
    created: [string];
}>();

const localVisible = computed({
    get: () => props.visible,
    set: (val: boolean) => emit('update:visible', val),
});

const form = useForm({
    nom: '',
    parent_id: null as string | null,
    description: null as string | null,
});

function close() {
    localVisible.value = false;
    form.reset();
    form.clearErrors();
}

function submit() {
    form.post('/backoffice/produits/categories', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            // Flashée par CategorieController::store() — cf. HandleInertiaRequests::share().
            const page = usePage();
            const createdId = (page.props as any).flash
                ?.created_categorie_id as string | undefined;
            close();
            if (createdId) emit('created', createdId);
        },
    });
}
</script>

<template>
    <Dialog
        v-model:visible="localVisible"
        modal
        header="Créer une catégorie"
        :style="{ width: '28rem' }"
        :draggable="false"
        @hide="
            form.reset();
            form.clearErrors();
        "
    >
        <div class="space-y-4">
            <div class="space-y-1.5">
                <Label for="categorie-nom"
                    >Nom <span class="text-destructive">*</span></Label
                >
                <InputText
                    id="categorie-nom"
                    v-model="form.nom"
                    class="w-full"
                    :class="form.errors.nom ? 'p-invalid' : ''"
                    autofocus
                    @keyup.enter="submit"
                />
                <p v-if="form.errors.nom" class="text-xs text-destructive">
                    {{ form.errors.nom }}
                </p>
            </div>

            <div class="space-y-1.5">
                <Label for="categorie-parent">Catégorie parente</Label>
                <Dropdown
                    v-model="form.parent_id"
                    input-id="categorie-parent"
                    :options="categories"
                    option-label="label"
                    option-value="id"
                    filter
                    show-clear
                    placeholder="Aucune (catégorie racine)"
                    class="w-full"
                    :class="form.errors.parent_id ? 'p-invalid' : ''"
                >
                    <template #option="{ option }">
                        <span
                            :style="{ paddingLeft: `${option.depth * 16}px` }"
                            >{{ option.displayLabel }}</span
                        >
                    </template>
                </Dropdown>
                <p
                    v-if="form.errors.parent_id"
                    class="text-xs text-destructive"
                >
                    {{ form.errors.parent_id }}
                </p>
            </div>

            <div class="space-y-1.5">
                <Label for="categorie-description">Description</Label>
                <Textarea
                    id="categorie-description"
                    v-model="form.description"
                    placeholder="Facultatif"
                    rows="2"
                    class="w-full"
                    auto-resize
                />
            </div>

            <div
                v-if="categories.length === 0"
                class="flex items-start gap-2 rounded-lg bg-muted/40 p-3 text-xs text-muted-foreground"
            >
                <Layers class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span
                    >Première catégorie du catalogue — elle sera créée à la
                    racine.</span
                >
            </div>
        </div>

        <template #footer>
            <div class="flex justify-end gap-2">
                <Button variant="outline" @click="close">Annuler</Button>
                <Button
                    :disabled="form.processing || !form.nom.trim()"
                    @click="submit"
                >
                    Créer
                </Button>
            </div>
        </template>
    </Dialog>
</template>
