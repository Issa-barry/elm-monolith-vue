<script setup lang="ts">
/**
 * Catégories libres d'une fiche Personne ou Entreprise (ADR 0060, lot 6a) : étiquettes sans effet
 * métier (ex. Partenaire). Le bouton « Modifier » suit `peut_modifier_categories`, calculé par le
 * serveur (contacts.update), qui revérifie la permission et la visibilité de la fiche.
 */
import { Button } from '@/components/ui/button';
import { useForm } from '@inertiajs/vue3';
import { Loader2, Pencil } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import MultiSelect from 'primevue/multiselect';
import { ref } from 'vue';
import type { CategorieFiche, OptionCategorie } from './types';

const props = defineProps<{
    /** URL PUT d'attribution, ex. /backoffice/contacts/personnes/{id}/categories */
    url: string;
    categories: CategorieFiche[];
    options: OptionCategorie[];
    peutModifier: boolean;
}>();

const visible = ref(false);
const form = useForm<{ categorie_ids: string[] }>({ categorie_ids: [] });

function ouvrir() {
    form.categorie_ids = props.categories.map((c) => c.id);
    form.clearErrors();
    visible.value = true;
}

function fermer(v: boolean) {
    if (!form.processing) visible.value = v;
}

function enregistrer() {
    if (form.processing) return;
    form.put(props.url, {
        preserveScroll: true,
        onSuccess: () => (visible.value = false),
    });
}
</script>

<template>
    <div data-testid="fiche-categories">
        <div class="mb-3 flex items-center justify-between gap-3">
            <h2
                class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
            >
                Catégories
            </h2>
            <Button
                v-if="peutModifier"
                variant="outline"
                size="sm"
                data-testid="fiche-categories-modifier"
                @click="ouvrir"
            >
                <Pencil class="mr-1.5 h-3.5 w-3.5" />
                Modifier
            </Button>
        </div>
        <div v-if="categories.length > 0" class="flex flex-wrap gap-2">
            <span
                v-for="c in categories"
                :key="c.id"
                class="inline-flex items-center rounded-md border bg-muted/40 px-2 py-0.5 text-xs font-medium"
                :class="{ 'text-muted-foreground': !c.is_active }"
                data-testid="fiche-categorie"
            >
                {{ c.libelle }}
                <template v-if="!c.is_active"> (désactivée)</template>
            </span>
        </div>
        <p v-else class="text-sm text-muted-foreground">Aucune catégorie.</p>

        <Dialog
            :visible="visible"
            modal
            header="Catégories de la fiche"
            :closable="!form.processing"
            :close-on-escape="!form.processing"
            :draggable="false"
            :style="{ width: 'min(30rem, 94vw)' }"
            @update:visible="fermer"
        >
            <p class="mb-3 text-sm text-muted-foreground">
                Étiquettes sans effet sur les ventes, les commissions ou la
                comptabilité. Une catégorie désactivée peut être gardée mais
                plus ajoutée.
            </p>
            <MultiSelect
                v-model="form.categorie_ids"
                :options="options"
                option-label="label"
                option-value="value"
                :option-disabled="
                    (o: OptionCategorie) =>
                        !o.actif && !form.categorie_ids.includes(o.value)
                "
                display="chip"
                placeholder="Choisir des catégories"
                class="w-full"
                data-testid="fiche-categories-choix"
            />
            <p
                v-if="form.errors.categorie_ids"
                class="mt-2 text-xs text-destructive"
            >
                {{ form.errors.categorie_ids }}
            </p>
            <template #footer>
                <Button
                    variant="outline"
                    :disabled="form.processing"
                    @click="fermer(false)"
                >
                    Annuler
                </Button>
                <Button
                    :disabled="form.processing"
                    data-testid="fiche-categories-enregistrer"
                    @click="enregistrer"
                >
                    <Loader2
                        v-if="form.processing"
                        class="mr-1.5 h-4 w-4 animate-spin"
                    />
                    Enregistrer
                </Button>
            </template>
        </Dialog>
    </div>
</template>
