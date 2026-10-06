<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { useFlashToast } from '@/composables/useFlashToast';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CircleAlert,
    Loader2,
    RotateCcw,
    Save,
    Send,
    Trash2,
    Wand2,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
} from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────

interface Membre {
    livreur_id: string;
    nom: string;
    role: string;
    ordre: number;
    requis: boolean;
    actuel: number | null;
    prepare: number | null;
    proposition: number | null;
}

interface Groupe {
    cle: string;
    equipe_id: string;
    vehicule_id: string;
    vehicule_nom: string;
    immatriculation: string | null;
    site_id: string | null;
    site_nom: string | null;
    type_vehicule_id: string | null;
    type_vehicule_nom: string | null;
    categorie_id: string;
    categorie_nom: string;
    bareme_actuel: number;
    bareme_cible: number;
    total_actuel: number;
    membres: Membre[];
    statut: 'a_corriger' | 'conforme' | 'a_revalider';
    motif: string | null;
}

const props = defineProps<{
    brouillon: {
        id: string;
        processus_code: string;
        processus_label: string;
        createur: string | null;
        created_at: string | null;
        updated_at: string | null;
    };
    groupes: Groupe[];
    resume: {
        total: number;
        nb_equipes: number;
        conformes: number;
        a_corriger: number;
        a_revalider: number;
    };
    filters: Record<string, unknown>;
    permissions: { modifier: boolean; publier: boolean; abandonner: boolean };
}>();

useFlashToast();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Paramètres', href: '/settings/profile' },
    {
        title: 'Commissions',
        href: `/settings/commissions?processus=${props.brouillon.processus_code}`,
    },
    { title: 'Reconfiguration des partages', href: '#' },
];

const baseUrl = `/settings/commissions/brouillons/${props.brouillon.id}`;
// Vrai pendant une requête lancée par cette page (enregistrer, publier, abandonner) : la garde de
// navigation ne doit pas bloquer ses propres visites.
let navigationAutorisee = false;
const formatNombre = (n: number) => new Intl.NumberFormat('fr-FR').format(n);
const formatGnf = (n: number) =>
    `${new Intl.NumberFormat('fr-FR').format(n)} GNF`;
const roleLabel = (role: string) =>
    role === 'chauffeur'
        ? 'Chauffeur'
        : role === 'convoyeur'
          ? 'Convoyeur'
          : role;

// ── Saisie (jamais enregistrée tant que « Enregistrer » n'est pas cliqué) ────────
// Valeurs de cellule gardées en texte, pour distinguer « vide » (aucune part) de « 0 » (part à
// 0 GNF, valide) — la règle métier (somme exacte, chaque membre présent) est rejouée par le
// serveur à l'enregistrement et à la publication, cette grille n'en est qu'un miroir.

type Saisie = Record<string, string>;
const saisies = reactive<Record<string, Saisie>>({});
const initiales = ref<Record<string, string>>({});

function valeurInitiale(m: Membre): string {
    return m.prepare === null ? '' : String(m.prepare);
}

function initialiser(): void {
    Object.keys(saisies).forEach((cle) => delete saisies[cle]);
    const snap: Record<string, string> = {};
    props.groupes.forEach((g) => {
        saisies[g.cle] = Object.fromEntries(
            g.membres.map((m) => [m.livreur_id, valeurInitiale(m)]),
        );
        snap[g.cle] = JSON.stringify(saisies[g.cle]);
    });
    initiales.value = snap;
}

initialiser();
watch(() => props.groupes, initialiser);

function estModifie(g: Groupe): boolean {
    return JSON.stringify(saisies[g.cle]) !== initiales.value[g.cle];
}

const groupesModifies = computed(() => props.groupes.filter(estModifie));

function total(g: Groupe): number {
    return g.membres.reduce(
        (somme, m) => somme + (Number(saisies[g.cle]?.[m.livreur_id]) || 0),
        0,
    );
}

function sansPart(g: Groupe): Membre[] {
    return g.membres.filter(
        (m) => m.requis && (saisies[g.cle]?.[m.livreur_id] ?? '') === '',
    );
}

function conformeLocal(g: Groupe): boolean {
    return total(g) === g.bareme_cible && sansPart(g).length === 0;
}

function statutAffiche(g: Groupe): { status: string; label: string } {
    if (estModifie(g)) {
        return conformeLocal(g)
            ? { status: 'modifie', label: 'À enregistrer' }
            : { status: 'a_corriger', label: 'Non conforme' };
    }

    return {
        conforme: { status: 'conforme', label: 'Conforme' },
        a_corriger: { status: 'a_corriger', label: 'À corriger' },
        a_revalider: { status: 'a_revalider', label: 'À revalider' },
    }[g.statut];
}

function ecartLibelle(g: Groupe): string {
    const ecart = g.bareme_cible - total(g);
    if (ecart === 0) return '';
    return ecart > 0
        ? `Manque ${formatGnf(ecart)}`
        : `Dépasse de ${formatGnf(-ecart)}`;
}

// ── Filtres (côté navigateur : les saisies non enregistrées survivent aux filtres/pages) ─

const valeursFiltres = ref<Record<string, unknown>>({ ...props.filters });

const filterFields = computed<FilterField[]>(() => {
    const unique = <T,>(items: T[], cle: (t: T) => string) => [
        ...new Map(items.map((i) => [cle(i), i])).values(),
    ];

    return [
        {
            key: 'statut',
            label: 'Statut',
            type: 'select',
            inline: true,
            options: [
                { value: 'a_corriger', label: 'À corriger' },
                { value: 'a_revalider', label: 'À revalider' },
                { value: 'conforme', label: 'Conforme' },
                { value: 'modifie', label: 'Modifié, non enregistré' },
            ],
        },
        {
            key: 'search',
            label: 'Recherche',
            type: 'text',
            inline: true,
            placeholder: 'Véhicule, immatriculation, membre…',
        },
        {
            key: 'categorie_id',
            label: 'Catégorie',
            type: 'select',
            inline: true,
            options: unique(props.groupes, (g) => g.categorie_id).map((g) => ({
                value: g.categorie_id,
                label: g.categorie_nom,
            })),
        },
        {
            key: 'type_vehicule_id',
            label: 'Type de véhicule',
            type: 'select',
            options: unique(
                props.groupes.filter((g) => g.type_vehicule_id),
                (g) => g.type_vehicule_id as string,
            ).map((g) => ({
                value: g.type_vehicule_id as string,
                label: g.type_vehicule_nom ?? '—',
            })),
        },
    ];
});

const groupesFiltres = computed(() => {
    const f = valeursFiltres.value;
    const recherche = String(f.search ?? '')
        .trim()
        .toLowerCase();
    const sites = (Array.isArray(f.site_ids) ? f.site_ids : []) as string[];

    return props.groupes.filter((g) => {
        if (sites.length && !sites.includes(g.site_id ?? '')) return false;
        if (f.categorie_id && g.categorie_id !== f.categorie_id) return false;
        if (f.type_vehicule_id && g.type_vehicule_id !== f.type_vehicule_id)
            return false;
        if (f.statut && statutAffiche(g).status !== f.statut) return false;
        if (recherche) {
            const texte = [
                g.vehicule_nom,
                g.immatriculation,
                g.categorie_nom,
                ...g.membres.map((m) => m.nom),
            ]
                .join(' ')
                .toLowerCase();
            if (!texte.includes(recherche)) return false;
        }
        return true;
    });
});

// ── Pagination (rendu) ──────────────────────────────────────────────────────────

const PAR_PAGE = 25;
const page = ref(1);
const nbPages = computed(() =>
    Math.max(1, Math.ceil(groupesFiltres.value.length / PAR_PAGE)),
);
const groupesPage = computed(() =>
    groupesFiltres.value.slice(
        (page.value - 1) * PAR_PAGE,
        page.value * PAR_PAGE,
    ),
);
watch(groupesFiltres, () => {
    if (page.value > nbPages.value) page.value = nbPages.value;
});

function appliquerFiltres(values: Record<string, unknown>): void {
    valeursFiltres.value = values;
    page.value = 1;
}

// ── Sélection et actions groupées ───────────────────────────────────────────────

const selection = ref<Set<string>>(new Set());

const cibleActions = computed<Groupe[]>(() =>
    selection.value.size
        ? props.groupes.filter((g) => selection.value.has(g.cle))
        : groupesFiltres.value,
);

const toutePageSelectionnee = computed(
    () =>
        groupesPage.value.length > 0 &&
        groupesPage.value.every((g) => selection.value.has(g.cle)),
);

function basculerSelection(cle: string): void {
    const s = new Set(selection.value);
    if (s.has(cle)) s.delete(cle);
    else s.add(cle);
    selection.value = s;
}

function basculerPage(): void {
    const s = new Set(selection.value);
    const tout = toutePageSelectionnee.value;
    groupesPage.value.forEach((g) => (tout ? s.delete(g.cle) : s.add(g.cle)));
    selection.value = s;
}

function selectionnerFiltres(): void {
    selection.value = new Set(groupesFiltres.value.map((g) => g.cle));
}

function viderSelection(): void {
    selection.value = new Set();
}

/** Préremplit avec la proposition proportionnelle — rien n'est enregistré. */
function appliquerProposition(): void {
    cibleActions.value.forEach((g) => {
        if (g.membres.some((m) => m.proposition === null)) return;
        g.membres.forEach((m) => {
            saisies[g.cle][m.livreur_id] = String(m.proposition);
        });
    });
}

const montantChauffeur = ref('');
const montantConvoyeur = ref('');

function appliquerParRole(): void {
    cibleActions.value.forEach((g) => {
        g.membres.forEach((m) => {
            const valeur =
                m.role === 'chauffeur'
                    ? montantChauffeur.value
                    : m.role === 'convoyeur'
                      ? montantConvoyeur.value
                      : '';
            if (valeur !== '') saisies[g.cle][m.livreur_id] = valeur;
        });
    });
}

/** Recopie la répartition de la première équipe sélectionnée sur les suivantes de même composition. */
function recopierPremiere(): void {
    const [modele, ...autres] = cibleActions.value;
    if (!modele) return;
    const roles = (g: Groupe) => g.membres.map((m) => m.role).join(',');
    const valeurs = modele.membres.map(
        (m) => saisies[modele.cle][m.livreur_id],
    );
    autres
        .filter((g) => roles(g) === roles(modele))
        .forEach((g) =>
            g.membres.forEach((m, i) => {
                saisies[g.cle][m.livreur_id] = valeurs[i];
            }),
        );
}

function annulerModifications(): void {
    props.groupes.forEach((g) => {
        saisies[g.cle] = JSON.parse(initiales.value[g.cle]);
    });
    erreurs.value = {};
}

// ── Cellules : clavier et collage type tableur ──────────────────────────────────

const grille = ref<HTMLElement | null>(null);

function cellules(): HTMLInputElement[] {
    return Array.from(
        grille.value?.querySelectorAll<HTMLInputElement>(
            'input[data-cellule]',
        ) ?? [],
    );
}

function focusCellule(depuis: HTMLInputElement, delta: number): void {
    const liste = cellules();
    const suivante = liste[liste.indexOf(depuis) + delta];
    if (suivante) {
        suivante.focus();
        suivante.select();
    }
}

function surTouche(event: KeyboardEvent): void {
    const input = event.target as HTMLInputElement;
    if (event.key === 'ArrowDown' || event.key === 'Enter') {
        event.preventDefault();
        focusCellule(input, 1);
        return;
    }
    if (event.key === 'ArrowUp') {
        event.preventDefault();
        focusCellule(input, -1);
        return;
    }
    const permises = [
        'Backspace',
        'Delete',
        'Tab',
        'Escape',
        'ArrowLeft',
        'ArrowRight',
        'Home',
        'End',
    ];
    if (permises.includes(event.key)) return;
    if (
        (event.ctrlKey || event.metaKey) &&
        ['a', 'c', 'v', 'x', 'z'].includes(event.key.toLowerCase())
    )
        return;
    if (!/^\d$/.test(event.key)) event.preventDefault();
}

/** Collage d'une colonne copiée depuis un tableur : remplit cette cellule puis les suivantes. */
function surCollage(event: ClipboardEvent): void {
    event.preventDefault();
    const valeurs = (event.clipboardData?.getData('text') ?? '')
        .split(/\r?\n/)
        .map((ligne) => ligne.split('\t')[0].replace(/\D/g, ''))
        .filter((v, i, tout) => !(i === tout.length - 1 && v === ''));
    const liste = cellules();
    const depart = liste.indexOf(event.target as HTMLInputElement);
    valeurs.forEach((valeur, i) => {
        const input = liste[depart + i];
        if (!input) return;
        const { cle, livreur } = input.dataset;
        if (cle && livreur) saisies[cle][livreur] = valeur;
    });
}

function surSaisie(cle: string, livreurId: string, event: Event): void {
    saisies[cle][livreurId] = (event.target as HTMLInputElement).value.replace(
        /\D/g,
        '',
    );
}

// ── Enregistrement (atomique côté serveur) ──────────────────────────────────────

const enregistrement = ref(false);
const erreurs = ref<Record<string, string>>({});

function enregistrer(): void {
    if (!groupesModifies.value.length || enregistrement.value) return;
    enregistrement.value = true;
    erreurs.value = {};
    navigationAutorisee = true;

    router.put(
        `${baseUrl}/partages`,
        {
            saisies: groupesModifies.value.map((g) => ({
                equipe_id: g.equipe_id,
                categorie_id: g.categorie_id,
                parts: g.membres
                    .filter((m) => saisies[g.cle][m.livreur_id] !== '')
                    .map((m) => ({
                        livreur_id: m.livreur_id,
                        montant_unitaire: Number(saisies[g.cle][m.livreur_id]),
                    })),
            })),
        },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (e) => {
                erreurs.value = e as Record<string, string>;
            },
            onFinish: () => {
                enregistrement.value = false;
                navigationAutorisee = false;
            },
        },
    );
}

function erreurGroupe(g: Groupe): string | undefined {
    return erreurs.value[`groupes.${g.cle}`];
}

const erreursGenerales = computed(() =>
    Object.entries(erreurs.value)
        .filter(([cle]) => !cle.startsWith('groupes.'))
        .map(([, message]) => message),
);

// ── Publication / abandon ───────────────────────────────────────────────────────

const toutConforme = computed(
    () =>
        props.resume.total > 0 &&
        props.resume.conformes === props.resume.total &&
        groupesModifies.value.length === 0,
);

const publicationVisible = ref(false);
const abandonVisible = ref(false);
const publicationEnCours = ref(false);

function publier(): void {
    publicationEnCours.value = true;
    navigationAutorisee = true;
    router.post(
        `${baseUrl}/publier`,
        {},
        {
            preserveScroll: true,
            onError: (e) => {
                erreurs.value = e as Record<string, string>;
                publicationVisible.value = false;
            },
            onFinish: () => {
                publicationEnCours.value = false;
                navigationAutorisee = false;
            },
        },
    );
}

function abandonner(): void {
    publicationEnCours.value = true;
    navigationAutorisee = true;
    router.delete(baseUrl, {
        onFinish: () => {
            publicationEnCours.value = false;
            navigationAutorisee = false;
        },
    });
}

// ── Garde : quitter la page avec des saisies non enregistrées ───────────────────

function avantDechargement(event: BeforeUnloadEvent): void {
    if (groupesModifies.value.length) event.preventDefault();
}

const retirerGarde = router.on('before', (event) => {
    if (navigationAutorisee || !groupesModifies.value.length) return;
    if (event.detail.visit.method !== 'get') return;
    if (
        !window.confirm(
            'Des partages modifiés ne sont pas enregistrés. Quitter quand même ?',
        )
    )
        event.preventDefault();
});

onMounted(() => window.addEventListener('beforeunload', avantDechargement));
onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', avantDechargement);
    retirerGarde();
});

const baremesModifies = computed(() => {
    const vus = new Map<string, Groupe>();
    props.groupes.forEach((g) =>
        vus.set(`${g.categorie_id}|${g.type_vehicule_id}`, g),
    );
    return [...vus.values()];
});
</script>

<template>
    <Head title="Reconfiguration des partages" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingsLayout :wide="true">
            <div class="max-w-full min-w-0 space-y-5 pb-28">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <HeadingSmall
                        :title="`Reconfiguration des partages — ${brouillon.processus_label}`"
                        description="Le nouveau barème n’est pas encore appliqué. Préparez les partages Livreur des équipes concernées, puis publiez le tout en une seule fois."
                    />
                    <Link
                        :href="`/settings/commissions?processus=${brouillon.processus_code}`"
                    >
                        <Button type="button" variant="outline" size="sm">
                            <ArrowLeft class="h-4 w-4" />
                            Modifier le barème
                        </Button>
                    </Link>
                </div>

                <!-- Résumé -->
                <div
                    class="grid gap-3 sm:grid-cols-4"
                    data-testid="reconfiguration-resume"
                >
                    <div class="rounded-lg border bg-card p-4">
                        <p class="text-xs text-muted-foreground">
                            Partages concernés
                        </p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums">
                            {{ resume.total }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            sur {{ resume.nb_equipes }} équipe(s)
                        </p>
                    </div>
                    <div class="rounded-lg border bg-card p-4">
                        <p class="text-xs text-muted-foreground">Conformes</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums">
                            {{ resume.conformes }}
                        </p>
                    </div>
                    <div class="rounded-lg border bg-card p-4">
                        <p class="text-xs text-muted-foreground">À corriger</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums">
                            {{ resume.a_corriger }}
                        </p>
                    </div>
                    <div class="rounded-lg border bg-card p-4">
                        <p class="text-xs text-muted-foreground">À revalider</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums">
                            {{ resume.a_revalider }}
                        </p>
                    </div>
                </div>

                <!-- Barèmes modifiés -->
                <div class="rounded-lg border bg-card p-4 text-sm">
                    <p class="text-xs font-medium text-muted-foreground">
                        Barème Livreur (GNF/pack) — en vigueur → nouveau
                    </p>
                    <ul class="mt-2 flex flex-wrap gap-x-6 gap-y-1">
                        <li
                            v-for="b in baremesModifies"
                            :key="`${b.categorie_id}|${b.type_vehicule_id}`"
                        >
                            <span class="font-medium">{{
                                b.categorie_nom
                            }}</span>
                            <span class="text-muted-foreground">
                                · {{ b.type_vehicule_nom ?? 'Tous types' }} :
                            </span>
                            <span class="tabular-nums">
                                {{ formatGnf(b.bareme_actuel) }} →
                                <strong>{{ formatGnf(b.bareme_cible) }}</strong>
                            </span>
                        </li>
                    </ul>
                </div>

                <div
                    v-for="message in erreursGenerales"
                    :key="message"
                    class="flex items-start gap-2 rounded-lg border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive"
                    data-testid="reconfiguration-erreur"
                >
                    <CircleAlert class="mt-0.5 h-4 w-4 shrink-0" />
                    {{ message }}
                </div>

                <DataFilters
                    :values="valeursFiltres"
                    :fields="filterFields"
                    :result-count="groupesFiltres.length"
                    @apply="appliquerFiltres"
                    @reset="appliquerFiltres({})"
                />

                <!-- Actions groupées -->
                <div
                    v-if="permissions.modifier"
                    class="flex flex-wrap items-end gap-3 rounded-lg border bg-card p-3 text-sm"
                    data-testid="reconfiguration-actions"
                >
                    <div class="text-xs text-muted-foreground">
                        <template v-if="selection.size">
                            {{ selection.size }} partage(s) sélectionné(s)
                            <button
                                type="button"
                                class="ml-1 underline"
                                @click="viderSelection"
                            >
                                Désélectionner
                            </button>
                        </template>
                        <template v-else>
                            Sans sélection, les actions portent sur les
                            {{ groupesFiltres.length }} partage(s) filtré(s)
                            <button
                                type="button"
                                class="ml-1 underline"
                                @click="selectionnerFiltres"
                            >
                                Tout sélectionner
                            </button>
                        </template>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        data-testid="reconfiguration-proposition"
                        @click="appliquerProposition"
                    >
                        <Wand2 class="h-4 w-4" />
                        Proposer (proportionnel)
                    </Button>
                    <div class="flex items-end gap-2">
                        <label class="text-xs">
                            <span class="text-muted-foreground">Chauffeur</span>
                            <input
                                v-model="montantChauffeur"
                                inputmode="numeric"
                                class="mt-1 block h-8 w-24 rounded-md border bg-background px-2 text-right tabular-nums"
                                data-testid="reconfiguration-role-chauffeur"
                                @input="
                                    montantChauffeur = montantChauffeur.replace(
                                        /\D/g,
                                        '',
                                    )
                                "
                            />
                        </label>
                        <label class="text-xs">
                            <span class="text-muted-foreground">Convoyeur</span>
                            <input
                                v-model="montantConvoyeur"
                                inputmode="numeric"
                                class="mt-1 block h-8 w-24 rounded-md border bg-background px-2 text-right tabular-nums"
                                data-testid="reconfiguration-role-convoyeur"
                                @input="
                                    montantConvoyeur = montantConvoyeur.replace(
                                        /\D/g,
                                        '',
                                    )
                                "
                            />
                        </label>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            data-testid="reconfiguration-appliquer-roles"
                            @click="appliquerParRole"
                        >
                            Appliquer par rôle
                        </Button>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="recopierPremiere"
                    >
                        Recopier la première
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :disabled="!groupesModifies.length"
                        @click="annulerModifications"
                    >
                        <RotateCcw class="h-4 w-4" />
                        Annuler les modifications
                    </Button>
                </div>

                <!-- Grille -->
                <div
                    ref="grille"
                    class="overflow-x-auto rounded-xl border bg-card"
                >
                    <table
                        class="w-full min-w-[980px] text-sm"
                        data-testid="reconfiguration-grille"
                    >
                        <thead
                            class="border-b bg-muted/20 text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="w-10 px-3 py-2">
                                    <input
                                        type="checkbox"
                                        :checked="toutePageSelectionnee"
                                        aria-label="Sélectionner la page"
                                        @change="basculerPage"
                                    />
                                </th>
                                <th class="px-3 py-2 text-left font-medium">
                                    Véhicule
                                </th>
                                <th class="px-3 py-2 text-left font-medium">
                                    Catégorie
                                </th>
                                <th class="px-3 py-2 text-left font-medium">
                                    Membre
                                </th>
                                <th class="px-3 py-2 text-left font-medium">
                                    Rôle
                                </th>
                                <th class="px-3 py-2 text-right font-medium">
                                    Actuel (GNF)
                                </th>
                                <th class="px-3 py-2 text-right font-medium">
                                    Nouveau (GNF)
                                </th>
                                <th class="px-3 py-2 text-right font-medium">
                                    Proposé (GNF)
                                </th>
                                <th class="px-3 py-2 text-right font-medium">
                                    Total / barème (GNF)
                                </th>
                                <th class="px-3 py-2 text-left font-medium">
                                    Statut
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="g in groupesPage" :key="g.cle">
                                <tr
                                    v-for="(m, i) in g.membres"
                                    :key="`${g.cle}|${m.livreur_id}`"
                                    :class="[
                                        i === 0 ? 'border-t' : '',
                                        selection.has(g.cle)
                                            ? 'bg-primary/5'
                                            : '',
                                    ]"
                                    :data-testid="`reconfiguration-ligne-${g.vehicule_nom}-${g.categorie_nom}-${i}`"
                                >
                                    <template v-if="i === 0">
                                        <td
                                            :rowspan="g.membres.length"
                                            class="px-3 py-2 align-top"
                                        >
                                            <input
                                                type="checkbox"
                                                :checked="selection.has(g.cle)"
                                                :aria-label="`Sélectionner ${g.vehicule_nom} ${g.categorie_nom}`"
                                                @change="
                                                    basculerSelection(g.cle)
                                                "
                                            />
                                        </td>
                                        <td
                                            :rowspan="g.membres.length"
                                            class="px-3 py-2 align-top"
                                        >
                                            <p class="font-medium">
                                                {{ g.vehicule_nom }}
                                            </p>
                                            <p
                                                class="text-xs text-muted-foreground"
                                            >
                                                {{
                                                    [
                                                        g.immatriculation,
                                                        g.type_vehicule_nom,
                                                        g.site_nom,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ')
                                                }}
                                            </p>
                                        </td>
                                        <td
                                            :rowspan="g.membres.length"
                                            class="px-3 py-2 align-top"
                                        >
                                            {{ g.categorie_nom }}
                                        </td>
                                    </template>
                                    <td class="px-3 py-1.5">
                                        {{ m.nom }}
                                        <span
                                            v-if="!m.requis"
                                            class="text-xs text-muted-foreground"
                                        >
                                            (inactif)
                                        </span>
                                    </td>
                                    <td
                                        class="px-3 py-1.5 text-muted-foreground"
                                    >
                                        {{ roleLabel(m.role) }}
                                    </td>
                                    <td
                                        class="px-3 py-1.5 text-right text-muted-foreground tabular-nums"
                                    >
                                        {{
                                            m.actuel === null
                                                ? '—'
                                                : formatNombre(m.actuel)
                                        }}
                                    </td>
                                    <td class="px-3 py-1 text-right">
                                        <input
                                            :value="
                                                saisies[g.cle]?.[
                                                    m.livreur_id
                                                ] ?? ''
                                            "
                                            :readonly="!permissions.modifier"
                                            inputmode="numeric"
                                            autocomplete="off"
                                            data-cellule
                                            :data-cle="g.cle"
                                            :data-livreur="m.livreur_id"
                                            :aria-label="`Nouveau montant ${m.nom} ${g.vehicule_nom} ${g.categorie_nom}`"
                                            class="h-8 w-24 rounded-md border bg-background px-2 text-right tabular-nums focus:ring-2 focus:ring-primary focus:outline-none"
                                            :class="
                                                m.requis &&
                                                (saisies[g.cle]?.[
                                                    m.livreur_id
                                                ] ?? '') === '' &&
                                                estModifie(g)
                                                    ? 'border-amber-500'
                                                    : ''
                                            "
                                            @input="
                                                surSaisie(
                                                    g.cle,
                                                    m.livreur_id,
                                                    $event,
                                                )
                                            "
                                            @keydown="surTouche"
                                            @paste="surCollage"
                                            @focus="
                                                (
                                                    $event.target as HTMLInputElement
                                                ).select()
                                            "
                                        />
                                    </td>
                                    <td
                                        class="px-3 py-1.5 text-right text-xs text-muted-foreground tabular-nums"
                                    >
                                        {{
                                            m.proposition === null
                                                ? '—'
                                                : formatNombre(m.proposition)
                                        }}
                                    </td>
                                    <template v-if="i === 0">
                                        <td
                                            :rowspan="g.membres.length"
                                            class="px-3 py-2 text-right align-top tabular-nums"
                                        >
                                            <p
                                                :class="
                                                    total(g) === g.bareme_cible
                                                        ? 'text-emerald-700 dark:text-emerald-400'
                                                        : 'text-amber-700 dark:text-amber-400'
                                                "
                                                class="font-medium"
                                                :data-testid="`reconfiguration-total-${g.vehicule_nom}-${g.categorie_nom}`"
                                            >
                                                {{ formatNombre(total(g)) }} /
                                                {{
                                                    formatNombre(g.bareme_cible)
                                                }}
                                            </p>
                                            <p
                                                class="text-xs text-muted-foreground"
                                            >
                                                {{ ecartLibelle(g) }}
                                            </p>
                                        </td>
                                        <td
                                            :rowspan="g.membres.length"
                                            class="min-w-[10rem] px-3 py-2 align-top"
                                        >
                                            <StatusDot
                                                :status="
                                                    statutAffiche(g).status
                                                "
                                                :label="statutAffiche(g).label"
                                                :data-testid="`reconfiguration-statut-${g.vehicule_nom}-${g.categorie_nom}`"
                                            />
                                            <p
                                                v-if="
                                                    sansPart(g).length &&
                                                    estModifie(g)
                                                "
                                                class="mt-1 text-xs text-amber-700 dark:text-amber-400"
                                            >
                                                Sans part :
                                                {{
                                                    sansPart(g)
                                                        .map((x) => x.nom)
                                                        .join(', ')
                                                }}
                                            </p>
                                            <p
                                                v-if="!estModifie(g) && g.motif"
                                                class="mt-1 max-w-[16rem] text-xs text-muted-foreground"
                                            >
                                                {{ g.motif }}
                                            </p>
                                            <p
                                                v-if="erreurGroupe(g)"
                                                class="mt-1 max-w-[16rem] text-xs text-destructive"
                                            >
                                                {{ erreurGroupe(g) }}
                                            </p>
                                        </td>
                                    </template>
                                </tr>
                            </template>
                            <tr v-if="!groupesPage.length">
                                <td
                                    colspan="10"
                                    class="px-6 py-10 text-center text-sm text-muted-foreground"
                                >
                                    Aucun partage ne correspond aux filtres.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="nbPages > 1"
                    class="flex items-center justify-end gap-2 text-sm"
                >
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="page === 1"
                        @click="page--"
                    >
                        Précédent
                    </Button>
                    <span class="tabular-nums"
                        >Page {{ page }} / {{ nbPages }}</span
                    >
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="page === nbPages"
                        @click="page++"
                    >
                        Suivant
                    </Button>
                </div>

                <!-- Barre d'actions -->
                <div
                    class="sticky bottom-0 z-10 flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-card px-5 py-3"
                >
                    <div class="text-sm">
                        <template v-if="groupesModifies.length">
                            <strong>{{ groupesModifies.length }}</strong>
                            partage(s) modifié(s), non enregistré(s)
                        </template>
                        <template v-else-if="toutConforme">
                            Tous les partages sont conformes : le barème peut
                            être publié.
                        </template>
                        <template v-else>
                            {{ resume.total - resume.conformes }} partage(s)
                            restent à préparer avant publication.
                        </template>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-if="permissions.abandonner"
                            type="button"
                            variant="ghost"
                            class="text-muted-foreground hover:text-destructive"
                            @click="abandonVisible = true"
                        >
                            <Trash2 class="h-4 w-4" />
                            Abandonner le brouillon
                        </Button>
                        <Button
                            v-if="permissions.modifier"
                            type="button"
                            variant="outline"
                            :disabled="
                                !groupesModifies.length || enregistrement
                            "
                            data-testid="reconfiguration-enregistrer"
                            @click="enregistrer"
                        >
                            <Loader2
                                v-if="enregistrement"
                                class="h-4 w-4 animate-spin"
                            />
                            <Save v-else class="h-4 w-4" />
                            Enregistrer les modifications ({{
                                groupesModifies.length
                            }})
                        </Button>
                        <Button
                            v-if="permissions.publier"
                            type="button"
                            :disabled="!toutConforme"
                            data-testid="reconfiguration-publier"
                            @click="publicationVisible = true"
                        >
                            <Send class="h-4 w-4" />
                            Publier le barème
                        </Button>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>

    <Dialog
        :visible="publicationVisible"
        modal
        header="Publier le nouveau barème"
        :closable="!publicationEnCours"
        :style="{ width: 'min(560px, 94vw)' }"
        @update:visible="
            (v: boolean) => !publicationEnCours && (publicationVisible = v)
        "
    >
        <p class="text-sm">
            Le nouveau barème et les {{ resume.total }} partage(s) préparés
            s’appliqueront ensemble dès aujourd’hui aux nouvelles commissions.
            Les commissions déjà générées ne changent pas.
        </p>
        <div class="mt-5 flex justify-end gap-2">
            <Button
                type="button"
                variant="outline"
                :disabled="publicationEnCours"
                @click="publicationVisible = false"
            >
                Retour
            </Button>
            <Button
                type="button"
                :disabled="publicationEnCours"
                data-testid="reconfiguration-confirmer-publication"
                @click="publier"
            >
                <Loader2
                    v-if="publicationEnCours"
                    class="h-4 w-4 animate-spin"
                />
                Publier
            </Button>
        </div>
    </Dialog>

    <Dialog
        :visible="abandonVisible"
        modal
        header="Abandonner le brouillon"
        :closable="!publicationEnCours"
        :style="{ width: 'min(520px, 94vw)' }"
        @update:visible="
            (v: boolean) => !publicationEnCours && (abandonVisible = v)
        "
    >
        <p class="text-sm">
            Le nouveau barème et les partages préparés seront abandonnés. Le
            barème en vigueur reste inchangé.
        </p>
        <div class="mt-5 flex justify-end gap-2">
            <Button
                type="button"
                variant="outline"
                :disabled="publicationEnCours"
                @click="abandonVisible = false"
            >
                Retour
            </Button>
            <Button
                type="button"
                variant="destructive"
                :disabled="publicationEnCours"
                @click="abandonner"
            >
                <Loader2
                    v-if="publicationEnCours"
                    class="h-4 w-4 animate-spin"
                />
                Abandonner
            </Button>
        </div>
    </Dialog>
</template>
