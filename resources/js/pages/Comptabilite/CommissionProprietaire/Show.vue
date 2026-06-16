<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, HandCoins, History } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import { reactive, ref } from 'vue';

interface CommandeRow {
    commission_id: string;
    commande_reference: string | null;
    date_commande: string | null;
    site: string | null;
    vehicule: string | null;
    montant_brut: number;
    montant_net: number;
    montant_verse: number;
    periode: string | null;
    periode_label: string | null;
}

interface FraisRow {
    id: string;
    date: string;
    type: string;
    montant: number;
    commentaire: string | null;
}

interface PaiementRow {
    id: string;
    paid_at: string | null;
    montant: number;
    mode_paiement: string;
    note: string | null;
    created_by: string | null;
}

interface PeriodeOption { code: string; label: string; }
interface ModePaiement { value: string; label: string; }

const props = defineProps<{
    proprietaire: { id: string; nom: string; telephone: string | null };
    resume_global: { total_brut_cumule: number; total_frais_depenses: number; total_net_cumule: number; total_verse: number; solde_global: number };
    frais_depenses: FraisRow[];
    historique_commandes: CommandeRow[];
    historique_paiements: PaiementRow[];
    modes_paiement: ModePaiement[];
    periode_courante: string;
    selected_periode: string;
    periodes_disponibles: PeriodeOption[];
    can_payer: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Comptabilité', href: '/comptabilite' },
    { title: 'Commission propriétaire', href: '/comptabilite/commissions/proprietaires' },
    { title: props.proprietaire.nom, href: '' },
];

const periodeFiltre = ref(props.selected_periode ?? '');
const PERIODE_OPTIONS = [
    { code: '', label: 'Toutes les périodes' },
    ...props.periodes_disponibles,
];

function changePeriode(code: string) {
    const params: Record<string, string> = {};
    if (code) params.periode = code;
    router.get(`/comptabilite/commissions/proprietaires/${props.proprietaire.id}`, params, { preserveScroll: true, replace: true });
}

// Dialog paiement
const showPaiementDialog = ref(false);
const paiementForm = reactive({
    montant: null as number | null,
    mode_paiement: 'especes',
    note: '',
    processing: false,
    errors: {} as Record<string, string>,
});

function openPaiement() {
    paiementForm.montant = props.resume_global.solde_global > 0 ? props.resume_global.solde_global : null;
    paiementForm.mode_paiement = 'especes';
    paiementForm.note = '';
    paiementForm.processing = false;
    paiementForm.errors = {};
    showPaiementDialog.value = true;
}

function submitPaiement() {
    if (!paiementForm.montant) return;
    paiementForm.processing = true;
    paiementForm.errors = {};
    router.post(
        `/comptabilite/commissions/proprietaires/${props.proprietaire.id}/paiements`,
        { montant: paiementForm.montant, mode_paiement: paiementForm.mode_paiement, note: paiementForm.note || null },
        {
            preserveScroll: true,
            onSuccess: () => { showPaiementDialog.value = false; },
            onError: (e) => { paiementForm.errors = e as Record<string, string>; },
            onFinish: () => { paiementForm.processing = false; },
        },
    );
}

const showHistoriqueDialog = ref(false);
const showFraisDialog = ref(false);

function fmt(val: number | null | undefined) {
    return new Intl.NumberFormat('fr-FR').format(Math.round(Number(val ?? 0))) + ' GNF';
}

function formatMode(mode: string) {
    return props.modes_paiement.find((m) => m.value === mode)?.label ?? mode;
}
</script>

<template>
    <Head :title="`Commission propriétaire — ${proprietaire.nom}`" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-5xl space-y-6 px-4 py-6 sm:px-6">
            <!-- En-tête -->
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-center gap-3">
                    <Link href="/comptabilite/commissions/proprietaires" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground hover:bg-muted/80">
                        <ArrowLeft class="h-4 w-4" />
                    </Link>
                    <div>
                        <p class="text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase">Propriétaire</p>
                        <p class="mt-0.5 text-xl font-semibold">{{ proprietaire.nom }}</p>
                        <p v-if="proprietaire.telephone" class="text-sm text-muted-foreground">{{ proprietaire.telephone }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button v-if="frais_depenses.length > 0" variant="outline" size="sm" @click="showFraisDialog = true">
                        Frais ({{ frais_depenses.length }})
                    </Button>
                    <Button v-if="historique_paiements.length > 0" variant="outline" size="sm" @click="showHistoriqueDialog = true">
                        <History class="mr-1.5 h-3.5 w-3.5" />
                        Historique
                    </Button>
                    <Button v-if="can_payer && resume_global.solde_global > 0" size="sm" @click="openPaiement">
                        <HandCoins class="mr-1.5 h-4 w-4" />
                        Payer {{ fmt(resume_global.solde_global) }}
                    </Button>
                </div>
            </div>

            <!-- KPIs globaux -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-lg border bg-card p-4 text-center">
                    <p class="text-base font-bold tabular-nums">{{ fmt(resume_global.total_brut_cumule) }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">Brut cumulé</p>
                </div>
                <div class="rounded-lg border bg-card p-4 text-center">
                    <p class="text-base font-bold tabular-nums text-red-600 dark:text-red-400">-{{ fmt(resume_global.total_frais_depenses) }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">Frais véhicules</p>
                </div>
                <div class="rounded-lg border bg-card p-4 text-center">
                    <p class="text-base font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ fmt(resume_global.total_verse) }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">Déjà payé</p>
                </div>
                <div class="rounded-lg border bg-card p-4 text-center" :class="resume_global.solde_global > 0 ? 'border-amber-200 dark:border-amber-900' : ''">
                    <p class="text-base font-bold tabular-nums" :class="resume_global.solde_global > 0 ? 'text-amber-600 dark:text-amber-400' : ''">
                        {{ fmt(resume_global.solde_global) }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">Reste à payer</p>
                </div>
            </div>

            <!-- Tableau commandes -->
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3">
                    <div class="flex items-center gap-2">
                        <h2 class="text-xs font-semibold tracking-wider text-muted-foreground uppercase">Commissions</h2>
                        <span class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">{{ historique_commandes.length }}</span>
                    </div>
                    <Dropdown
                        v-model="periodeFiltre"
                        :options="PERIODE_OPTIONS"
                        option-label="label"
                        option-value="code"
                        placeholder="Toutes les périodes"
                        class="w-full text-sm sm:w-56"
                        @change="changePeriode(periodeFiltre)"
                    />
                </div>

                <table v-if="historique_commandes.length > 0" class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40">
                            <th class="px-4 py-3 text-left font-medium text-muted-foreground">Commande</th>
                            <th class="px-4 py-3 text-left font-medium text-muted-foreground">Date</th>
                            <th class="px-4 py-3 text-left font-medium text-muted-foreground">Période</th>
                            <th class="px-4 py-3 text-right font-medium text-muted-foreground">Brut</th>
                            <th class="px-4 py-3 text-right font-medium text-muted-foreground">Payé</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="c in historique_commandes" :key="c.commission_id" class="hover:bg-muted/10">
                            <td class="px-4 py-3 font-mono text-xs">{{ c.commande_reference ?? '—' }}</td>
                            <td class="px-4 py-3 text-xs text-muted-foreground">{{ c.date_commande ?? '—' }}</td>
                            <td class="px-4 py-3 text-xs text-muted-foreground">{{ c.periode_label ?? '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ fmt(c.montant_brut) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-emerald-600 dark:text-emerald-400">{{ fmt(c.montant_verse) }}</td>
                        </tr>
                    </tbody>
                </table>
                <div v-else class="flex flex-col items-center gap-3 py-12 text-muted-foreground">
                    <HandCoins class="h-10 w-10 opacity-30" />
                    <p class="text-sm">Aucune commission pour cette période.</p>
                </div>
            </div>
        </div>
    </AppLayout>

    <!-- Dialog paiement -->
    <Dialog v-model:visible="showPaiementDialog" modal :style="{ width: '420px' }" header="Enregistrer un paiement">
        <div class="flex flex-col gap-4 py-2">
            <div class="flex flex-col gap-1.5">
                <Label>Montant (GNF)</Label>
                <InputNumber v-model="paiementForm.montant" :min="1" :max="resume_global.solde_global" :use-grouping="true" class="w-full" input-class="w-full" suffix=" GNF" locale="fr-FR" autofocus />
                <p v-if="paiementForm.errors.montant" class="text-xs text-destructive">{{ paiementForm.errors.montant }}</p>
                <p class="text-xs text-muted-foreground">Disponible : {{ fmt(resume_global.solde_global) }}</p>
            </div>
            <div class="flex flex-col gap-1.5">
                <Label>Mode de paiement</Label>
                <Dropdown v-model="paiementForm.mode_paiement" :options="modes_paiement" option-label="label" option-value="value" class="w-full text-sm" />
            </div>
            <div class="flex flex-col gap-1.5">
                <Label>Note (optionnel)</Label>
                <textarea v-model="paiementForm.note" rows="2" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-ring" />
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <Button variant="outline" size="sm" @click="showPaiementDialog = false">Annuler</Button>
                <Button size="sm" :disabled="paiementForm.processing || !paiementForm.montant" @click="submitPaiement">
                    {{ paiementForm.processing ? 'Enregistrement…' : 'Confirmer' }}
                </Button>
            </div>
        </template>
    </Dialog>

    <!-- Dialog historique -->
    <Dialog v-model:visible="showHistoriqueDialog" modal :style="{ width: '480px' }" header="Historique des paiements">
        <div v-if="historique_paiements.length > 0" class="divide-y">
            <div v-for="p in historique_paiements" :key="p.id" class="py-3">
                <p class="text-sm font-medium">{{ fmt(p.montant) }}</p>
                <p class="text-xs text-muted-foreground">{{ p.paid_at }} · {{ formatMode(p.mode_paiement) }}</p>
                <p v-if="p.note" class="text-xs text-muted-foreground">{{ p.note }}</p>
                <p v-if="p.created_by" class="text-xs text-muted-foreground/60">Par {{ p.created_by }}</p>
            </div>
        </div>
        <p v-else class="py-8 text-center text-sm text-muted-foreground">Aucun paiement enregistré.</p>
    </Dialog>

    <!-- Dialog frais -->
    <Dialog v-model:visible="showFraisDialog" modal :style="{ width: '500px' }" header="Frais véhicules déduits">
        <div v-if="frais_depenses.length > 0" class="divide-y">
            <div v-for="f in frais_depenses" :key="f.id" class="flex items-start justify-between gap-3 py-3">
                <div>
                    <p class="text-sm font-medium">{{ f.type }}</p>
                    <p class="text-xs text-muted-foreground">{{ f.date }}<span v-if="f.commentaire"> · {{ f.commentaire }}</span></p>
                </div>
                <p class="text-sm font-semibold tabular-nums text-red-600 dark:text-red-400">-{{ fmt(f.montant) }}</p>
            </div>
            <div class="flex items-center justify-between pt-3">
                <p class="text-sm font-semibold">Total frais</p>
                <p class="text-sm font-bold tabular-nums text-red-600 dark:text-red-400">-{{ fmt(resume_global.total_frais_depenses) }}</p>
            </div>
        </div>
        <p v-else class="py-8 text-center text-sm text-muted-foreground">Aucun frais validé.</p>
    </Dialog>
</template>
