<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ChevronLeft,
    ChevronRight,
    MoreVertical,
    PackageCheck,
    Plus,
    Trash2,
    XCircle,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Textarea from 'primevue/textarea';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

interface Commande {
    id: string;
    reference: string;
    statut: string;
    statut_label: string;
    total_commande: number;
    fournisseur_nom: string | null;
    site_nom: string | null;
    created_at: string;
    qte_commandee: number;
    qte_recue: number;
    is_annulee: boolean;
    annulable: boolean;
}

interface Paginator<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    last_page: number;
}

interface Option {
    value: string;
    label: string;
}

const props = defineProps<{
    commandes: Paginator<Commande>;
    filters: Record<string, unknown>;
    statuts: Option[];
    fournisseurs: Option[];
    sites: { id: string; nom: string }[];
}>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Achats', href: '/backoffice/achats' },
];

const filterFields = computed<FilterField[]>(() => {
    const fields: FilterField[] = [
        {
            key: 'statut',
            label: 'Statut',
            type: 'select',
            inline: true,
            options: [
                { value: '', label: 'Tous les statuts' },
                ...props.statuts,
            ],
        },
        {
            key: 'fournisseur_id',
            label: 'Fournisseur',
            type: 'select',
            inline: true,
            searchable: true,
            options: [
                { value: '', label: 'Tous les fournisseurs' },
                ...props.fournisseurs,
            ],
        },
        {
            key: 'reference',
            label: 'Référence',
            type: 'text',
            inline: true,
            placeholder: 'BC-…',
        },
    ];
    if (can('achats.valider')) {
        fields.push({
            key: 'a_valider_par_moi',
            label: 'À valider par moi',
            type: 'boolean',
        });
    }
    return fields;
});

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

function paginationLabel(label: string): string {
    return label.replace(/&laquo;|&raquo;/g, '').trim();
}

function ouvrir(c: Commande) {
    router.visit(`/backoffice/achats/${c.id}`);
}

// ── Annulation ────────────────────────────────────────────────────────────────
const annulerDialogVisible = ref(false);
const selectedCommande = ref<Commande | null>(null);
const annulerForm = useForm({ motif_annulation: '' });

function openAnnulerDialog(commande: Commande) {
    selectedCommande.value = commande;
    annulerForm.reset();
    annulerForm.clearErrors();
    annulerDialogVisible.value = true;
}

function submitAnnuler() {
    if (!selectedCommande.value) return;
    annulerForm.patch(
        `/backoffice/achats/${selectedCommande.value.id}/annuler`,
        {
            preserveScroll: true,
            onSuccess: () => {
                annulerDialogVisible.value = false;
                toast.add({
                    severity: 'success',
                    summary: 'Commande annulée',
                    life: 3000,
                });
            },
        },
    );
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
            router.delete(`/backoffice/achats/${c.id}`, {
                onSuccess: () =>
                    toast.add({
                        severity: 'success',
                        summary: 'Commande supprimée',
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
        <!-- En-tête mobile -->
        <div
            class="sticky top-0 z-10 flex items-center justify-between border-b bg-background px-4 py-3 sm:hidden"
        >
            <Link
                href="/backoffice/dashboard"
                class="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:text-foreground"
            >
                <ArrowLeft class="h-5 w-5" />
            </Link>
            <span class="text-base font-semibold">Achats</span>
            <Link v-if="can('achats.create')" href="/backoffice/achats/create">
                <Button size="sm" class="h-8 px-3 text-xs">
                    <Plus class="mr-1 h-3.5 w-3.5" />
                    Nouveau
                </Button>
            </Link>
            <div v-else class="w-8" />
        </div>

        <div class="flex flex-col gap-4 p-4 sm:gap-6 sm:p-6">
            <div class="hidden items-center justify-between sm:flex">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Bons de commande fournisseurs
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ commandes.total }} bon{{
                            commandes.total !== 1 ? 's' : ''
                        }}
                        de commande
                    </p>
                </div>
                <Link
                    v-if="can('achats.create')"
                    href="/backoffice/achats/create"
                >
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Nouveau bon de commande
                    </Button>
                </Link>
            </div>

            <DataFilters
                url="/backoffice/achats"
                :values="filters"
                :sites="sites"
                :result-count="commandes.total"
                :fields="filterFields"
            />

            <!-- Tableau (desktop) -->
            <div
                class="hidden overflow-hidden overflow-x-auto rounded-xl border bg-card sm:block"
            >
                <table class="w-full text-sm">
                    <thead>
                        <tr
                            class="border-b bg-muted/40 text-left text-muted-foreground"
                        >
                            <th class="px-4 py-3 font-medium">Référence</th>
                            <th class="px-4 py-3 font-medium">Date</th>
                            <th class="px-4 py-3 font-medium">Fournisseur</th>
                            <th class="px-4 py-3 font-medium">Agence</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Reçu / commandé
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Total
                            </th>
                            <th class="px-4 py-3 font-medium">Statut</th>
                            <th class="w-12 px-2 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="c in commandes.data"
                            :key="c.id"
                            class="cursor-pointer hover:bg-muted/20"
                            @click="ouvrir(c)"
                        >
                            <td class="px-4 py-3">
                                <span
                                    class="font-mono font-semibold tracking-wide"
                                    >{{ c.reference }}</span
                                >
                            </td>
                            <td
                                class="px-4 py-3 text-muted-foreground tabular-nums"
                            >
                                {{ c.created_at }}
                            </td>
                            <td class="px-4 py-3">
                                {{ c.fournisseur_nom ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ c.site_nom ?? '—' }}
                            </td>
                            <td
                                class="px-4 py-3 text-right text-muted-foreground tabular-nums"
                            >
                                {{ c.qte_recue }} / {{ c.qte_commandee }}
                            </td>
                            <td
                                class="px-4 py-3 text-right font-medium tabular-nums"
                            >
                                {{ formatGNF(c.total_commande) }}
                            </td>
                            <td class="px-4 py-3">
                                <StatusDot
                                    :status="c.statut"
                                    :label="c.statut_label"
                                    class="text-muted-foreground"
                                />
                            </td>
                            <td class="px-2 py-3" @click.stop>
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="h-8 w-8"
                                        >
                                            <MoreVertical class="h-4 w-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        class="w-44"
                                    >
                                        <DropdownMenuItem as-child>
                                            <Link
                                                :href="`/backoffice/achats/${c.id}`"
                                                class="flex w-full cursor-pointer items-center gap-2"
                                            >
                                                <PackageCheck class="h-4 w-4" />
                                                Voir
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="
                                                c.annulable &&
                                                can('achats.annuler')
                                            "
                                            class="cursor-pointer text-amber-600 focus:text-amber-600"
                                            @click="openAnnulerDialog(c)"
                                        >
                                            <XCircle class="h-4 w-4" />
                                            Annuler
                                        </DropdownMenuItem>
                                        <template
                                            v-if="
                                                c.is_annulee &&
                                                can('achats.delete')
                                            "
                                        >
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                class="cursor-pointer text-destructive focus:text-destructive"
                                                @click="confirmDelete(c)"
                                            >
                                                <Trash2 class="h-4 w-4" />
                                                Supprimer
                                            </DropdownMenuItem>
                                        </template>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </td>
                        </tr>
                        <tr v-if="commandes.data.length === 0">
                            <td
                                colspan="8"
                                class="px-4 py-16 text-center text-muted-foreground"
                            >
                                <PackageCheck
                                    class="mx-auto mb-3 h-10 w-10 opacity-30"
                                />
                                Aucun bon de commande trouvé.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Cartes (mobile) -->
            <div class="divide-y rounded-xl border bg-card sm:hidden">
                <Link
                    v-for="c in commandes.data"
                    :key="c.id"
                    :href="`/backoffice/achats/${c.id}`"
                    class="flex items-start justify-between gap-3 px-4 py-3 active:bg-muted/20"
                >
                    <div class="min-w-0 flex-1">
                        <p
                            class="font-mono text-sm font-semibold tracking-wide text-primary"
                        >
                            {{ c.reference }}
                        </p>
                        <p
                            class="mt-0.5 truncate text-xs text-muted-foreground"
                        >
                            {{ c.fournisseur_nom ?? '—' }} ·
                            {{ c.site_nom ?? 'Sans agence' }}
                        </p>
                        <p class="mt-1 text-sm font-medium tabular-nums">
                            {{ formatGNF(c.total_commande) }}
                        </p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            Reçu {{ c.qte_recue }} / {{ c.qte_commandee }}
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1.5">
                        <StatusDot
                            :status="c.statut"
                            :label="c.statut_label"
                            class="text-xs text-muted-foreground"
                        />
                        <span
                            class="text-xs text-muted-foreground tabular-nums"
                        >
                            {{ c.created_at }}
                        </span>
                    </div>
                </Link>
                <div
                    v-if="commandes.data.length === 0"
                    class="px-4 py-12 text-center text-sm text-muted-foreground"
                >
                    Aucun bon de commande trouvé.
                </div>
            </div>

            <!-- Pagination -->
            <div
                v-if="commandes.last_page > 1"
                class="flex flex-wrap items-center justify-center gap-1"
            >
                <template v-for="link in commandes.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-scroll
                        class="inline-flex h-9 min-w-9 items-center justify-center rounded-md border px-2 text-sm transition-colors hover:bg-muted"
                        :class="{
                            'border-primary bg-primary text-primary-foreground hover:bg-primary/90':
                                link.active,
                        }"
                    >
                        <ChevronLeft
                            v-if="link.label.includes('&laquo')"
                            class="h-4 w-4"
                        />
                        <ChevronRight
                            v-else-if="link.label.includes('&raquo')"
                            class="h-4 w-4"
                        />
                        <span v-else>{{ paginationLabel(link.label) }}</span>
                    </Link>
                </template>
            </div>
        </div>

        <!-- Annulation -->
        <Dialog
            v-model:visible="annulerDialogVisible"
            modal
            header="Annuler la commande"
            :closable="!annulerForm.processing"
            :style="{ width: '480px', maxWidth: '95vw' }"
        >
            <div class="space-y-4">
                <p class="text-sm text-muted-foreground">
                    Annuler la commande
                    <span class="font-mono font-semibold">{{
                        selectedCommande?.reference
                    }}</span>
                    ? Cette action est irréversible.
                </p>
                <div>
                    <Label class="mb-1.5 block text-sm">
                        Motif d'annulation
                        <span class="text-destructive">*</span>
                    </Label>
                    <Textarea
                        v-model="annulerForm.motif_annulation"
                        rows="4"
                        class="w-full"
                        placeholder="Indiquez la raison de l'annulation..."
                        :invalid="!!annulerForm.errors.motif_annulation"
                    />
                    <p
                        v-if="annulerForm.errors.motif_annulation"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ annulerForm.errors.motif_annulation }}
                    </p>
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button
                        variant="outline"
                        :disabled="annulerForm.processing"
                        @click="annulerDialogVisible = false"
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
                        <XCircle v-else class="mr-2 h-4 w-4" />
                        Confirmer l'annulation
                    </Button>
                </div>
            </template>
        </Dialog>
    </AppLayout>
</template>
