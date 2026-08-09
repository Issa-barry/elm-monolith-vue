<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/vue3';
import { Layers } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { computed, watch } from 'vue';

interface Variante {
    id: string;
    libelle: string;
    sku: string | null;
    code_barres: string | null;
    code_fournisseur: string | null;
    prix_usine: number | null;
    prix_vente: number | null;
    prix_achat: number | null;
    cout: number | null;
    seuil_alerte_stock: number | null;
    is_active: boolean;
    media_id?: string | null;
    image_url?: string | null;
}

interface MediaOption {
    id: string;
    thumb_url: string | null;
    url: string;
}

const props = withDefaults(
    defineProps<{
        visible: boolean;
        produitId: string;
        variante: Variante | null;
        isFabricable: boolean;
        /** Galerie du produit — absente si l'appelant ne gère pas encore les médias. */
        medias?: MediaOption[];
    }>(),
    { medias: () => [] },
);

const emit = defineEmits<{
    'update:visible': [boolean];
}>();

const localVisible = computed({
    get: () => props.visible,
    set: (val: boolean) => emit('update:visible', val),
});

const form = useForm({
    code_barres: null as string | null,
    code_fournisseur: null as string | null,
    prix_usine: null as number | null,
    prix_vente: null as number | null,
    prix_achat: null as number | null,
    cout: null as number | null,
    seuil_alerte_stock: null as number | null,
    is_active: true,
    media_id: null as string | null,
});

// La modale reste montée (v-if géré par le parent au moment de l'ouverture) : resynchroniser
// le form à chaque changement de variante ciblée plutôt qu'une seule fois au montage.
watch(
    () => props.variante,
    (v) => {
        if (!v) return;
        form.code_barres = v.code_barres;
        form.code_fournisseur = v.code_fournisseur;
        form.prix_usine = v.prix_usine;
        form.prix_vente = v.prix_vente;
        form.prix_achat = v.prix_achat;
        form.cout = v.cout;
        form.seuil_alerte_stock = v.seuil_alerte_stock;
        form.is_active = v.is_active;
        form.media_id = v.media_id ?? null;
    },
    { immediate: true },
);

function close() {
    localVisible.value = false;
    form.clearErrors();
}

function submit() {
    if (!props.variante) return;
    form.put(
        `/backoffice/produits/${props.produitId}/variantes/${props.variante.id}`,
        {
            preserveScroll: true,
            onSuccess: () => close(),
        },
    );
}
</script>

<template>
    <Dialog
        v-model:visible="localVisible"
        modal
        header="Modifier la variante"
        :style="{ width: '32rem' }"
        :draggable="false"
        @hide="form.clearErrors()"
    >
        <div
            v-if="variante"
            class="mb-5 flex items-center gap-3 rounded-lg bg-muted/50 px-4 py-3"
        >
            <Layers class="h-5 w-5 shrink-0 text-muted-foreground" />
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold">
                    {{ variante.libelle || 'Variante par défaut' }}
                </p>
                <p
                    v-if="variante.sku"
                    class="font-mono text-xs text-muted-foreground"
                >
                    {{ variante.sku }}
                </p>
            </div>
        </div>

        <div class="space-y-4">
            <div v-if="medias.length > 0" class="space-y-1.5">
                <Label class="block">Image de la variante</Label>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        title="Utiliser l'image principale du produit"
                        class="flex h-14 w-14 items-center justify-center rounded-md border-2 text-[10px] text-muted-foreground"
                        :class="
                            form.media_id === null
                                ? 'border-primary bg-primary/5'
                                : 'border-dashed'
                        "
                        @click="form.media_id = null"
                    >
                        Défaut
                    </button>
                    <button
                        v-for="m in medias"
                        :key="m.id"
                        type="button"
                        class="h-14 w-14 overflow-hidden rounded-md border-2"
                        :class="
                            form.media_id === m.id
                                ? 'border-primary'
                                : 'border-transparent'
                        "
                        @click="form.media_id = m.id"
                    >
                        <img
                            :src="m.thumb_url ?? m.url"
                            class="h-full w-full object-cover"
                            alt=""
                        />
                    </button>
                </div>
            </div>

            <div
                class="grid gap-4"
                :class="isFabricable ? 'grid-cols-2' : 'grid-cols-2'"
            >
                <div v-if="isFabricable" class="space-y-1.5">
                    <Label class="block">Prix usine</Label>
                    <InputNumber
                        v-model="form.prix_usine"
                        :min="0"
                        :use-grouping="true"
                        locale="fr-GN"
                        class="w-full"
                        input-class="w-full"
                        :class="form.errors.prix_usine ? 'p-invalid' : ''"
                    />
                    <p
                        v-if="form.errors.prix_usine"
                        class="text-xs text-destructive"
                    >
                        {{ form.errors.prix_usine }}
                    </p>
                </div>

                <div class="space-y-1.5">
                    <Label class="block">Prix achat</Label>
                    <InputNumber
                        v-model="form.prix_achat"
                        :min="0"
                        :use-grouping="true"
                        locale="fr-GN"
                        class="w-full"
                        input-class="w-full"
                        :class="form.errors.prix_achat ? 'p-invalid' : ''"
                    />
                    <p
                        v-if="form.errors.prix_achat"
                        class="text-xs text-destructive"
                    >
                        {{ form.errors.prix_achat }}
                    </p>
                </div>

                <div class="space-y-1.5">
                    <Label class="block">Prix vente</Label>
                    <InputNumber
                        v-model="form.prix_vente"
                        :min="0"
                        :use-grouping="true"
                        locale="fr-GN"
                        class="w-full"
                        input-class="w-full"
                        :class="form.errors.prix_vente ? 'p-invalid' : ''"
                    />
                    <p
                        v-if="form.errors.prix_vente"
                        class="text-xs text-destructive"
                    >
                        {{ form.errors.prix_vente }}
                    </p>
                </div>

                <div class="space-y-1.5">
                    <Label class="block">Coût de revient</Label>
                    <InputNumber
                        v-model="form.cout"
                        :min="0"
                        :use-grouping="true"
                        locale="fr-GN"
                        class="w-full"
                        input-class="w-full"
                    />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-1.5">
                    <Label class="block">Code-barres</Label>
                    <InputText
                        v-model="form.code_barres"
                        class="w-full font-mono"
                        :class="form.errors.code_barres ? 'p-invalid' : ''"
                    />
                </div>
                <div class="space-y-1.5">
                    <Label class="block">Code fournisseur</Label>
                    <InputText
                        v-model="form.code_fournisseur"
                        class="w-full font-mono"
                    />
                </div>
            </div>

            <div class="space-y-1.5">
                <Label class="block">Seuil d'alerte stock</Label>
                <InputNumber
                    v-model="form.seuil_alerte_stock"
                    :min="0"
                    class="w-full"
                    input-class="w-full"
                />
                <p class="text-xs text-muted-foreground">
                    Laisser vide pour utiliser le seuil global
                </p>
            </div>

            <div class="flex items-center gap-3">
                <Checkbox id="variante-active" v-model="form.is_active" />
                <div>
                    <Label
                        for="variante-active"
                        class="cursor-pointer font-medium"
                        >Variante active</Label
                    >
                    <p class="text-xs text-muted-foreground">
                        Une variante inactive n'est plus proposée à la vente
                    </p>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex justify-end gap-2">
                <Button variant="outline" @click="close">Annuler</Button>
                <Button :disabled="form.processing" @click="submit">
                    Enregistrer
                </Button>
            </div>
        </template>
    </Dialog>
</template>
