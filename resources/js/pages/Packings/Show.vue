<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ChevronDown,
    Pencil,
    Plus,
    Trash2,
    XCircle,
} from 'lucide-vue-next';
import Calendar from 'primevue/calendar';
import InputNumber from 'primevue/inputnumber';
import Textarea from 'primevue/textarea';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────
interface PackingData {
    id: number;
    reference: string;
    prestataire_id: number;
    prestataire_nom: string | null;
    date: string;
    nb_rouleaux: number;
    prix_par_rouleau: number;
    montant: number;
    montant_verse: number;
    montant_restant: number;
    statut: string;
    statut_label: string;
    notes: string | null;
    can_edit: boolean;
    can_cancel: boolean;
}

interface Versement {
    id: number;
    date: string;
    montant: number;
    notes: string | null;
    created_by: string | null;
    created_at: string;
}

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps<{
    packing: PackingData;
    versements: Versement[];
}>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();

// ── Breadcrumbs ───────────────────────────────────────────────────────────────
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Packings', href: '/packings' },
    { title: props.packing.reference, href: '#' },
];

// ── Badges statut ─────────────────────────────────────────────────────────────
const statutColor: Record<string, string> = {
    impayee:   'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    partielle: 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
    payee:     'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    annulee:   'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
};

// ── Formatage ─────────────────────────────────────────────────────────────────
function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR', { style: 'decimal', maximumFractionDigits: 0 }).format(val) + ' GNF';
}

function formatDate(val: string): string {
    if (!val) return '—';
    return new Date(val).toLocaleDateString('fr-FR');
}

function formatDateTime(val: string): string {
    if (!val) return '—';
    return new Date(val).toLocaleString('fr-FR');
}

// ── Progress ──────────────────────────────────────────────────────────────────
const progressPercent = computed(() => {
    if (!props.packing.montant || props.packing.montant <= 0) return 0;
    return Math.min(100, Math.round((props.packing.montant_verse / props.packing.montant) * 100));
});

// ── Conversions de date pour Calendar ─────────────────────────────────────────
function toDate(val: string): Date | null {
    if (!val) return null;
    const d = new Date(val);
    return isNaN(d.getTime()) ? null : d;
}

function fromDate(val: Date | null): string {
    if (!val) return '';
    const y = val.getFullYear();
    const m = String(val.getMonth() + 1).padStart(2, '0');
    const d = String(val.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

// ── Formulaire versement ───────────────────────────────────────────────────────
const versementForm = useForm({
    date:    new Date().toISOString().slice(0, 10),
    montant: null as number | null,
    notes:   null as string | null,
});

function addVersement() {
    versementForm.post(`/packings/${props.packing.id}/versements`, {
        onSuccess: () => versementForm.reset(),
    });
}

// ── Suppression versement ─────────────────────────────────────────────────────
function confirmDeleteVersement(v: Versement) {
    confirm.require({
        message: `Supprimer ce versement de ${formatGNF(v.montant)} ?`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/packings/${props.packing.id}/versements/${v.id}`, {
                onSuccess: () => toast.add({ severity: 'success', summary: 'Supprimé', detail: 'Versement supprimé.', life: 3000 }),
            });
        },
    });
}

// ── Annulation packing ────────────────────────────────────────────────────────
function confirmAnnuler() {
    confirm.require({
        message: `Annuler le packing « ${props.packing.reference} » ?`,
        header: 'Confirmer l\'annulation',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Retour',
        acceptLabel: 'Annuler le packing',
        acceptClass: 'p-button-warning',
        accept: () => {
            router.patch(`/packings/${props.packing.id}/annuler`, {}, {
                onSuccess: () => toast.add({ severity: 'success', summary: 'Annulé', detail: 'Packing annulé.', life: 3000 }),
            });
        },
    });
}

const canAddVersement = computed(() =>
    props.packing.statut !== 'annulee' && props.packing.montant_restant > 0 && can('packings.update')
);
</script>

<template>
    <Head :title="packing.reference" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-4xl space-y-6 p-6">

            <!-- En-tête ──────────────────────────────────────────────────────── -->
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="font-mono text-2xl font-bold tracking-wide">{{ packing.reference }}</h1>
                    <p class="mt-1 text-sm text-muted-foreground">{{ packing.prestataire_nom ?? '—' }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <Link v-if="packing.can_edit && can('packings.update')" :href="`/packings/${packing.id}/edit`">
                        <Button variant="outline" size="sm">
                            <Pencil class="mr-2 h-4 w-4" />
                            Modifier
                        </Button>
                    </Link>
                    <DropdownMenu v-if="packing.can_cancel && can('packings.update')">
                        <DropdownMenuTrigger as-child>
                            <Button variant="outline" size="sm">
                                Actions
                                <ChevronDown class="ml-2 h-4 w-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-44">
                            <DropdownMenuItem
                                class="cursor-pointer text-amber-600 focus:text-amber-600"
                                @click="confirmAnnuler"
                            >
                                <XCircle class="mr-2 h-4 w-4" />
                                Annuler
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            <!-- Récapitulatif ────────────────────────────────────────────────── -->
            <div class="rounded-xl border bg-card p-6 shadow-sm">
                <h3 class="mb-5 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                    Récapitulatif
                </h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <p class="text-xs text-muted-foreground">Date</p>
                        <p class="mt-0.5 font-medium">{{ formatDate(packing.date) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Statut</p>
                        <div class="mt-0.5">
                            <span
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="statutColor[packing.statut] ?? 'bg-muted text-muted-foreground'"
                            >
                                {{ packing.statut_label }}
                            </span>
                        </div>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Nombre de rouleaux</p>
                        <p class="mt-0.5 font-medium tabular-nums">{{ packing.nb_rouleaux.toLocaleString('fr-FR') }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Prix par rouleau</p>
                        <p class="mt-0.5 font-medium tabular-nums">{{ formatGNF(packing.prix_par_rouleau) }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <p class="text-xs text-muted-foreground">Montant total</p>
                        <p class="mt-0.5 text-lg font-bold tabular-nums">{{ formatGNF(packing.montant) }}</p>
                    </div>
                </div>
            </div>

            <!-- Notes ────────────────────────────────────────────────────────── -->
            <div v-if="packing.notes" class="rounded-xl border bg-card p-6 shadow-sm">
                <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                    Notes
                </h3>
                <p class="whitespace-pre-wrap text-sm">{{ packing.notes }}</p>
            </div>

            <!-- Paiements ────────────────────────────────────────────────────── -->
            <div class="rounded-xl border bg-card p-6 shadow-sm">
                <h3 class="mb-5 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                    Paiements
                </h3>

                <!-- Barre de progression -->
                <div class="mb-6 space-y-2">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-muted-foreground">
                            Versé : <span class="font-semibold text-foreground tabular-nums">{{ formatGNF(packing.montant_verse) }}</span>
                            / {{ formatGNF(packing.montant) }}
                        </span>
                        <span class="font-semibold tabular-nums" :class="packing.montant_restant > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'">
                            {{ progressPercent }}%
                        </span>
                    </div>
                    <div class="h-2 rounded-full bg-muted">
                        <div
                            class="h-2 rounded-full bg-emerald-500 transition-all"
                            :style="{ width: progressPercent + '%' }"
                        />
                    </div>
                    <div class="text-xs text-muted-foreground">
                        Restant dû :
                        <span class="font-semibold" :class="packing.montant_restant > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'">
                            {{ formatGNF(packing.montant_restant) }}
                        </span>
                    </div>
                </div>

                <!-- Tableau des versements -->
                <div v-if="versements.length > 0" class="mb-6 overflow-hidden rounded-lg border">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40">
                                <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Date</th>
                                <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Montant</th>
                                <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Notes</th>
                                <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Enregistré par</th>
                                <th class="px-4 py-2.5 text-right font-medium text-muted-foreground" v-if="can('packings.update')"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="v in versements" :key="v.id" class="hover:bg-muted/20">
                                <td class="px-4 py-3 tabular-nums">{{ formatDate(v.date) }}</td>
                                <td class="px-4 py-3 font-medium tabular-nums text-emerald-600 dark:text-emerald-400">
                                    {{ formatGNF(v.montant) }}
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">{{ v.notes ?? '—' }}</td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    <div>{{ v.created_by ?? '—' }}</div>
                                    <div class="text-xs text-muted-foreground/60">{{ formatDateTime(v.created_at) }}</div>
                                </td>
                                <td v-if="can('packings.update')" class="px-4 py-3 text-right">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="h-7 w-7 text-destructive hover:text-destructive"
                                        type="button"
                                        @click="confirmDeleteVersement(v)"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-else-if="!canAddVersement" class="mb-6 rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                    Aucun versement enregistré.
                </div>

                <!-- Formulaire d'ajout de versement -->
                <div v-if="canAddVersement" class="rounded-lg border bg-muted/20 p-4">
                    <p class="mb-4 text-sm font-medium">Ajouter un versement</p>
                    <form class="grid gap-4 sm:grid-cols-3" @submit.prevent="addVersement">
                        <!-- Date -->
                        <div>
                            <Label class="mb-1.5 block text-xs">Date <span class="text-destructive">*</span></Label>
                            <Calendar
                                :model-value="toDate(versementForm.date)"
                                @update:model-value="versementForm.date = fromDate($event as Date | null)"
                                date-format="dd/mm/yy"
                                :show-icon="true"
                                class="w-full"
                                input-class="w-full"
                                :class="{ 'p-invalid': versementForm.errors.date }"
                            />
                            <p v-if="versementForm.errors.date" class="mt-1 text-xs text-destructive">{{ versementForm.errors.date }}</p>
                        </div>

                        <!-- Montant -->
                        <div>
                            <Label class="mb-1.5 block text-xs">Montant <span class="text-destructive">*</span></Label>
                            <InputNumber
                                :model-value="versementForm.montant"
                                @update:model-value="versementForm.montant = $event"
                                :min="1"
                                :max="packing.montant_restant"
                                :use-grouping="true"
                                locale="fr-FR"
                                :min-fraction-digits="0"
                                :max-fraction-digits="0"
                                suffix=" GNF"
                                class="w-full"
                                input-class="w-full"
                                :class="{ 'p-invalid': versementForm.errors.montant }"
                            />
                            <p v-if="versementForm.errors.montant" class="mt-1 text-xs text-destructive">{{ versementForm.errors.montant }}</p>
                        </div>

                        <!-- Notes -->
                        <div>
                            <Label class="mb-1.5 block text-xs">Notes</Label>
                            <Textarea
                                :model-value="versementForm.notes ?? ''"
                                @update:model-value="versementForm.notes = ($event as string) || null"
                                rows="1"
                                placeholder="Optionnel..."
                                class="w-full resize-none"
                            />
                        </div>

                        <!-- Bouton submit (pleine largeur) -->
                        <div class="sm:col-span-3 flex justify-end">
                            <Button type="submit" size="sm" :disabled="versementForm.processing">
                                <Plus class="mr-2 h-4 w-4" />
                                {{ versementForm.processing ? 'Enregistrement…' : 'Ajouter le versement' }}
                            </Button>
                        </div>
                    </form>
                </div>

            </div>

        </div>
    </AppLayout>
</template>
