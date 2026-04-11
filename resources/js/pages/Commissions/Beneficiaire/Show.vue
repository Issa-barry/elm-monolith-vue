<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ChevronRight,
    HandCoins,
    History,
    Truck,
    User,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { computed, reactive, ref } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────

interface VersementItem {
    id: number;
    part_id: number;
    commission_id: number;
    date_versement: string | null;
    enregistre_le: string | null;
    montant: number;
    mode_paiement: string;
    note: string | null;
    created_by: string | null;
}

interface CommandeRow {
    commission_id: number;
    commande_reference: string | null;
    commande_id: number | null;
    date: string | null;
    vehicule: string | null;
    immatriculation: string | null;
    site: string | null;
    taux: number;
    montant_brut: number;
    frais: number;
    montant_net: number;
    montant_verse: number;
    restant: number;
    statut: string;
    statut_label: string;
    part_id: number;
    versements: VersementItem[];
    disponible_le: string | null;
    montant_disponible: number;
    montant_en_attente: number;
}

interface Resume {
    id: number;
    type: 'livreur' | 'proprietaire';
    nom: string;
    telephone: string | null;
    nb_commandes: number;
    total_brut: number;
    total_frais: number;
    total_net: number;
    total_verse: number;
    solde_restant: number;
    total_disponible: number;
    total_en_attente: number;
}

interface ModePaiementOption {
    value: string;
    label: string;
}

// ── Props ─────────────────────────────────────────────────────────────────────

const props = defineProps<{
    resume: Resume;
    commandes: CommandeRow[];
    modes_paiement: ModePaiementOption[];
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Commissions', href: '/commissions' },
    { title: props.resume.nom, href: '' },
];

// ── Formatage ─────────────────────────────────────────────────────────────────

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

const statutDotColor: Record<string, string> = {
    en_attente: 'bg-amber-500',
    partielle: 'bg-blue-500',
    versee: 'bg-emerald-500',
    annulee: 'bg-zinc-400 dark:bg-zinc-500',
};

const typeIcon = computed(() =>
    props.resume.type === 'livreur' ? Truck : User,
);

const typeLabel = computed(() =>
    props.resume.type === 'livreur' ? 'Livreur' : 'Propriétaire',
);

// ── Dialog versement ──────────────────────────────────────────────────────────

const dialogVisible = ref(false);
const dialogCommande = ref<CommandeRow | null>(null);

interface VersementForm {
    montant: number | null;
    mode_paiement: string;
    note: string | null;
    processing: boolean;
}

const versementForm = reactive<VersementForm>({
    montant: null,
    mode_paiement: 'especes',
    note: null,
    processing: false,
});

function openVersementDialog(commande: CommandeRow) {
    dialogCommande.value = commande;
    versementForm.montant = commande.restant > 0 ? commande.restant : null;
    versementForm.mode_paiement = 'especes';
    versementForm.note = null;
    versementForm.processing = false;
    dialogVisible.value = true;
}

function closeDialog() {
    dialogVisible.value = false;
    dialogCommande.value = null;
}

function submitVersement() {
    const commande = dialogCommande.value;
    if (!commande || !versementForm.montant || versementForm.montant <= 0) return;
    versementForm.processing = true;
    const today = new Date().toISOString().slice(0, 10);
    router.post(
        `/commissions/${commande.commission_id}/parts/${commande.part_id}/versements`,
        {
            montant: versementForm.montant,
            mode_paiement: versementForm.mode_paiement,
            date_versement: today,
            note: versementForm.note,
        },
        {
            preserveScroll: true,
            onSuccess: () => closeDialog(),
            onFinish: () => { versementForm.processing = false; },
        },
    );
}

// ── Dialog historique ─────────────────────────────────────────────────────────

const historyVisible = ref(false);
const historyCommande = ref<CommandeRow | null>(null);

function openHistory(commande: CommandeRow) {
    historyCommande.value = commande;
    historyVisible.value = true;
}
</script>

<template>
    <Head :title="`Commission — ${resume.nom}`" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- ══════════════════════ MOBILE ══════════════════════════════════════ -->
        <div class="flex flex-col sm:hidden">
            <!-- Header -->
            <div class="sticky top-0 z-10 border-b bg-background">
                <div class="flex items-center justify-between px-4 py-3">
                    <Link
                        href="/commissions"
                        class="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground"
                    >
                        <ArrowLeft class="h-5 w-5" />
                    </Link>
                    <span class="text-base font-semibold">{{ resume.nom }}</span>
                    <div class="w-8" />
                </div>
            </div>

            <!-- Résumé mobile -->
            <div class="grid grid-cols-2 gap-3 p-4">
                <div class="rounded-xl border bg-card p-3 shadow-sm">
                    <p class="text-xs text-muted-foreground">Total net</p>
                    <p class="mt-1 text-base font-bold tabular-nums">{{ formatGNF(resume.total_net) }}</p>
                </div>
                <div class="rounded-xl border bg-card p-3 shadow-sm">
                    <p class="text-xs text-muted-foreground">Total versé</p>
                    <p class="mt-1 text-base font-bold text-emerald-600 tabular-nums dark:text-emerald-400">
                        {{ formatGNF(resume.total_verse) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-3 shadow-sm">
                    <p class="text-xs text-muted-foreground">Disponible</p>
                    <p class="mt-1 text-base font-bold text-emerald-700 tabular-nums dark:text-emerald-400">
                        {{ formatGNF(resume.total_disponible) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-3 shadow-sm">
                    <p class="text-xs text-muted-foreground">En attente</p>
                    <p class="mt-1 text-base font-bold text-amber-600 tabular-nums dark:text-amber-400">
                        {{ formatGNF(resume.total_en_attente) }}
                    </p>
                </div>
            </div>

            <!-- Liste commandes mobile -->
            <div class="divide-y">
                <div
                    v-for="c in commandes"
                    :key="c.commission_id"
                    class="px-4 py-3.5"
                >
                    <div class="flex items-start justify-between">
                        <div class="min-w-0 flex-1">
                            <p class="font-mono text-xs font-semibold text-primary">
                                {{ c.commande_reference ?? '—' }}
                            </p>
                            <p class="text-xs text-muted-foreground">{{ c.date }} · {{ c.site ?? '—' }}</p>
                            <p class="mt-1 font-semibold tabular-nums">{{ formatGNF(c.montant_net) }}</p>
                            <p v-if="c.restant > 0" class="text-xs text-amber-600 tabular-nums dark:text-amber-400">
                                Restant : {{ formatGNF(c.restant) }}
                            </p>
                        </div>
                        <StatusDot
                            :label="c.statut_label"
                            :dot-class="statutDotColor[c.statut] ?? 'bg-zinc-400'"
                            class="shrink-0 text-xs text-muted-foreground"
                        />
                    </div>
                    <div class="mt-2 flex gap-2">
                        <Button
                            v-if="can('ventes.update') && c.restant > 0"
                            size="sm"
                            variant="outline"
                            class="h-7 gap-1 text-xs"
                            @click="openVersementDialog(c)"
                        >
                            <HandCoins class="h-3.5 w-3.5" />
                            Verser
                        </Button>
                        <Button
                            v-if="c.versements.length > 0"
                            size="sm"
                            variant="ghost"
                            class="h-7 gap-1 text-xs"
                            @click="openHistory(c)"
                        >
                            <History class="h-3.5 w-3.5" />
                            {{ c.versements.length }}
                        </Button>
                        <Link :href="`/commissions/${c.commission_id}`">
                            <Button size="sm" variant="ghost" class="h-7 gap-1 text-xs">
                                Détails
                                <ChevronRight class="h-3 w-3" />
                            </Button>
                        </Link>
                    </div>
                </div>
            </div>

            <div v-if="commandes.length === 0" class="py-16 text-center text-sm text-muted-foreground">
                Aucune commande.
            </div>
        </div>

        <!-- ══════════════════════ DESKTOP ═════════════════════════════════════ -->
        <div class="hidden w-full space-y-6 p-6 sm:block">
            <!-- Navigation retour -->
            <div class="flex items-center gap-3">
                <Link href="/commissions" class="text-sm text-muted-foreground hover:text-foreground">
                    ← Commissions
                </Link>
            </div>

            <!-- En-tête bénéficiaire -->
            <div class="flex items-start gap-4 rounded-xl border bg-card p-6 shadow-sm">
                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10">
                    <component :is="typeIcon" class="h-6 w-6 text-primary" />
                </div>
                <div class="flex-1">
                    <div class="flex items-center gap-2">
                        <h1 class="text-xl font-semibold">{{ resume.nom }}</h1>
                        <span class="rounded-full border px-2 py-0.5 text-xs text-muted-foreground">
                            {{ typeLabel }}
                        </span>
                    </div>
                    <p v-if="resume.telephone" class="mt-0.5 text-sm text-muted-foreground">
                        {{ formatPhoneDisplay(resume.telephone) }}
                    </p>
                </div>
            </div>

            <!-- KPI résumé -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Commandes</p>
                    <p class="mt-2 text-2xl font-bold tabular-nums">{{ resume.nb_commandes }}</p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Total net cumulé</p>
                    <p class="mt-2 text-2xl font-bold tabular-nums">{{ formatGNF(resume.total_net) }}</p>
                    <p v-if="resume.total_frais > 0" class="mt-0.5 text-xs text-destructive tabular-nums">
                        − {{ formatGNF(resume.total_frais) }} frais
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Disponible maintenant</p>
                    <p
                        class="mt-2 text-2xl font-bold tabular-nums"
                        :class="resume.total_disponible > 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-muted-foreground'"
                    >
                        {{ formatGNF(resume.total_disponible) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">En attente</p>
                    <p
                        class="mt-2 text-2xl font-bold tabular-nums"
                        :class="resume.total_en_attente > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'"
                    >
                        {{ formatGNF(resume.total_en_attente) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ resume.type === 'livreur' ? '(+14 j après commande)' : '(1er du mois suivant)' }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Total versé</p>
                    <p class="mt-2 text-2xl font-bold text-emerald-600 tabular-nums dark:text-emerald-400">
                        {{ formatGNF(resume.total_verse) }}
                    </p>
                </div>
            </div>

            <!-- Tableau des commandes -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <div class="border-b bg-muted/30 px-4 py-3">
                    <p class="text-sm font-medium">Historique des commandes</p>
                </div>
                <table class="w-full text-sm">
                    <thead class="border-b bg-muted/20">
                        <tr>
                            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Commande</th>
                            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Véhicule</th>
                            <th class="px-4 py-2.5 text-right font-medium text-muted-foreground">Brut</th>
                            <th class="px-4 py-2.5 text-right font-medium text-muted-foreground">Net</th>
                            <th class="px-4 py-2.5 text-right font-medium text-muted-foreground">Versé</th>
                            <th class="px-4 py-2.5 text-right font-medium text-muted-foreground">Restant</th>
                            <th class="px-4 py-2.5 text-right font-medium text-muted-foreground">Disponible</th>
                            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Statut</th>
                            <th class="px-4 py-2.5 text-right font-medium text-muted-foreground">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="c in commandes"
                            :key="c.commission_id"
                            class="transition-colors hover:bg-muted/20"
                        >
                            <!-- Commande ref + date + site -->
                            <td class="px-4 py-3">
                                <Link
                                    v-if="c.commande_id"
                                    :href="`/ventes/${c.commande_id}`"
                                    class="font-mono text-xs font-semibold text-primary hover:underline"
                                >
                                    {{ c.commande_reference ?? '—' }}
                                </Link>
                                <span v-else class="font-mono text-xs">{{ c.commande_reference ?? '—' }}</span>
                                <p class="text-xs text-muted-foreground">
                                    {{ c.date }}
                                    <span v-if="c.site"> · {{ c.site }}</span>
                                </p>
                            </td>

                            <!-- Véhicule -->
                            <td class="px-4 py-3">
                                <p>{{ c.vehicule ?? '—' }}</p>
                                <p v-if="c.immatriculation" class="font-mono text-xs text-muted-foreground">
                                    {{ c.immatriculation }}
                                </p>
                            </td>

                            <!-- Montant brut -->
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(c.montant_brut) }}
                                <p v-if="c.frais > 0" class="text-xs text-destructive">
                                    − {{ formatGNF(c.frais) }}
                                </p>
                            </td>

                            <!-- Montant net -->
                            <td class="px-4 py-3 text-right font-semibold tabular-nums">
                                {{ formatGNF(c.montant_net) }}
                                <p class="text-xs font-normal text-muted-foreground">{{ c.taux }}%</p>
                            </td>

                            <!-- Versé -->
                            <td class="px-4 py-3 text-right tabular-nums text-emerald-700 dark:text-emerald-400">
                                {{ formatGNF(c.montant_verse) }}
                            </td>

                            <!-- Restant -->
                            <td class="px-4 py-3 text-right font-semibold tabular-nums"
                                :class="c.restant > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'">
                                {{ formatGNF(c.restant) }}
                            </td>

                            <!-- Disponible -->
                            <td class="px-4 py-3 text-right tabular-nums">
                                <span
                                    class="font-semibold"
                                    :class="c.montant_disponible > 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-muted-foreground'"
                                >
                                    {{ formatGNF(c.montant_disponible) }}
                                </span>
                                <p v-if="c.montant_en_attente > 0 && c.disponible_le" class="text-xs text-muted-foreground">
                                    dès {{ c.disponible_le }}
                                </p>
                            </td>

                            <!-- Statut -->
                            <td class="px-4 py-3">
                                <StatusDot
                                    :label="c.statut_label"
                                    :dot-class="statutDotColor[c.statut] ?? 'bg-zinc-400'"
                                    class="text-muted-foreground"
                                />
                            </td>

                            <!-- Actions -->
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    <!-- Historique versements -->
                                    <Button
                                        v-if="c.versements.length > 0"
                                        size="sm"
                                        variant="ghost"
                                        class="h-7 gap-1 text-xs"
                                        @click="openHistory(c)"
                                    >
                                        <History class="h-3.5 w-3.5" />
                                        {{ c.versements.length }}
                                    </Button>

                                    <!-- Verser -->
                                    <Button
                                        v-if="can('ventes.update') && c.restant > 0"
                                        size="sm"
                                        variant="outline"
                                        class="h-7 gap-1 text-xs"
                                        @click="openVersementDialog(c)"
                                    >
                                        <HandCoins class="h-3.5 w-3.5" />
                                        Verser
                                    </Button>

                                    <!-- Lien vers commission détail -->
                                    <Link :href="`/commissions/${c.commission_id}`">
                                        <Button size="sm" variant="ghost" class="h-7 px-2">
                                            <ChevronRight class="h-4 w-4" />
                                        </Button>
                                    </Link>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="commandes.length === 0" class="py-16 text-center text-sm text-muted-foreground">
                    Aucune commande pour ce bénéficiaire.
                </div>
            </div>
        </div>

        <!-- ══════════════════════ DIALOGS ═════════════════════════════════════ -->

        <!-- Dialog versement -->
        <Dialog
            v-model:visible="dialogVisible"
            :header="`Verser — ${dialogCommande?.commande_reference ?? '—'}`"
            modal
            :style="{ width: '420px' }"
            @hide="closeDialog"
        >
            <div v-if="dialogCommande" class="space-y-4 pt-2">
                <div class="rounded-lg bg-muted/40 p-3 text-sm">
                    <p class="text-muted-foreground">
                        Solde disponible :
                        <span class="font-semibold text-foreground">{{ formatGNF(dialogCommande.restant) }}</span>
                    </p>
                </div>

                <div class="space-y-1">
                    <label class="text-sm font-medium">Montant (GNF)</label>
                    <InputNumber
                        v-model="versementForm.montant"
                        :max="dialogCommande.restant"
                        :min="1"
                        class="w-full"
                        :use-grouping="true"
                        locale="fr-FR"
                    />
                </div>

                <div class="space-y-1">
                    <label class="text-sm font-medium">Mode de paiement</label>
                    <Dropdown
                        v-model="versementForm.mode_paiement"
                        :options="modes_paiement"
                        option-label="label"
                        option-value="value"
                        class="w-full"
                    />
                </div>

                <div class="space-y-1">
                    <label class="text-sm font-medium">Note (optionnel)</label>
                    <InputText v-model="versementForm.note" class="w-full" placeholder="Commentaire…" />
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <Button variant="ghost" @click="closeDialog">Annuler</Button>
                    <Button
                        :disabled="
                            versementForm.processing ||
                            !versementForm.montant ||
                            versementForm.montant <= 0
                        "
                        @click="submitVersement"
                    >
                        <HandCoins class="mr-1.5 h-4 w-4" />
                        Enregistrer
                    </Button>
                </div>
            </div>
        </Dialog>

        <!-- Dialog historique -->
        <Dialog
            v-model:visible="historyVisible"
            :header="`Versements — ${historyCommande?.commande_reference ?? '—'}`"
            modal
            :style="{ width: '480px' }"
        >
            <div v-if="historyCommande" class="divide-y pt-2">
                <div
                    v-for="v in historyCommande.versements"
                    :key="v.id"
                    class="flex items-center justify-between py-3"
                >
                    <div>
                        <p class="text-sm font-semibold tabular-nums">{{ formatGNF(v.montant) }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ v.mode_paiement }}
                            <span v-if="v.date_versement"> · {{ v.date_versement }}</span>
                        </p>
                        <p v-if="v.note" class="mt-0.5 text-xs text-muted-foreground italic">{{ v.note }}</p>
                    </div>
                    <p class="text-xs text-muted-foreground">{{ v.created_by }}</p>
                </div>
                <div v-if="historyCommande.versements.length === 0" class="py-6 text-center text-sm text-muted-foreground">
                    Aucun versement.
                </div>
            </div>
        </Dialog>
    </AppLayout>
</template>
