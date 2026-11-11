<script setup lang="ts">
/**
 * Fusion manuelle de deux fiches (ADR 0060, lot 9b) : la fiche A (celle de départ) est conservée,
 * la fiche B est absorbée puis archivée. Chaque choix relance la simulation côté serveur (aucune
 * écriture) ; la confirmation refait tous les contrôles sous verrou.
 */
import DetailHeader from '@/components/DetailHeader.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeftRight, GitMerge, Loader2, Search } from 'lucide-vue-next';
import Checkbox from 'primevue/checkbox';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import RadioButton from 'primevue/radiobutton';
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
    champs: Champ[];
    telephones: {
        numeros: Numero[];
        principal: string | null;
        secondaire: string | null;
        retires: Numero[];
    };
    interlocuteur_principal: {
        options: { id: string; libelle: string }[];
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

// ── Recherche de la fiche B ─────────────────────────────────────────────
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

// ── Arbitrages : chaque choix relance la simulation côté serveur ────────
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
    choix.value[cle] = valeur;
    recalculer();
}
function inverser() {
    if (!props.simulation) return;
    router.get(
        `/backoffice/contacts/fusion/${props.type}/${props.simulation.b.id}`,
        { b: props.simulation.a.id },
    );
}

// Champs vides des deux côtés : rien à arbitrer ni à afficher.
const champsAffiches = computed(() =>
    (props.simulation?.champs ?? []).filter(
        (c) => c.valeur_a !== null || c.valeur_b !== null,
    ),
);

const groupes = computed(() => {
    const resultat: Record<string, Relation[]> = {};
    for (const r of props.simulation?.relations ?? []) {
        (resultat[r.groupe] ??= []).push(r);
    }
    return resultat;
});
const LIBELLES_ACTION: Record<Relation['action'], string> = {
    conservee: 'Déjà sur A',
    transferee: 'Transféré vers A',
    laissee: 'Déjà présent sur A : reste sur la fiche archivée',
};

function telephone(valeur: string | null): string {
    return formatPhoneDisplay(valeur, null) ?? valeur ?? '—';
}

// ── Confirmation ────────────────────────────────────────────────────────
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
function ouvrirConfirmation() {
    if (!props.simulation || bloquee.value || !confirmationsCompletes.value)
        return;
    dialogue.value = true;
}
function fermerDialogue(visible: boolean) {
    if (!form.processing) dialogue.value = visible;
}
function fusionner() {
    if (form.processing || !props.simulation) return;
    form.fiche_b = props.simulation.b.id;
    form.choix = { ...props.simulation.choix };
    form.empreinte = props.simulation.empreinte;
    form.post('/backoffice/contacts/fusions', {
        preserveScroll: true,
        onError: () => (dialogue.value = false),
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
                eyebrow="Fusion manuelle"
                :title="`Fusionner « ${fiche_a.nom} » avec une autre fiche`"
                :icon="GitMerge"
                data-testid="fusion-header"
            >
                <template #subtitle>
                    <p class="mt-1 text-sm text-muted-foreground">
                        La fiche A est conservée ; la fiche B est absorbée puis
                        archivée. Rien n'est fusionné sans votre confirmation.
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

            <!-- 1. Fiche B -->
            <section class="space-y-4 rounded-xl border bg-card p-4 sm:p-6">
                <h2
                    class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    Fiche à absorber (B)
                </h2>
                <form class="flex gap-2" @submit.prevent="rechercher">
                    <InputText
                        v-model="terme"
                        class="w-full"
                        placeholder="Nom, téléphone, e-mail…"
                        data-testid="fusion-recherche"
                    />
                    <Button type="submit" data-testid="fusion-rechercher">
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
                                <span v-if="c.ville"> · {{ c.ville }}</span>
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
                <p
                    v-if="erreur"
                    class="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive"
                >
                    {{ erreur }}
                </p>
            </section>

            <template v-if="simulation">
                <!-- 2. Comparaison -->
                <section
                    class="space-y-4 rounded-xl border bg-card p-4 sm:p-6"
                    data-testid="fusion-simulation"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <h2
                            class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Simulation
                            <Loader2
                                v-if="chargement"
                                class="ml-1 inline h-4 w-4 animate-spin"
                            />
                        </h2>
                        <Button
                            size="sm"
                            variant="outline"
                            data-testid="fusion-inverser"
                            @click="inverser"
                        >
                            <ArrowLeftRight class="mr-1.5 h-4 w-4" />
                            Conserver plutôt B
                        </Button>
                    </div>

                    <div
                        v-if="simulation.blocages.length > 0"
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

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr
                                    class="border-b text-left text-muted-foreground"
                                >
                                    <th class="py-2 pr-3 font-medium">Champ</th>
                                    <th class="py-2 pr-3 font-medium">
                                        A — conservée :
                                        <Link
                                            :href="simulation.a.lien"
                                            class="underline"
                                            >{{ simulation.a.nom }}</Link
                                        >
                                    </th>
                                    <th class="py-2 font-medium">
                                        B — absorbée :
                                        <Link
                                            :href="simulation.b.lien"
                                            class="underline"
                                            >{{ simulation.b.nom }}</Link
                                        >
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="c in champsAffiches"
                                    :key="c.cle"
                                    class="border-b last:border-0"
                                    :class="{
                                        'text-muted-foreground': !c.different,
                                    }"
                                >
                                    <td class="py-2 pr-3 font-medium">
                                        {{ c.libelle }}
                                    </td>
                                    <td
                                        v-for="cote in ['a', 'b'] as const"
                                        :key="cote"
                                        class="py-2 pr-3"
                                    >
                                        <label
                                            v-if="c.different"
                                            class="flex items-center gap-2"
                                        >
                                            <RadioButton
                                                :model-value="choix[c.cle]"
                                                :value="cote"
                                                :input-id="`champ-${c.cle}-${cote}`"
                                                :data-testid="`fusion-champ-${c.cle}-${cote}`"
                                                @update:model-value="
                                                    choisir(c.cle, cote)
                                                "
                                            />
                                            <span>{{
                                                (cote === 'a'
                                                    ? c.valeur_a
                                                    : c.valeur_b) ?? '—'
                                            }}</span>
                                        </label>
                                        <span v-else>{{
                                            (cote === 'a'
                                                ? c.valeur_a
                                                : c.valeur_b) ?? '—'
                                        }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- 3. Téléphones -->
                <section class="space-y-3 rounded-xl border bg-card p-4 sm:p-6">
                    <h2
                        class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Téléphones
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Un numéro principal et un secondaire au plus. Les autres
                        numéros quittent la fiche et restent au journal.
                    </p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <Label>Principal</Label>
                            <label
                                v-for="n in simulation.telephones.numeros"
                                :key="`p-${n.normalise}`"
                                class="flex items-center gap-2 text-sm"
                            >
                                <RadioButton
                                    :model-value="choix.telephone"
                                    :value="n.normalise"
                                    :data-testid="`fusion-principal-${n.normalise}`"
                                    @update:model-value="
                                        choisir('telephone', n.normalise)
                                    "
                                />
                                {{ telephone(n.valeur) }}
                                <span class="text-muted-foreground"
                                    >({{ n.origine }})</span
                                >
                            </label>
                        </div>
                        <div class="space-y-2">
                            <Label>Secondaire</Label>
                            <label class="flex items-center gap-2 text-sm">
                                <RadioButton
                                    :model-value="
                                        choix.telephone_secondaire ?? ''
                                    "
                                    value=""
                                    @update:model-value="
                                        choisir('telephone_secondaire', '')
                                    "
                                />
                                Aucun
                            </label>
                            <label
                                v-for="n in simulation.telephones.numeros.filter(
                                    (x) => x.normalise !== choix.telephone,
                                )"
                                :key="`s-${n.normalise}`"
                                class="flex items-center gap-2 text-sm"
                            >
                                <RadioButton
                                    :model-value="choix.telephone_secondaire"
                                    :value="n.normalise"
                                    :data-testid="`fusion-secondaire-${n.normalise}`"
                                    @update:model-value="
                                        choisir(
                                            'telephone_secondaire',
                                            n.normalise,
                                        )
                                    "
                                />
                                {{ telephone(n.valeur) }}
                                <span class="text-muted-foreground"
                                    >({{ n.origine }})</span
                                >
                            </label>
                        </div>
                    </div>
                    <p
                        v-if="simulation.telephones.retires.length > 0"
                        class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
                        data-testid="fusion-numeros-retires"
                    >
                        Numéros qui quitteront la fiche (gardés au journal) :
                        {{
                            simulation.telephones.retires
                                .map((n) => telephone(n.valeur))
                                .join(', ')
                        }}
                    </p>

                    <div
                        v-if="simulation.interlocuteur_principal"
                        class="space-y-2 pt-2"
                    >
                        <Label>Interlocuteur principal</Label>
                        <label
                            v-for="o in simulation.interlocuteur_principal
                                .options"
                            :key="o.id"
                            class="flex items-center gap-2 text-sm"
                        >
                            <RadioButton
                                :model-value="choix.interlocuteur_principal"
                                :value="o.id"
                                @update:model-value="
                                    choisir('interlocuteur_principal', o.id)
                                "
                            />
                            {{ o.libelle }}
                        </label>
                    </div>
                </section>

                <!-- 4. Liens et fonctions -->
                <section class="space-y-3 rounded-xl border bg-card p-4 sm:p-6">
                    <h2
                        class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Fonctions et liens
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Les fonctions changent de fiche avec tout leur
                        historique (ventes, factures, paiements, commissions) :
                        rien n'est supprimé ni recréé.
                    </p>
                    <div v-for="(lignes, groupe) in groupes" :key="groupe">
                        <p class="mt-2 text-sm font-medium">{{ groupe }}</p>
                        <ul class="mt-1 space-y-1 text-sm">
                            <li
                                v-for="(r, i) in lignes"
                                :key="`${groupe}-${i}`"
                                class="flex flex-wrap justify-between gap-2"
                            >
                                <span>{{ r.libelle }}</span>
                                <span class="text-muted-foreground">{{
                                    LIBELLES_ACTION[r.action]
                                }}</span>
                            </li>
                        </ul>
                    </div>
                </section>

                <!-- 5. Compte utilisateur -->
                <section
                    v-if="simulation.compte"
                    class="space-y-2 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 sm:p-6 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
                    data-testid="fusion-compte"
                >
                    <h2 class="font-semibold">
                        Compte utilisateur (fiche
                        {{ simulation.compte.cote.toUpperCase() }})
                    </h2>
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
                        Le compte sera rattaché à la fiche A.
                    </p>
                    <p v-if="simulation.compte.fonctions_rattachables.length">
                        Fonctions qui pourront être rattachées au compte
                        (visibles dans son espace client, commissions comprises)
                        :
                        {{
                            simulation.compte.fonctions_rattachables.join(', ')
                        }}.
                    </p>
                </section>

                <!-- 6. Confirmation -->
                <section
                    v-if="!bloquee"
                    class="space-y-3 rounded-xl border bg-card p-4 sm:p-6"
                >
                    <label class="flex items-start gap-2 text-sm">
                        <Checkbox
                            v-model="form.confirmations.fusion"
                            binary
                            data-testid="fusion-confirmer"
                        />
                        Je confirme la fusion de « {{ simulation.b.nom }} » dans
                        « {{ simulation.a.nom }} ». B sera archivée.
                    </label>
                    <label
                        v-if="requises.has('numeros_retires')"
                        class="flex items-start gap-2 text-sm"
                    >
                        <Checkbox
                            v-model="form.confirmations.numeros_retires"
                            binary
                            data-testid="fusion-confirmer-numeros"
                        />
                        Les numéros non retenus quittent la fiche (ils restent
                        au journal).
                    </label>
                    <label
                        v-if="requises.has('compte')"
                        class="flex items-start gap-2 text-sm"
                    >
                        <Checkbox
                            v-model="form.confirmations.compte"
                            binary
                            data-testid="fusion-confirmer-compte"
                        />
                        J'ai vérifié les conséquences sur le compte utilisateur.
                    </label>
                    <div
                        v-if="requises.has('transfert_compte')"
                        class="space-y-1"
                    >
                        <Label for="fusion-transfert">
                            Saisissez {{ simulation.mot_transfert_compte }} pour
                            transférer le compte de B vers A
                        </Label>
                        <InputText
                            id="fusion-transfert"
                            v-model="form.confirmations.transfert_compte"
                            class="w-full sm:w-64"
                            data-testid="fusion-transfert-compte"
                        />
                    </div>
                    <p
                        v-for="(e, i) in erreurs"
                        :key="i"
                        class="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive"
                    >
                        {{ e }}
                    </p>
                    <Button
                        :disabled="!confirmationsCompletes || chargement"
                        data-testid="fusion-fusionner"
                        @click="ouvrirConfirmation"
                    >
                        <GitMerge class="mr-1.5 h-4 w-4" />
                        Fusionner
                    </Button>
                </section>
            </template>
        </div>

        <Dialog
            :visible="dialogue"
            modal
            header="Confirmer la fusion"
            :closable="!form.processing"
            :close-on-escape="!form.processing"
            :draggable="false"
            :style="{ width: 'min(32rem, 94vw)' }"
            @update:visible="fermerDialogue"
        >
            <p class="text-sm">
                « {{ simulation?.b.nom }} » sera fusionnée dans «
                {{ simulation?.a.nom }} » puis archivée. L'opération est
                journalisée et annulable depuis l'historique des fusions.
            </p>
            <template #footer>
                <Button
                    variant="outline"
                    :disabled="form.processing"
                    @click="fermerDialogue(false)"
                >
                    Annuler
                </Button>
                <Button
                    :disabled="form.processing"
                    data-testid="fusion-confirmer-dialogue"
                    @click="fusionner"
                >
                    <Loader2
                        v-if="form.processing"
                        class="mr-1.5 h-4 w-4 animate-spin"
                    />
                    Fusionner
                </Button>
            </template>
        </Dialog>
    </AppLayout>
</template>
