<script setup lang="ts">
import ListPageActions from '@/components/ListPageActions.vue';
import StatusDot from '@/components/StatusDot.vue';
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useFlashToast } from '@/composables/useFlashToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRightLeft, Info } from 'lucide-vue-next';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

interface Mouvement {
    id: string;
    reference: string;
    nature: string;
    nature_label: string;
    commentaire: string | null;
    site_origine: string | null;
    site_destination: string | null;
    site_destination_id: string;
    compte_origine: string | null;
    compte_destination: string | null;
    compte_destination_id: string | null;
    montant: number;
    statut: string;
    statut_label: string;
    date_envoi: string | null;
    date_reception: string | null;
    expediteur: string | null;
    receptionnaire: string | null;
    created_at: string;
    peut_envoyer: boolean;
    peut_recevoir: boolean;
    peut_annuler: boolean;
    peut_contester: boolean;
    peut_confirmer_retour: boolean;
}

interface CompteTresorerie {
    id: string;
    site_id: string;
    libelle: string;
    type: string;
}

const props = defineProps<{
    mouvements: { data: Mouvement[]; total: number };
    filters: {
        statut: string;
        nature: string;
        search: string;
        site_ids: string[];
        site_origine_id: string;
        site_destination_id: string;
        caisse_id: string;
        caisse_role: string;
        montant_min: string;
        montant_max: string;
    };
    statut_options: { value: string; label: string }[];
    nature_options: { value: string; label: string }[];
    sites: { value: string; label: string }[];
    sites_mouvements: { value: string; label: string }[];
    caisses_filtre: { value: string; label: string }[];
    is_admin: boolean;
    peut_creer: boolean;
    comptes_tresorerie: CompteTresorerie[];
}>();

useFlashToast('top');
const toast = useToast();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    { title: 'Mouvements de fonds', href: '#' },
];

// Barre : Agence → Caisse → Référence → Nature. Le reste (Origine / Destination, Statut, agences
// d'origine et de destination, montants) est dans le tiroir du bouton « Filtres », lui-même placé
// dans l'en-tête à côté de « Nouveau mouvement ». « Origine / Destination » ne remplace pas deux
// listes : c'est la POSITION de la caisse choisie dans le mouvement (sans choix : origine ou
// destination) — un filtre avancé, pas un besoin de premier niveau.
const filterFields: FilterField[] = [
    {
        key: 'caisse_id',
        label: 'Caisse',
        type: 'select',
        inline: true,
        searchable: true,
        wide: true,
        placeholder: 'Rechercher une caisse…',
        options: props.caisses_filtre,
    },
    {
        key: 'search',
        label: 'Référence',
        type: 'text',
        inline: true,
        placeholder: 'MVT-2026-00001',
    },
    {
        key: 'nature',
        label: 'Nature',
        type: 'select',
        inline: true,
        options: props.nature_options,
    },
    {
        key: 'caisse_role',
        label: 'Origine / Destination',
        type: 'select',
        placeholder: 'Les deux',
        options: [
            { value: 'origine', label: 'Origine' },
            { value: 'destination', label: 'Destination' },
        ],
    },
    {
        key: 'statut',
        label: 'Statut',
        type: 'select',
        options: props.statut_options,
    },
    {
        key: 'site_origine_id',
        label: "Agence d'origine",
        type: 'select',
        options: props.sites_mouvements,
    },
    {
        key: 'site_destination_id',
        label: 'Agence de destination',
        type: 'select',
        options: props.sites_mouvements,
    },
    {
        key: 'montant_min',
        label: 'Montant min',
        type: 'number',
        placeholder: '0',
    },
    {
        key: 'montant_max',
        label: 'Montant max',
        type: 'number',
        placeholder: '0',
    },
];

// Versement d'une caisse dédiée vers la caisse de l'agence (même agence) : affiché caisse → caisse.
function estVersement(m: Mouvement): boolean {
    return m.nature === 'interne_caisses';
}

function dateFr(date: string): string {
    return new Date(date).toLocaleDateString('fr-FR');
}

const filtresHote = ref<HTMLElement | null>(null);

// DataFilters attend { id, nom } (convention Site), pas { value, label }.
const sitesPourFiltre = computed(() =>
    props.sites.map((s) => ({ id: s.value, nom: s.label })),
);

// Une seule requête à la fois pour « Envoyer » et les deux fenêtres de confirmation. La bascule est
// posée dans le gestionnaire de clic, avant tout rendu : un double clic n'envoie jamais deux fois.
const enCours = ref(false);

// Croix de fermeture des fenêtres (dernier bouton enfant du contenu) : grisée pendant l'envoi.
const CROIX_INACTIVE =
    '[&>button:last-child]:pointer-events-none [&>button:last-child]:opacity-40';

function poster(
    url: string,
    donnees: Record<string, string>,
    options: NonNullable<Parameters<typeof router.post>[2]> = {},
) {
    if (enCours.value) return;
    enCours.value = true;

    router.post(url, donnees, {
        preserveScroll: true,
        ...options,
        onFinish: () => {
            enCours.value = false;
        },
    });
}

function envoyer(m: Mouvement) {
    poster(
        `/backoffice/comptabilite/tresorerie/mouvements/${m.id}/envoyer`,
        {},
        {
            // Pas de dialogue dédié pour « Envoyer » (contrairement à Confirmer réception/motif) :
            // un solde insuffisant (règle backend, cf. MouvementFondsService::garantirSoldeSuffisant())
            // doit quand même être visible, pas juste ravaler l'erreur en silence.
            onError: (errors) =>
                toast.add({
                    group: 'top',
                    severity: 'error',
                    summary: 'Envoi impossible',
                    detail:
                        Object.values(errors)[0] ?? 'Une erreur est survenue.',
                    life: 6000,
                }),
        },
    );
}

// ── Dialog réception : le destinataire choisit le support de trésorerie qui a
// réellement reçu les fonds (caisse, banque, mobile money du site destination) ──

const receptionDialogOpen = ref(false);
const receptionCible = ref<Mouvement | null>(null);
const receptionCompteId = ref('');
const receptionError = ref('');

const comptesReception = computed(() =>
    props.comptes_tresorerie.filter(
        (c) => c.site_id === receptionCible.value?.site_destination_id,
    ),
);

function ouvrirDialogReception(m: Mouvement) {
    receptionCible.value = m;
    // Un versement a sa caisse de destination fixée à l'envoi : rien à choisir, on la confirme.
    receptionCompteId.value = estVersement(m)
        ? (m.compte_destination_id ?? '')
        : '';
    receptionError.value = '';
    receptionDialogOpen.value = true;
}

function confirmerReception() {
    if (!receptionCompteId.value) {
        receptionError.value = 'Le support de trésorerie est obligatoire.';
        return;
    }
    if (!receptionCible.value) return;

    poster(
        `/backoffice/comptabilite/tresorerie/mouvements/${receptionCible.value.id}/recevoir`,
        { compte_tresorerie_destination_id: receptionCompteId.value },
        {
            preserveState: true,
            onSuccess: () => {
                receptionDialogOpen.value = false;
            },
            onError: (errors) => {
                receptionError.value =
                    errors.compte_tresorerie_destination_id ??
                    'Une erreur est survenue.';
            },
        },
    );
}

// ── Dialog motif (annuler / contester / confirmer-retour) — un seul dialog réutilisé ──

type ActionMotif = 'annuler' | 'contester' | 'confirmer-retour';

const motifDialogOpen = ref(false);
const motifDialogAction = ref<ActionMotif>('annuler');
const motifCible = ref<Mouvement | null>(null);
const motif = ref('');
const motifError = ref('');

const motifDialogTitres: Record<ActionMotif, string> = {
    annuler: 'Annuler le mouvement',
    contester: 'Contester le mouvement',
    'confirmer-retour': 'Confirmer le retour des fonds',
};

function ouvrirDialogMotif(action: ActionMotif, m: Mouvement) {
    motifDialogAction.value = action;
    motifCible.value = m;
    motif.value = '';
    motifError.value = '';
    motifDialogOpen.value = true;
}

function confirmerMotif() {
    if (!motif.value.trim()) {
        motifError.value = 'Le motif est obligatoire.';
        return;
    }
    if (!motifCible.value) return;

    poster(
        `/backoffice/comptabilite/tresorerie/mouvements/${motifCible.value.id}/${motifDialogAction.value}`,
        { motif: motif.value },
        {
            preserveState: true,
            onSuccess: () => {
                motifDialogOpen.value = false;
            },
            onError: (errors) => {
                motifError.value = errors.motif ?? 'Une erreur est survenue.';
            },
        },
    );
}
</script>

<template>
    <Head title="Mouvements de fonds" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <h1 class="flex items-center gap-2 text-xl font-semibold">
                        <ArrowRightLeft class="h-5 w-5 text-muted-foreground" />
                        Mouvements de fonds
                        <TooltipProvider :delay-duration="150">
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <button
                                        type="button"
                                        aria-label="Informations sur les mouvements de fonds"
                                        class="shrink-0 rounded-sm text-primary transition-colors outline-none hover:text-primary/80 focus-visible:ring-2 focus-visible:ring-ring"
                                    >
                                        <Info
                                            class="h-4 w-4"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent
                                    side="bottom"
                                    class="w-80 max-w-[calc(100vw-2rem)] px-4 py-3 text-left text-sm leading-relaxed font-normal text-pretty"
                                >
                                    <p class="mb-2 font-semibold">
                                        Cette page regroupe :
                                    </p>
                                    <ul class="list-disc space-y-2 pl-4">
                                        <li>
                                            Les
                                            <strong>remises des agences</strong>
                                            au siège.
                                        </li>
                                        <li>
                                            Les <strong>financements</strong>
                                            envoyés par le siège.
                                        </li>
                                        <li>
                                            Les
                                            <strong
                                                >versements des caisses
                                                dédiées</strong
                                            >
                                            vers la caisse de l'agence.
                                        </li>
                                    </ul>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </h1>
                </div>
                <ListPageActions>
                    <template #filters>
                        <div ref="filtresHote" class="contents"></div>
                    </template>
                    <template #primary>
                        <Link
                            v-if="peut_creer"
                            href="/backoffice/comptabilite/tresorerie/mouvements/create"
                            class="inline-flex h-9 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                        >
                            Nouveau mouvement
                        </Link>
                    </template>
                </ListPageActions>
            </div>

            <DataFilters
                url="/backoffice/comptabilite/tresorerie/mouvements"
                :values="filters"
                :fields="filterFields"
                :sites="sitesPourFiltre"
                :result-count="mouvements.total"
                :trigger-target="filtresHote"
            />

            <div class="overflow-x-auto rounded-xl border bg-card">
                <table class="w-full min-w-[960px] text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40 text-left">
                            <th class="px-4 py-3 font-medium">Référence</th>
                            <th class="px-4 py-3 font-medium">Type</th>
                            <th class="px-4 py-3 font-medium">Origine</th>
                            <th class="px-4 py-3 font-medium">Destination</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Montant
                            </th>
                            <th class="px-4 py-3 font-medium">Envoyé par</th>
                            <th class="px-4 py-3 font-medium">Reçu par</th>
                            <th class="px-4 py-3 text-left font-medium">
                                Statut
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="m in mouvements.data"
                            :key="m.id"
                            class="hover:bg-muted/30"
                        >
                            <td class="px-4 py-3 font-medium whitespace-nowrap">
                                {{ m.reference }}
                            </td>
                            <td class="px-4 py-3" data-testid="mouvement-type">
                                {{ m.nature_label }}
                                <div
                                    v-if="m.commentaire"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ m.commentaire }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <template v-if="estVersement(m)">
                                    <div class="font-medium">
                                        {{ m.compte_origine ?? '—' }}
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ m.site_origine }}
                                    </div>
                                </template>
                                <template v-else>{{
                                    m.site_origine ?? '—'
                                }}</template>
                            </td>
                            <td class="px-4 py-3">
                                <template v-if="estVersement(m)">
                                    <div class="font-medium">
                                        {{ m.compte_destination ?? '—' }}
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ m.site_destination }}
                                    </div>
                                </template>
                                <template v-else>{{
                                    m.site_destination ?? '—'
                                }}</template>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(m.montant) }}
                            </td>
                            <td
                                class="px-4 py-3"
                                data-testid="mouvement-envoye-par"
                            >
                                <template v-if="m.expediteur">
                                    <div>{{ m.expediteur }}</div>
                                    <div
                                        v-if="m.date_envoi"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ dateFr(m.date_envoi) }}
                                    </div>
                                </template>
                                <template v-else>—</template>
                            </td>
                            <td
                                class="px-4 py-3"
                                data-testid="mouvement-recu-par"
                            >
                                <template v-if="m.receptionnaire">
                                    <div>{{ m.receptionnaire }}</div>
                                    <div
                                        v-if="m.date_reception"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ dateFr(m.date_reception) }}
                                    </div>
                                </template>
                                <template v-else>—</template>
                            </td>
                            <td class="px-4 py-3">
                                <StatusDot
                                    :status="m.statut"
                                    :label="m.statut_label"
                                />
                                <!-- Versement envoyé : l'argent a quitté la caisse de l'agent mais n'est pas encore
                                     crédité à la caisse de l'agence. Visible de tous, pas seulement de l'envoyeur. -->
                                <div
                                    v-if="
                                        estVersement(m) && m.statut === 'envoye'
                                    "
                                    class="mt-0.5 text-xs text-muted-foreground"
                                    data-testid="mouvement-en-attente"
                                >
                                    En attente de confirmation
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <div class="flex justify-end gap-3">
                                    <button
                                        v-if="m.peut_envoyer"
                                        type="button"
                                        :disabled="enCours"
                                        class="text-xs font-medium text-primary hover:underline disabled:cursor-not-allowed disabled:no-underline disabled:opacity-50"
                                        @click="envoyer(m)"
                                    >
                                        Envoyer
                                    </button>
                                    <button
                                        v-if="m.peut_recevoir"
                                        type="button"
                                        class="text-xs font-medium text-primary hover:underline"
                                        @click="ouvrirDialogReception(m)"
                                    >
                                        Confirmer réception
                                    </button>
                                    <button
                                        v-if="m.peut_annuler"
                                        type="button"
                                        class="text-xs font-medium text-muted-foreground hover:underline"
                                        @click="ouvrirDialogMotif('annuler', m)"
                                    >
                                        Annuler
                                    </button>
                                    <button
                                        v-if="m.peut_contester"
                                        type="button"
                                        class="text-xs font-medium text-red-600 hover:underline dark:text-red-400"
                                        @click="
                                            ouvrirDialogMotif('contester', m)
                                        "
                                    >
                                        Contester
                                    </button>
                                    <button
                                        v-if="m.peut_confirmer_retour"
                                        type="button"
                                        class="text-xs font-medium text-primary hover:underline"
                                        @click="
                                            ouvrirDialogMotif(
                                                'confirmer-retour',
                                                m,
                                            )
                                        "
                                    >
                                        Confirmer le retour
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="mouvements.data.length === 0">
                            <td
                                colspan="9"
                                class="px-4 py-10 text-center text-muted-foreground"
                            >
                                Aucun mouvement de fonds.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Dialog
            :open="motifDialogOpen"
            @update:open="
                (ouvert: boolean) => {
                    if (!enCours) motifDialogOpen = ouvert;
                }
            "
        >
            <DialogContent
                class="sm:max-w-md"
                :class="{ [CROIX_INACTIVE]: enCours }"
            >
                <DialogHeader>
                    <DialogTitle>
                        {{ motifDialogTitres[motifDialogAction] }}
                        {{ motifCible?.reference }}
                    </DialogTitle>
                </DialogHeader>
                <div class="space-y-1.5">
                    <Label for="motif">Motif</Label>
                    <textarea
                        id="motif"
                        v-model="motif"
                        rows="3"
                        placeholder="Expliquez la raison…"
                        class="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    />
                    <p
                        v-if="motifError"
                        class="text-xs text-red-600 dark:text-red-400"
                    >
                        {{ motifError }}
                    </p>
                </div>
                <DialogFooter>
                    <button
                        type="button"
                        data-testid="motif-annuler"
                        :disabled="enCours"
                        class="h-9 rounded-md border px-4 text-sm disabled:cursor-not-allowed disabled:opacity-50"
                        @click="motifDialogOpen = false"
                    >
                        Annuler
                    </button>
                    <button
                        type="button"
                        data-testid="motif-confirmer"
                        :disabled="enCours"
                        :aria-busy="enCours"
                        class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground disabled:cursor-not-allowed disabled:opacity-50"
                        @click="confirmerMotif"
                    >
                        <Spinner v-if="enCours" />
                        {{ enCours ? 'Confirmation…' : 'Confirmer' }}
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="receptionDialogOpen"
            @update:open="
                (ouvert: boolean) => {
                    if (!enCours) receptionDialogOpen = ouvert;
                }
            "
        >
            <DialogContent
                class="sm:max-w-md"
                :class="{ [CROIX_INACTIVE]: enCours }"
            >
                <DialogHeader>
                    <DialogTitle>
                        Confirmer réception {{ receptionCible?.reference }}
                    </DialogTitle>
                </DialogHeader>
                <div
                    v-if="receptionCible && estVersement(receptionCible)"
                    class="space-y-1.5"
                    data-testid="reception-versement"
                >
                    <p class="text-sm">
                        Caisse de destination :
                        <span class="font-medium">{{
                            receptionCible.compte_destination
                        }}</span>
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Cette caisse a été fixée à l'envoi. Confirmez que les
                        {{ formatGNF(receptionCible.montant) }} envoyés par
                        {{ receptionCible.expediteur ?? "l'expéditeur" }} ont
                        bien été reçus.
                    </p>
                    <p
                        v-if="receptionError"
                        class="text-xs text-red-600 dark:text-red-400"
                    >
                        {{ receptionError }}
                    </p>
                </div>
                <div v-else class="space-y-1.5">
                    <Label for="compte-reception"
                        >Support de trésorerie reçu</Label
                    >
                    <select
                        id="compte-reception"
                        v-model="receptionCompteId"
                        class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="" disabled>Sélectionner…</option>
                        <option
                            v-for="c in comptesReception"
                            :key="c.id"
                            :value="c.id"
                        >
                            {{ c.libelle }}
                        </option>
                    </select>
                    <p class="text-xs text-muted-foreground">
                        Indiquez dans quelle caisse, banque ou wallet les fonds
                        ont réellement été reçus à
                        {{ receptionCible?.site_destination }}.
                    </p>
                    <p
                        v-if="receptionError"
                        class="text-xs text-red-600 dark:text-red-400"
                    >
                        {{ receptionError }}
                    </p>
                </div>
                <DialogFooter>
                    <button
                        type="button"
                        data-testid="reception-annuler"
                        :disabled="enCours"
                        class="h-9 rounded-md border px-4 text-sm disabled:cursor-not-allowed disabled:opacity-50"
                        @click="receptionDialogOpen = false"
                    >
                        Annuler
                    </button>
                    <button
                        type="button"
                        data-testid="reception-confirmer"
                        :disabled="enCours || !receptionCompteId"
                        :aria-busy="enCours"
                        class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground disabled:cursor-not-allowed disabled:opacity-50"
                        @click="confirmerReception"
                    >
                        <Spinner v-if="enCours" />
                        {{ enCours ? 'Confirmation…' : 'Confirmer' }}
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
