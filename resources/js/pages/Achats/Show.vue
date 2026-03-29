<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, PackageCheck, XCircle } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import Textarea from 'primevue/textarea';
import { useToast } from 'primevue/usetoast';
import { ref } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────
interface LigneCommande {
    id: number;
    produit_id: number;
    produit_nom: string | null;
    qte: number;
    qte_recue: number;
    prix_achat_snapshot: number;
    total_ligne: number;
}

interface CommandeData {
    id: number;
    reference: string;
    statut: string;
    statut_label: string;
    total_commande: number;
    prestataire_nom: string | null;
    note: string | null;
    motif_annulation: string | null;
    annulee_at: string | null;
    is_annulee: boolean;
    is_receptionnee: boolean;
    created_at: string;
    created_by: string | null;
    lignes: LigneCommande[];
}

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps<{ commande: CommandeData }>();

const { can } = usePermissions();
const toast = useToast();

// ── Breadcrumbs ───────────────────────────────────────────────────────────────
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Achats', href: '/achats' },
    { title: props.commande.reference, href: '#' },
];

// ── Statut couleurs ───────────────────────────────────────────────────────────
const statutColor: Record<string, string> = {
    en_cours:     'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
    receptionnee: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    annulee:      'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
};

// ── Formatage ─────────────────────────────────────────────────────────────────
function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

function formatDateTime(val: string | null): string {
    if (!val) return '—';
    return new Date(val).toLocaleString('fr-FR');
}

// ── Réception ────────────────────────────────────────────────────────────────
const receptionDialogVisible = ref(false);

const receptionForm = useForm({
    lignes: props.commande.lignes.map(l => ({
        id: l.id,
        qte_recue: l.qte,
    })),
});

function openReceptionDialog() {
    // Réinitialise les qtés reçues à la qté commandée
    receptionForm.lignes = props.commande.lignes.map(l => ({
        id: l.id,
        qte_recue: l.qte,
    }));
    receptionDialogVisible.value = true;
}

function submitReception() {
    receptionForm.patch(`/achats/${props.commande.id}/receptionner`, {
        onSuccess: () => {
            receptionDialogVisible.value = false;
            toast.add({ severity: 'success', summary: 'Réceptionné', detail: 'Commande réceptionnée. Le stock a été mis à jour.', life: 4000 });
        },
    });
}

// ── Annulation ────────────────────────────────────────────────────────────────
const annulerDialogVisible = ref(false);
const annulerForm = useForm({
    motif_annulation: '',
});

function submitAnnuler() {
    annulerForm.patch(`/achats/${props.commande.id}/annuler`, {
        onSuccess: () => {
            annulerDialogVisible.value = false;
            toast.add({ severity: 'success', summary: 'Annulée', detail: 'Commande annulée.', life: 3000 });
        },
    });
}
</script>

<template>
    <Head :title="commande.reference" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">

        <!-- Mobile sticky header -->
        <div class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden">
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link href="/achats" class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95">
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div class="text-center">
                    <h1 class="text-[17px] font-semibold leading-tight">{{ commande.reference }}</h1>
                </div>
            </div>
        </div>

        <div class="mx-auto max-w-5xl space-y-6 p-4 sm:p-6">

            <!-- En-tête ──────────────────────────────────────────────────────── -->
            <div class="hidden sm:flex items-start justify-between gap-4">
                <div class="flex items-start gap-4">
                    <Link href="/achats">
                        <Button variant="ghost" size="icon" class="h-8 w-8 mt-1">
                            <ArrowLeft class="h-4 w-4" />
                        </Button>
                    </Link>
                    <div>
                        <h1 class="font-mono text-2xl font-bold tracking-wide">{{ commande.reference }}</h1>
                        <div class="mt-1 flex items-center gap-2">
                            <span
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="statutColor[commande.statut] ?? 'bg-muted text-muted-foreground'"
                            >
                                {{ commande.statut_label }}
                            </span>
                            <span class="text-sm text-muted-foreground">{{ commande.created_at }}</span>
                            <span v-if="commande.created_by" class="text-sm text-muted-foreground">— {{ commande.created_by }}</span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <Button
                        v-if="!commande.is_annulee && !commande.is_receptionnee && can('achats.update')"
                        @click="openReceptionDialog"
                        class="bg-emerald-600 hover:bg-emerald-700 text-white"
                    >
                        <PackageCheck class="mr-2 h-4 w-4" />
                        Réceptionner
                    </Button>

                    <Button
                        v-if="!commande.is_annulee && !commande.is_receptionnee && can('achats.update')"
                        variant="outline"
                        size="sm"
                        class="text-amber-600 border-amber-300 hover:bg-amber-50 dark:hover:bg-amber-950"
                        @click="annulerDialogVisible = true"
                    >
                        <XCircle class="mr-2 h-4 w-4" />
                        Annuler
                    </Button>
                </div>
            </div>

            <!-- Badge statut mobile -->
            <div class="sm:hidden flex items-center gap-2 px-1">
                <span
                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                    :class="statutColor[commande.statut] ?? 'bg-muted text-muted-foreground'"
                >
                    {{ commande.statut_label }}
                </span>
                <span class="text-sm text-muted-foreground">{{ commande.created_at }}</span>
            </div>

            <!-- Infos générales ──────────────────────────────────────────────── -->
            <div class="rounded-xl border bg-card p-4 sm:p-5 shadow-sm">
                <h3 class="mb-5 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                    Informations
                </h3>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <p class="text-xs text-muted-foreground">Fournisseur</p>
                        <p class="mt-0.5 font-medium">{{ commande.prestataire_nom ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Note</p>
                        <p class="mt-0.5 font-medium">{{ commande.note ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Total commande</p>
                        <p class="mt-0.5 text-xl font-bold tabular-nums">{{ formatGNF(commande.total_commande) }}</p>
                    </div>
                </div>

                <!-- Motif annulation -->
                <div v-if="commande.is_annulee && commande.motif_annulation" class="mt-4 rounded-lg bg-zinc-100 dark:bg-zinc-800 p-4">
                    <p class="text-xs font-medium text-zinc-600 dark:text-zinc-400 uppercase tracking-wider mb-1">Motif d'annulation</p>
                    <p class="text-sm">{{ commande.motif_annulation }}</p>
                    <p v-if="commande.annulee_at" class="mt-1 text-xs text-zinc-500">{{ formatDateTime(commande.annulee_at) }}</p>
                </div>

                <!-- Badge réceptionné -->
                <div v-if="commande.is_receptionnee" class="mt-4 rounded-lg bg-emerald-50 dark:bg-emerald-950/30 p-4 flex items-center gap-3">
                    <PackageCheck class="h-5 w-5 text-emerald-600 dark:text-emerald-400 shrink-0" />
                    <div>
                        <p class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">Commande réceptionnée</p>
                        <p class="text-xs text-emerald-600 dark:text-emerald-400">Le stock des produits a été mis à jour lors de la réception.</p>
                    </div>
                </div>
            </div>

            <!-- Lignes de commande ───────────────────────────────────────────── -->
            <div class="rounded-xl border bg-card p-4 sm:p-5 shadow-sm">
                <h3 class="mb-5 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                    Produits commandés
                </h3>
                <div class="overflow-x-auto overflow-hidden rounded-lg border">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40">
                                <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Produit</th>
                                <th class="px-4 py-2.5 text-center font-medium text-muted-foreground" style="width: 90px">Commandé</th>
                                <th class="px-4 py-2.5 text-center font-medium text-muted-foreground" style="width: 90px">Reçu</th>
                                <th class="px-4 py-2.5 text-right font-medium text-muted-foreground" style="width: 150px">Prix achat unit.</th>
                                <th class="px-4 py-2.5 text-right font-medium text-muted-foreground" style="width: 150px">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="ligne in commande.lignes" :key="ligne.id" class="hover:bg-muted/10">
                                <td class="px-4 py-3 font-medium">{{ ligne.produit_nom ?? '—' }}</td>
                                <td class="px-4 py-3 text-center tabular-nums text-muted-foreground">{{ ligne.qte }}</td>
                                <td class="px-4 py-3 text-center">
                                    <span
                                        class="inline-flex items-center justify-center rounded-full px-2.5 py-0.5 text-xs font-semibold tabular-nums"
                                        :class="commande.is_receptionnee
                                            ? (ligne.qte_recue >= ligne.qte
                                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
                                                : ligne.qte_recue > 0
                                                    ? 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300'
                                                    : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400')
                                            : 'bg-zinc-100 text-zinc-400 dark:bg-zinc-800 dark:text-zinc-500'
                                        "
                                    >
                                        {{ commande.is_receptionnee ? ligne.qte_recue : 0 }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-muted-foreground">{{ formatGNF(ligne.prix_achat_snapshot) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums font-semibold">{{ formatGNF(ligne.total_ligne) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t bg-muted/20">
                                <td colspan="4" class="px-4 py-3 text-right text-sm font-semibold text-muted-foreground">Total</td>
                                <td class="px-4 py-3 text-right tabular-nums text-lg font-bold">{{ formatGNF(commande.total_commande) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Actions mobile ───────────────────────────────────────────────── -->
            <div v-if="!commande.is_annulee && !commande.is_receptionnee && can('achats.update')" class="sm:hidden space-y-3">
                <Button class="w-full bg-emerald-600 hover:bg-emerald-700 text-white" @click="openReceptionDialog">
                    <PackageCheck class="mr-2 h-4 w-4" />
                    Réceptionner la commande
                </Button>
                <Button variant="outline" class="w-full text-amber-600 border-amber-300" @click="annulerDialogVisible = true">
                    <XCircle class="mr-2 h-4 w-4" />
                    Annuler la commande
                </Button>
            </div>

        </div>

        <!-- Dialog Réception ──────────────────────────────────────────────────── -->
        <Dialog
            v-model:visible="receptionDialogVisible"
            modal
            header="Réceptionner la commande"
            :style="{ width: '560px' }"
        >
            <div class="space-y-4">
                <p class="text-sm text-muted-foreground">
                    Indiquez les quantités réellement reçues. Par défaut elles correspondent aux quantités commandées.
                </p>

                <div class="overflow-hidden rounded-lg border">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40">
                                <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Produit</th>
                                <th class="px-4 py-2.5 text-center font-medium text-muted-foreground" style="width: 100px">Commandé</th>
                                <th class="px-4 py-2.5 text-center font-medium text-muted-foreground" style="width: 130px">Qté reçue</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="(ligne, index) in receptionForm.lignes" :key="ligne.id" class="hover:bg-muted/10">
                                <td class="px-4 py-3 font-medium">{{ commande.lignes[index]?.produit_nom ?? '—' }}</td>
                                <td class="px-4 py-3 text-center tabular-nums text-muted-foreground">{{ commande.lignes[index]?.qte }}</td>
                                <td class="px-4 py-3">
                                    <InputNumber
                                        v-model="ligne.qte_recue"
                                        :min="0"
                                        :max="commande.lignes[index]?.qte"
                                        :use-grouping="false"
                                        class="w-full"
                                        input-class="w-full text-center"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="text-xs text-muted-foreground">
                    Seuls les produits avec une quantité reçue &gt; 0 mettront à jour le stock.
                </p>
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button variant="outline" @click="receptionDialogVisible = false">Annuler</Button>
                    <Button
                        class="bg-emerald-600 hover:bg-emerald-700 text-white"
                        :disabled="receptionForm.processing"
                        @click="submitReception"
                    >
                        <PackageCheck class="mr-2 h-4 w-4" />
                        {{ receptionForm.processing ? 'Enregistrement…' : 'Confirmer la réception' }}
                    </Button>
                </div>
            </template>
        </Dialog>

        <!-- Dialog Annulation ────────────────────────────────────────────────── -->
        <Dialog
            v-model:visible="annulerDialogVisible"
            modal
            header="Annuler la commande"
            :style="{ width: '480px' }"
        >
            <div class="space-y-4">
                <p class="text-sm text-muted-foreground">
                    Vous êtes sur le point d'annuler la commande
                    <span class="font-mono font-semibold">{{ commande.reference }}</span>.
                    Cette action est irréversible.
                </p>
                <div>
                    <Label class="mb-1.5 block text-sm">
                        Motif d'annulation <span class="text-destructive">*</span>
                    </Label>
                    <Textarea
                        v-model="annulerForm.motif_annulation"
                        rows="4"
                        class="w-full"
                        placeholder="Indiquez la raison de l'annulation..."
                        :class="{ 'p-invalid': annulerForm.errors.motif_annulation }"
                    />
                    <p v-if="annulerForm.errors.motif_annulation" class="mt-1 text-xs text-destructive">
                        {{ annulerForm.errors.motif_annulation }}
                    </p>
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button variant="outline" @click="annulerDialogVisible = false">Retour</Button>
                    <Button
                        variant="destructive"
                        :disabled="annulerForm.processing || !annulerForm.motif_annulation.trim()"
                        @click="submitAnnuler"
                    >
                        <XCircle class="mr-2 h-4 w-4" />
                        {{ annulerForm.processing ? 'Annulation…' : "Confirmer l'annulation" }}
                    </Button>
                </div>
            </template>
        </Dialog>

    </AppLayout>
</template>
