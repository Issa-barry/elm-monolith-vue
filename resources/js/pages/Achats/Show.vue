<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    Download,
    FileText,
    Info,
    PackageCheck,
    Pencil,
    Trash2,
    XCircle,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Textarea from 'primevue/textarea';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

interface LigneCommande {
    id: string;
    produit_nom: string;
    reference: string | null;
    qte: number;
    qte_recue: number;
    reliquat: number;
    prix_achat_snapshot: number;
    total_ligne: number;
}

interface Reception {
    id: string;
    reference: string;
    date_reception: string;
    note: string | null;
    created_by: string | null;
    lignes: { produit_nom: string; qte_recue: number; cout_unitaire: number }[];
}

interface CommandeData {
    id: string;
    reference: string;
    statut: string;
    statut_label: string;
    total_commande: number;
    montant_valide: number | null;
    fournisseur_nom: string | null;
    site_nom: string | null;
    note: string | null;
    created_at: string;
    created_by: string | null;
    validee_at: string | null;
    validee_par: string | null;
    validation_regle: {
        role_label: string;
        plafond: number | null;
        plafond_illimite: boolean;
    } | null;
    motif_annulation: string | null;
    annulee_at: string | null;
    annulee_par: string | null;
    motif_cloture: string | null;
    cloturee_at: string | null;
    cloturee_par: string | null;
    is_a_valider: boolean;
    lignes: LigneCommande[];
    receptions: Reception[];
    factures: {
        id: string;
        reference: string;
        numero_facture_fournisseur: string;
        date_facture: string;
        montant_ttc: number;
        reste_du: number;
        statut: string;
        statut_label: string;
    }[];
}

interface Actions {
    peut_modifier: boolean;
    peut_valider: boolean;
    motif_non_validable: string | null;
    peut_annuler: boolean;
    peut_cloturer: boolean;
    lien_reception: string | null;
    lien_facture: string | null;
    peut_supprimer: boolean;
}

const props = defineProps<{
    commande: CommandeData;
    actions: Actions;
    validable_par: { role: string; label: string; plafond: number | null }[];
}>();

const toast = useToast();
const confirm = useConfirm();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Achats', href: '/backoffice/achats' },
    { title: props.commande.reference, href: '#' },
];

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

const qteCommandee = computed(() =>
    props.commande.lignes.reduce((s, l) => s + l.qte, 0),
);
const qteRecue = computed(() =>
    props.commande.lignes.reduce((s, l) => s + l.qte_recue, 0),
);

const validateursTexte = computed(() =>
    props.validable_par
        .map(
            (v) =>
                `${v.label} (${v.plafond === null ? 'sans limite' : '≤ ' + formatGNF(v.plafond)})`,
        )
        .join(', '),
);

// ── Validation ────────────────────────────────────────────────────────────────
const validerOuvert = ref(false);
const validationEnCours = ref(false);

function fermerValider(valeur: boolean) {
    if (!valeur && validationEnCours.value) return;
    validerOuvert.value = valeur;
}

function valider() {
    validationEnCours.value = true;
    router.patch(
        `/backoffice/achats/${props.commande.id}/valider`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                validerOuvert.value = false;
                toast.add({
                    severity: 'success',
                    summary: 'Bon de commande validé',
                    life: 3000,
                });
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

// ── Annulation / clôture ──────────────────────────────────────────────────────
const annulerOuvert = ref(false);
const annulerForm = useForm({ motif_annulation: '' });

function fermerAnnuler(valeur: boolean) {
    if (!valeur && annulerForm.processing) return;
    annulerOuvert.value = valeur;
}

function submitAnnuler() {
    annulerForm.patch(`/backoffice/achats/${props.commande.id}/annuler`, {
        preserveScroll: true,
        onSuccess: () => {
            annulerOuvert.value = false;
            toast.add({
                severity: 'success',
                summary: 'Commande annulée',
                life: 3000,
            });
        },
    });
}

const cloturerOuvert = ref(false);
const cloturerForm = useForm({ motif_cloture: '' });

function fermerCloturer(valeur: boolean) {
    if (!valeur && cloturerForm.processing) return;
    cloturerOuvert.value = valeur;
}

function submitCloturer() {
    cloturerForm.patch(`/backoffice/achats/${props.commande.id}/cloturer`, {
        preserveScroll: true,
        onSuccess: () => {
            cloturerOuvert.value = false;
            toast.add({
                severity: 'success',
                summary: 'Commande clôturée',
                life: 3000,
            });
        },
    });
}

function supprimer() {
    confirm.require({
        message: `Supprimer la commande « ${props.commande.reference} » ? Cette action est irréversible.`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => router.delete(`/backoffice/achats/${props.commande.id}`),
    });
}
</script>

<template>
    <Head :title="commande.reference" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <div
            class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden"
        >
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link
                    href="/backoffice/achats"
                    class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <h1 class="font-mono text-[17px] leading-tight font-semibold">
                    {{ commande.reference }}
                </h1>
            </div>
        </div>

        <div class="mx-auto max-w-5xl space-y-6 p-4 sm:p-6">
            <!-- En-tête -->
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="flex items-start gap-3">
                    <Link href="/backoffice/achats" class="hidden sm:block">
                        <Button
                            variant="ghost"
                            size="icon"
                            class="mt-1 h-8 w-8"
                        >
                            <ArrowLeft class="h-4 w-4" />
                        </Button>
                    </Link>
                    <div>
                        <h1
                            class="hidden font-mono text-2xl font-bold tracking-wide sm:block"
                        >
                            {{ commande.reference }}
                        </h1>
                        <div
                            class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground"
                        >
                            <StatusDot
                                :status="commande.statut"
                                :label="commande.statut_label"
                                class="text-foreground"
                            />
                            <span>· créé le {{ commande.created_at }}</span>
                            <span v-if="commande.created_by"
                                >par {{ commande.created_by }}</span
                            >
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <a
                        :href="`/backoffice/achats/${commande.id}/pdf`"
                        target="_blank"
                    >
                        <Button variant="outline" size="sm">
                            <Download class="mr-2 h-4 w-4" />
                            PDF
                        </Button>
                    </a>
                    <Link
                        v-if="actions.peut_modifier"
                        :href="`/backoffice/achats/${commande.id}/edit`"
                    >
                        <Button variant="outline" size="sm">
                            <Pencil class="mr-2 h-4 w-4" />
                            Modifier
                        </Button>
                    </Link>
                    <Button
                        v-if="actions.peut_valider"
                        size="sm"
                        @click="validerOuvert = true"
                    >
                        <CheckCircle2 class="mr-2 h-4 w-4" />
                        Valider
                    </Button>
                    <Link
                        v-if="actions.lien_reception"
                        :href="actions.lien_reception"
                    >
                        <Button
                            size="sm"
                            class="bg-emerald-600 text-white hover:bg-emerald-700"
                        >
                            <PackageCheck class="mr-2 h-4 w-4" />
                            Réceptionner dans Logistique
                        </Button>
                    </Link>
                    <Link
                        v-if="actions.lien_facture"
                        :href="actions.lien_facture"
                    >
                        <Button variant="outline" size="sm">
                            <FileText class="mr-2 h-4 w-4" />
                            Saisir une facture
                        </Button>
                    </Link>
                    <Button
                        v-if="actions.peut_cloturer"
                        variant="outline"
                        size="sm"
                        @click="cloturerOuvert = true"
                    >
                        Clôturer le reliquat
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
                    <Button
                        v-if="actions.peut_supprimer"
                        variant="outline"
                        size="sm"
                        class="text-destructive"
                        @click="supprimer"
                    >
                        <Trash2 class="mr-2 h-4 w-4" />
                        Supprimer
                    </Button>
                </div>
            </div>

            <!-- Validation en attente -->
            <div
                v-if="commande.is_a_valider"
                class="space-y-2 rounded-xl border p-4 text-sm"
                :class="
                    actions.motif_non_validable
                        ? 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200'
                        : 'border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-200'
                "
            >
                <p class="flex items-start gap-2 font-medium">
                    <AlertTriangle
                        v-if="actions.motif_non_validable"
                        class="mt-0.5 h-4 w-4 shrink-0"
                    />
                    <Info v-else class="mt-0.5 h-4 w-4 shrink-0" />
                    En attente de validation —
                    {{ formatGNF(commande.total_commande) }}
                </p>
                <p v-if="actions.motif_non_validable" class="pl-6">
                    {{ actions.motif_non_validable }}
                </p>
                <p v-if="validable_par.length > 0" class="pl-6">
                    Validable par : {{ validateursTexte }}.
                </p>
                <p v-else-if="commande.site_nom" class="pl-6">
                    Aucun rôle n'a de plafond suffisant pour ce montant
                    (Paramètres → Achats) : seul le super administrateur peut la
                    valider.
                </p>
            </div>

            <!-- Informations -->
            <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-5">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <p class="text-xs text-muted-foreground">Fournisseur</p>
                        <p class="mt-0.5 font-medium">
                            {{ commande.fournisseur_nom ?? '—' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">
                            Agence de livraison
                        </p>
                        <p class="mt-0.5 font-medium">
                            {{ commande.site_nom ?? '—' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Validation</p>
                        <p class="mt-0.5 font-medium">
                            <template v-if="commande.validee_at">
                                {{ commande.validee_at }}
                                <span class="text-muted-foreground"
                                    >par {{ commande.validee_par ?? '—' }}</span
                                >
                                <span
                                    v-if="commande.validation_regle"
                                    class="block text-xs font-normal text-muted-foreground"
                                >
                                    Rôle
                                    {{ commande.validation_regle.role_label }}
                                    —
                                    {{
                                        commande.validation_regle
                                            .plafond_illimite
                                            ? 'sans limite'
                                            : 'plafond ' +
                                              formatGNF(
                                                  commande.validation_regle
                                                      .plafond ?? 0,
                                              )
                                    }}
                                </span>
                            </template>
                            <template v-else>—</template>
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">
                            Total commande
                        </p>
                        <p class="mt-0.5 text-xl font-bold tabular-nums">
                            {{ formatGNF(commande.total_commande) }}
                        </p>
                    </div>
                    <div
                        v-if="commande.note"
                        class="sm:col-span-2 lg:col-span-4"
                    >
                        <p class="text-xs text-muted-foreground">Note</p>
                        <p class="mt-0.5">{{ commande.note }}</p>
                    </div>
                </div>

                <div
                    v-if="commande.motif_annulation"
                    class="mt-4 rounded-lg bg-muted p-4 text-sm"
                >
                    <p class="font-medium">
                        Annulée le {{ commande.annulee_at }}
                        <span v-if="commande.annulee_par" class="font-normal"
                            >par {{ commande.annulee_par }}</span
                        >
                    </p>
                    <p class="mt-1 text-muted-foreground">
                        {{ commande.motif_annulation }}
                    </p>
                </div>
                <div
                    v-if="commande.motif_cloture"
                    class="mt-4 rounded-lg bg-muted p-4 text-sm"
                >
                    <p class="font-medium">
                        Reliquat abandonné le {{ commande.cloturee_at }}
                        <span v-if="commande.cloturee_par" class="font-normal"
                            >par {{ commande.cloturee_par }}</span
                        >
                    </p>
                    <p class="mt-1 text-muted-foreground">
                        {{ commande.motif_cloture }}
                    </p>
                </div>
            </div>

            <!-- Lignes -->
            <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-5">
                <h3
                    class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    Produits commandés · reçu {{ qteRecue }} /
                    {{ qteCommandee }}
                </h3>
                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="border-b bg-muted/40 text-muted-foreground"
                            >
                                <th class="px-4 py-2.5 text-left font-medium">
                                    Produit
                                </th>
                                <th class="px-4 py-2.5 text-center font-medium">
                                    Commandé
                                </th>
                                <th class="px-4 py-2.5 text-center font-medium">
                                    Reçu
                                </th>
                                <th class="px-4 py-2.5 text-center font-medium">
                                    Reste
                                </th>
                                <th class="px-4 py-2.5 text-right font-medium">
                                    Prix unit.
                                </th>
                                <th class="px-4 py-2.5 text-right font-medium">
                                    Total
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="ligne in commande.lignes"
                                :key="ligne.id"
                            >
                                <td class="px-4 py-3 font-medium">
                                    {{ ligne.produit_nom }}
                                    <span
                                        v-if="ligne.reference"
                                        class="block font-mono text-xs font-normal text-muted-foreground"
                                        >{{ ligne.reference }}</span
                                    >
                                </td>
                                <td class="px-4 py-3 text-center tabular-nums">
                                    {{ ligne.qte }}
                                </td>
                                <td class="px-4 py-3 text-center tabular-nums">
                                    {{ ligne.qte_recue }}
                                </td>
                                <td
                                    class="px-4 py-3 text-center text-muted-foreground tabular-nums"
                                >
                                    {{ ligne.reliquat }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ formatGNF(ligne.prix_achat_snapshot) }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right font-semibold tabular-nums"
                                >
                                    {{ formatGNF(ligne.total_ligne) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Factures fournisseurs -->
            <div
                v-if="commande.factures.length > 0"
                class="rounded-xl border bg-card p-4 shadow-sm sm:p-5"
            >
                <h3
                    class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    Factures fournisseurs
                </h3>
                <div class="divide-y rounded-lg border">
                    <Link
                        v-for="f in commande.factures"
                        :key="f.id"
                        :href="`/backoffice/achats/factures/${f.id}`"
                        class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm hover:bg-muted/20"
                    >
                        <span>
                            <span class="font-mono font-semibold">{{
                                f.reference
                            }}</span>
                            <span class="text-muted-foreground">
                                · N° {{ f.numero_facture_fournisseur }} ·
                                {{ f.date_facture }}</span
                            >
                        </span>
                        <span class="flex items-center gap-4">
                            <span class="tabular-nums"
                                >{{ formatGNF(f.montant_ttc) }} · reste dû
                                {{ formatGNF(f.reste_du) }}</span
                            >
                            <StatusDot
                                :status="f.statut"
                                :label="f.statut_label"
                                class="text-muted-foreground"
                            />
                        </span>
                    </Link>
                </div>
            </div>

            <!-- Réceptions -->
            <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-5">
                <h3
                    class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    Réceptions
                </h3>
                <p
                    v-if="commande.receptions.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Aucune réception enregistrée.
                </p>
                <div v-else class="space-y-3">
                    <div
                        v-for="r in commande.receptions"
                        :key="r.id"
                        class="rounded-lg border p-3"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-2 text-sm"
                        >
                            <span class="font-mono font-semibold">{{
                                r.reference
                            }}</span>
                            <span class="text-muted-foreground">
                                {{ r.date_reception }}
                                <template v-if="r.created_by"
                                    >· {{ r.created_by }}</template
                                >
                            </span>
                        </div>
                        <ul class="mt-2 space-y-0.5 text-sm">
                            <li
                                v-for="(l, i) in r.lignes"
                                :key="i"
                                class="flex justify-between gap-2"
                            >
                                <span>{{ l.produit_nom }}</span>
                                <span class="tabular-nums"
                                    >{{ l.qte_recue }} ×
                                    {{ formatGNF(l.cout_unitaire) }}</span
                                >
                            </li>
                        </ul>
                        <p
                            v-if="r.note"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            {{ r.note }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Validation -->
        <Dialog
            :visible="validerOuvert"
            modal
            header="Valider le bon de commande"
            :closable="!validationEnCours"
            :style="{ width: '460px', maxWidth: '95vw' }"
            @update:visible="fermerValider"
        >
            <p class="text-sm text-muted-foreground">
                Valider
                <span class="font-mono font-semibold text-foreground">{{
                    commande.reference
                }}</span>
                pour
                <span class="font-semibold text-foreground">{{
                    formatGNF(commande.total_commande)
                }}</span>
                ? La commande ne sera plus modifiable et pourra être
                réceptionnée à {{ commande.site_nom }}.
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

        <!-- Annulation -->
        <Dialog
            :visible="annulerOuvert"
            modal
            header="Annuler la commande"
            :closable="!annulerForm.processing"
            :style="{ width: '480px', maxWidth: '95vw' }"
            @update:visible="fermerAnnuler"
        >
            <div class="space-y-2">
                <Label for="motif-annulation" class="block text-sm">
                    Motif d'annulation
                    <span class="text-destructive">*</span>
                </Label>
                <Textarea
                    id="motif-annulation"
                    v-model="annulerForm.motif_annulation"
                    rows="4"
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
                        @click="submitAnnuler"
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

        <!-- Clôture du reliquat -->
        <Dialog
            :visible="cloturerOuvert"
            modal
            header="Clôturer le reliquat"
            :closable="!cloturerForm.processing"
            :style="{ width: '480px', maxWidth: '95vw' }"
            @update:visible="fermerCloturer"
        >
            <div class="space-y-2">
                <p class="text-sm text-muted-foreground">
                    Les quantités restantes ne seront plus attendues et la
                    commande ne pourra plus être réceptionnée.
                </p>
                <Label for="motif-cloture" class="block text-sm">
                    Motif <span class="text-destructive">*</span>
                </Label>
                <Textarea
                    id="motif-cloture"
                    v-model="cloturerForm.motif_cloture"
                    rows="3"
                    class="w-full"
                    :invalid="!!cloturerForm.errors.motif_cloture"
                />
                <p
                    v-if="cloturerForm.errors.motif_cloture"
                    class="text-xs text-destructive"
                >
                    {{ cloturerForm.errors.motif_cloture }}
                </p>
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button
                        variant="outline"
                        :disabled="cloturerForm.processing"
                        @click="fermerCloturer(false)"
                        >Retour</Button
                    >
                    <Button
                        :disabled="
                            cloturerForm.processing ||
                            !cloturerForm.motif_cloture.trim()
                        "
                        @click="submitCloturer"
                    >
                        <i
                            v-if="cloturerForm.processing"
                            class="pi pi-spin pi-spinner mr-2"
                        />
                        Clôturer
                    </Button>
                </div>
            </template>
        </Dialog>
    </AppLayout>
</template>
