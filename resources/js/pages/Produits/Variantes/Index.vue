<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Save } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { useToast } from 'primevue/usetoast';
import { computed, reactive, ref } from 'vue';

interface VarianteOption {
    option: string;
    valeur: string;
}

interface VarianteRow {
    id: string;
    libelle: string;
    sku: string | null;
    code_barres: string | null;
    prix_usine: number | null;
    prix_usine_tricycle: number | null;
    prix_vente: number | null;
    prix_achat: number | null;
    cout: number | null;
    is_default: boolean;
    is_active: boolean;
    options: VarianteOption[];
}

const props = defineProps<{
    produit: {
        id: string;
        nom: string;
        type_nom: string | null;
        prix_usine_requis: boolean;
    };
    variantes: VarianteRow[];
}>();

const toast = useToast();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Produits', href: '/backoffice/produits' },
    {
        title: props.produit.nom,
        href: `/backoffice/produits/${props.produit.id}`,
    },
    { title: 'Variantes', href: '#' },
];

const prixUsineRequis = computed(() => props.produit.prix_usine_requis);

// ── État éditable ────────────────────────────────────────────────────────────
// Copie locale éditable + snapshot d'origine pour calculer le diff à l'enregistrement
// (on n'envoie au serveur que les lignes réellement modifiées, cf. variantesBulkUpdate()).
const rows = reactive<VarianteRow[]>(props.variantes.map((v) => ({ ...v })));
const original = new Map(props.variantes.map((v) => [v.id, { ...v }]));

function estModifiee(row: VarianteRow): boolean {
    const orig = original.get(row.id);
    if (!orig) return false;

    return (
        row.code_barres !== orig.code_barres ||
        row.prix_usine !== orig.prix_usine ||
        row.prix_usine_tricycle !== orig.prix_usine_tricycle ||
        row.prix_vente !== orig.prix_vente ||
        row.prix_achat !== orig.prix_achat ||
        row.cout !== orig.cout ||
        row.is_active !== orig.is_active
    );
}

const nbModifiees = computed(() => rows.filter(estModifiee).length);

// ── Sélection ────────────────────────────────────────────────────────────────
const selection = reactive(new Set<string>());
const toutSelectionne = computed(
    () => rows.length > 0 && selection.size === rows.length,
);

function toggleTout() {
    if (toutSelectionne.value) {
        selection.clear();
    } else {
        rows.forEach((r) => selection.add(r.id));
    }
}

function toggleLigne(id: string) {
    if (selection.has(id)) selection.delete(id);
    else selection.add(id);
}

// ── Modification groupée ────────────────────────────────────────────────────
const showBulkPrix = ref(false);
const showBulkStatut = ref(false);
const bulkPrixValeur = ref<number | null>(null);
const bulkStatutValeur = ref<'actif' | 'inactif'>('actif');

function appliquerPrixGroupe() {
    if (bulkPrixValeur.value === null) return;
    rows.forEach((r) => {
        if (selection.has(r.id)) r.prix_vente = bulkPrixValeur.value;
    });
    showBulkPrix.value = false;
    bulkPrixValeur.value = null;
}

function appliquerStatutGroupe() {
    rows.forEach((r) => {
        if (selection.has(r.id))
            r.is_active = bulkStatutValeur.value === 'actif';
    });
    showBulkStatut.value = false;
}

// ── Enregistrement ───────────────────────────────────────────────────────────
const processing = ref(false);

function enregistrer() {
    const modifiees = rows.filter(estModifiee);
    if (modifiees.length === 0) return;

    processing.value = true;
    router.put(
        `/backoffice/produits/${props.produit.id}/variantes`,
        {
            variantes: modifiees.map((r) => ({
                id: r.id,
                code_barres: r.code_barres,
                prix_usine: r.prix_usine,
                prix_usine_tricycle: r.prix_usine_tricycle,
                prix_vente: r.prix_vente,
                prix_achat: r.prix_achat,
                cout: r.cout,
                is_active: r.is_active,
            })),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                modifiees.forEach((r) => original.set(r.id, { ...r }));
                toast.add({
                    severity: 'success',
                    summary: 'Variantes mises à jour',
                    detail: `${modifiees.length} variante(s) enregistrée(s).`,
                    life: 3000,
                });
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="`Variantes — ${produit.nom}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-6xl space-y-4 p-4 sm:p-6">
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <Link
                        :href="`/backoffice/produits/${produit.id}`"
                        class="inline-flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    >
                        <ArrowLeft class="h-4 w-4" />
                    </Link>
                    <div>
                        <h1 class="text-xl font-semibold tracking-tight">
                            Gestion des variantes
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            {{ produit.nom }} — {{ rows.length }} variante(s)
                        </p>
                    </div>
                </div>
                <Button
                    :disabled="nbModifiees === 0 || processing"
                    @click="enregistrer"
                >
                    <Save class="mr-2 h-4 w-4" />
                    {{
                        processing
                            ? 'Enregistrement…'
                            : nbModifiees > 0
                              ? `Enregistrer (${nbModifiees})`
                              : 'Enregistrer'
                    }}
                </Button>
            </div>

            <!-- Barre d'action groupée -->
            <div
                v-if="selection.size > 0"
                class="flex items-center gap-3 rounded-lg border bg-muted/40 px-4 py-2.5"
            >
                <span class="text-sm font-medium"
                    >{{ selection.size }} sélectionnée(s)</span
                >
                <div class="flex gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        @click="showBulkPrix = true"
                    >
                        Modifier le prix
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="showBulkStatut = true"
                    >
                        Modifier le statut
                    </Button>
                </div>
                <Button
                    variant="ghost"
                    size="sm"
                    class="ml-auto"
                    @click="selection.clear()"
                >
                    Désélectionner
                </Button>
            </div>

            <!-- Tableau dense -->
            <div class="overflow-x-auto rounded-xl border bg-card">
                <table class="w-full text-xs">
                    <thead>
                        <tr
                            class="border-b bg-muted/30 text-[11px] font-semibold text-muted-foreground uppercase"
                        >
                            <th class="w-10 py-2 pl-3">
                                <Checkbox
                                    :model-value="toutSelectionne"
                                    @update:model-value="toggleTout"
                                />
                            </th>
                            <th class="px-2 py-2 text-left">Variante</th>
                            <th class="px-2 py-2 text-left">Référence</th>
                            <th class="px-2 py-2 text-left">Code-barres</th>
                            <th
                                v-if="prixUsineRequis"
                                class="px-2 py-2 text-right"
                            >
                                Prix usine — Autres véhicules
                            </th>
                            <th
                                v-if="prixUsineRequis"
                                class="px-2 py-2 text-right"
                            >
                                Prix usine — Tricycle
                            </th>
                            <th class="px-2 py-2 text-right">Prix achat</th>
                            <th class="px-2 py-2 text-right">Prix vente</th>
                            <th class="px-2 py-2 text-right">Coût</th>
                            <th class="px-2 py-2 text-left">Statut</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/50">
                        <tr
                            v-for="row in rows"
                            :key="row.id"
                            class="[&>td]:py-1.5"
                            :class="{ 'bg-primary/5': estModifiee(row) }"
                        >
                            <td class="pl-3">
                                <Checkbox
                                    :model-value="selection.has(row.id)"
                                    @update:model-value="toggleLigne(row.id)"
                                />
                            </td>
                            <td class="px-2 font-medium whitespace-nowrap">
                                {{ row.libelle || 'Variante par défaut' }}
                            </td>
                            <td
                                class="px-2 font-mono whitespace-nowrap text-muted-foreground"
                            >
                                {{ row.sku || '—' }}
                            </td>
                            <td class="px-2">
                                <InputText
                                    v-model="row.code_barres"
                                    class="h-7 w-32 text-xs"
                                />
                            </td>
                            <td v-if="prixUsineRequis" class="px-2">
                                <InputNumber
                                    v-model="row.prix_usine"
                                    :min="0"
                                    :use-grouping="true"
                                    locale="fr-GN"
                                    class="w-28"
                                    input-class="h-7 w-28 text-xs text-right"
                                />
                            </td>
                            <td v-if="prixUsineRequis" class="px-2">
                                <InputNumber
                                    v-model="row.prix_usine_tricycle"
                                    :min="0"
                                    :use-grouping="true"
                                    locale="fr-GN"
                                    class="w-28"
                                    input-class="h-7 w-28 text-xs text-right"
                                />
                            </td>
                            <td class="px-2">
                                <InputNumber
                                    v-model="row.prix_achat"
                                    :min="0"
                                    :use-grouping="true"
                                    locale="fr-GN"
                                    class="w-28"
                                    input-class="h-7 w-28 text-xs text-right"
                                />
                            </td>
                            <td class="px-2">
                                <InputNumber
                                    v-model="row.prix_vente"
                                    :min="0"
                                    :use-grouping="true"
                                    locale="fr-GN"
                                    class="w-28"
                                    input-class="h-7 w-28 text-xs text-right"
                                />
                            </td>
                            <td class="px-2">
                                <InputNumber
                                    v-model="row.cout"
                                    :min="0"
                                    :use-grouping="true"
                                    locale="fr-GN"
                                    class="w-24"
                                    input-class="h-7 w-24 text-xs text-right"
                                />
                            </td>
                            <td class="px-2">
                                <div class="flex items-center gap-1.5">
                                    <Checkbox
                                        :model-value="row.is_active"
                                        @update:model-value="
                                            (v) => (row.is_active = v === true)
                                        "
                                    />
                                    <span class="text-muted-foreground">{{
                                        row.is_active ? 'Actif' : 'Inactif'
                                    }}</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-xs text-muted-foreground">
                Le stock ne se modifie pas ici — utilisez « Ajuster le stock »
                depuis la fiche produit, qui trace chaque mouvement avec un
                motif. Le seuil d'alerte de stock faible se configure au niveau
                du produit (pas par variante) et s'applique à toutes ses
                variantes.
            </p>
        </div>
    </AppLayout>

    <!-- Modification groupée : prix -->
    <Dialog
        v-model:visible="showBulkPrix"
        modal
        header="Modifier le prix de vente"
        :style="{ width: 'min(22rem, 95vw)' }"
        :draggable="false"
    >
        <div class="space-y-1.5">
            <Label for="bulk-prix">Nouveau prix de vente (GNF)</Label>
            <InputNumber
                id="bulk-prix"
                v-model="bulkPrixValeur"
                :min="0"
                :use-grouping="true"
                locale="fr-GN"
                class="w-full"
                input-class="w-full"
                autofocus
            />
            <p class="text-xs text-muted-foreground">
                Appliqué aux {{ selection.size }} variante(s) sélectionnée(s) —
                n'oubliez pas d'enregistrer ensuite.
            </p>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <Button variant="outline" @click="showBulkPrix = false"
                    >Annuler</Button
                >
                <Button
                    :disabled="bulkPrixValeur === null"
                    @click="appliquerPrixGroupe"
                >
                    Appliquer
                </Button>
            </div>
        </template>
    </Dialog>

    <!-- Modification groupée : statut -->
    <Dialog
        v-model:visible="showBulkStatut"
        modal
        header="Modifier le statut"
        :style="{ width: 'min(22rem, 95vw)' }"
        :draggable="false"
    >
        <div class="space-y-1.5">
            <Label for="bulk-statut">Nouveau statut</Label>
            <Dropdown
                id="bulk-statut"
                v-model="bulkStatutValeur"
                :options="[
                    { label: 'Actif', value: 'actif' },
                    { label: 'Inactif', value: 'inactif' },
                ]"
                option-label="label"
                option-value="value"
                class="w-full"
            />
            <p class="text-xs text-muted-foreground">
                Appliqué aux {{ selection.size }} variante(s) sélectionnée(s) —
                n'oubliez pas d'enregistrer ensuite.
            </p>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <Button variant="outline" @click="showBulkStatut = false"
                    >Annuler</Button
                >
                <Button @click="appliquerStatutGroupe">Appliquer</Button>
            </div>
        </template>
    </Dialog>
</template>
