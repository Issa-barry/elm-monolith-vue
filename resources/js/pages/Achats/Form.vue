<script setup lang="ts">
import CreateFournisseurModal from '@/components/fournisseurs/CreateFournisseurModal.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Save, Trash2 } from 'lucide-vue-next';
import InputNumber, { type InputNumberInputEvent } from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import { computed, ref } from 'vue';

interface VarianteOption {
    id: string;
    label: string;
    prix_achat: number;
}

interface LigneForm {
    variante_id: string | null;
    qte: number;
    prix_achat: number;
}

interface CommandeEdition {
    id: string;
    reference: string;
    site_id: string | null;
    fournisseur_id: string | null;
    note: string | null;
    lignes: LigneForm[];
}

const props = defineProps<{
    commande: CommandeEdition | null;
    variantes: VarianteOption[];
    fournisseurs: { id: string; nom: string }[];
    sites: { id: string; nom: string }[];
    site_par_defaut: string | null;
}>();

const enEdition = computed(() => props.commande !== null);
const titre = computed(() =>
    props.commande
        ? `Modifier ${props.commande.reference}`
        : 'Nouveau bon de commande',
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Achats', href: '/backoffice/achats' },
    {
        title: props.commande?.reference ?? 'Nouveau bon de commande',
        href: '#',
    },
];

function siteInitial(): string | null {
    if (props.commande) return props.commande.site_id;
    if (
        props.site_par_defaut &&
        props.sites.some((s) => s.id === props.site_par_defaut)
    )
        return props.site_par_defaut;
    return props.sites.length === 1 ? props.sites[0].id : null;
}

const form = useForm({
    site_id: siteInitial(),
    fournisseur_id: props.commande?.fournisseur_id ?? null,
    note: props.commande?.note ?? '',
    lignes: (props.commande?.lignes.length
        ? props.commande.lignes.map((l) => ({ ...l }))
        : [{ variante_id: null, qte: 1, prix_achat: 0 }]) as LigneForm[],
});

const { can } = usePermissions();

const fournisseurSelect = ref<{ hide: () => void } | null>(null);
const rechercheFournisseur = ref('');
const creationFournisseurVisible = ref(false);

// Création rapide (même route que le formulaire Produit) : le fournisseur créé revient dans la
// liste au rechargement des props et est sélectionné via l'événement created.
function ouvrirCreationFournisseur() {
    fournisseurSelect.value?.hide();
    creationFournisseurVisible.value = true;
}

const retourHref = computed(() =>
    props.commande
        ? `/backoffice/achats/${props.commande.id}`
        : '/backoffice/achats',
);

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

/** Une variante déjà présente sur une autre ligne n'est pas reproposée. */
function optionsPour(index: number): VarianteOption[] {
    const prises = new Set(
        form.lignes
            .filter((l, i) => i !== index && l.variante_id)
            .map((l) => l.variante_id),
    );
    return props.variantes.filter((v) => !prises.has(v.id));
}

function onVarianteChange(index: number, varianteId: string | null) {
    const ligne = form.lignes[index];
    ligne.variante_id = varianteId;
    const variante = props.variantes.find((v) => v.id === varianteId);
    ligne.prix_achat = variante ? variante.prix_achat : 0;
}

// InputNumber ne committe son v-model qu'au blur : l'événement input garde le total à jour pendant la saisie.
function onQteInput(ligne: LigneForm, e: InputNumberInputEvent) {
    ligne.qte = Number(e.value ?? 0);
}

function onPrixInput(ligne: LigneForm, e: InputNumberInputEvent) {
    ligne.prix_achat = Number(e.value ?? 0);
}

function addLigne() {
    form.lignes.push({ variante_id: null, qte: 1, prix_achat: 0 });
}

function removeLigne(index: number) {
    if (form.lignes.length > 1) form.lignes.splice(index, 1);
}

const totalGeneral = computed(() =>
    form.lignes.reduce((sum, l) => sum + (l.qte || 0) * (l.prix_achat || 0), 0),
);

const canSubmit = computed(
    () =>
        !form.processing &&
        !!form.site_id &&
        !!form.fournisseur_id &&
        form.lignes.length > 0 &&
        form.lignes.every((l) => l.variante_id && l.qte > 0),
);

function erreurLigne(index: number, champ: string): string | undefined {
    return (form.errors as Record<string, string>)[`lignes.${index}.${champ}`];
}

function submit() {
    if (!canSubmit.value) return;
    if (props.commande) {
        form.put(`/backoffice/achats/${props.commande.id}`);
    } else {
        form.post('/backoffice/achats');
    }
}
</script>

<template>
    <Head :title="titre" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <div
            class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden"
        >
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link
                    :href="retourHref"
                    class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <h1 class="text-[17px] leading-tight font-semibold">
                    {{ titre }}
                </h1>
            </div>
        </div>

        <div class="mx-auto max-w-5xl p-4 sm:p-6">
            <div class="mb-6 hidden sm:block">
                <h1 class="text-2xl font-semibold tracking-tight">
                    {{ titre }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Le bon de commande devra être validé par un rôle dont le
                    plafond couvre son montant avant de pouvoir être
                    réceptionné.
                </p>
            </div>

            <form class="space-y-6" @submit.prevent="submit">
                <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
                    <h2
                        class="mb-5 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Informations générales
                    </h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <Label
                                for="achat-site"
                                class="mb-1.5 block text-sm"
                            >
                                Agence de livraison
                                <span class="text-destructive">*</span>
                            </Label>
                            <Select
                                v-model="form.site_id"
                                input-id="achat-site"
                                :options="sites"
                                option-label="nom"
                                option-value="id"
                                placeholder="Choisir une agence"
                                class="w-full"
                                :invalid="!!form.errors.site_id"
                            />
                            <p
                                v-if="form.errors.site_id"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.site_id }}
                            </p>
                        </div>
                        <div>
                            <Label
                                for="achat-fournisseur"
                                class="mb-1.5 block text-sm"
                            >
                                Fournisseur
                                <span class="text-destructive">*</span>
                            </Label>
                            <Select
                                ref="fournisseurSelect"
                                v-model="form.fournisseur_id"
                                input-id="achat-fournisseur"
                                :options="fournisseurs"
                                option-label="nom"
                                option-value="id"
                                placeholder="Choisir un fournisseur"
                                filter
                                filter-placeholder="Rechercher un fournisseur…"
                                class="w-full"
                                :invalid="!!form.errors.fournisseur_id"
                                @filter="rechercheFournisseur = $event.value"
                                @show="rechercheFournisseur = ''"
                            >
                                <template #empty>
                                    <div
                                        class="px-3 py-2 text-sm text-muted-foreground"
                                    >
                                        Aucun fournisseur enregistré.
                                    </div>
                                </template>
                                <template #emptyfilter>
                                    <div
                                        class="px-3 py-2 text-sm text-muted-foreground"
                                    >
                                        Aucun fournisseur ne correspond à «
                                        {{ rechercheFournisseur }} ».
                                    </div>
                                </template>
                                <template
                                    v-if="can('fournisseurs.create')"
                                    #footer
                                >
                                    <div class="border-t p-1">
                                        <button
                                            type="button"
                                            class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-primary hover:bg-muted"
                                            @click="ouvrirCreationFournisseur"
                                        >
                                            <Plus class="h-4 w-4" />
                                            {{
                                                rechercheFournisseur.trim()
                                                    ? `Créer « ${rechercheFournisseur.trim()} »`
                                                    : 'Créer un fournisseur'
                                            }}
                                        </button>
                                    </div>
                                </template>
                            </Select>
                            <CreateFournisseurModal
                                v-model:visible="creationFournisseurVisible"
                                :nom-initial="rechercheFournisseur.trim()"
                                @created="form.fournisseur_id = $event"
                            />
                            <p
                                v-if="form.errors.fournisseur_id"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.fournisseur_id }}
                            </p>
                        </div>
                        <div class="sm:col-span-2">
                            <Label for="achat-note" class="mb-1.5 block text-sm"
                                >Note</Label
                            >
                            <InputText
                                id="achat-note"
                                v-model="form.note"
                                placeholder="Référence fournisseur, commentaire…"
                                class="w-full"
                            />
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
                    <h2
                        class="mb-5 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Produits à commander
                    </h2>

                    <p
                        v-if="form.errors.lignes"
                        class="mb-3 text-xs text-destructive"
                    >
                        {{ form.errors.lignes }}
                    </p>

                    <div class="space-y-3">
                        <div
                            v-for="(ligne, index) in form.lignes"
                            :key="index"
                            class="grid grid-cols-2 gap-3 rounded-lg border bg-muted/10 p-3 sm:grid-cols-[1fr_110px_170px_150px_40px] sm:items-start sm:border-0 sm:bg-transparent sm:p-0"
                        >
                            <div class="col-span-2 sm:col-span-1">
                                <Label
                                    :for="`ligne-variante-${index}`"
                                    class="mb-1 block text-xs text-muted-foreground sm:sr-only"
                                    >Produit</Label
                                >
                                <Select
                                    :model-value="ligne.variante_id"
                                    :input-id="`ligne-variante-${index}`"
                                    :options="optionsPour(index)"
                                    option-label="label"
                                    option-value="id"
                                    placeholder="Choisir un produit…"
                                    filter
                                    class="w-full"
                                    :invalid="
                                        !!erreurLigne(index, 'variante_id')
                                    "
                                    @update:model-value="
                                        onVarianteChange(index, $event)
                                    "
                                />
                                <p
                                    v-if="erreurLigne(index, 'variante_id')"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ erreurLigne(index, 'variante_id') }}
                                </p>
                            </div>
                            <div>
                                <Label
                                    :for="`ligne-qte-${index}`"
                                    class="mb-1 block text-xs text-muted-foreground sm:sr-only"
                                    >Quantité</Label
                                >
                                <InputNumber
                                    v-model="ligne.qte"
                                    :input-id="`ligne-qte-${index}`"
                                    :min="1"
                                    :use-grouping="false"
                                    class="w-full"
                                    input-class="w-full text-center"
                                    @input="onQteInput(ligne, $event)"
                                />
                            </div>
                            <div>
                                <Label
                                    :for="`ligne-prix-${index}`"
                                    class="mb-1 block text-xs text-muted-foreground sm:sr-only"
                                    >Prix d'achat unitaire</Label
                                >
                                <InputNumber
                                    v-model="ligne.prix_achat"
                                    :input-id="`ligne-prix-${index}`"
                                    :min="0"
                                    suffix=" GNF"
                                    class="w-full"
                                    input-class="w-full text-right"
                                    @input="onPrixInput(ligne, $event)"
                                />
                            </div>
                            <div
                                class="flex items-center justify-end text-right font-medium tabular-nums sm:h-10"
                            >
                                {{ formatGNF(ligne.qte * ligne.prix_achat) }}
                            </div>
                            <div class="flex items-center justify-end sm:h-10">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="h-8 w-8 text-destructive hover:text-destructive"
                                    :disabled="form.lignes.length <= 1"
                                    :aria-label="`Retirer la ligne ${index + 1}`"
                                    @click="removeLigne(index)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    </div>

                    <div
                        class="mt-4 flex flex-wrap items-center justify-between gap-3"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="form.lignes.length >= variantes.length"
                            @click="addLigne"
                        >
                            <Plus class="mr-2 h-4 w-4" />
                            Ajouter une ligne
                        </Button>
                        <div class="text-right">
                            <p
                                class="text-xs tracking-wider text-muted-foreground uppercase"
                            >
                                Total commande
                            </p>
                            <p class="text-2xl font-bold tabular-nums">
                                {{ formatGNF(totalGeneral) }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="h-20 sm:hidden" />

                <div class="hidden items-center justify-between sm:flex">
                    <Link :href="retourHref">
                        <Button type="button" variant="outline">Retour</Button>
                    </Link>
                    <Button type="submit" :disabled="!canSubmit">
                        <i
                            v-if="form.processing"
                            class="pi pi-spin pi-spinner mr-2"
                        />
                        <Save v-else class="mr-2 h-4 w-4" />
                        {{
                            enEdition
                                ? 'Enregistrer les modifications'
                                : 'Enregistrer le bon de commande'
                        }}
                    </Button>
                </div>
            </form>
        </div>

        <div
            class="fixed right-0 bottom-0 left-0 z-20 border-t border-border/60 bg-background/95 px-4 py-3 backdrop-blur-sm sm:hidden"
        >
            <Button class="w-full" :disabled="!canSubmit" @click="submit">
                <i v-if="form.processing" class="pi pi-spin pi-spinner mr-2" />
                <Save v-else class="mr-2 h-4 w-4" />
                {{
                    enEdition ? 'Enregistrer' : 'Enregistrer le bon de commande'
                }}
            </Button>
        </div>
    </AppLayout>
</template>
