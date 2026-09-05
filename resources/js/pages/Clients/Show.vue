<script setup lang="ts">
import DerogationImpayesCard from '@/components/DerogationImpayesCard.vue';
import DetailHeader from '@/components/DetailHeader.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF, formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CircleHelp,
    Gift,
    Lock,
    Pencil,
    Plus,
    Tag,
    Trash2,
    TrendingUp,
    UserRound,
} from 'lucide-vue-next';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

interface ClientData {
    id: number;
    nom_complet: string;
    telephone: string | null;
    adresse: string | null;
    ville: string | null;
    pays: string | null;
    code_pays: string | null;
    code_phone_pays: string | null;
    is_active: boolean;
    type: string;
    type_label: string;
    cashback_eligible: boolean;
    cashback_montant_par_pack: number | null;
    derogation_impayes_autorisee: boolean;
    seuil_derogation_impayes: number | null;
}

interface CashbackSolde {
    cumul_achats: number;
    cashback_en_attente: number;
    total_cashback_gagne: number;
    total_cashback_verse: number;
}

interface CategorieOption {
    id: string;
    nom: string;
    produits_count: number;
}

interface TarifGrossiste {
    categorie_id: string;
    mode: string;
    prix: number;
}

const props = defineProps<{
    client: ClientData;
    cashback_solde: CashbackSolde | null;
    seuil_global_impayes: number;
    tarifs_grossiste: {
        categories: CategorieOption[];
        tarifs: TarifGrossiste[];
    };
    mode_remise_grossiste_options: { value: string; label: string }[];
}>();

const { can } = usePermissions();
const toast = useToast();
const activeTab = ref<'informations' | 'cashback' | 'tarification'>(
    'informations',
);
const isRevendeur = computed(() => props.client.type === 'revendeur');
const isGrossiste = computed(() => props.client.type === 'grossiste');
const isEditingCashback = ref(false);

const cashbackForm = useForm({
    cashback_eligible: Boolean(props.client.cashback_eligible),
    cashback_montant_par_pack: props.client.cashback_montant_par_pack,
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Clients', href: '/backoffice/clients' },
    { title: props.client.nom_complet, href: '#' },
];

const locationLabel = computed(() => {
    const address = (props.client.adresse ?? '').trim();
    const city = (props.client.ville ?? '').trim();

    if (!address && !city) return '—';
    if (!address) return city;
    if (!city) return address;

    return address + ', ' + city;
});

function flagUrl(code: string): string {
    return 'https://flagcdn.com/20x15/' + code.toLowerCase() + '.png';
}

function startCashbackEdit(): void {
    cashbackForm.clearErrors();
    cashbackForm.reset();
    isEditingCashback.value = true;
}

function cancelCashbackEdit(): void {
    cashbackForm.clearErrors();
    cashbackForm.reset();
    isEditingCashback.value = false;
}

function saveCashback(): void {
    if (isRevendeur.value) {
        cashbackForm.cashback_eligible = true;
    }

    cashbackForm.patch('/backoffice/clients/' + props.client.id + '/cashback', {
        preserveScroll: true,
        onSuccess: () => {
            cashbackForm.defaults({
                cashback_eligible: cashbackForm.cashback_eligible,
                cashback_montant_par_pack:
                    cashbackForm.cashback_montant_par_pack,
            });
            cashbackForm.reset();
            isEditingCashback.value = false;
            toast.add({
                severity: 'success',
                summary: 'Configuration cashback',
                detail: 'Les paramètres du client ont été enregistrés.',
                life: 4000,
                group: 'top',
            });
        },
    });
}

// ── Tarification Grossiste — propre à CE client, jamais une grille organisation (cf.
// docs/grossiste.md). Une ligne par (catégorie × mode), pré-remplie avec le tarif existant ou 0.
// Une ligne par catégorie CONFIGURÉE (jamais toutes les catégories du catalogue — cf. demande du
// 05/09/2026, même pattern que CapacitesEditor.vue : "Ajouter une ligne" + choix explicite de la
// catégorie, plutôt qu'une grille pré-remplissant chaque catégorie existante, y compris celles
// sans rapport avec ce Grossiste).
interface TarifLigne {
    categorie_id: string | null;
    enlevement: number | null;
    livraison: number | null;
}

function buildLignesDepuisTarifs(): TarifLigne[] {
    const lignes = new Map<string, TarifLigne>();
    for (const t of props.tarifs_grossiste.tarifs) {
        if (!lignes.has(t.categorie_id)) {
            lignes.set(t.categorie_id, {
                categorie_id: t.categorie_id,
                enlevement: null,
                livraison: null,
            });
        }
        const ligne = lignes.get(t.categorie_id)!;
        if (t.mode === 'enlevement') ligne.enlevement = t.prix;
        else if (t.mode === 'livraison') ligne.livraison = t.prix;
    }

    return Array.from(lignes.values());
}

const isEditingTarifs = ref(false);
const tarifsForm = useForm({ lignes: buildLignesDepuisTarifs() });

// Lignes affichées en lecture seule — celles déjà éditées si l'utilisateur est en cours
// d'édition, sinon reconstruites depuis les tarifs enregistrés.
const lignesAffichees = computed(() =>
    isEditingTarifs.value ? tarifsForm.lignes : buildLignesDepuisTarifs(),
);

function categorieNom(categorieId: string | null): string {
    return (
        props.tarifs_grossiste.categories.find((c) => c.id === categorieId)
            ?.nom ?? '—'
    );
}

// Catégories déjà utilisées par une AUTRE ligne — jamais reproposées (même garde-fou que
// CapacitesEditor.vue), la contrainte unique client+catégorie+mode côté backend reste le filet
// de sécurité final.
function categoriesDisponibles(excludeIndex: number): CategorieOption[] {
    const utilisees = tarifsForm.lignes
        .filter((_, i) => i !== excludeIndex)
        .map((l) => l.categorie_id)
        .filter((id): id is string => id !== null);

    return props.tarifs_grossiste.categories.filter(
        (c) => !utilisees.includes(c.id),
    );
}

const categoriesRestantes = computed(() => categoriesDisponibles(-1));

function addLigne(): void {
    tarifsForm.lignes.push({
        categorie_id: null,
        enlevement: null,
        livraison: null,
    });
}

function removeLigne(index: number): void {
    tarifsForm.lignes.splice(index, 1);
}

function startTarifsEdit(): void {
    tarifsForm.clearErrors();
    tarifsForm.lignes = buildLignesDepuisTarifs();
    isEditingTarifs.value = true;
}

function cancelTarifsEdit(): void {
    tarifsForm.clearErrors();
    isEditingTarifs.value = false;
}

function saveTarifs(): void {
    tarifsForm
        .transform((data) => ({
            tarifs: data.lignes.flatMap((l) => {
                if (!l.categorie_id) return [];

                const t: { categorie_id: string; mode: string; prix: number }[] =
                    [];
                if (l.enlevement !== null && l.enlevement > 0) {
                    t.push({
                        categorie_id: l.categorie_id,
                        mode: 'enlevement',
                        prix: l.enlevement,
                    });
                }
                if (l.livraison !== null && l.livraison > 0) {
                    t.push({
                        categorie_id: l.categorie_id,
                        mode: 'livraison',
                        prix: l.livraison,
                    });
                }

                return t;
            }),
        }))
        .put('/backoffice/clients/' + props.client.id + '/tarifs-grossiste', {
            preserveScroll: true,
            onSuccess: () => {
                isEditingTarifs.value = false;
                toast.add({
                    severity: 'success',
                    summary: 'Tarification Grossiste',
                    detail: 'Les tarifs de ce client ont été enregistrés.',
                    life: 4000,
                    group: 'top',
                });
            },
        });
}
</script>

<template>
    <Head :title="client.nom_complet + ' — Détail client'" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <DetailHeader
                eyebrow="Client"
                :title="client.nom_complet"
                :icon="UserRound"
                :status-label="client.is_active ? 'Actif' : 'Inactif'"
                :status-dot-class="
                    client.is_active
                        ? 'bg-emerald-500'
                        : 'bg-zinc-400 dark:bg-zinc-500'
                "
                data-testid="client-detail-header"
            >
                <template #subtitle>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <span
                            class="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium"
                            data-testid="client-type-badge"
                        >
                            {{ client.type_label }}
                        </span>
                        <span class="text-sm text-muted-foreground">
                            {{
                                formatPhoneDisplay(
                                    client.telephone,
                                    client.code_phone_pays,
                                )
                            }}
                        </span>
                    </div>
                </template>

                <template #actions>
                    <Link href="/backoffice/clients">
                        <Button variant="outline" size="sm">
                            <ArrowLeft class="mr-1.5 h-4 w-4" />
                            Liste des clients
                        </Button>
                    </Link>
                </template>
            </DetailHeader>

            <div class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)]">
                <aside
                    class="grid h-fit grid-cols-2 gap-2 rounded-xl border bg-card p-2 lg:block"
                    aria-label="Sections de la fiche client"
                    data-testid="client-detail-navigation"
                >
                    <button
                        type="button"
                        class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                        :class="
                            activeTab === 'informations'
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-muted'
                        "
                        data-testid="client-informations-tab"
                        @click="activeTab = 'informations'"
                    >
                        <span class="inline-flex items-center gap-2">
                            <CircleHelp class="h-4 w-4" />
                            Informations
                        </span>
                    </button>

                    <button
                        type="button"
                        class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition-colors lg:mt-2"
                        :class="
                            activeTab === 'cashback'
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-muted'
                        "
                        data-testid="client-cashback-tab"
                        @click="activeTab = 'cashback'"
                    >
                        <span class="inline-flex items-center gap-2">
                            <Gift class="h-4 w-4" />
                            Cashback
                        </span>
                    </button>

                    <button
                        v-if="isGrossiste"
                        type="button"
                        class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition-colors lg:mt-2"
                        :class="
                            activeTab === 'tarification'
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-muted'
                        "
                        data-testid="client-tarification-tab"
                        @click="activeTab = 'tarification'"
                    >
                        <span class="inline-flex items-center gap-2">
                            <Tag class="h-4 w-4" />
                            Tarification
                        </span>
                    </button>
                </aside>

                <div
                    v-if="activeTab === 'informations'"
                    class="rounded-xl border bg-card p-5 sm:p-6"
                    data-testid="client-informations-panel"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h2
                            class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Informations du client
                        </h2>
                        <Link
                            v-if="can('clients.update')"
                            :href="'/backoffice/clients/' + client.id + '/edit'"
                            data-testid="client-edit-button"
                        >
                            <Button size="sm" variant="outline">
                                <Pencil class="mr-1.5 h-4 w-4" />
                                Modifier
                            </Button>
                        </Link>
                    </div>

                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Nom complet
                            </p>
                            <p
                                class="mt-1 text-sm font-medium"
                                data-testid="client-name"
                            >
                                {{ client.nom_complet }}
                            </p>
                        </div>

                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Nature du client
                            </p>
                            <p
                                class="mt-1 text-sm font-medium"
                                data-testid="client-type"
                            >
                                {{ client.type_label }}
                            </p>
                        </div>

                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Téléphone
                            </p>
                            <p
                                class="mt-1 text-sm font-medium"
                                data-testid="client-phone"
                            >
                                {{
                                    formatPhoneDisplay(
                                        client.telephone,
                                        client.code_phone_pays,
                                    )
                                }}
                            </p>
                        </div>

                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Localisation
                            </p>
                            <div class="mt-1 flex items-center gap-2">
                                <img
                                    v-if="client.code_pays"
                                    :src="flagUrl(client.code_pays)"
                                    :alt="client.pays ?? 'Pays du client'"
                                    class="h-4 w-auto rounded-sm shadow-sm"
                                />
                                <p
                                    class="text-sm font-medium"
                                    data-testid="client-location"
                                >
                                    {{ locationLabel }}
                                </p>
                            </div>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ client.pays || 'Pays non renseigné' }}
                            </p>
                        </div>

                        <DerogationImpayesCard
                            class="sm:col-span-2"
                            :active="client.derogation_impayes_autorisee"
                            :seuil="client.seuil_derogation_impayes"
                            :seuil-global="seuil_global_impayes"
                            :update-url="
                                '/backoffice/clients/' +
                                client.id +
                                '/derogation-impayes'
                            "
                            :can-update="can('clients.update')"
                            entite-label="ce client"
                        />
                    </div>
                </div>

                <div
                    v-else-if="activeTab === 'cashback'"
                    class="space-y-6"
                    data-testid="client-cashback-panel"
                >
                    <div
                        v-if="cashback_solde !== null"
                        class="rounded-xl border bg-card p-5 sm:p-6"
                    >
                        <div>
                            <h2
                                class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                Synthèse cashback
                            </h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Vue d'ensemble des achats et des gains de ce
                                client.
                            </p>
                        </div>

                        <div
                            class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                        >
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Cumul des achats
                                </p>
                                <p
                                    class="mt-1 text-lg font-semibold tabular-nums"
                                    data-testid="cashback-purchases-total"
                                >
                                    {{ formatGNF(cashback_solde.cumul_achats) }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    En attente
                                </p>
                                <p
                                    class="mt-1 text-lg font-semibold tabular-nums"
                                    :class="
                                        cashback_solde.cashback_en_attente > 0
                                            ? 'text-amber-600 dark:text-amber-400'
                                            : ''
                                    "
                                    data-testid="cashback-pending-total"
                                >
                                    {{
                                        formatGNF(
                                            cashback_solde.cashback_en_attente,
                                        )
                                    }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Total gagné
                                </p>
                                <p
                                    class="mt-1 text-lg font-semibold text-primary tabular-nums"
                                    data-testid="cashback-earned-total"
                                >
                                    {{
                                        formatGNF(
                                            cashback_solde.total_cashback_gagne,
                                        )
                                    }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Total versé
                                </p>
                                <p
                                    class="mt-1 text-lg font-semibold text-emerald-600 tabular-nums dark:text-emerald-400"
                                    data-testid="cashback-paid-total"
                                >
                                    {{
                                        formatGNF(
                                            cashback_solde.total_cashback_verse,
                                        )
                                    }}
                                </p>
                            </div>
                        </div>

                        <div
                            v-if="cashback_solde.cashback_en_attente > 0"
                            class="mt-4 flex flex-col gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 sm:flex-row sm:items-center sm:justify-between dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                            data-testid="cashback-pending-alert"
                        >
                            <p class="flex items-start gap-2">
                                <TrendingUp class="mt-0.5 h-4 w-4 shrink-0" />
                                <span>
                                    <strong>{{
                                        formatGNF(
                                            cashback_solde.cashback_en_attente,
                                        )
                                    }}</strong>
                                    de cashback sont à verser à ce client.
                                </span>
                            </p>
                            <Link
                                href="/backoffice/comptabilite/commissions/cashback"
                                class="shrink-0 font-medium underline underline-offset-4 hover:no-underline"
                            >
                                Gérer le cashback
                            </Link>
                        </div>
                    </div>

                    <div
                        v-else
                        class="rounded-xl border bg-card p-5 sm:p-6"
                        data-testid="cashback-module-disabled"
                    >
                        <div
                            class="flex min-h-40 flex-col items-center justify-center rounded-lg border border-dashed p-6 text-center"
                        >
                            <Gift class="h-8 w-8 text-muted-foreground/60" />
                            <h2 class="mt-3 text-sm font-semibold">
                                Module cashback désactivé
                            </h2>
                            <p
                                class="mt-1 max-w-md text-sm text-muted-foreground"
                            >
                                La synthèse cashback n'est pas disponible pour
                                cette organisation.
                            </p>
                        </div>
                    </div>

                    <form
                        class="rounded-xl border bg-card p-5 sm:p-6"
                        data-testid="cashback-configuration-form"
                        @submit.prevent="saveCashback"
                    >
                        <div
                            class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                        >
                            <div>
                                <h2
                                    class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                                >
                                    Configuration cashback
                                </h2>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    Paramétrage actuellement appliqué à ce
                                    client.
                                </p>
                            </div>

                            <div
                                v-if="can('clients.update')"
                                class="flex shrink-0 items-center gap-2"
                            >
                                <Button
                                    v-if="!isEditingCashback"
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    data-testid="cashback-edit-button"
                                    @click="startCashbackEdit"
                                >
                                    <Pencil class="mr-1.5 h-4 w-4" />
                                    Modifier
                                </Button>
                                <template v-else>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        :disabled="cashbackForm.processing"
                                        data-testid="cashback-cancel-button"
                                        @click="cancelCashbackEdit"
                                    >
                                        Annuler
                                    </Button>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        :disabled="
                                            cashbackForm.processing ||
                                            !cashbackForm.isDirty
                                        "
                                        data-testid="cashback-save-button"
                                    >
                                        {{
                                            cashbackForm.processing
                                                ? 'Enregistrement…'
                                                : 'Enregistrer'
                                        }}
                                    </Button>
                                </template>
                            </div>
                        </div>

                        <div class="mt-5 grid gap-4 md:grid-cols-2">
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-sm font-medium">
                                    Éligible au cashback
                                </p>

                                <div v-if="isRevendeur" class="mt-2">
                                    <span
                                        class="inline-flex items-center gap-1.5 text-sm font-medium text-primary"
                                        data-testid="cashback-revendeur-locked"
                                    >
                                        <Lock class="h-3.5 w-3.5" />
                                        Oui — obligatoire
                                    </span>
                                </div>

                                <div
                                    v-else
                                    class="mt-2 inline-flex gap-1 rounded-lg border bg-muted/40 p-1"
                                    role="radiogroup"
                                    aria-label="Éligible au cashback"
                                >
                                    <label
                                        class="rounded-md px-5 py-1.5 text-sm font-medium transition-colors"
                                        :class="[
                                            cashbackForm.cashback_eligible
                                                ? 'bg-primary text-primary-foreground shadow-sm'
                                                : 'text-muted-foreground',
                                            isEditingCashback
                                                ? 'cursor-pointer hover:text-foreground'
                                                : 'cursor-default',
                                        ]"
                                    >
                                        <input
                                            v-model="
                                                cashbackForm.cashback_eligible
                                            "
                                            class="sr-only"
                                            type="radio"
                                            :value="true"
                                            :disabled="
                                                cashbackForm.processing ||
                                                !isEditingCashback
                                            "
                                            data-testid="cashback-eligible-yes"
                                            @change="cashbackForm.clearErrors()"
                                        />
                                        Oui
                                    </label>
                                    <label
                                        class="rounded-md px-5 py-1.5 text-sm font-medium transition-colors"
                                        :class="[
                                            !cashbackForm.cashback_eligible
                                                ? 'bg-destructive text-white shadow-sm'
                                                : 'text-muted-foreground',
                                            isEditingCashback
                                                ? 'cursor-pointer hover:text-foreground'
                                                : 'cursor-default',
                                        ]"
                                    >
                                        <input
                                            v-model="
                                                cashbackForm.cashback_eligible
                                            "
                                            class="sr-only"
                                            type="radio"
                                            :value="false"
                                            :disabled="
                                                cashbackForm.processing ||
                                                !isEditingCashback
                                            "
                                            data-testid="cashback-eligible-no"
                                            @change="cashbackForm.clearErrors()"
                                        />
                                        Non
                                    </label>
                                </div>

                                <p
                                    v-if="cashbackForm.errors.cashback_eligible"
                                    class="mt-1.5 text-xs text-destructive"
                                >
                                    {{ cashbackForm.errors.cashback_eligible }}
                                </p>
                            </div>

                            <div class="rounded-lg border bg-background p-4">
                                <label
                                    for="cashback_montant_par_pack_show"
                                    class="text-sm font-medium"
                                >
                                    Montant cashback par pack
                                    <span class="text-destructive">*</span>
                                </label>
                                <div
                                    class="mt-2 flex max-w-sm items-center gap-2"
                                >
                                    <InputNumber
                                        v-model="
                                            cashbackForm.cashback_montant_par_pack
                                        "
                                        input-id="cashback_montant_par_pack_show"
                                        :min="1"
                                        :use-grouping="true"
                                        locale="fr-GN"
                                        class="w-full"
                                        input-class="w-full"
                                        :disabled="
                                            cashbackForm.processing ||
                                            !isEditingCashback ||
                                            (!isRevendeur &&
                                                !cashbackForm.cashback_eligible)
                                        "
                                        :class="{
                                            'p-invalid':
                                                cashbackForm.errors
                                                    .cashback_montant_par_pack,
                                        }"
                                        data-testid="cashback-amount-per-pack"
                                        @update:model-value="
                                            cashbackForm.clearErrors(
                                                'cashback_montant_par_pack',
                                            )
                                        "
                                    />
                                    <span
                                        class="shrink-0 text-sm text-muted-foreground"
                                        >GNF</span
                                    >
                                </div>
                                <p
                                    v-if="
                                        cashbackForm.errors
                                            .cashback_montant_par_pack
                                    "
                                    class="mt-1.5 text-xs text-destructive"
                                >
                                    {{
                                        cashbackForm.errors
                                            .cashback_montant_par_pack
                                    }}
                                </p>
                                <p
                                    v-else
                                    class="mt-1.5 text-xs text-muted-foreground"
                                >
                                    {{
                                        cashbackForm.cashback_eligible
                                            ? 'Montant attribué pour chaque pack éligible.'
                                            : 'Activez le cashback pour modifier ce montant.'
                                    }}
                                </p>
                            </div>
                        </div>

                        <p
                            class="mt-5 border-t pt-4 text-xs text-muted-foreground"
                        >
                            Les montants déjà gagnés conservent leur valeur
                            historique ; ce paramétrage concerne les prochains
                            gains.
                        </p>
                    </form>
                </div>

                <div
                    v-else-if="activeTab === 'tarification' && isGrossiste"
                    class="rounded-xl border bg-card p-5 sm:p-6"
                    data-testid="client-tarification-panel"
                >
                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div>
                            <h2
                                class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                Tarification Grossiste
                            </h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Prix appliqué à CE client, par catégorie de
                                produit et par mode de remise — propre à ce
                                Grossiste, jamais partagé avec un autre.
                            </p>
                        </div>

                        <div
                            v-if="can('clients.update')"
                            class="flex shrink-0 items-center gap-2"
                        >
                            <Button
                                v-if="!isEditingTarifs"
                                type="button"
                                size="sm"
                                variant="outline"
                                data-testid="tarifs-edit-button"
                                @click="startTarifsEdit"
                            >
                                <Pencil class="mr-1.5 h-4 w-4" />
                                Modifier
                            </Button>
                            <template v-else>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    :disabled="tarifsForm.processing"
                                    data-testid="tarifs-cancel-button"
                                    @click="cancelTarifsEdit"
                                >
                                    Annuler
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    :disabled="tarifsForm.processing"
                                    data-testid="tarifs-save-button"
                                    @click="saveTarifs"
                                >
                                    {{
                                        tarifsForm.processing
                                            ? 'Enregistrement…'
                                            : 'Enregistrer'
                                    }}
                                </Button>
                            </template>
                        </div>
                    </div>

                    <p
                        v-if="(tarifsForm.errors as Record<string, string>).tarifs"
                        class="mt-3 text-xs text-destructive"
                    >
                        {{ (tarifsForm.errors as Record<string, string>).tarifs }}
                    </p>

                    <div
                        v-if="tarifs_grossiste.categories.length === 0"
                        class="mt-5 rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
                    >
                        Aucune catégorie de produit n'existe encore. Créez-en
                        une dans Produits &gt; Catégories avant de configurer
                        un tarif Grossiste.
                    </div>

                    <!-- Lecture seule : une ligne par catégorie déjà configurée, jamais toutes
                    les catégories du catalogue. -->
                    <template v-else-if="!isEditingTarifs">
                        <div
                            v-if="lignesAffichees.length === 0"
                            class="mt-5 rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
                        >
                            Aucun tarif configuré pour ce client.
                        </div>
                        <div v-else class="mt-5 overflow-x-auto rounded-lg border">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b bg-muted/40">
                                        <th
                                            class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                                        >
                                            Catégorie
                                        </th>
                                        <th
                                            v-for="m in mode_remise_grossiste_options"
                                            :key="m.value"
                                            class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                                        >
                                            {{ m.label }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <tr
                                        v-for="(ligne, index) in lignesAffichees"
                                        :key="ligne.categorie_id ?? index"
                                    >
                                        <td class="px-4 py-3 font-medium">
                                            {{ categorieNom(ligne.categorie_id) }}
                                        </td>
                                        <td class="px-4 py-3 tabular-nums">
                                            {{
                                                ligne.enlevement
                                                    ? formatGNF(ligne.enlevement)
                                                    : 'Non configuré'
                                            }}
                                        </td>
                                        <td class="px-4 py-3 tabular-nums">
                                            {{
                                                ligne.livraison
                                                    ? formatGNF(ligne.livraison)
                                                    : 'Non configuré'
                                            }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>

                    <!-- Édition : lignes ajoutables, une catégorie sélectionnée à la fois (même
                    pattern que CapacitesEditor.vue). -->
                    <template v-else>
                        <div
                            v-if="tarifsForm.lignes.length > 0"
                            class="mt-5 space-y-3"
                        >
                            <div
                                v-for="(ligne, index) in tarifsForm.lignes"
                                :key="index"
                                class="flex flex-col gap-2 rounded-lg border p-3 sm:flex-row sm:items-start"
                            >
                                <div class="flex-1">
                                    <Label class="mb-1.5 block text-xs"
                                        >Catégorie</Label
                                    >
                                    <Dropdown
                                        v-model="ligne.categorie_id"
                                        :options="categoriesDisponibles(index)"
                                        option-label="nom"
                                        option-value="id"
                                        placeholder="Choisir…"
                                        class="w-full"
                                    />
                                </div>
                                <div class="w-full sm:w-40">
                                    <Label class="mb-1.5 block text-xs"
                                        >Enlèvement usine</Label
                                    >
                                    <div class="flex items-center gap-1.5">
                                        <InputNumber
                                            v-model="ligne.enlevement"
                                            :min="0"
                                            :step="100"
                                            class="w-full"
                                        />
                                        <span
                                            class="shrink-0 text-xs text-muted-foreground"
                                            >GNF</span
                                        >
                                    </div>
                                </div>
                                <div class="w-full sm:w-40">
                                    <Label class="mb-1.5 block text-xs"
                                        >Livraison</Label
                                    >
                                    <div class="flex items-center gap-1.5">
                                        <InputNumber
                                            v-model="ligne.livraison"
                                            :min="0"
                                            :step="100"
                                            class="w-full"
                                        />
                                        <span
                                            class="shrink-0 text-xs text-muted-foreground"
                                            >GNF</span
                                        >
                                    </div>
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="mt-1 h-9 w-9 shrink-0 text-destructive sm:mt-6"
                                    data-testid="tarifs-remove-ligne"
                                    @click="removeLigne(index)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </Button>
                            </div>
                        </div>

                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="mt-4"
                            :disabled="categoriesRestantes.length === 0"
                            data-testid="tarifs-add-ligne"
                            @click="addLigne"
                        >
                            <Plus class="mr-1.5 h-3.5 w-3.5" />
                            Ajouter une ligne
                        </Button>
                        <p
                            v-if="categoriesRestantes.length === 0"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            Toutes les catégories ont déjà une ligne de tarif
                            définie.
                        </p>
                    </template>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
