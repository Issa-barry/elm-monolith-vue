<script setup lang="ts">
/**
 * Fusion manuelle de deux fiches (ADR 0060, lot 9b), en trois étapes : choisir la fiche à
 * fusionner, choisir les informations à garder, vérifier le récapitulatif puis confirmer. La fiche
 * de départ est conservée ; l'autre est absorbée puis archivée. Chaque choix relance la simulation
 * côté serveur (aucune écriture) : le récapitulatif n'affiche que ce que la simulation a calculé,
 * et la confirmation refait tous les contrôles sous verrou.
 */
import OptionFusion from '@/components/contacts/OptionFusion.vue';
import DetailHeader from '@/components/DetailHeader.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    ArrowRight,
    GitMerge,
    Loader2,
    Search,
    TriangleAlert,
} from 'lucide-vue-next';
import Checkbox from 'primevue/checkbox';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import { computed, ref } from 'vue';

type TypeFiche = 'personne' | 'entreprise';
type Cote = 'a' | 'b';

interface Resume {
    id: string;
    nom: string;
    lien: string;
    telephone: string | null;
}

interface Candidat {
    id: string;
    nom: string;
    telephone: string | null;
    ville: string | null;
    fonctions: string[];
}

interface Champ {
    cle: string;
    libelle: string;
    valeur_a: string | null;
    valeur_b: string | null;
    different: boolean;
    choix: Cote;
}

interface Numero {
    normalise: string;
    valeur: string;
    origine: string;
    fiche?: Cote;
    rang?: 'principal' | 'secondaire';
}

interface Relation {
    groupe: string;
    libelle: string;
    cote: Cote;
    action: 'conservee' | 'transferee' | 'laissee';
    archivee: boolean;
}

interface Compte {
    cote: Cote;
    nom: string | null;
    statut: string | null;
    actif: boolean;
    roles: string[];
    identifiants_connexion: string[];
    fonctions_rattachables: string[];
}

interface Simulation {
    type: TypeFiche;
    a: Resume;
    b: Resume;
    nom_final?: string;
    champs: Champ[];
    telephones: {
        numeros: Numero[];
        principal: string | null;
        secondaire: string | null;
        retires: Numero[];
    };
    interlocuteur_principal: {
        options: {
            id: string;
            libelle: string;
            nom?: string;
            fiche?: Cote;
        }[];
        choix: string;
    } | null;
    relations: Relation[];
    compte: Compte | null;
    blocages: { code: string; message: string }[];
    confirmations_requises: string[];
    mot_transfert_compte: string;
    choix: Record<string, string | null>;
    empreinte: string;
}

const props = defineProps<{
    type: TypeFiche;
    fiche_a: Resume;
    recherche: string;
    candidats: Candidat[];
    simulation: Simulation | null;
    erreur: string | null;
    jeton: string;
}>();

const dossier = computed(() =>
    props.type === 'personne' ? 'personnes' : 'entreprises',
);
const urlPage = computed(
    () => `/backoffice/contacts/fusion/${props.type}/${props.fiche_a.id}`,
);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    {
        title:
            props.type === 'personne' ? 'Personnes physiques' : 'Entreprises',
        href: `/backoffice/contacts/${dossier.value}`,
    },
    { title: props.fiche_a.nom, href: props.fiche_a.lien },
    { title: 'Fusionner', href: '#' },
]);

const SOURCE: Record<Cote, string> = {
    a: 'Fiche conservée',
    b: 'Fiche à fusionner',
};

function telephone(valeur: string | null): string {
    return formatPhoneDisplay(valeur, null) ?? valeur ?? '—';
}

function origineNumero(n: Numero): string {
    if (!n.fiche || !n.rang) return n.origine;
    return `${SOURCE[n.fiche]} · ${n.rang}`;
}

// ── Étape 1 : fiche à fusionner ─────────────────────────────────────────
const terme = ref(props.recherche);
function rechercher() {
    router.get(
        urlPage.value,
        { recherche: terme.value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
function choisirB(id: string) {
    router.get(urlPage.value, { b: id }, { preserveScroll: true });
}
function changerB() {
    router.get(urlPage.value);
}
function inverser() {
    if (!props.simulation) return;
    router.get(
        `/backoffice/contacts/fusion/${props.type}/${props.simulation.b.id}`,
        { b: props.simulation.a.id },
    );
}

// ── Étape 2 : chaque choix relance la simulation côté serveur ───────────
const choix = ref<Record<string, string | null>>({
    ...(props.simulation?.choix ?? {}),
});
const chargement = ref(false);
function recalculer() {
    if (!props.simulation) return;
    router.get(
        urlPage.value,
        { b: props.simulation.b.id, choix: choix.value },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['simulation', 'erreur'],
            onStart: () => (chargement.value = true),
            onFinish: () => {
                chargement.value = false;
                choix.value = { ...(props.simulation?.choix ?? {}) };
            },
        },
    );
}
function choisir(cle: string, valeur: string | null) {
    if (choix.value[cle] === valeur) return;
    choix.value[cle] = valeur;
    recalculer();
}

const champs = computed(() => props.simulation?.champs ?? []);
const differences = computed(() => champs.value.filter((c) => c.different));
const identiques = computed(() =>
    champs.value.filter((c) => !c.different && c.valeur_a !== null),
);
const valeurRetenue = (c: Champ) => (c.choix === 'b' ? c.valeur_b : c.valeur_a);
const valeurEcartee = (c: Champ) => (c.choix === 'b' ? c.valeur_a : c.valeur_b);

const numeros = computed(() => props.simulation?.telephones.numeros ?? []);
const numero = (normalise: string | null) =>
    numeros.value.find((n) => n.normalise === normalise) ?? null;

const relations = computed(() => props.simulation?.relations ?? []);
const groupes = computed(() => {
    const resultat: Record<string, Relation[]> = {};
    for (const r of relations.value) {
        (resultat[r.groupe] ??= []).push(r);
    }
    return resultat;
});
const ACTIONS: Record<Relation['action'], string> = {
    conservee: 'Déjà sur la fiche conservée',
    transferee: 'Transférée vers la fiche conservée',
    laissee: 'Déjà présente : reste sur la fiche archivée',
};

// ── Étape 3 : récapitulatif, construit uniquement depuis la simulation ──
const nomFinal = computed(
    () => props.simulation?.nom_final ?? props.simulation?.a.nom ?? '',
);
const ficheFinale = computed(() =>
    champs.value
        .map((c) => ({
            cle: c.cle,
            libelle: c.libelle,
            valeur: valeurRetenue(c),
        }))
        .filter((c) => c.valeur !== null),
);
const repris = computed(() =>
    differences.value.filter((c) => c.choix === 'b' && c.valeur_b !== null),
);
const numerosRepris = computed(() =>
    [
        numero(props.simulation?.telephones.principal ?? null),
        numero(props.simulation?.telephones.secondaire ?? null),
    ].filter((n): n is Numero => n !== null && n.fiche === 'b'),
);
const nonRepris = computed(() =>
    differences.value.filter((c) => valeurEcartee(c) !== null),
);
const relationsTransferees = computed(() =>
    relations.value.filter((r) => r.action === 'transferee'),
);
const relationsConservees = computed(() =>
    relations.value.filter((r) => r.action === 'conservee'),
);
const relationsLaissees = computed(() =>
    relations.value.filter((r) => r.action === 'laissee'),
);

const requises = computed(
    () => new Set(props.simulation?.confirmations_requises ?? []),
);
const bloquee = computed(() => (props.simulation?.blocages.length ?? 0) > 0);
const form = useForm({
    type: props.type,
    fiche_a: props.fiche_a.id,
    fiche_b: '',
    choix: {} as Record<string, string | null>,
    confirmations: {
        fusion: false,
        compte: false,
        numeros_retires: false,
        transfert_compte: '',
    },
    empreinte: '',
    jeton: props.jeton,
});
const confirmationsCompletes = computed(() => {
    const c = form.confirmations;
    return (
        c.fusion &&
        (!requises.value.has('compte') || c.compte) &&
        (!requises.value.has('numeros_retires') || c.numeros_retires) &&
        (!requises.value.has('transfert_compte') ||
            c.transfert_compte.trim().toUpperCase() ===
                props.simulation?.mot_transfert_compte)
    );
});
const dialogue = ref(false);
// Basculé dès le clic, avant le rendu : un double clic ne part qu'une fois.
const enCours = ref(false);
function ouvrirRecapitulatif() {
    if (!props.simulation || bloquee.value || chargement.value) return;
    form.clearErrors();
    form.confirmations = {
        fusion: false,
        compte: false,
        numeros_retires: false,
        transfert_compte: '',
    };
    dialogue.value = true;
}
function fermerDialogue(visible: boolean) {
    if (!enCours.value) dialogue.value = visible;
}
function fusionner() {
    if (enCours.value || !props.simulation || !confirmationsCompletes.value)
        return;
    enCours.value = true;
    form.fiche_b = props.simulation.b.id;
    form.choix = { ...props.simulation.choix };
    form.empreinte = props.simulation.empreinte;
    form.post('/backoffice/contacts/fusions', {
        preserveScroll: true,
        onError: () => (dialogue.value = false),
        onFinish: () => (enCours.value = false),
    });
}
const erreurs = computed(() =>
    Object.values(form.errors as Record<string, string>),
);
</script>

<template>
    <Head :title="`Fusionner — ${fiche_a.nom}`" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <DetailHeader
                eyebrow="Fusion de fiches"
                :title="`Fusionner « ${fiche_a.nom} » avec une autre fiche`"
                :icon="GitMerge"
                data-testid="fusion-header"
            >
                <template #subtitle>
                    <p class="mt-1 text-sm text-muted-foreground">
                        « {{ fiche_a.nom }} » est conservée. L'autre fiche lui
                        transmet ses informations et ses fonctions, puis elle
                        est archivée. Rien n'est fait avant votre confirmation.
                    </p>
                </template>
                <template #actions>
                    <Link :href="fiche_a.lien">
                        <Button variant="outline" size="sm">
                            Retour à la fiche
                        </Button>
                    </Link>
                </template>
            </DetailHeader>

            <!-- Étape 1 -->
            <section class="space-y-4 rounded-xl border bg-card p-4 sm:p-6">
                <h2 class="text-base font-semibold">
                    1. Choisir la fiche à fusionner
                </h2>

                <div
                    class="grid items-stretch gap-3 md:grid-cols-[1fr_auto_1fr]"
                >
                    <div class="rounded-lg border p-3">
                        <p class="text-xs text-muted-foreground">
                            Fiche conservée
                        </p>
                        <p class="font-semibold">
                            {{ simulation?.a.nom ?? fiche_a.nom }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{
                                telephone(
                                    simulation?.a.telephone ??
                                        fiche_a.telephone,
                                )
                            }}
                        </p>
                    </div>
                    <div
                        class="flex items-center justify-center text-muted-foreground"
                    >
                        <ArrowRight class="h-5 w-5 -rotate-90 md:rotate-180" />
                    </div>
                    <div
                        v-if="simulation"
                        class="rounded-lg border p-3"
                        data-testid="fusion-fiche-b"
                    >
                        <p class="text-xs text-muted-foreground">
                            Fiche à fusionner, puis archivée
                        </p>
                        <p class="font-semibold">{{ simulation.b.nom }}</p>
                        <p class="text-sm text-muted-foreground">
                            {{ telephone(simulation.b.telephone) }}
                        </p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                data-testid="fusion-changer"
                                @click="changerB"
                            >
                                Choisir une autre fiche
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                data-testid="fusion-inverser"
                                @click="inverser"
                            >
                                <ArrowLeftRight class="mr-1.5 h-4 w-4" />
                                Garder plutôt celle-ci
                            </Button>
                        </div>
                    </div>
                    <div v-else class="space-y-3 rounded-lg border p-3">
                        <p class="text-xs text-muted-foreground">
                            Fiche à fusionner, puis archivée
                        </p>
                        <form class="flex gap-2" @submit.prevent="rechercher">
                            <InputText
                                v-model="terme"
                                class="w-full"
                                placeholder="Nom, téléphone, e-mail…"
                                data-testid="fusion-recherche"
                            />
                            <Button
                                type="submit"
                                data-testid="fusion-rechercher"
                            >
                                <Search class="mr-1.5 h-4 w-4" />
                                Rechercher
                            </Button>
                        </form>
                        <ul
                            v-if="candidats.length > 0"
                            class="divide-y rounded-lg border"
                        >
                            <li
                                v-for="c in candidats"
                                :key="c.id"
                                class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm"
                            >
                                <div>
                                    <p class="font-medium">{{ c.nom }}</p>
                                    <p class="text-muted-foreground">
                                        {{ telephone(c.telephone) }}
                                        <span v-if="c.ville">
                                            · {{ c.ville }}</span
                                        >
                                        <span v-if="c.fonctions.length">
                                            · {{ c.fonctions.join(', ') }}</span
                                        >
                                    </p>
                                </div>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    :data-testid="`fusion-choisir-${c.id}`"
                                    @click="choisirB(c.id)"
                                >
                                    Choisir
                                </Button>
                            </li>
                        </ul>
                        <p
                            v-else-if="recherche.length >= 2"
                            class="text-sm text-muted-foreground"
                        >
                            Aucune fiche trouvée.
                        </p>
                        <p v-else class="text-sm text-muted-foreground">
                            Recherchez la fiche en double par son nom, son
                            téléphone ou son e-mail.
                        </p>
                    </div>
                </div>

                <p
                    v-if="erreur"
                    class="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive"
                >
                    {{ erreur }}
                </p>
                <div
                    v-if="simulation && simulation.blocages.length > 0"
                    class="space-y-1 rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive"
                    data-testid="fusion-blocages"
                >
                    <p class="font-medium">Fusion impossible :</p>
                    <p
                        v-for="b in simulation.blocages"
                        :key="b.code + b.message"
                    >
                        {{ b.message }}
                    </p>
                </div>
            </section>

            <template v-if="simulation">
                <!-- Étape 2 -->
                <section
                    class="space-y-6 rounded-xl border bg-card p-4 sm:p-6"
                    data-testid="fusion-simulation"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <h2 class="text-base font-semibold">
                            2. Choisir les informations à garder
                            <Loader2
                                v-if="chargement"
                                class="ml-1 inline h-4 w-4 animate-spin"
                            />
                        </h2>
                        <p class="text-sm">
                            <span class="text-muted-foreground"
                                >Nom après la fusion :
                            </span>
                            <span
                                class="font-semibold"
                                data-testid="fusion-nom-final"
                                >{{ nomFinal }}</span
                            >
                        </p>
                    </div>

                    <div class="space-y-3">
                        <p
                            v-if="differences.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            Aucune information différente entre les deux fiches.
                        </p>
                        <div
                            v-for="c in differences"
                            :key="c.cle"
                            class="space-y-1.5"
                        >
                            <p class="text-sm font-medium">{{ c.libelle }}</p>
                            <div
                                class="grid gap-2 sm:grid-cols-2"
                                role="radiogroup"
                                :aria-label="c.libelle"
                            >
                                <OptionFusion
                                    v-for="cote in ['a', 'b'] as const"
                                    :key="cote"
                                    :source="SOURCE[cote]"
                                    :valeur="
                                        cote === 'a' ? c.valeur_a : c.valeur_b
                                    "
                                    :selectionne="choix[c.cle] === cote"
                                    :desactive="chargement"
                                    :data-testid="`fusion-champ-${c.cle}-${cote}`"
                                    @choisir="choisir(c.cle, cote)"
                                />
                            </div>
                            <p
                                v-if="
                                    valeurRetenue(c) === null &&
                                    valeurEcartee(c) !== null
                                "
                                class="flex items-center gap-1.5 text-xs text-amber-700 dark:text-amber-300"
                            >
                                <TriangleAlert class="h-3.5 w-3.5 shrink-0" />
                                Ce champ restera vide : «
                                {{ valeurEcartee(c) }} » ne sera pas repris.
                            </p>
                        </div>
                        <p
                            v-if="identiques.length > 0"
                            class="text-sm text-muted-foreground"
                        >
                            Identique sur les deux fiches :
                            {{
                                identiques
                                    .map((c) => `${c.libelle} (${c.valeur_a})`)
                                    .join(', ')
                            }}.
                        </p>
                    </div>

                    <div class="space-y-3 border-t pt-4">
                        <h3 class="text-sm font-semibold">Téléphones</h3>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="space-y-2">
                                <p class="text-sm text-muted-foreground">
                                    Numéro principal
                                </p>
                                <OptionFusion
                                    v-for="n in numeros"
                                    :key="`p-${n.normalise}`"
                                    :source="origineNumero(n)"
                                    :valeur="telephone(n.valeur)"
                                    :selectionne="
                                        choix.telephone === n.normalise
                                    "
                                    :desactive="chargement"
                                    :data-testid="`fusion-principal-${n.normalise}`"
                                    @choisir="choisir('telephone', n.normalise)"
                                />
                            </div>
                            <div class="space-y-2">
                                <p class="text-sm text-muted-foreground">
                                    Numéro secondaire
                                </p>
                                <OptionFusion
                                    source=""
                                    valeur="Aucun numéro secondaire"
                                    :selectionne="!choix.telephone_secondaire"
                                    :desactive="chargement"
                                    data-testid="fusion-secondaire-aucun"
                                    @choisir="
                                        choisir('telephone_secondaire', '')
                                    "
                                />
                                <OptionFusion
                                    v-for="n in numeros.filter(
                                        (x) => x.normalise !== choix.telephone,
                                    )"
                                    :key="`s-${n.normalise}`"
                                    :source="origineNumero(n)"
                                    :valeur="telephone(n.valeur)"
                                    :selectionne="
                                        choix.telephone_secondaire ===
                                        n.normalise
                                    "
                                    :desactive="chargement"
                                    :data-testid="`fusion-secondaire-${n.normalise}`"
                                    @choisir="
                                        choisir(
                                            'telephone_secondaire',
                                            n.normalise,
                                        )
                                    "
                                />
                            </div>
                        </div>
                        <p
                            v-if="simulation.telephones.retires.length > 0"
                            class="flex items-start gap-1.5 text-sm text-amber-700 dark:text-amber-300"
                            data-testid="fusion-numeros-retires"
                        >
                            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />
                            Numéros qui quitteront la fiche (gardés au journal)
                            :
                            {{
                                simulation.telephones.retires
                                    .map((n) => telephone(n.valeur))
                                    .join(', ')
                            }}
                        </p>
                    </div>

                    <div
                        v-if="simulation.interlocuteur_principal"
                        class="space-y-2 border-t pt-4"
                    >
                        <h3 class="text-sm font-semibold">
                            Interlocuteur principal
                        </h3>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <OptionFusion
                                v-for="o in simulation.interlocuteur_principal
                                    .options"
                                :key="o.id"
                                :source="o.fiche ? SOURCE[o.fiche] : ''"
                                :valeur="o.nom ?? o.libelle"
                                :selectionne="
                                    choix.interlocuteur_principal === o.id
                                "
                                :desactive="chargement"
                                @choisir="
                                    choisir('interlocuteur_principal', o.id)
                                "
                            />
                        </div>
                    </div>

                    <div class="space-y-2 border-t pt-4">
                        <h3 class="text-sm font-semibold">
                            Fonctions et liens
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            Les fonctions changent de fiche avec tout leur
                            historique (ventes, factures, paiements,
                            commissions) : rien n'est supprimé ni recréé.
                        </p>
                        <div v-for="(lignes, groupe) in groupes" :key="groupe">
                            <p class="mt-2 text-sm font-medium">{{ groupe }}</p>
                            <ul class="mt-1 divide-y text-sm">
                                <li
                                    v-for="(r, i) in lignes"
                                    :key="`${groupe}-${i}`"
                                    class="flex flex-wrap justify-between gap-2 py-1.5"
                                >
                                    <span>{{ r.libelle }}</span>
                                    <span class="text-muted-foreground">{{
                                        ACTIONS[r.action]
                                    }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div
                        v-if="simulation.compte"
                        class="space-y-1 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
                        data-testid="fusion-compte"
                    >
                        <p class="font-semibold">
                            Compte utilisateur ({{
                                SOURCE[simulation.compte.cote].toLowerCase()
                            }})
                        </p>
                        <p>
                            {{ simulation.compte.nom ?? '—' }} · rôles :
                            {{ simulation.compte.roles.join(', ') || 'aucun' }}
                        </p>
                        <p>
                            Connexion inchangée : le compte continuera à se
                            connecter avec
                            {{
                                simulation.compte.identifiants_connexion.join(
                                    ', ',
                                ) || '—'
                            }}.
                        </p>
                        <p v-if="simulation.compte.cote === 'b'">
                            Le compte sera rattaché à la fiche conservée.
                        </p>
                        <p
                            v-if="
                                simulation.compte.fonctions_rattachables.length
                            "
                        >
                            Fonctions qui pourront être rattachées au compte
                            (visibles dans son espace client, commissions
                            comprises) :
                            {{
                                simulation.compte.fonctions_rattachables.join(
                                    ', ',
                                )
                            }}.
                        </p>
                    </div>
                </section>

                <!-- Étape 3 -->
                <section
                    v-if="!bloquee"
                    class="space-y-3 rounded-xl border bg-card p-4 sm:p-6"
                >
                    <h2 class="text-base font-semibold">
                        3. Vérifier et confirmer
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Un récapitulatif complet s'affiche avant la fusion.
                    </p>
                    <p
                        v-for="(e, i) in erreurs"
                        :key="i"
                        class="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive"
                    >
                        {{ e }}
                    </p>
                    <Button
                        :disabled="chargement"
                        data-testid="fusion-fusionner"
                        @click="ouvrirRecapitulatif"
                    >
                        <GitMerge class="mr-1.5 h-4 w-4" />
                        Vérifier et fusionner
                    </Button>
                </section>
            </template>
        </div>

        <Dialog
            :visible="dialogue"
            modal
            header="Vérifier avant de fusionner"
            :closable="!enCours"
            :close-on-escape="!enCours"
            :draggable="false"
            :style="{ width: 'min(44rem, 94vw)' }"
            @update:visible="fermerDialogue"
        >
            <div
                v-if="simulation"
                class="space-y-5 text-sm"
                data-testid="fusion-recapitulatif"
            >
                <p>
                    « {{ simulation.b.nom }} » sera fusionnée dans «
                    {{ simulation.a.nom }} », puis archivée.
                </p>

                <div data-testid="fusion-recap-fiche">
                    <h3 class="mb-2 font-semibold">Fiche après la fusion</h3>
                    <dl
                        class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 rounded-lg border p-3"
                    >
                        <dt class="text-muted-foreground">Nom affiché</dt>
                        <dd class="font-semibold">{{ nomFinal }}</dd>
                        <template v-for="c in ficheFinale" :key="c.cle">
                            <dt class="text-muted-foreground">
                                {{ c.libelle }}
                            </dt>
                            <dd>{{ c.valeur }}</dd>
                        </template>
                        <dt class="text-muted-foreground">
                            Téléphone principal
                        </dt>
                        <dd>
                            {{
                                telephone(
                                    numero(simulation.telephones.principal)
                                        ?.valeur ?? null,
                                )
                            }}
                        </dd>
                        <dt class="text-muted-foreground">
                            Téléphone secondaire
                        </dt>
                        <dd>
                            {{
                                simulation.telephones.secondaire
                                    ? telephone(
                                          numero(
                                              simulation.telephones.secondaire,
                                          )?.valeur ?? null,
                                      )
                                    : 'Aucun'
                            }}
                        </dd>
                    </dl>
                </div>

                <div
                    v-if="repris.length > 0 || numerosRepris.length > 0"
                    data-testid="fusion-recap-repris"
                >
                    <h3 class="mb-1 font-semibold">
                        Repris de « {{ simulation.b.nom }} »
                    </h3>
                    <ul class="list-disc space-y-0.5 pl-5">
                        <li v-for="c in repris" :key="c.cle">
                            {{ c.libelle }} : {{ c.valeur_b }}
                        </li>
                        <li v-for="n in numerosRepris" :key="n.normalise">
                            Téléphone {{ telephone(n.valeur) }}
                        </li>
                    </ul>
                </div>

                <div
                    v-if="
                        nonRepris.length > 0 ||
                        simulation.telephones.retires.length > 0
                    "
                    class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
                    data-testid="fusion-recap-non-repris"
                >
                    <h3 class="mb-1 flex items-center gap-1.5 font-semibold">
                        <TriangleAlert class="h-4 w-4" />
                        Non repris
                    </h3>
                    <ul class="list-disc space-y-0.5 pl-5">
                        <li v-for="c in nonRepris" :key="c.cle">
                            {{ c.libelle }} : « {{ valeurEcartee(c) }} » ({{
                                SOURCE[
                                    c.choix === 'b' ? 'a' : 'b'
                                ].toLowerCase()
                            }})<span v-if="valeurRetenue(c) === null">
                                — le champ restera vide</span
                            >
                        </li>
                        <li
                            v-for="n in simulation.telephones.retires"
                            :key="n.normalise"
                        >
                            Téléphone {{ telephone(n.valeur) }} (gardé au
                            journal)
                        </li>
                    </ul>
                    <p class="mt-1 text-xs">
                        Les valeurs écartées restent consultables dans le
                        journal de la fusion et sur la fiche archivée.
                    </p>
                </div>

                <div
                    v-if="relations.length > 0"
                    data-testid="fusion-recap-fonctions"
                >
                    <h3 class="mb-1 font-semibold">Fonctions et liens</h3>
                    <ul class="space-y-0.5">
                        <li
                            v-for="(r, i) in relationsTransferees"
                            :key="`t-${i}`"
                        >
                            {{ r.libelle }}
                            <span class="text-muted-foreground"
                                >— transférée</span
                            >
                        </li>
                        <li
                            v-for="(r, i) in relationsConservees"
                            :key="`c-${i}`"
                        >
                            {{ r.libelle }}
                            <span class="text-muted-foreground"
                                >— déjà sur la fiche conservée</span
                            >
                        </li>
                        <li v-for="(r, i) in relationsLaissees" :key="`l-${i}`">
                            {{ r.libelle }}
                            <span class="text-muted-foreground"
                                >— déjà présente, reste sur la fiche
                                archivée</span
                            >
                        </li>
                    </ul>
                </div>

                <div>
                    <h3 class="mb-1 font-semibold">Après la fusion</h3>
                    <ul class="list-disc space-y-0.5 pl-5">
                        <li>
                            « {{ simulation.b.nom }} » est archivée ; son
                            adresse mène à la fiche conservée.
                        </li>
                        <li>
                            Les ventes, factures, paiements et commissions
                            suivent leurs fonctions : aucun historique n'est
                            perdu.
                        </li>
                        <li>
                            La fusion est journalisée et peut être annulée
                            depuis « Historique des fusions ».
                        </li>
                    </ul>
                </div>

                <div class="space-y-3 border-t pt-4">
                    <label
                        v-if="requises.has('numeros_retires')"
                        class="flex items-start gap-2"
                    >
                        <Checkbox
                            v-model="form.confirmations.numeros_retires"
                            binary
                            :disabled="enCours"
                            data-testid="fusion-confirmer-numeros"
                        />
                        Les numéros non retenus quittent la fiche (ils restent
                        au journal).
                    </label>
                    <label
                        v-if="requises.has('compte')"
                        class="flex items-start gap-2"
                    >
                        <Checkbox
                            v-model="form.confirmations.compte"
                            binary
                            :disabled="enCours"
                            data-testid="fusion-confirmer-compte"
                        />
                        J'ai vérifié les conséquences sur le compte utilisateur.
                    </label>
                    <div
                        v-if="requises.has('transfert_compte')"
                        class="space-y-1"
                    >
                        <label for="fusion-transfert">
                            Saisissez {{ simulation.mot_transfert_compte }} pour
                            transférer le compte vers la fiche conservée
                        </label>
                        <InputText
                            id="fusion-transfert"
                            v-model="form.confirmations.transfert_compte"
                            class="w-full sm:w-64"
                            :disabled="enCours"
                            data-testid="fusion-transfert-compte"
                        />
                    </div>
                    <label class="flex items-start gap-2 font-medium">
                        <Checkbox
                            v-model="form.confirmations.fusion"
                            binary
                            :disabled="enCours"
                            data-testid="fusion-confirmer"
                        />
                        J'ai vérifié ce récapitulatif et je confirme la fusion.
                    </label>
                </div>
            </div>
            <template #footer>
                <Button
                    variant="outline"
                    :disabled="enCours"
                    data-testid="fusion-modifier-choix"
                    @click="fermerDialogue(false)"
                >
                    Modifier mes choix
                </Button>
                <Button
                    :disabled="enCours || !confirmationsCompletes"
                    :aria-busy="enCours"
                    data-testid="fusion-confirmer-dialogue"
                    @click="fusionner"
                >
                    <Loader2
                        v-if="enCours"
                        class="mr-1.5 h-4 w-4 animate-spin"
                    />
                    {{ enCours ? 'Fusion…' : 'Confirmer la fusion' }}
                </Button>
            </template>
        </Dialog>
    </AppLayout>
</template>
