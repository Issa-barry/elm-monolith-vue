<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight, Image, Pencil } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import { computed, reactive, ref } from 'vue';

export interface VarianteOption {
    option: string;
    valeur: string;
}

export interface Variante {
    id: string;
    libelle: string;
    sku: string | null;
    code_barres?: string | null;
    prix_vente: number | null;
    is_default: boolean;
    is_active: boolean;
    options: VarianteOption[];
    image_url?: string | null;
}

export interface MediaOption {
    id: string;
    thumb_url: string | null;
    url: string;
}

const props = withDefaults(
    defineProps<{
        variantes: Variante[];
        editable?: boolean;
        /** Galerie du produit + son id — nécessaires pour l'assignation d'image par groupe.
         * Absents = pas de miniature ni d'action "Associer une image" (mode simple, ex: dans
         * ProduitForm.vue en édition, qui ne charge pas encore les médias). */
        medias?: MediaOption[];
        produitId?: string;
    }>(),
    { editable: true, medias: () => [] },
);

const emit = defineEmits<{
    'edit-variante': [Variante];
}>();

const peutAssignerImage = computed(
    () => props.editable && !!props.produitId && props.medias.length > 0,
);

function imageGroupe(variantes: Variante[]): string | null {
    return variantes.find((v) => v.image_url)?.image_url ?? null;
}

const groupeAssignation = ref<string | null>(null);

function ouvrirAssignation(cle: string) {
    groupeAssignation.value = cle;
}

function assignerImage(media: MediaOption) {
    const groupe = groupes.value.find((g) => g.cle === groupeAssignation.value);
    if (!groupe || !props.produitId) return;

    router.post(
        `/backoffice/produits/${props.produitId}/medias/${media.id}/variantes`,
        { variante_ids: groupe.variantes.map((v) => v.id) },
        {
            preserveScroll: true,
            onSuccess: () => (groupeAssignation.value = null),
        },
    );
}

function formatPrice(val: number | null): string {
    if (val === null || val === undefined) return '—';
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

// Noms d'options distincts, dans l'ordre de position déjà appliqué côté backend
// (ProduitController::varianteOptions()) — sert à peupler le sélecteur "Regrouper par".
const optionNames = computed(() => {
    const noms: string[] = [];
    for (const v of props.variantes) {
        for (const o of v.options) {
            if (!noms.includes(o.option)) noms.push(o.option);
        }
    }

    return noms;
});

// Le regroupement n'a de sens qu'à partir de 2 options (sinon le groupe == la variante
// elle-même) — avec une seule option, on retombe sur une liste plate classique.
const isGroupable = computed(() => optionNames.value.length >= 2);

const groupBy = ref<string | null>(null);
const groupByActif = computed(
    () => groupBy.value ?? optionNames.value[0] ?? null,
);

const groupes = computed(() => {
    if (!isGroupable.value || !groupByActif.value) return [];

    const parCle = new Map<string, Variante[]>();
    for (const v of props.variantes) {
        const cle =
            v.options.find((o) => o.option === groupByActif.value)?.valeur ??
            '—';
        if (!parCle.has(cle)) parCle.set(cle, []);
        parCle.get(cle)!.push(v);
    }

    return Array.from(parCle.entries()).map(([cle, variantes]) => ({
        cle,
        variantes,
    }));
});

function sousLibelle(v: Variante): string {
    return (
        v.options
            .filter((o) => o.option !== groupByActif.value)
            .map((o) => o.valeur)
            .join(' / ') || (v.is_default ? 'Variante par défaut' : v.libelle)
    );
}

const groupesOuverts = reactive(new Set<string>());

function toggleGroupe(cle: string) {
    if (groupesOuverts.has(cle)) groupesOuverts.delete(cle);
    else groupesOuverts.add(cle);
}

function prixGroupe(variantes: Variante[]): string {
    const prix = [...new Set(variantes.map((v) => v.prix_vente))];

    return prix.length === 1 ? formatPrice(prix[0]) : '—';
}
</script>

<template>
    <div>
        <div v-if="isGroupable" class="mb-3 flex items-center gap-2">
            <span class="text-xs text-muted-foreground">Regrouper par</span>
            <Dropdown
                v-model="groupBy"
                :options="optionNames"
                :placeholder="optionNames[0]"
                class="w-40"
            />
        </div>

        <!-- Vue groupée (2+ options) ─────────────────────────────────────── -->
        <div
            v-if="isGroupable"
            class="divide-y divide-border/50 rounded-lg border"
        >
            <div v-for="groupe in groupes" :key="groupe.cle">
                <div
                    class="flex w-full items-center justify-between gap-3 px-3 py-2.5 hover:bg-muted/40"
                >
                    <button
                        type="button"
                        class="flex flex-1 items-center gap-2 text-left"
                        @click="toggleGroupe(groupe.cle)"
                    >
                        <ChevronDown
                            v-if="groupesOuverts.has(groupe.cle)"
                            class="h-4 w-4 shrink-0 text-muted-foreground"
                        />
                        <ChevronRight
                            v-else
                            class="h-4 w-4 shrink-0 text-muted-foreground"
                        />
                        <img
                            v-if="imageGroupe(groupe.variantes)"
                            :src="imageGroupe(groupe.variantes)!"
                            class="h-8 w-8 shrink-0 rounded-md border object-cover"
                            alt=""
                        />
                        <span class="font-medium">{{ groupe.cle }}</span>
                        <span class="text-xs text-muted-foreground"
                            >{{ groupe.variantes.length }} variante{{
                                groupe.variantes.length > 1 ? 's' : ''
                            }}</span
                        >
                    </button>
                    <button
                        v-if="peutAssignerImage"
                        type="button"
                        title="Associer une image à ce groupe"
                        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                        @click="ouvrirAssignation(groupe.cle)"
                    >
                        <Image class="h-3.5 w-3.5" />
                    </button>
                    <span class="text-sm font-semibold tabular-nums">{{
                        prixGroupe(groupe.variantes)
                    }}</span>
                </div>

                <div
                    v-if="groupesOuverts.has(groupe.cle)"
                    class="overflow-x-auto border-t bg-muted/10"
                >
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-border/50">
                            <tr v-for="v in groupe.variantes" :key="v.id">
                                <td class="py-2 pr-4 pl-9">
                                    {{ sousLibelle(v) }}
                                </td>
                                <td
                                    class="py-2 pr-4 font-mono text-xs text-muted-foreground"
                                >
                                    {{ v.sku || '—' }}
                                </td>
                                <td
                                    class="py-2 pr-4 text-right font-semibold tabular-nums"
                                >
                                    {{ formatPrice(v.prix_vente) }}
                                </td>
                                <td class="py-2 pr-4">
                                    <StatusDot
                                        :status="
                                            v.is_active ? 'actif' : 'inactif'
                                        "
                                        :label="
                                            v.is_active ? 'Actif' : 'Inactif'
                                        "
                                    />
                                </td>
                                <td class="py-2 pr-3 text-right">
                                    <Button
                                        v-if="editable"
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        @click="emit('edit-variante', v)"
                                    >
                                        <Pencil class="mr-1.5 h-3.5 w-3.5" />
                                        Modifier
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Vue plate (1 seule option) ───────────────────────────────────── -->
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-xs text-muted-foreground">
                        <th class="pr-4 pb-2 text-left font-medium">
                            Variante
                        </th>
                        <th class="pr-4 pb-2 text-left font-medium">
                            Référence
                        </th>
                        <th class="pr-4 pb-2 text-right font-medium">
                            Prix vente
                        </th>
                        <th class="pr-4 pb-2 text-left font-medium">Statut</th>
                        <th class="pb-2 text-right font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/50">
                    <tr v-for="v in variantes" :key="v.id">
                        <td class="py-2.5 pr-4 font-medium">
                            {{ v.libelle || 'Variante par défaut' }}
                        </td>
                        <td
                            class="py-2.5 pr-4 font-mono text-xs text-muted-foreground"
                        >
                            {{ v.sku || '—' }}
                        </td>
                        <td
                            class="py-2.5 pr-4 text-right font-semibold tabular-nums"
                        >
                            {{ formatPrice(v.prix_vente) }}
                        </td>
                        <td class="py-2.5 pr-4">
                            <StatusDot
                                :status="v.is_active ? 'actif' : 'inactif'"
                                :label="v.is_active ? 'Actif' : 'Inactif'"
                            />
                        </td>
                        <td class="py-2.5 text-right">
                            <Button
                                v-if="editable"
                                type="button"
                                variant="ghost"
                                size="sm"
                                @click="emit('edit-variante', v)"
                            >
                                <Pencil class="mr-1.5 h-3.5 w-3.5" />
                                Modifier
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Assignation d'image à un groupe -->
        <Dialog
            :visible="groupeAssignation !== null"
            modal
            header="Associer une image au groupe"
            :style="{ width: 'min(28rem, 95vw)' }"
            :draggable="false"
            @update:visible="(v) => !v && (groupeAssignation = null)"
        >
            <p class="mb-3 text-xs text-muted-foreground">
                Appliquée à toutes les variantes « {{ groupeAssignation }} ».
            </p>
            <div class="grid grid-cols-4 gap-2">
                <button
                    v-for="m in medias"
                    :key="m.id"
                    type="button"
                    class="aspect-square overflow-hidden rounded-md border-2 border-transparent hover:border-primary"
                    @click="assignerImage(m)"
                >
                    <img
                        :src="m.thumb_url ?? m.url"
                        class="h-full w-full object-cover"
                        alt=""
                    />
                </button>
            </div>
        </Dialog>
    </div>
</template>
