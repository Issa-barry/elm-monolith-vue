<script setup lang="ts">
import type {
    EncaissementPayload,
    MoyenEncaissement,
} from '@/components/payment/moyensEncaissement';
import PaymentCard from '@/components/payment/PaymentCard.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    Eye,
    HandCoins,
    Info,
    Pencil,
    RefreshCw,
    XCircle,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Textarea from 'primevue/textarea';
import { useToast } from 'primevue/usetoast';
import { ref } from 'vue';

interface Ligne {
    reception_ligne_id: string;
    reception_reference: string | null;
    produit_nom: string;
    reference: string | null;
    qte_commandee: number;
    qte_recue: number;
    deja_facture_ailleurs: number;
    qte: number;
    prix_unitaire: number;
    cout_reception: number;
    total_ht: number;
}

interface Facture {
    id: string;
    reference: string;
    numero_facture_fournisseur: string | null;
    date_facture: string;
    date_echeance: string | null;
    taux_tva: number;
    montant_ht: number;
    montant_tva: number;
    montant_ttc: number;
    montant_paye: number;
    reste_du: number;
    statut: string;
    statut_label: string;
    statut_affichage: string;
    note: string | null;
    fournisseur_nom: string | null;
    created_by: string | null;
    created_at: string | null;
    validee_par: string | null;
    validee_at: string | null;
    annulee_par: string | null;
    annulee_at: string | null;
    motif_annulation: string | null;
    receptions: { id: string; reference: string; date_reception: string }[];
    lignes: Ligne[];
}

interface Paiement {
    id: string;
    date_paiement: string;
    montant: number;
    mode_paiement: string;
    support: string | null;
    reference_paiement: string | null;
    created_by: string | null;
}

const props = defineProps<{
    facture: Facture;
    commande: {
        id: string;
        reference: string;
        fournisseur_nom: string | null;
        site_nom: string | null;
    };
    comptabilite: {
        statut: string;
        label: string;
        piece_numero: string | null;
        extourne_numero: string | null;
        erreur: string | null;
    };
    paiements: Paiement[];
    paiement: {
        moyens: MoyenEncaissement[];
        especes_disponibles: boolean;
        solde_especes: number | null;
    } | null;
    actions: {
        peut_modifier: boolean;
        peut_valider: boolean;
        motif_non_validable: string | null;
        peut_annuler: boolean;
        peut_relancer_comptabilite: boolean;
        peut_payer: boolean;
        motif_non_payable: string | null;
    };
}>();

const toast = useToast();

const page = usePage();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Achats', href: '/backoffice/achats' },
    { title: 'Factures d’achat', href: '/backoffice/achats/factures' },
    { title: props.facture.reference, href: '#' },
];

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(Math.round(val)) + ' GNF';
}

function formatDate(val: string | null): string {
    if (!val) return '—';
    const [a, m, j] = val.split('-');
    return `${j}/${m}/${a}`;
}

function signalerFlash() {
    const flash = (page.props as any).flash ?? {};
    if (flash.warning) {
        toast.add({
            severity: 'warn',
            summary: 'Comptabilité',
            detail: flash.warning,
            life: 8000,
        });
    }
}

// ── Validation ────────────────────────────────────────────────────────────────
const validerOuvert = ref(false);
const validationEnCours = ref(false);

function fermerValider(v: boolean) {
    if (!v && validationEnCours.value) return;
    validerOuvert.value = v;
}

function valider() {
    validationEnCours.value = true;
    router.patch(
        `/backoffice/achats/factures/${props.facture.id}/valider`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                validerOuvert.value = false;
                toast.add({
                    severity: 'success',
                    summary: 'Facture validée',
                    life: 3000,
                });
                signalerFlash();
            },
            onError: (errors) => {
                validerOuvert.value = false;
                toast.add({
                    severity: 'error',
                    summary: 'Validation refusée',
                    detail: errors.validation ?? Object.values(errors)[0],
                    life: 7000,
                });
            },
            onFinish: () => (validationEnCours.value = false),
        },
    );
}

// ── Annulation ────────────────────────────────────────────────────────────────
const annulerOuvert = ref(false);
const annulerForm = useForm({ motif_annulation: '' });

function fermerAnnuler(v: boolean) {
    if (!v && annulerForm.processing) return;
    annulerOuvert.value = v;
}

function annuler() {
    annulerForm.patch(
        `/backoffice/achats/factures/${props.facture.id}/annuler`,
        {
            preserveScroll: true,
            onSuccess: () => {
                annulerOuvert.value = false;
                toast.add({
                    severity: 'success',
                    summary: 'Facture annulée',
                    life: 3000,
                });
            },
        },
    );
}

// ── Paiement (lot 4) : PaymentCard en décaissement, comme pour les fiches ───────
const paiementOuvert = ref(false);
const paiementEnCours = ref(false);
const erreursPaiement = ref<Record<string, string>>({});

function dateDuJour(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function payer(payload: EncaissementPayload) {
    paiementEnCours.value = true;
    erreursPaiement.value = {};
    router.post(
        `/backoffice/achats/factures/${props.facture.id}/paiements`,
        { ...payload, date_paiement: dateDuJour() },
        {
            preserveScroll: true,
            onSuccess: () => {
                paiementOuvert.value = false;
                toast.add({
                    severity: 'success',
                    summary: 'Paiement enregistré',
                    life: 3000,
                });
            },
            onError: (e) => {
                erreursPaiement.value = e as Record<string, string>;
                const affichees = [
                    'montant',
                    'mode_paiement',
                    'compte_tresorerie_id',
                    'reference_paiement',
                ];
                const autres = Object.entries(erreursPaiement.value)
                    .filter(([cle]) => !affichees.includes(cle))
                    .map(([, message]) => message);
                if (autres.length > 0) {
                    toast.add({
                        severity: 'error',
                        summary: 'Paiement non enregistré',
                        detail: autres.join(' '),
                        life: 6000,
                    });
                }
            },
            onFinish: () => (paiementEnCours.value = false),
        },
    );
}

// ── Relance comptable ─────────────────────────────────────────────────────────
const relanceEnCours = ref(false);

function relancerComptabilite() {
    relanceEnCours.value = true;
    router.post(
        `/backoffice/achats/factures/${props.facture.id}/comptabiliser`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => signalerFlash(),
            onFinish: () => (relanceEnCours.value = false),
        },
    );
}
</script>

<template>
    <Head :title="facture.reference" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-6xl space-y-6 p-4 sm:p-6">
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
            >
                <div>
                    <h1 class="font-mono text-2xl font-bold tracking-wide">
                        {{ facture.reference }}
                    </h1>
                    <div
                        class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground"
                    >
                        <StatusDot
                            :status="facture.statut_affichage"
                            :label="facture.statut_label"
                            class="text-foreground"
                        />
                        <span
                            >·
                            {{
                                facture.numero_facture_fournisseur
                                    ? `N° fournisseur ${facture.numero_facture_fournisseur}`
                                    : 'Sans numéro de document'
                            }}</span
                        >
                        <span v-if="facture.created_by"
                            >· saisie par {{ facture.created_by }}</span
                        >
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button as-child variant="outline" size="sm">
                        <a
                            :href="`/backoffice/achats/factures/${facture.id}/pdf?preview=1`"
                            target="_blank"
                            rel="noopener noreferrer"
                            title="Voir le récapitulatif de la facture dans un nouvel onglet"
                        >
                            <Eye class="mr-2 h-4 w-4" />
                            Voir la facture
                        </a>
                    </Button>
                    <Link
                        v-if="actions.peut_modifier"
                        :href="`/backoffice/achats/factures/${facture.id}/edit`"
                    >
                        <Button variant="outline" size="sm">
                            <Pencil class="mr-2 h-4 w-4" />
                            Modifier
                        </Button>
                    </Link>
                    <Button
                        v-if="actions.peut_payer"
                        size="sm"
                        class="bg-emerald-600 text-white hover:bg-emerald-700"
                        @click="paiementOuvert = true"
                    >
                        <HandCoins class="mr-2 h-4 w-4" />
                        Payer
                    </Button>
                    <Button
                        v-if="actions.peut_valider"
                        size="sm"
                        @click="validerOuvert = true"
                    >
                        <CheckCircle2 class="mr-2 h-4 w-4" />
                        Valider
                    </Button>
                    <Button
                        v-if="actions.peut_annuler"
                        variant="outline"
                        size="sm"
                        class="border-amber-300 text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-950"
                        @click="annulerOuvert = true"
                    >
                        <XCircle class="mr-2 h-4 w-4" />
                        Annuler
                    </Button>
                </div>
            </div>

            <div
                v-if="facture.statut === 'brouillon'"
                class="flex items-start gap-2 rounded-xl border p-4 text-sm"
                :class="
                    actions.motif_non_validable
                        ? 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200'
                        : 'border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-200'
                "
            >
                <AlertTriangle
                    v-if="actions.motif_non_validable"
                    class="mt-0.5 h-4 w-4 shrink-0"
                />
                <Info v-else class="mt-0.5 h-4 w-4 shrink-0" />
                <p>
                    Brouillon : aucune dette n'est constatée tant que la facture
                    n'est pas validée.
                    <template v-if="actions.motif_non_validable">
                        {{ actions.motif_non_validable }}</template
                    >
                </p>
            </div>

            <div
                v-if="comptabilite.erreur"
                class="flex flex-wrap items-start justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
            >
                <p class="flex items-start gap-2">
                    <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>
                        Dette constatée, mais écriture comptable en attente de
                        paramétrage : {{ comptabilite.erreur }}
                    </span>
                </p>
                <Button
                    v-if="actions.peut_relancer_comptabilite"
                    size="sm"
                    variant="outline"
                    :disabled="relanceEnCours"
                    @click="relancerComptabilite"
                >
                    <i
                        v-if="relanceEnCours"
                        class="pi pi-spin pi-spinner mr-2"
                    />
                    <RefreshCw v-else class="mr-2 h-4 w-4" />
                    Relancer
                </Button>
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <div
                    class="rounded-xl border bg-card p-4 shadow-sm lg:col-span-2"
                >
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Fournisseur
                            </p>
                            <p class="font-medium">
                                {{ facture.fournisseur_nom ?? '—' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Bon de commande
                            </p>
                            <Link
                                :href="`/backoffice/achats/${commande.id}`"
                                class="font-mono font-medium hover:underline"
                                >{{ commande.reference }}</Link
                            >
                            <span class="text-muted-foreground">
                                · {{ commande.site_nom ?? '—' }}</span
                            >
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Date / échéance
                            </p>
                            <p class="font-medium">
                                {{ formatDate(facture.date_facture) }} /
                                {{ formatDate(facture.date_echeance) }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Réceptions concernées
                            </p>
                            <p class="font-mono text-sm">
                                {{
                                    facture.receptions
                                        .map(
                                            (r) =>
                                                `${r.reference} (${r.date_reception})`,
                                        )
                                        .join(', ') || '—'
                                }}
                            </p>
                        </div>
                        <div v-if="facture.validee_at">
                            <p class="text-xs text-muted-foreground">
                                Validation
                            </p>
                            <p class="font-medium">
                                {{ facture.validee_at }} par
                                {{ facture.validee_par ?? '—' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Comptabilisation
                            </p>
                            <StatusDot
                                :status="comptabilite.statut"
                                :label="comptabilite.label"
                            />
                            <p
                                v-if="comptabilite.piece_numero"
                                class="font-mono text-xs text-muted-foreground"
                            >
                                Pièce {{ comptabilite.piece_numero }}
                                <template v-if="comptabilite.extourne_numero">
                                    · contrepassée par
                                    {{ comptabilite.extourne_numero }}</template
                                >
                            </p>
                        </div>
                        <div
                            v-if="facture.motif_annulation"
                            class="sm:col-span-2"
                        >
                            <p class="text-xs text-muted-foreground">
                                Annulée le {{ facture.annulee_at }} par
                                {{ facture.annulee_par ?? '—' }}
                            </p>
                            <p>{{ facture.motif_annulation }}</p>
                        </div>
                        <div v-if="facture.note" class="sm:col-span-2">
                            <p class="text-xs text-muted-foreground">Note</p>
                            <p>{{ facture.note }}</p>
                        </div>
                    </div>
                </div>

                <div
                    class="space-y-2 rounded-xl border bg-card p-4 text-sm shadow-sm"
                >
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Total HT</span>
                        <span class="tabular-nums">{{
                            formatGNF(facture.montant_ht)
                        }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted-foreground"
                            >TVA ({{ facture.taux_tva }} %)</span
                        >
                        <span class="tabular-nums">{{
                            formatGNF(facture.montant_tva)
                        }}</span>
                    </div>
                    <div class="flex justify-between font-semibold">
                        <span>Total TTC</span>
                        <span class="tabular-nums">{{
                            formatGNF(facture.montant_ttc)
                        }}</span>
                    </div>
                    <div class="flex justify-between border-t pt-2">
                        <span class="text-muted-foreground">Déjà payé</span>
                        <span class="tabular-nums">{{
                            formatGNF(facture.montant_paye)
                        }}</span>
                    </div>
                    <div class="flex justify-between text-base font-bold">
                        <span>Reste dû</span>
                        <span class="tabular-nums">{{
                            formatGNF(facture.reste_du)
                        }}</span>
                    </div>
                </div>
            </div>

            <p
                v-if="actions.motif_non_payable"
                class="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
            >
                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                {{ actions.motif_non_payable }}
            </p>

            <div
                v-if="paiements.length > 0"
                class="overflow-x-auto rounded-xl border bg-card shadow-sm"
            >
                <div class="border-b bg-muted/30 px-4 py-2 text-sm font-medium">
                    Paiements
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-muted-foreground">
                            <th class="px-3 py-2 font-medium">Date</th>
                            <th class="px-3 py-2 font-medium">Moyen</th>
                            <th class="px-3 py-2 font-medium">Référence</th>
                            <th class="px-3 py-2 font-medium">Par</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Montant
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="p in paiements" :key="p.id">
                            <td class="px-3 py-2 tabular-nums">
                                {{ p.date_paiement }}
                            </td>
                            <td class="px-3 py-2">
                                {{ p.support ?? p.mode_paiement }}
                            </td>
                            <td class="px-3 py-2 font-mono text-xs">
                                {{ p.reference_paiement ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-muted-foreground">
                                {{ p.created_by ?? '—' }}
                            </td>
                            <td
                                class="px-3 py-2 text-right font-semibold tabular-nums"
                            >
                                {{ formatGNF(p.montant) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="overflow-x-auto rounded-xl border bg-card shadow-sm">
                <table class="w-full text-sm">
                    <thead>
                        <tr
                            class="border-b bg-muted/40 text-left text-muted-foreground"
                        >
                            <th class="px-3 py-2 font-medium">Produit</th>
                            <th class="px-3 py-2 font-medium">Réception</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Commandé
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Reçu
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Facturé ailleurs
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Facturé ici
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Restant facturable
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Prix unitaire
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Total HT
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="l in facture.lignes"
                            :key="l.reception_ligne_id"
                        >
                            <td class="px-3 py-2 font-medium">
                                {{ l.produit_nom }}
                                <span
                                    v-if="l.reference"
                                    class="block font-mono text-xs font-normal text-muted-foreground"
                                    >{{ l.reference }}</span
                                >
                            </td>
                            <td class="px-3 py-2 font-mono text-xs">
                                {{ l.reception_reference ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ l.qte_commandee }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ l.qte_recue }}
                            </td>
                            <td
                                class="px-3 py-2 text-right text-muted-foreground tabular-nums"
                            >
                                {{ l.deja_facture_ailleurs }}
                            </td>
                            <td
                                class="px-3 py-2 text-right font-medium tabular-nums"
                            >
                                {{ l.qte }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{
                                    Math.max(
                                        0,
                                        l.qte_recue -
                                            l.deja_facture_ailleurs -
                                            (facture.statut === 'brouillon' ||
                                            facture.statut === 'annulee'
                                                ? 0
                                                : l.qte),
                                    )
                                }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ formatGNF(l.prix_unitaire) }}
                                <span
                                    v-if="l.prix_unitaire !== l.cout_reception"
                                    class="flex items-center justify-end gap-1 text-xs text-amber-600 dark:text-amber-400"
                                >
                                    <AlertTriangle class="h-3 w-3" />
                                    Bon : {{ formatGNF(l.cout_reception) }}
                                </span>
                            </td>
                            <td
                                class="px-3 py-2 text-right font-semibold tabular-nums"
                            >
                                {{ formatGNF(l.total_ht) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <PaymentCard
            v-if="paiement"
            v-model:visible="paiementOuvert"
            :title="`Payer la facture ${facture.reference}`"
            :info-rows="[
                { label: 'Fournisseur', value: facture.fournisseur_nom ?? '—' },
                {
                    label: 'N° fournisseur',
                    value: facture.numero_facture_fournisseur ?? 'Sans numéro',
                },
                { label: 'Montant TTC', value: formatGNF(facture.montant_ttc) },
                { label: 'Déjà payé', value: formatGNF(facture.montant_paye) },
            ]"
            sens="decaissement"
            solde-label="Reste dû"
            :solde="facture.reste_du"
            :moyens="paiement.moyens"
            :especes-disponibles="paiement.especes_disponibles"
            :solde-especes="paiement.solde_especes"
            :processing="paiementEnCours"
            :errors="erreursPaiement"
            @submit="payer"
        />

        <Dialog
            :visible="validerOuvert"
            modal
            header="Valider la facture"
            :closable="!validationEnCours"
            :style="{ width: '480px', maxWidth: '95vw' }"
            @update:visible="fermerValider"
        >
            <p class="text-sm text-muted-foreground">
                La validation constate une dette de
                <span class="font-semibold text-foreground">{{
                    formatGNF(facture.montant_ttc)
                }}</span>
                envers {{ facture.fournisseur_nom }}. La facture ne sera plus
                modifiable.
            </p>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button
                        variant="outline"
                        :disabled="validationEnCours"
                        @click="fermerValider(false)"
                        >Retour</Button
                    >
                    <Button :disabled="validationEnCours" @click="valider">
                        <i
                            v-if="validationEnCours"
                            class="pi pi-spin pi-spinner mr-2"
                        />
                        <CheckCircle2 v-else class="mr-2 h-4 w-4" />
                        Valider
                    </Button>
                </div>
            </template>
        </Dialog>

        <Dialog
            :visible="annulerOuvert"
            modal
            header="Annuler la facture"
            :closable="!annulerForm.processing"
            :style="{ width: '480px', maxWidth: '95vw' }"
            @update:visible="fermerAnnuler"
        >
            <div class="space-y-2">
                <p class="text-sm text-muted-foreground">
                    Les quantités redeviendront facturables.
                    <template v-if="facture.statut !== 'brouillon'">
                        L'écriture comptable sera contrepassée.</template
                    >
                </p>
                <Label for="ff-motif" class="block text-sm">
                    Motif <span class="text-destructive">*</span>
                </Label>
                <Textarea
                    id="ff-motif"
                    v-model="annulerForm.motif_annulation"
                    rows="3"
                    class="w-full"
                    :invalid="!!annulerForm.errors.motif_annulation"
                />
                <p
                    v-if="annulerForm.errors.motif_annulation"
                    class="text-xs text-destructive"
                >
                    {{ annulerForm.errors.motif_annulation }}
                </p>
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button
                        variant="outline"
                        :disabled="annulerForm.processing"
                        @click="fermerAnnuler(false)"
                        >Retour</Button
                    >
                    <Button
                        variant="destructive"
                        :disabled="
                            annulerForm.processing ||
                            !annulerForm.motif_annulation.trim()
                        "
                        @click="annuler"
                    >
                        <i
                            v-if="annulerForm.processing"
                            class="pi pi-spin pi-spinner mr-2"
                        />
                        Confirmer l'annulation
                    </Button>
                </div>
            </template>
        </Dialog>
    </AppLayout>
</template>
