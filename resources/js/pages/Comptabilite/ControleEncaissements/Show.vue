<script setup lang="ts">
import GraviteAnomalie from '@/components/controle-encaissements/GraviteAnomalie.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useFlashToast } from '@/composables/useFlashToast';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CircleCheck,
    ExternalLink,
    Info,
    TriangleAlert,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Piece {
    id: string;
    numero: string;
    libelle: string | null;
    date: string | null;
    statut: string;
    contrepassation: boolean;
    lignes: { compte: string; debit: number; credit: number }[];
}

interface Encaissement {
    id: string;
    montant: number;
    mode_label: string | null;
    operateur: string | null;
    reference_paiement: string | null;
    date_encaissement: string | null;
    cree_le: string | null;
    agent_nom: string | null;
    site_encaissement: string | null;
    support: string | null;
    est_acompte: boolean;
    existe: boolean;
    suspect: boolean;
    annulable: boolean;
    raison_non_annulable: string | null;
    commande_reference: string | null;
    impact: {
        encaisse_apres: number;
        statut_apres: string;
        compte_tresorerie: string | null;
        pieces_a_contrepasser: string[];
    } | null;
    pieces: Piece[];
}

const props = defineProps<{
    anomalie: {
        id: string;
        type: string;
        type_label: string;
        gravite: string;
        gravite_label: string;
        statut: string;
        statut_label: string;
        ouverte: boolean;
        ecart_present: boolean;
        reference: string;
        facture_reference: string | null;
        site_nom: string | null;
        agents: string[];
        montant_facture: number | null;
        montant_encaisse_net: number | null;
        surplus: number;
        detectee_le: string | null;
        derniere_detection_le: string | null;
        ecart_disparu_le: string | null;
        explication: string | null;
        demarche: string;
        certaine: boolean;
        reference_mobile_money: string | null;
        piece_numero: string | null;
        occurrence: number;
        nb_relances: number;
        derniere_relance_le: string | null;
        pris_en_charge_par: string | null;
        pris_en_charge_le: string | null;
        cloture_par: string | null;
        cloture_le: string | null;
        commentaire_cloture: string | null;
    };
    facture: {
        reference: string;
        statut: string | null;
        statut_label: string | null;
        montant_net: number;
        encaisse_net: number | null;
        restant: number;
        trop_percu: number;
        commande_reference: string | null;
        commande_url: string | null;
        est_precommande: boolean;
    } | null;
    encaissements: Encaissement[];
    annulation_possible: boolean;
    notifications: {
        id: string;
        destinataire: string;
        canal: string;
        canal_label: string;
        envoi_label: string;
        statut: string;
        statut_label: string;
        tentatives: number;
        erreur: string | null;
        envoyee_le: string | null;
        creee_le: string | null;
    }[];
    historique: {
        id: string;
        libelle: string;
        code: string;
        auteur: string;
        date: string | null;
        commentaire: string | null;
        cause: string | null;
        statut_avant: string | null;
        statut_apres: string | null;
    }[];
    can_traiter: boolean;
}>();

const URL_CONTROLE =
    '/backoffice/comptabilite/tresorerie/controle-encaissements';
const urlFiche = computed(() => `${URL_CONTROLE}/${props.anomalie.id}`);

const breadcrumbs = computed((): BreadcrumbItem[] => [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    { title: 'Contrôle des encaissements', href: URL_CONTROLE },
    { title: props.anomalie.reference, href: urlFiche.value },
]);

useFlashToast();
const { can } = usePermissions();

// Mêmes clés que le serveur : ControleEncaissementsController (traitement),
// DestroyEncaissementVenteController (annulation), CommandeVentePolicy::rembourser().
const peutTraiter = computed(
    () => props.can_traiter && can('encaissements.traiter_anomalies'),
);
const peutAnnulerEncaissement = computed(() =>
    can('ventes.annuler_exceptionnel'),
);
const peutRembourser = computed(() => can('ventes.rembourser'));

const typeDoublon = computed(() =>
    ['double_encaissement', 'surplus_encaisse', 'doublon_suspect'].includes(
        props.anomalie.type,
    ),
);

function dateHeure(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
}

function montant(val: number | null | undefined): string {
    return val === null || val === undefined ? '—' : formatGNF(val);
}

// ── Actions de traitement ───────────────────────────────────────────────────

type Action =
    | 'prendre-en-charge'
    | 'commentaires'
    | 'resoudre'
    | 'fausse-alerte';

const ACTIONS: Record<
    Action,
    {
        titre: string;
        description: string;
        bouton: string;
        champ: string;
        obligatoire: boolean;
    }
> = {
    'prendre-en-charge': {
        titre: "Prendre en charge l'anomalie",
        description:
            "Vous confirmez l'analyse en cours (montant réellement reçu, preuves de paiement, écritures). L'annulation d'un encaissement en trop sera ensuite proposée.",
        bouton: 'Prendre en charge',
        champ: 'Analyse (facultatif)',
        obligatoire: false,
    },
    commentaires: {
        titre: 'Ajouter un commentaire',
        description: "Le commentaire est conservé dans l'historique.",
        bouton: 'Ajouter',
        champ: 'Commentaire',
        obligatoire: true,
    },
    resoudre: {
        titre: 'Classer résolue',
        description:
            "Un contrôle est refait à l'instant : la résolution est refusée si l'écart est toujours constaté.",
        bouton: 'Classer résolue',
        champ: 'Commentaire (facultatif)',
        obligatoire: false,
    },
    'fausse-alerte': {
        titre: 'Classer en fausse alerte',
        description:
            "L'anomalie est close sans régularisation ; elle ne sera plus notifiée. Le motif est obligatoire.",
        bouton: 'Classer en fausse alerte',
        champ: 'Motif',
        obligatoire: true,
    },
};

const action = ref<Action | null>(null);
const commentaire = ref('');
const erreurs = ref<Record<string, string>>({});
const enCours = ref(false);
const CROIX_INACTIVE =
    '[&>button:last-child]:pointer-events-none [&>button:last-child]:opacity-40';

function ouvrir(a: Action) {
    action.value = a;
    commentaire.value = '';
    erreurs.value = {};
}

function confirmerAction() {
    if (!action.value || enCours.value) return;
    enCours.value = true;
    router.post(
        `${urlFiche.value}/${action.value}`,
        { commentaire: commentaire.value || null },
        {
            preserveScroll: true,
            onSuccess: () => (action.value = null),
            onError: (e) => (erreurs.value = e as Record<string, string>),
            onFinish: () => (enCours.value = false),
        },
    );
}

// ── Annulation d'un encaissement en doublon ─────────────────────────────────

const aAnnuler = ref<Encaissement | null>(null);
const verificationFaite = ref(false);
const erreurAnnulation = ref<string | null>(null);

function preparerAnnulation(e: Encaissement) {
    aAnnuler.value = e;
    verificationFaite.value = false;
    erreurAnnulation.value = null;
}

function confirmerAnnulation() {
    if (!aAnnuler.value || !verificationFaite.value || enCours.value) return;
    enCours.value = true;
    router.delete(`/backoffice/encaissements/${aAnnuler.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (aAnnuler.value = null),
        onError: (e) =>
            (erreurAnnulation.value =
                Object.values(e as Record<string, string>)[0] ??
                "L'annulation a échoué."),
        onFinish: () => (enCours.value = false),
    });
}
</script>

<template>
    <Head :title="`Anomalie ${anomalie.reference}`" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-5 p-4 sm:p-6">
            <!-- En-tête -->
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-start gap-3">
                    <Link
                        :href="URL_CONTROLE"
                        class="mt-1 text-muted-foreground hover:text-foreground"
                        aria-label="Retour au contrôle des encaissements"
                    >
                        <ArrowLeft class="h-5 w-5" />
                    </Link>
                    <div>
                        <p
                            class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            {{ anomalie.type_label }}
                        </p>
                        <h1
                            class="font-mono text-2xl font-semibold tracking-tight"
                            data-testid="anomalie-reference"
                        >
                            {{ anomalie.reference }}
                        </h1>
                        <div
                            class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm"
                        >
                            <GraviteAnomalie
                                :gravite="anomalie.gravite"
                                :label="anomalie.gravite_label"
                            />
                            <StatusDot
                                :status="anomalie.statut"
                                :label="anomalie.statut_label"
                            />
                            <span class="text-muted-foreground">
                                Détectée le {{ anomalie.detectee_le }}
                                <template v-if="anomalie.occurrence > 1">
                                    · réapparue ({{ anomalie.occurrence }}e
                                    détection)</template
                                >
                            </span>
                            <span
                                v-if="!anomalie.certaine"
                                class="text-muted-foreground"
                                >· Suspicion à vérifier</span
                            >
                        </div>
                    </div>
                </div>

                <div
                    v-if="peutTraiter && anomalie.ouverte"
                    class="flex flex-wrap gap-2"
                    data-testid="anomalie-actions"
                >
                    <Button
                        v-if="anomalie.statut === 'nouvelle'"
                        size="sm"
                        @click="ouvrir('prendre-en-charge')"
                    >
                        Prendre en charge
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="ouvrir('commentaires')"
                    >
                        Commenter
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="anomalie.ecart_present"
                        :title="
                            anomalie.ecart_present
                                ? 'L\'écart est toujours constaté : régularisez d\'abord, ou classez en fausse alerte.'
                                : undefined
                        "
                        data-testid="anomalie-resoudre"
                        @click="ouvrir('resoudre')"
                    >
                        Classer résolue
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="ouvrir('fausse-alerte')"
                    >
                        Fausse alerte
                    </Button>
                </div>
            </div>

            <!-- Constat et démarche -->
            <div
                v-if="!anomalie.ecart_present && anomalie.ouverte"
                class="flex items-start gap-2 rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-300"
                data-testid="anomalie-ecart-disparu"
            >
                <CircleCheck class="mt-0.5 h-4 w-4 shrink-0" />
                <p>
                    L'écart n'est plus constaté depuis le
                    {{ anomalie.ecart_disparu_le }} : l'anomalie peut être
                    classée résolue.
                </p>
            </div>
            <div
                class="rounded-lg border px-4 py-3 text-sm"
                :class="
                    anomalie.gravite === 'critique'
                        ? 'border-red-200 bg-red-50/60 dark:border-red-900 dark:bg-red-950/20'
                        : 'bg-muted/30'
                "
            >
                <p class="font-medium">{{ anomalie.explication }}</p>
                <p class="mt-1 text-muted-foreground">
                    <span class="font-medium text-foreground">Démarche :</span>
                    {{ anomalie.demarche }}
                </p>
                <p
                    v-if="anomalie.commentaire_cloture"
                    class="mt-2 text-muted-foreground"
                >
                    Clôture par {{ anomalie.cloture_par }} le
                    {{ anomalie.cloture_le }} :
                    {{ anomalie.commentaire_cloture }}
                </p>
            </div>

            <!-- Chiffres clés -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Montant facturé</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums">
                        {{ montant(anomalie.montant_facture) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">
                        Encaissé net (à la détection)
                    </p>
                    <p class="mt-1 text-xl font-semibold tabular-nums">
                        {{ montant(anomalie.montant_encaisse_net) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">
                        Surplus constaté
                    </p>
                    <p
                        class="mt-1 text-xl font-semibold tabular-nums"
                        :class="
                            anomalie.surplus > 0 && anomalie.ecart_present
                                ? 'text-red-600 dark:text-red-400'
                                : ''
                        "
                    >
                        {{
                            anomalie.surplus > 0
                                ? formatGNF(anomalie.surplus)
                                : '—'
                        }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Agence · agent</p>
                    <p class="mt-1 font-semibold">
                        {{ anomalie.site_nom ?? '—' }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ anomalie.agents.join(', ') || '—' }}
                    </p>
                </div>
            </div>

            <!-- Régularisation -->
            <div
                v-if="anomalie.ouverte"
                class="rounded-xl border bg-card p-4 text-sm"
                data-testid="anomalie-regularisation"
            >
                <h2 class="text-base font-semibold">Régularisation</h2>
                <template v-if="typeDoublon">
                    <p
                        v-if="anomalie.statut === 'nouvelle'"
                        class="mt-1 text-muted-foreground"
                    >
                        Vérifiez d'abord avec l'agent le montant réellement
                        reçu, puis prenez l'anomalie en charge : l'annulation de
                        l'encaissement en trop sera alors proposée dans le
                        tableau ci-dessous.
                    </p>
                    <p
                        v-else-if="
                            annulation_possible && peutAnnulerEncaissement
                        "
                        class="mt-1 text-muted-foreground"
                    >
                        Doublon confirmé : annulez l'encaissement en trop avec
                        le bouton « Annuler » de sa ligne. L'écriture est
                        contrepassée, la facture recalculée et l'opération
                        tracée.
                    </p>
                    <p
                        v-else-if="annulation_possible"
                        class="mt-1 text-muted-foreground"
                    >
                        L'annulation d'un encaissement est réservée aux
                        personnes ayant la permission « Ventes — annulation
                        exceptionnelle ».
                    </p>
                    <p
                        v-if="anomalie.type === 'surplus_encaisse'"
                        class="mt-2 flex items-start gap-1.5 text-amber-700 dark:text-amber-400"
                    >
                        <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />
                        Si le client a réellement payé ce surplus, ne supprimez
                        pas un encaissement légitime : l'application n'a pas de
                        circuit de remboursement hors précommande, traitez-le
                        avec la comptabilité puis classez l'anomalie avec un
                        commentaire.
                    </p>
                </template>
                <template
                    v-else-if="anomalie.type === 'trop_percu_precommande'"
                >
                    <p class="mt-1 text-muted-foreground">
                        Le client doit récupérer
                        {{ facture ? formatGNF(facture.trop_percu) : '' }} :
                        remboursez-le depuis la commande (onglet Facturation).
                        L'anomalie se libère dès le remboursement enregistré.
                    </p>
                    <Link
                        v-if="facture?.commande_url && peutRembourser"
                        :href="facture.commande_url"
                        class="mt-2 inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline"
                    >
                        Rembourser depuis la commande
                        <ExternalLink class="h-3.5 w-3.5" />
                    </Link>
                </template>
                <p v-else class="mt-1 text-muted-foreground">
                    {{ anomalie.demarche }}
                </p>
            </div>

            <!-- Facture -->
            <div
                v-if="facture"
                class="flex flex-wrap items-center justify-between gap-4 rounded-xl border bg-card px-4 py-3 text-sm"
            >
                <div class="flex flex-wrap items-center gap-x-6 gap-y-1">
                    <span>
                        Facture
                        <span class="font-mono font-medium">{{
                            facture.reference
                        }}</span>
                    </span>
                    <StatusDot
                        v-if="facture.statut"
                        :status="facture.statut"
                        :label="facture.statut_label ?? ''"
                    />
                    <span class="text-muted-foreground"
                        >Facturé {{ formatGNF(facture.montant_net) }}</span
                    >
                    <span class="text-muted-foreground"
                        >Encaissé net aujourd'hui
                        {{ montant(facture.encaisse_net) }}</span
                    >
                </div>
                <Link
                    v-if="facture.commande_url"
                    :href="facture.commande_url"
                    class="inline-flex items-center gap-1.5 font-medium text-primary hover:underline"
                >
                    Ouvrir la commande {{ facture.commande_reference }}
                    <ExternalLink class="h-3.5 w-3.5" />
                </Link>
            </div>

            <!-- Encaissements et écritures -->
            <div
                v-if="encaissements.length > 0"
                class="overflow-hidden rounded-xl border bg-card shadow-sm"
            >
                <div class="border-b px-5 py-3">
                    <h2 class="text-base font-semibold">
                        Encaissements concernés
                    </h2>
                </div>
                <div class="divide-y">
                    <div
                        v-for="(e, index) in encaissements"
                        :key="e.id"
                        class="px-5 py-4"
                        :data-testid="`anomalie-encaissement-${index + 1}`"
                    >
                        <div
                            class="flex flex-wrap items-start justify-between gap-3"
                        >
                            <div>
                                <p class="text-xs text-muted-foreground">
                                    Encaissement {{ index + 1 }}
                                    <span
                                        v-if="e.suspect"
                                        class="font-medium text-amber-700 dark:text-amber-400"
                                        >· concerné par l'anomalie</span
                                    >
                                    <span
                                        v-if="!e.existe"
                                        class="font-medium text-emerald-700 dark:text-emerald-400"
                                        >· annulé</span
                                    >
                                </p>
                                <p
                                    class="text-lg font-semibold tabular-nums"
                                    :class="
                                        !e.existe
                                            ? 'line-through opacity-60'
                                            : ''
                                    "
                                >
                                    {{ formatGNF(e.montant) }}
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    {{ dateHeure(e.cree_le) }} ·
                                    {{ e.mode_label ?? '—'
                                    }}<template v-if="e.support">
                                        · {{ e.support }}</template
                                    ><template v-if="e.reference_paiement">
                                        · Réf.
                                        {{ e.reference_paiement }}</template
                                    >
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    Par {{ e.agent_nom ?? '—' }}
                                    <template v-if="e.site_encaissement">
                                        · encaissé à
                                        {{ e.site_encaissement }}</template
                                    >
                                    <template
                                        v-if="e.commande_reference && !facture"
                                    >
                                        · {{ e.commande_reference }}</template
                                    >
                                </p>
                            </div>
                            <div v-if="typeDoublon && e.existe">
                                <Button
                                    v-if="
                                        annulation_possible &&
                                        peutAnnulerEncaissement &&
                                        e.annulable
                                    "
                                    size="sm"
                                    variant="destructive"
                                    :data-testid="`anomalie-annuler-${index + 1}`"
                                    @click="preparerAnnulation(e)"
                                >
                                    Annuler cet encaissement
                                </Button>
                                <p
                                    v-else-if="
                                        annulation_possible &&
                                        e.raison_non_annulable
                                    "
                                    class="max-w-xs text-xs text-muted-foreground"
                                >
                                    {{ e.raison_non_annulable }}
                                </p>
                            </div>
                        </div>

                        <div v-if="e.pieces.length > 0" class="mt-3 space-y-2">
                            <div
                                v-for="p in e.pieces"
                                :key="p.id"
                                class="rounded-md border bg-muted/20 px-3 py-2 text-xs"
                            >
                                <p class="font-medium">
                                    Pièce {{ p.numero }} · {{ p.date }} ·
                                    <span
                                        :class="
                                            p.statut === 'validee'
                                                ? 'text-emerald-700 dark:text-emerald-400'
                                                : 'text-muted-foreground'
                                        "
                                        >{{
                                            p.contrepassation
                                                ? 'contrepassation'
                                                : p.statut === 'validee'
                                                  ? 'validée'
                                                  : p.statut === 'contrepassee'
                                                    ? 'contrepassée'
                                                    : p.statut
                                        }}</span
                                    >
                                </p>
                                <table class="mt-1 w-full">
                                    <tr
                                        v-for="(l, i) in p.lignes"
                                        :key="i"
                                        class="text-muted-foreground"
                                    >
                                        <td class="py-0.5">{{ l.compte }}</td>
                                        <td
                                            class="py-0.5 text-right tabular-nums"
                                        >
                                            {{
                                                l.debit > 0
                                                    ? `Débit ${formatGNF(l.debit)}`
                                                    : ''
                                            }}
                                        </td>
                                        <td
                                            class="py-0.5 text-right tabular-nums"
                                        >
                                            {{
                                                l.credit > 0
                                                    ? `Crédit ${formatGNF(l.credit)}`
                                                    : ''
                                            }}
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        <p
                            v-else
                            class="mt-2 flex items-center gap-1.5 text-xs text-red-600 dark:text-red-400"
                        >
                            <TriangleAlert class="h-3.5 w-3.5" />
                            Aucune écriture comptable trouvée pour cet
                            encaissement.
                        </p>
                    </div>
                </div>
            </div>

            <div class="grid gap-5 lg:grid-cols-2">
                <!-- Notifications -->
                <div
                    class="overflow-hidden rounded-xl border bg-card shadow-sm"
                >
                    <div class="border-b px-5 py-3">
                        <h2 class="text-base font-semibold">
                            Notifications envoyées
                        </h2>
                    </div>
                    <div
                        v-if="notifications.length > 0"
                        class="max-w-full overflow-x-auto"
                    >
                        <table
                            class="w-full text-sm"
                            data-testid="anomalie-notifications"
                        >
                            <thead>
                                <tr class="border-b bg-muted/50 text-left">
                                    <th
                                        class="px-4 py-2 font-semibold text-foreground/70"
                                    >
                                        Destinataire
                                    </th>
                                    <th
                                        class="px-4 py-2 font-semibold text-foreground/70"
                                    >
                                        Canal
                                    </th>
                                    <th
                                        class="px-4 py-2 font-semibold text-foreground/70"
                                    >
                                        État
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="n in notifications" :key="n.id">
                                    <td class="px-4 py-2">
                                        {{ n.destinataire }}
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ n.envoi_label }} ·
                                            {{ n.creee_le }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-2">
                                        {{ n.canal_label }}
                                    </td>
                                    <td class="px-4 py-2">
                                        <StatusDot
                                            :status="n.statut"
                                            :label="n.statut_label"
                                        />
                                        <p
                                            v-if="n.erreur"
                                            class="mt-0.5 max-w-[16rem] truncate text-xs text-muted-foreground"
                                            :title="n.erreur"
                                        >
                                            {{ n.tentatives }} tentative(s) —
                                            {{ n.erreur }}
                                        </p>
                                        <p
                                            v-else-if="n.envoyee_le"
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ n.envoyee_le }}
                                        </p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="px-5 py-6 text-sm text-muted-foreground">
                        Aucune notification.
                    </p>
                </div>

                <!-- Historique -->
                <div
                    class="overflow-hidden rounded-xl border bg-card shadow-sm"
                >
                    <div class="border-b px-5 py-3">
                        <h2 class="text-base font-semibold">Historique</h2>
                    </div>
                    <ol
                        class="space-y-3 px-5 py-4 text-sm"
                        data-testid="anomalie-historique"
                    >
                        <li
                            v-for="h in historique"
                            :key="h.id"
                            class="border-l-2 border-muted pl-3"
                        >
                            <p class="font-medium">
                                {{ h.libelle }}
                                <template v-if="h.statut_apres">
                                    : {{ h.statut_avant }} →
                                    {{ h.statut_apres }}</template
                                >
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ h.auteur }} · {{ h.date }}
                            </p>
                            <p v-if="h.commentaire" class="mt-0.5">
                                {{ h.commentaire }}
                            </p>
                            <p
                                v-if="h.cause"
                                class="mt-0.5 text-xs text-muted-foreground"
                            >
                                {{ h.cause }}
                            </p>
                        </li>
                        <li
                            v-if="historique.length === 0"
                            class="text-muted-foreground"
                        >
                            Aucun événement.
                        </li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- Action de traitement -->
        <Dialog
            :open="action !== null"
            @update:open="
                (ouvert: boolean) => {
                    if (!ouvert && !enCours) action = null;
                }
            "
        >
            <DialogContent
                v-if="action"
                class="sm:max-w-md"
                :class="{ [CROIX_INACTIVE]: enCours }"
            >
                <DialogHeader>
                    <DialogTitle>{{ ACTIONS[action].titre }}</DialogTitle>
                    <DialogDescription>{{
                        ACTIONS[action].description
                    }}</DialogDescription>
                </DialogHeader>
                <div class="space-y-1.5">
                    <Label for="commentaire">{{ ACTIONS[action].champ }}</Label>
                    <textarea
                        id="commentaire"
                        v-model="commentaire"
                        rows="3"
                        data-testid="anomalie-commentaire"
                        class="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    />
                    <p
                        v-for="(message, cle) in erreurs"
                        :key="cle"
                        class="text-xs text-red-600 dark:text-red-400"
                    >
                        {{ message }}
                    </p>
                </div>
                <DialogFooter>
                    <Button
                        variant="outline"
                        :disabled="enCours"
                        @click="action = null"
                    >
                        Annuler
                    </Button>
                    <Button
                        :disabled="
                            enCours ||
                            (ACTIONS[action].obligatoire && !commentaire.trim())
                        "
                        :aria-busy="enCours"
                        data-testid="anomalie-confirmer"
                        @click="confirmerAction"
                    >
                        <Spinner v-if="enCours" class="mr-1.5" />
                        {{ ACTIONS[action].bouton }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Annulation d'un encaissement : récapitulatif avant confirmation -->
        <Dialog
            :open="aAnnuler !== null"
            @update:open="
                (ouvert: boolean) => {
                    if (!ouvert && !enCours) aAnnuler = null;
                }
            "
        >
            <DialogContent
                v-if="aAnnuler"
                class="sm:max-w-lg"
                :class="{ [CROIX_INACTIVE]: enCours }"
                data-testid="anomalie-annulation"
            >
                <DialogHeader>
                    <DialogTitle>Annuler cet encaissement ?</DialogTitle>
                    <DialogDescription>
                        Opération exceptionnelle, tracée, réservée à un doublon
                        confirmé.
                    </DialogDescription>
                </DialogHeader>
                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
                    <dt class="text-muted-foreground">Encaissement</dt>
                    <dd class="font-semibold">
                        {{ formatGNF(aAnnuler.montant) }} ·
                        {{ aAnnuler.mode_label }}
                    </dd>
                    <dt class="text-muted-foreground">Saisi le</dt>
                    <dd>
                        {{ dateHeure(aAnnuler.cree_le) }} par
                        {{ aAnnuler.agent_nom ?? '—' }}
                    </dd>
                    <template v-if="aAnnuler.impact">
                        <dt class="text-muted-foreground">Facture</dt>
                        <dd>
                            Encaissé net
                            {{ montant(facture?.encaisse_net) }} →
                            <span class="font-semibold">{{
                                formatGNF(aAnnuler.impact.encaisse_apres)
                            }}</span>
                            ({{ aAnnuler.impact.statut_apres }})
                        </dd>
                        <dt class="text-muted-foreground">Trésorerie</dt>
                        <dd>
                            {{
                                aAnnuler.impact.compte_tresorerie ??
                                (aAnnuler.support || "Caisse de l'agent")
                            }}
                            diminue de {{ formatGNF(aAnnuler.montant) }}
                        </dd>
                        <dt class="text-muted-foreground">Écritures</dt>
                        <dd>
                            <template
                                v-if="
                                    aAnnuler.impact.pieces_a_contrepasser.length
                                "
                            >
                                Contrepassation de
                                {{
                                    aAnnuler.impact.pieces_a_contrepasser.join(
                                        ', ',
                                    )
                                }}
                            </template>
                            <template v-else>Aucune pièce validée</template>
                        </dd>
                    </template>
                </dl>
                <label
                    class="mt-2 flex items-start gap-2 rounded-md border bg-muted/30 px-3 py-2 text-sm"
                >
                    <input
                        v-model="verificationFaite"
                        type="checkbox"
                        class="mt-0.5"
                        data-testid="anomalie-verification"
                    />
                    J'ai vérifié avec l'agent que ce paiement n'a pas été reçu
                    une seconde fois.
                </label>
                <p
                    v-if="erreurAnnulation"
                    class="flex items-start gap-1.5 text-sm text-red-600 dark:text-red-400"
                >
                    <Info class="mt-0.5 h-4 w-4 shrink-0" />
                    {{ erreurAnnulation }}
                </p>
                <DialogFooter>
                    <Button
                        variant="outline"
                        :disabled="enCours"
                        @click="aAnnuler = null"
                    >
                        Retour
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="enCours || !verificationFaite"
                        :aria-busy="enCours"
                        data-testid="anomalie-annulation-confirmer"
                        @click="confirmerAnnulation"
                    >
                        <Spinner v-if="enCours" class="mr-1.5" />
                        Annuler l'encaissement
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
