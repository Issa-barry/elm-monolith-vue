<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Lock, Plus, Save, Trash2 } from 'lucide-vue-next';
import AutoComplete from 'primevue/autocomplete';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import { computed, ref } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────

interface SiteOption {
    id: number;
    nom: string;
}

interface CapaciteCategorie {
    categorie_id: string;
    categorie_nom: string;
    capacite_max: number;
}

interface VehiculeOption {
    id: number;
    nom_vehicule: string;
    immatriculation: string;
    equipe_livraison_id: number | null;
    equipe_nom: string | null;
    capacites: CapaciteCategorie[];
}

interface EquipeOption {
    id: number;
    nom: string;
}

interface ProduitOption {
    id: number;
    nom: string;
    categorie_id: string | null;
}

interface TransfertData {
    id: number;
    site_source_id: number | null;
    site_destination_id: number | null;
    vehicule_id: number | null;
    equipe_livraison_id: number | null;
    date_depart_prevue: string | null;
    date_arrivee_prevue: string | null;
    notes: string | null;
    lignes: Array<{
        produit_id: number;
        produit_nom: string;
        quantite_demandee: number;
        notes: string | null;
    }>;
}

interface LigneForm {
    produit_id: number | null;
    quantite_demandee: number;
    notes: string;
}

// ── Props ─────────────────────────────────────────────────────────────────────

const props = withDefaults(
    defineProps<{
        site_source: SiteOption | null;
        is_admin?: boolean;
        sites: SiteOption[];
        vehicules: VehiculeOption[];
        equipes: EquipeOption[];
        produits: ProduitOption[];
        transfert?: TransfertData;
    }>(),
    { is_admin: false },
);

const isEditing = computed(() => !!props.transfert);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Logistique', href: '/backoffice/logistique' },
    {
        title: isEditing.value ? 'Modifier le transfert' : 'Nouveau transfert',
        href: isEditing.value
            ? `/backoffice/logistique/${props.transfert?.id}/editer`
            : '/backoffice/logistique/creer',
    },
];

const defaultProduit =
    props.produits.find((p) => p.nom.toLowerCase().includes('pack de 6')) ??
    props.produits[0] ??
    null;

// ── Form ──────────────────────────────────────────────────────────────────────

const pad = (n: number) => String(n).padStart(2, '0');
const today = new Date();
// ISO 8601 (YYYY-MM-DD) — format attendu par la validation Laravel `date`
const todayStr = `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`;

const form = useForm({
    site_source_id: props.site_source?.id ?? (null as number | null),
    site_destination_id:
        props.transfert?.site_destination_id ?? (null as number | null),
    vehicule_id: props.transfert?.vehicule_id ?? (null as number | null),
    equipe_livraison_id:
        props.transfert?.equipe_livraison_id ?? (null as number | null),
    date_depart_prevue: props.transfert?.date_depart_prevue ?? todayStr,
    date_arrivee_prevue: props.transfert?.date_arrivee_prevue ?? todayStr,
    notes: props.transfert?.notes ?? '',
    lignes: (props.transfert?.lignes ?? [
        {
            produit_id: defaultProduit?.id ?? null,
            quantite_demandee: 1,
            notes: '',
        },
    ]) as LigneForm[],
});

// ── Véhicule AutoComplete ─────────────────────────────────────────────────────

const vehiculeSelected = ref<VehiculeOption | null>(
    props.vehicules.find((v) => v.id === props.transfert?.vehicule_id) ?? null,
);
const vehiculeSuggests = ref<VehiculeOption[]>([]);

function searchVehicule(event: { query: string }) {
    const q = event.query.toLowerCase().trim();
    vehiculeSuggests.value = q
        ? props.vehicules.filter(
              (v) =>
                  v.nom_vehicule.toLowerCase().includes(q) ||
                  v.immatriculation.toLowerCase().includes(q),
          )
        : [...props.vehicules];
}

function onVehiculeSelect(v: VehiculeOption | null) {
    form.vehicule_id = v?.id ?? null;
    form.equipe_livraison_id = v?.equipe_livraison_id ?? null;
    // Pré-remplit l'unique ligne seulement quand ce véhicule n'a qu'un seul groupe de
    // capacité configuré (sinon ambigu : lequel choisir ?).
    if (v?.capacites.length === 1 && form.lignes.length === 1) {
        form.lignes[0].quantite_demandee = v.capacites[0].capacite_max;
    }
}

function onVehiculeClear() {
    form.vehicule_id = null;
    form.equipe_livraison_id = null;
    vehiculeSelected.value = null;
}

function vehiculeLabel(v: VehiculeOption): string {
    return `${v.nom_vehicule} — ${v.immatriculation}`;
}

// ── Produit AutoComplete (par ligne) ─────────────────────────────────────────

const produitSuggests = ref<ProduitOption[]>([]);
const produitSelected = ref<Array<ProduitOption | null>>(
    (
        props.transfert?.lignes ?? [{ produit_id: defaultProduit?.id ?? null }]
    ).map((l) => props.produits.find((p) => p.id === l.produit_id) ?? null),
);

function searchProduit(event: { query: string }) {
    const q = event.query.toLowerCase().trim();
    produitSuggests.value = q
        ? props.produits.filter((p) => p.nom.toLowerCase().includes(q))
        : [...props.produits];
}

function onProduitSelect(index: number, p: ProduitOption | null) {
    form.lignes[index].produit_id = p?.id ?? null;
}

// ── Gestion des lignes ────────────────────────────────────────────────────────

function ajouterLigne() {
    form.lignes.push({
        produit_id: null,
        quantite_demandee: 1,
        notes: '',
    });
    produitSelected.value.push(null);
}

function supprimerLigne(index: number) {
    if (form.lignes.length === 1) return;
    form.lignes.splice(index, 1);
    produitSelected.value.splice(index, 1);
}

// ── Capacité véhicule ─────────────────────────────────────────────────────────

const vehiculeSelectionne = computed(
    () => props.vehicules.find((v) => v.id === form.vehicule_id) ?? null,
);

const quantiteTotale = computed(() =>
    form.lignes.reduce((sum, l) => sum + (l.quantite_demandee ?? 0), 0),
);

// Plafonds par catégorie de produit du véhicule sélectionné — vide si aucune capacité n'est
// configurée pour ce véhicule (non plafonné), exactement comme VehiculeCapaciteService côté
// serveur (plus aucun héritage depuis le type).
const capacitesSelectionnees = computed(
    () => vehiculeSelectionne.value?.capacites ?? [],
);

// Quantité demandée regroupée par catégorie de produit, uniquement pour les catégories
// présentes dans la commande et ayant un plafond configuré sur le véhicule sélectionné.
const quantitesParCategorie = computed(() => {
    const capacites = capacitesSelectionnees.value;
    if (capacites.length === 0) return [];

    const totaux = new Map<string, number>();
    for (const ligne of form.lignes) {
        const produit = props.produits.find((p) => p.id === ligne.produit_id);
        const categorieId = produit?.categorie_id;
        if (!categorieId) continue;
        totaux.set(
            categorieId,
            (totaux.get(categorieId) ?? 0) + (ligne.quantite_demandee ?? 0),
        );
    }

    return capacites
        .filter((c) => totaux.has(c.categorie_id))
        .map((c) => ({ ...c, qte: totaux.get(c.categorie_id) ?? 0 }));
});

function capaciteLigneClass(qte: number, max: number): string {
    return qte > max
        ? 'text-amber-600 dark:text-amber-400'
        : 'text-emerald-600 dark:text-emerald-400';
}

// Un transfert peut charger moins que la capacité (pas d'exigence de "chargement complet" côté
// logistique, contrairement à la vente) — il ne peut simplement jamais la dépasser. Véhicule
// sans aucune capacité configurée = non plafonné, toujours conforme.
const capaciteVehiculeConforme = computed(() => {
    if (form.vehicule_id === null) return true;
    if (capacitesSelectionnees.value.length === 0) return true;
    return quantitesParCategorie.value.every((c) => c.qte <= c.capacite_max);
});

// ── Validation locale ─────────────────────────────────────────────────────────

const canSubmit = computed(
    () =>
        (props.is_admin
            ? form.site_source_id !== null
            : props.site_source !== null) &&
        form.site_destination_id !== null &&
        form.site_source_id !== form.site_destination_id &&
        form.vehicule_id !== null &&
        form.lignes.every(
            (l) => l.produit_id !== null && l.quantite_demandee >= 1,
        ) &&
        capaciteVehiculeConforme.value &&
        !form.processing,
);

// ── Soumission ────────────────────────────────────────────────────────────────

function submit() {
    if (isEditing.value) {
        form.put(`/backoffice/logistique/${props.transfert!.id}`);
    } else {
        form.post('/backoffice/logistique');
    }
}
</script>

<template>
    <Head :title="isEditing ? 'Modifier le transfert' : 'Nouveau transfert'" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- Mobile sticky header -->
        <div
            class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden"
        >
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link
                    href="/backoffice/logistique"
                    class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <h1 class="text-[17px] leading-tight font-semibold">
                    {{
                        isEditing
                            ? 'Modifier le transfert'
                            : 'Nouveau transfert'
                    }}
                </h1>
            </div>
        </div>

        <div class="flex w-full flex-1 flex-col items-center">
            <div class="w-full max-w-4xl p-4 sm:p-6">
                <!-- En-tête desktop -->
                <div class="mb-6 hidden sm:block">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{
                            isEditing
                                ? 'Modifier le transfert'
                                : 'Nouveau transfert logistique'
                        }}
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{
                            isEditing
                                ? 'Modifiez les informations du transfert inter-sites.'
                                : 'Planifiez un transfert de produits entre deux sites.'
                        }}
                    </p>
                </div>

                <!-- Bandeau erreur global (ex: permission refusée) -->
                <div
                    v-if="Object.keys(form.errors).length && !form.processing"
                    class="mb-4 rounded-lg border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                >
                    Veuillez corriger les erreurs ci-dessous avant de soumettre.
                </div>

                <form
                    id="logistique-form"
                    class="space-y-6"
                    @submit.prevent="submit"
                >
                    <!-- ── Section : Informations ── -->
                    <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
                        <h2
                            class="mb-5 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Informations du transfert
                        </h2>

                        <!-- Sites source / destination -->
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div data-testid="site-source-field">
                                <Label class="mb-1.5 block text-sm">
                                    Site source
                                    <span
                                        v-if="is_admin"
                                        class="text-destructive"
                                        >*</span
                                    >
                                </Label>

                                <!-- Admin : sélecteur libre -->
                                <Dropdown
                                    v-if="is_admin"
                                    v-model="form.site_source_id"
                                    :options="
                                        sites.filter(
                                            (s) =>
                                                s.id !==
                                                form.site_destination_id,
                                        )
                                    "
                                    option-label="nom"
                                    option-value="id"
                                    placeholder="Sélectionner un site"
                                    class="w-full"
                                    :class="{
                                        'p-invalid': form.errors.site_source_id,
                                    }"
                                    filter
                                />

                                <!-- Non-admin : affichage verrouillé -->
                                <div
                                    v-else
                                    class="flex h-10 w-full items-center justify-between rounded-md border border-input bg-muted/40 px-3 text-sm"
                                    :class="
                                        site_source
                                            ? 'text-foreground'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    <span>{{
                                        site_source?.nom ?? 'Aucun site affecté'
                                    }}</span>
                                    <Lock
                                        class="h-3.5 w-3.5 shrink-0 text-muted-foreground/60"
                                    />
                                </div>

                                <p
                                    v-if="!is_admin && !site_source"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    Vous n'êtes affecté à aucun site. Contactez
                                    un administrateur.
                                </p>
                                <p
                                    v-if="form.errors.site_source_id"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ form.errors.site_source_id }}
                                </p>
                            </div>
                            <div data-testid="site-destination-field">
                                <Label class="mb-1.5 block text-sm">
                                    Site destination
                                    <span class="text-destructive">*</span>
                                </Label>
                                <Dropdown
                                    v-model="form.site_destination_id"
                                    :options="
                                        sites.filter(
                                            (s) =>
                                                s.id !==
                                                (is_admin
                                                    ? form.site_source_id
                                                    : site_source?.id),
                                        )
                                    "
                                    option-label="nom"
                                    option-value="id"
                                    placeholder="Sélectionner un site"
                                    class="w-full"
                                    :class="{
                                        'p-invalid':
                                            form.errors.site_destination_id,
                                    }"
                                    filter
                                />
                                <p
                                    v-if="form.errors.site_destination_id"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ form.errors.site_destination_id }}
                                </p>
                            </div>
                        </div>

                        <!-- Véhicule -->
                        <div class="mt-4">
                            <div data-testid="vehicule-field">
                                <Label class="mb-1.5 block text-sm"
                                    >Véhicule
                                    <span class="text-destructive"
                                        >*</span
                                    ></Label
                                >
                                <AutoComplete
                                    v-model="vehiculeSelected"
                                    :suggestions="vehiculeSuggests"
                                    :option-label="vehiculeLabel"
                                    placeholder="Nom, immatriculation…"
                                    class="w-full"
                                    input-class="w-full"
                                    dropdown
                                    force-selection
                                    @complete="searchVehicule"
                                    @item-select="
                                        (e) => onVehiculeSelect(e.value)
                                    "
                                    @clear="onVehiculeClear"
                                >
                                    <template #option="{ option }">
                                        <div class="py-0.5">
                                            <div
                                                class="leading-tight font-medium"
                                            >
                                                {{ option.nom_vehicule }}
                                            </div>
                                            <div
                                                class="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground"
                                            >
                                                <span class="font-mono">{{
                                                    option.immatriculation
                                                }}</span>
                                                <span
                                                    v-if="
                                                        option.capacites
                                                            .length > 0
                                                    "
                                                    class="before:mr-2 before:content-['·']"
                                                >
                                                    <template
                                                        v-for="(
                                                            c, i
                                                        ) in option.capacites"
                                                        :key="c.categorie_id"
                                                    >
                                                        {{
                                                            c.categorie_nom
                                                        }}
                                                        {{ c.capacite_max
                                                        }}<template
                                                            v-if="
                                                                i <
                                                                option
                                                                    .capacites
                                                                    .length -
                                                                    1
                                                            "
                                                            >,
                                                        </template>
                                                    </template>
                                                </span>
                                                <span
                                                    v-if="option.equipe_nom"
                                                    class="before:mr-2 before:content-['·']"
                                                >
                                                    {{ option.equipe_nom }}
                                                </span>
                                            </div>
                                        </div>
                                    </template>
                                    <template #empty>
                                        <span
                                            class="text-sm text-muted-foreground"
                                            >Aucun véhicule trouvé.</span
                                        >
                                    </template>
                                </AutoComplete>
                            </div>
                            <p
                                v-if="form.errors.vehicule_id"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.vehicule_id }}
                            </p>
                            <!-- Équipe masquée — alimentée automatiquement par le véhicule -->
                        </div>

                        <!-- Dates — masquées temporairement (valeur = date du jour par défaut) -->
                    </div>

                    <!-- ── Section : Lignes produits ── -->
                    <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
                        <h2
                            class="mb-5 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Lignes produits
                        </h2>

                        <p
                            v-if="form.errors.lignes"
                            class="mb-3 text-xs text-destructive"
                        >
                            {{ form.errors.lignes }}
                        </p>

                        <div
                            v-if="form.vehicule_id !== null"
                            class="mb-3 space-y-1 text-xs"
                        >
                            <p
                                v-if="capacitesSelectionnees.length === 0"
                                class="text-muted-foreground"
                            >
                                Véhicule non plafonné · Quantité saisie:
                                {{ quantiteTotale }} packs
                            </p>
                            <p
                                v-for="c in quantitesParCategorie"
                                :key="c.categorie_id"
                                :class="
                                    capaciteLigneClass(c.qte, c.capacite_max)
                                "
                            >
                                {{ c.categorie_nom }}: {{ c.qte }} /
                                {{ c.capacite_max }}
                                <span v-if="c.qte > c.capacite_max">
                                    —
                                    {{ c.qte - c.capacite_max }} pack(s) en
                                    trop</span
                                >
                            </p>
                        </div>

                        <!-- Table desktop -->
                        <div
                            class="hidden overflow-hidden rounded-lg border sm:block"
                        >
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b bg-muted/40">
                                        <th
                                            class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                                        >
                                            Produit
                                        </th>
                                        <th
                                            class="px-4 py-2.5 text-center font-medium text-muted-foreground"
                                            style="width: 120px"
                                        >
                                            Quantité
                                        </th>
                                        <th
                                            class="px-4 py-2.5"
                                            style="width: 48px"
                                        ></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <tr
                                        v-for="(ligne, index) in form.lignes"
                                        :key="index"
                                        class="hover:bg-muted/10"
                                    >
                                        <td class="px-4 py-3">
                                            <AutoComplete
                                                v-model="produitSelected[index]"
                                                :suggestions="produitSuggests"
                                                option-label="nom"
                                                placeholder="Rechercher un produit…"
                                                class="w-full"
                                                input-class="w-full"
                                                dropdown
                                                force-selection
                                                @complete="searchProduit"
                                                @item-select="
                                                    (e) =>
                                                        onProduitSelect(
                                                            index,
                                                            e.value,
                                                        )
                                                "
                                                @clear="
                                                    () => {
                                                        ligne.produit_id = null;
                                                        produitSelected[index] =
                                                            null;
                                                    }
                                                "
                                            />
                                            <p
                                                v-if="
                                                    (form.errors as any)[
                                                        `lignes.${index}.produit_id`
                                                    ]
                                                "
                                                class="mt-1 text-xs text-destructive"
                                            >
                                                {{
                                                    (form.errors as any)[
                                                        `lignes.${index}.produit_id`
                                                    ]
                                                }}
                                            </p>
                                        </td>
                                        <td class="px-4 py-3">
                                            <InputNumber
                                                v-model="
                                                    ligne.quantite_demandee
                                                "
                                                :min="1"
                                                :use-grouping="false"
                                                class="w-full"
                                                input-class="w-full text-center"
                                            />
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                class="h-7 w-7 text-destructive hover:text-destructive"
                                                :disabled="
                                                    form.lignes.length <= 1
                                                "
                                                @click="supprimerLigne(index)"
                                            >
                                                <Trash2 class="h-4 w-4" />
                                            </Button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Cards mobile -->
                        <div class="space-y-3 sm:hidden">
                            <div
                                v-for="(ligne, index) in form.lignes"
                                :key="index"
                                class="rounded-xl border bg-muted/20 p-3"
                            >
                                <AutoComplete
                                    v-model="produitSelected[index]"
                                    :suggestions="produitSuggests"
                                    option-label="nom"
                                    placeholder="Rechercher un produit…"
                                    class="w-full"
                                    input-class="w-full"
                                    dropdown
                                    force-selection
                                    @complete="searchProduit"
                                    @item-select="
                                        (e) => onProduitSelect(index, e.value)
                                    "
                                    @clear="
                                        () => {
                                            ligne.produit_id = null;
                                            produitSelected[index] = null;
                                        }
                                    "
                                />
                                <div class="mt-2.5 grid grid-cols-2 gap-2.5">
                                    <div>
                                        <p
                                            class="mb-1 text-[11px] font-medium text-muted-foreground"
                                        >
                                            Quantité
                                        </p>
                                        <InputNumber
                                            v-model="ligne.quantite_demandee"
                                            :min="1"
                                            :use-grouping="false"
                                            class="w-full"
                                            input-class="w-full text-center"
                                        />
                                    </div>
                                </div>
                                <div class="mt-2.5 flex justify-end">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        class="h-8 w-8 text-destructive hover:text-destructive"
                                        :disabled="form.lignes.length <= 1"
                                        @click="supprimerLigne(index)"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>
                        </div>

                        <!-- Ajouter une ligne -->
                        <div class="mt-4">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="ajouterLigne"
                            >
                                <Plus class="mr-2 h-4 w-4" />
                                Ajouter une ligne
                            </Button>
                        </div>
                    </div>

                    <!-- Spacer mobile pour sticky footer -->
                    <div class="h-20 sm:hidden" />

                    <!-- Footer desktop -->
                    <div class="hidden items-center justify-between sm:flex">
                        <Link href="/backoffice/logistique">
                            <Button type="button" variant="outline"
                                >Retour</Button
                            >
                        </Link>
                        <Button type="submit" :disabled="!canSubmit">
                            <Save class="mr-2 h-4 w-4" />
                            {{
                                form.processing
                                    ? 'Enregistrement…'
                                    : isEditing
                                      ? 'Mettre à jour'
                                      : 'Créer le transfert'
                            }}
                        </Button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Mobile sticky footer -->
        <div
            class="fixed right-0 bottom-0 left-0 z-20 border-t border-border/60 bg-background/95 px-4 py-3 backdrop-blur-sm sm:hidden"
        >
            <Button class="w-full" :disabled="!canSubmit" @click="submit">
                <Save class="mr-2 h-4 w-4" />
                {{
                    form.processing
                        ? 'Enregistrement…'
                        : isEditing
                          ? 'Mettre à jour'
                          : 'Créer le transfert'
                }}
            </Button>
        </div>
    </AppLayout>
</template>
