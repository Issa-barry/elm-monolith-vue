<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import StatusDot from '@/components/StatusDot.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ChevronRight, MoreVertical, PackageCheck, Plus, Search, Trash2, XCircle } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Dialog from 'primevue/dialog';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import Textarea from 'primevue/textarea';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, ref, watch } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────
interface Commande {
    id: number;
    reference: string;
    statut: string;
    statut_label: string;
    total_commande: number;
    prestataire_nom: string | null;
    note: string | null;
    created_at: string;
    is_annulee: boolean;
    is_receptionnee: boolean;
    qte_commandee: number;
    qte_recue: number;
}

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps<{ commandes: Commande[] }>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();

const search = ref('');
const filters = ref({ global: { value: '', matchMode: 'contains' } });
watch(search, (val) => { filters.value.global.value = val; });

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Achats', href: '/achats' },
];

// ── Statut couleurs ───────────────────────────────────────────────────────────
const statutColor: Record<string, string> = {
    en_cours:     'bg-blue-500',
    receptionnee: 'bg-emerald-500',
    annulee:      'bg-zinc-400 dark:bg-zinc-500',
};

// ── Formatage ─────────────────────────────────────────────────────────────────
function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

// ── Filtre mobile ─────────────────────────────────────────────────────────────
const mobileSearch = ref('');

const mobileFiltered = computed(() => {
    const q = mobileSearch.value.toLowerCase().trim();
    if (!q) return props.commandes;
    return props.commandes.filter(c =>
        c.reference.toLowerCase().includes(q) ||
        (c.prestataire_nom && c.prestataire_nom.toLowerCase().includes(q))
    );
});

// ── Annulation ────────────────────────────────────────────────────────────────
const annulerDialogVisible = ref(false);
const selectedCommande = ref<Commande | null>(null);

const annulerForm = useForm({
    motif_annulation: '',
});

function openAnnulerDialog(commande: Commande) {
    selectedCommande.value = commande;
    annulerForm.reset();
    annulerDialogVisible.value = true;
}

function submitAnnuler() {
    if (!selectedCommande.value) return;
    annulerForm.patch(`/achats/${selectedCommande.value.id}/annuler`, {
        onSuccess: () => {
            annulerDialogVisible.value = false;
            toast.add({ severity: 'success', summary: 'Annulée', detail: 'Commande annulée avec succès.', life: 3000 });
        },
    });
}

// ── Suppression ───────────────────────────────────────────────────────────────
function confirmDelete(c: Commande) {
    confirm.require({
        message: `Supprimer la commande « ${c.reference} » ? Cette action est irréversible.`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/achats/${c.id}`, {
                onSuccess: () => toast.add({
                    severity: 'success',
                    summary: 'Supprimée',
                    detail: 'Commande supprimée.',
                    life: 3000,
                }),
            });
        },
    });
}
</script>

<template>
    <Head title="Achats" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">

        <!-- ── MOBILE VIEW ─────────────────────────────────────────────────── -->
        <div class="flex flex-col sm:hidden">

            <!-- Sticky header -->
            <div class="sticky top-0 z-10 flex items-center justify-between border-b bg-background px-4 py-3">
                <Link href="/dashboard" class="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:text-foreground">
                    <ArrowLeft class="h-5 w-5" />
                </Link>
                <span class="text-base font-semibold">Achats</span>
                <Link v-if="can('achats.create')" href="/achats/create">
                    <Button size="sm" class="h-8 px-3 text-xs">
                        <Plus class="mr-1 h-3.5 w-3.5" />
                        Nouveau
                    </Button>
                </Link>
                <div v-else class="w-8" />
            </div>

            <!-- Search -->
            <div class="border-b px-4 py-2">
                <div class="relative">
                    <Search class="absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground pointer-events-none" />
                    <input
                        v-model="mobileSearch"
                        type="text"
                        placeholder="Référence, fournisseur…"
                        class="h-9 w-full rounded-md border border-input bg-background pl-8 pr-3 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
                    />
                </div>
            </div>

            <!-- Card list -->
            <div class="divide-y">
                <Link
                    v-for="c in mobileFiltered"
                    :key="c.id"
                    :href="`/achats/${c.id}`"
                    class="flex items-start justify-between gap-3 px-4 py-3 hover:bg-muted/10 active:bg-muted/20"
                >
                    <div class="min-w-0 flex-1">
                        <p class="font-mono text-sm font-semibold tracking-wide text-primary">{{ c.reference }}</p>
                        <p class="mt-0.5 text-xs text-muted-foreground">{{ c.prestataire_nom ?? '—' }}</p>
                        <p class="mt-1 text-sm font-medium tabular-nums">{{ formatGNF(c.total_commande) }}</p>
                        <div class="mt-1 flex items-center gap-2 text-xs text-muted-foreground">
                            <span>Cmdé : {{ c.qte_commandee }}</span>
                            <span>·</span>
                            <span>Reçu : {{ c.is_receptionnee ? c.qte_recue : 0 }}</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <div class="flex flex-col items-end gap-1.5">
                            <StatusDot
                                :label="c.statut_label"
                                :dot-class="statutColor[c.statut] ?? 'bg-zinc-400 dark:bg-zinc-500'"
                                class="text-xs text-muted-foreground"
                            />
                            <span class="text-xs tabular-nums text-muted-foreground">{{ c.created_at }}</span>
                        </div>
                        <ChevronRight class="h-4 w-4 text-muted-foreground/50 shrink-0" />
                    </div>
                </Link>
            </div>

            <!-- Empty state -->
            <div v-if="mobileFiltered.length === 0" class="flex flex-col items-center gap-3 py-16 text-muted-foreground">
                <PackageCheck class="h-10 w-10 opacity-30" />
                <p class="text-sm">Aucune commande trouvée.</p>
                <Link v-if="can('achats.create')" href="/achats/create">
                    <Button variant="outline" size="sm">
                        <Plus class="mr-2 h-4 w-4" />
                        Créer le premier bon de commande
                    </Button>
                </Link>
            </div>
        </div>

        <!-- ── DESKTOP VIEW ────────────────────────────────────────────────── -->
        <div class="hidden sm:flex flex-col gap-6 p-6">

            <!-- En-tête -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Achats</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ commandes.length }} bon{{ commandes.length !== 1 ? 's' : '' }} de commande
                    </p>
                </div>
                <Link v-if="can('achats.create')" href="/achats/create">
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Nouveau bon de commande
                    </Button>
                </Link>
            </div>

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="commandes"
                    :paginator="commandes.length > 20"
                    :rows="20"
                    :global-filter-fields="['reference', 'prestataire_nom', 'statut_label', 'note']"
                    v-model:filters="filters"
                    data-key="id"
                    striped-rows
                    removable-sort
                    class="text-sm"
                    table-class="w-full"
                    :pt="{
                        root: { class: 'w-full' },
                        header: { class: 'border-b bg-muted/30 px-4 py-3' },
                        tbody: { class: 'divide-y' },
                    }"
                >
                    <template #header>
                        <div class="flex items-center gap-3">
                            <IconField class="max-w-sm flex-1">
                                <InputIcon class="pointer-events-none">
                                    <Search class="h-4 w-4 text-muted-foreground" />
                                </InputIcon>
                                <InputText v-model="search" placeholder="Rechercher..." class="w-full text-sm" />
                            </IconField>
                            <span class="text-xs text-muted-foreground">{{ commandes.length }} résultat{{ commandes.length !== 1 ? 's' : '' }}</span>
                        </div>
                    </template>

                    <!-- Référence -->
                    <Column field="reference" header="Référence" sortable style="min-width: 180px">
                        <template #body="{ data }">
                            <Link :href="`/achats/${data.id}`" class="font-mono text-sm font-semibold tracking-wide hover:underline">
                                {{ data.reference }}
                            </Link>
                        </template>
                    </Column>

                    <!-- Date -->
                    <Column field="created_at" header="Date" sortable style="width: 120px">
                        <template #body="{ data }">
                            <span class="tabular-nums text-muted-foreground">{{ data.created_at }}</span>
                        </template>
                    </Column>

                    <!-- Fournisseur -->
                    <Column field="prestataire_nom" header="Fournisseur" style="min-width: 150px">
                        <template #body="{ data }">
                            <span class="text-muted-foreground">{{ data.prestataire_nom ?? '—' }}</span>
                        </template>
                    </Column>

                    <!-- Note -->
                    <Column field="note" header="Note" style="min-width: 150px">
                        <template #body="{ data }">
                            <span class="text-muted-foreground">{{ data.note ?? '—' }}</span>
                        </template>
                    </Column>

                    <!-- Qté commandée -->
                    <Column field="qte_commandee" header="Qté cmdée" sortable style="width: 100px">
                        <template #body="{ data }">
                            <span class="tabular-nums text-muted-foreground">{{ data.qte_commandee }}</span>
                        </template>
                    </Column>

                    <!-- Qté reçue -->
                    <Column field="qte_recue" header="Qté reçue" sortable style="width: 100px">
                        <template #body="{ data }">
                            <span class="tabular-nums text-muted-foreground">{{ data.is_receptionnee ? data.qte_recue : 0 }}</span>
                        </template>
                    </Column>

                    <!-- Total -->
                    <Column field="total_commande" header="Total" sortable style="width: 160px">
                        <template #body="{ data }">
                            <span class="font-medium tabular-nums">{{ formatGNF(data.total_commande) }}</span>
                        </template>
                    </Column>

                    <!-- Statut -->
                    <Column field="statut" header="Statut" sortable style="width: 130px">
                        <template #body="{ data }">
                            <StatusDot
                                :label="data.statut_label"
                                :dot-class="statutColor[data.statut] ?? 'bg-zinc-400 dark:bg-zinc-500'"
                                class="text-muted-foreground"
                            />
                        </template>
                    </Column>

                    <!-- Actions -->
                    <Column header="" style="width: 56px">
                        <template #body="{ data }">
                            <div class="flex justify-end">
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button variant="ghost" size="icon" class="h-8 w-8">
                                            <MoreVertical class="h-4 w-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" class="w-44">
                                        <DropdownMenuItem as-child>
                                            <Link :href="`/achats/${data.id}`" class="flex items-center gap-2 w-full cursor-pointer">
                                                <PackageCheck class="h-4 w-4" />
                                                Voir
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="!data.is_annulee && !data.is_receptionnee && can('achats.update')"
                                            class="cursor-pointer text-amber-600 focus:text-amber-600"
                                            @click="openAnnulerDialog(data)"
                                        >
                                            <XCircle class="h-4 w-4" />
                                            Annuler
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator v-if="data.is_annulee && can('achats.delete')" />
                                        <DropdownMenuItem
                                            v-if="data.is_annulee && can('achats.delete')"
                                            class="cursor-pointer text-destructive focus:text-destructive"
                                            @click="confirmDelete(data)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                            Supprimer
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </template>
                    </Column>

                    <!-- État vide -->
                    <template #empty>
                        <div class="flex flex-col items-center gap-3 py-16 text-muted-foreground">
                            <PackageCheck class="h-12 w-12 opacity-30" />
                            <p class="text-sm">Aucun bon de commande trouvé.</p>
                            <Link v-if="can('achats.create')" href="/achats/create">
                                <Button variant="outline" size="sm">
                                    <Plus class="mr-2 h-4 w-4" />
                                    Créer le premier bon de commande
                                </Button>
                            </Link>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>

        <!-- Dialog Annulation -->
        <Dialog
            v-model:visible="annulerDialogVisible"
            modal
            header="Annuler la commande"
            :style="{ width: '480px' }"
        >
            <div class="space-y-4">
                <p class="text-sm text-muted-foreground">
                    Vous êtes sur le point d'annuler la commande
                    <span class="font-mono font-semibold">{{ selectedCommande?.reference }}</span>.
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
