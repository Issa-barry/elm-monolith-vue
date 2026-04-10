<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Download,
    Eye,
    MoreVertical,
    Package,
    Pencil,
    Plus,
    Search,
    Trash2,
    X,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProduitsMobile from './partials/ProduitsMobile.vue';

const lightboxUrl = ref<string | null>(null);
const lightboxAlt = ref('');

function openLightbox(url: string, alt: string) {
    lightboxUrl.value = url;
    lightboxAlt.value = alt;
}

function closeLightbox() {
    lightboxUrl.value = null;
}

function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape') closeLightbox();
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));

interface Produit {
    id: number;
    organization_id: number;
    nom: string;
    code_interne: string | null;
    code_fournisseur: string | null;
    type: string | null;
    type_label: string | null;
    image_url: string | null;
    is_critique: boolean;
    statut: string | null;
    statut_label: string | null;
    prix_usine: number | null;
    prix_vente: number | null;
    prix_achat: number | null;
    cout: number | null;
    qte_stock: number | null;
    seuil_alerte_stock: number | null;
    description: string | null;
    last_stockout_notified_at: string | null;
    archived_at: string | null;
    created_by: number | null;
    updated_by: number | null;
    deleted_by: number | null;
    archived_by: number | null;
    created_at: string | null;
    updated_at: string | null;
    deleted_at: string | null;
    in_stock: boolean;
    is_low_stock: boolean;
    has_stock: boolean;
}

const props = defineProps<{ produits: Produit[] }>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();
const page = usePage();
const _stockAlertes = computed(
    () =>
        (page.props as any).stock_alertes ?? {
            ruptures: 0,
            faibles: 0,
            total: 0,
        },
);
const ruptures = computed(() =>
    props.produits.filter(
        (p) => p.has_stock && p.qte_stock !== null && p.qte_stock <= 0,
    ),
);
const faibles = computed(() =>
    props.produits.filter((p) => p.has_stock && p.is_low_stock),
);

const search = ref('');
const filters = ref({ global: { value: '', matchMode: 'contains' } });
watch(search, (val) => {
    filters.value.global.value = val;
});

const filteredProduits = computed(() => {
    const query = search.value.trim().toLowerCase();
    if (!query) return props.produits;

    return props.produits.filter((p) =>
        [p.nom, p.code_interne ?? ''].join(' ').toLowerCase().includes(query),
    );
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Produits', href: '/produits' },
];

function escapeHtml(value: string): string {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

function safeExcelText(value: string): string {
    const cleaned = value.replace(/\r?\n/g, ' ').trim();
    return /^[=+\-@]/.test(cleaned) ? `'${cleaned}` : cleaned;
}

function toExcelValue(value: unknown): string | number {
    if (value === null || value === undefined) return '';
    if (typeof value === 'boolean') return value ? 1 : 0;
    if (typeof value === 'number') return Number.isFinite(value) ? value : '';

    return safeExcelText(String(value));
}

function toExcelCell(value: unknown): string {
    const normalized = toExcelValue(value);

    if (typeof normalized === 'number') {
        return `<td>${normalized}</td>`;
    }

    return `<td>${escapeHtml(normalized)}</td>`;
}

function formatExportDate(d: Date): string {
    const pad = (v: number) => String(v).padStart(2, '0');
    return `${d.getFullYear()}${pad(d.getMonth() + 1)}${pad(d.getDate())}-${pad(d.getHours())}${pad(d.getMinutes())}`;
}

function exportExcel(): void {
    const rows = filteredProduits.value;

    if (rows.length === 0) {
        toast.add({
            severity: 'warn',
            summary: 'Export impossible',
            detail: 'Aucun résultat à exporter.',
            life: 3000,
        });

        return;
    }

    const columns: Array<{
        label: string;
        value: (produit: Produit) => unknown;
    }> = [
        { label: 'id', value: (p) => p.id },
        { label: 'organization_id', value: (p) => p.organization_id },
        { label: 'nom', value: (p) => p.nom },
        { label: 'code_interne', value: (p) => p.code_interne },
        { label: 'code_fournisseur', value: (p) => p.code_fournisseur },
        { label: 'type', value: (p) => p.type },
        { label: 'statut', value: (p) => p.statut },
        { label: 'prix_usine', value: (p) => p.prix_usine },
        { label: 'prix_vente', value: (p) => p.prix_vente },
        { label: 'prix_achat', value: (p) => p.prix_achat },
        { label: 'cout', value: (p) => p.cout },
        { label: 'qte_stock', value: (p) => p.qte_stock },
        { label: 'seuil_alerte_stock', value: (p) => p.seuil_alerte_stock },
        { label: 'description', value: (p) => p.description },
        { label: 'image_url', value: (p) => p.image_url },
        { label: 'is_critique', value: (p) => p.is_critique },
        {
            label: 'last_stockout_notified_at',
            value: (p) => p.last_stockout_notified_at,
        },
        { label: 'archived_at', value: (p) => p.archived_at },
        { label: 'created_by', value: (p) => p.created_by },
        { label: 'updated_by', value: (p) => p.updated_by },
        { label: 'deleted_by', value: (p) => p.deleted_by },
        { label: 'archived_by', value: (p) => p.archived_by },
        { label: 'created_at', value: (p) => p.created_at },
        { label: 'updated_at', value: (p) => p.updated_at },
        { label: 'deleted_at', value: (p) => p.deleted_at },
    ];

    const header = columns
        .map((column) => `<th>${escapeHtml(column.label)}</th>`)
        .join('');

    const body = rows
        .map((p) => {
            const cells = columns.map((column) => toExcelCell(column.value(p)));

            return `<tr>${cells.join('')}</tr>`;
        })
        .join('');

    const html = `<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
</head>
<body>
    <table border="1">
        <thead>
            <tr>${header}</tr>
        </thead>
        <tbody>
            ${body}
        </tbody>
    </table>
</body>
</html>`;

    const blob = new Blob([`\uFEFF${html}`], {
        type: 'application/vnd.ms-excel;charset=utf-8;',
    });

    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = `produits-${formatExportDate(new Date())}.xls`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);

    toast.add({
        severity: 'success',
        summary: 'Export lancé',
        detail: `${rows.length} produit${rows.length > 1 ? 's' : ''} exporté${rows.length > 1 ? 's' : ''}.`,
        life: 2500,
    });
}

function confirmDelete(produit: Produit) {
    confirm.require({
        message: `Supprimer "${produit.nom}" ? Cette action est irreversible.`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/produits/${produit.id}`, {
                onSuccess: () => {
                    toast.add({
                        severity: 'success',
                        summary: 'Supprimé',
                        detail: `${produit.nom} a été supprimé.`,
                        life: 3000,
                    });
                },
            });
        },
    });
}
</script>

<template>
    <Head title="Produits" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- ─── VUE DESKTOP ─── -->
        <div class="hidden flex-col gap-6 p-6 sm:flex">
            <!-- Alertes stock -->
            <div
                v-if="ruptures.length > 0 || faibles.length > 0"
                class="flex flex-col gap-2"
            >
                <div
                    v-if="ruptures.length > 0"
                    class="flex items-start gap-3 rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive"
                >
                    <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                    <div>
                        <span class="font-semibold">Rupture de stock</span>
                        <span class="text-destructive/80"> — </span>
                        <span class="text-destructive/80">{{
                            ruptures.map((p) => p.nom).join(', ')
                        }}</span>
                    </div>
                </div>
                <div
                    v-if="faibles.length > 0"
                    class="flex items-start gap-3 rounded-lg border border-amber-400/30 bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:bg-amber-950/20 dark:text-amber-400"
                >
                    <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                    <div>
                        <span class="font-semibold">Stock faible</span>
                        <span class="text-amber-600/80 dark:text-amber-400/80">
                            —
                        </span>
                        <span
                            class="text-amber-600/80 dark:text-amber-400/80"
                            >{{ faibles.map((p) => p.nom).join(', ') }}</span
                        >
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Produits
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ props.produits.length }} produit{{
                            props.produits.length !== 1 ? 's' : ''
                        }}
                        dans le catalogue
                    </p>
                </div>
                <Link v-if="can('produits.create')" href="/produits/create">
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Nouveau produit
                    </Button>
                </Link>
            </div>

            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="props.produits"
                    :paginator="props.produits.length > 20"
                    :rows="20"
                    :global-filter-fields="['nom', 'code_interne']"
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
                                    <Search
                                        class="h-4 w-4 text-muted-foreground"
                                    />
                                </InputIcon>
                                <InputText
                                    v-model="search"
                                    placeholder="Rechercher un produit..."
                                    class="w-full text-sm"
                                />
                            </IconField>
                            <span class="text-xs text-muted-foreground">
                                {{ props.produits.length }} résultat{{
                                    props.produits.length !== 1 ? 's' : ''
                                }}
                            </span>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                class="h-9"
                                @click="exportExcel"
                            >
                                <Download class="mr-2 h-4 w-4" />
                                Exporter Excel
                            </Button>
                        </div>
                    </template>

                    <!-- Image -->
                    <Column header="Image" style="width: 72px">
                        <template #body="{ data }">
                            <div
                                class="h-10 w-10 overflow-hidden rounded-lg border bg-muted"
                                :class="data.image_url ? 'cursor-zoom-in' : ''"
                                @click="
                                    data.image_url &&
                                    openLightbox(data.image_url, data.nom)
                                "
                            >
                                <img
                                    v-if="data.image_url"
                                    :src="data.image_url"
                                    :alt="data.nom"
                                    class="h-full w-full object-cover"
                                />
                                <div
                                    v-else
                                    class="flex h-full w-full items-center justify-center"
                                >
                                    <Package
                                        class="h-5 w-5 text-muted-foreground/40"
                                    />
                                </div>
                            </div>
                        </template>
                    </Column>

                    <!-- Code -->
                    <Column
                        field="code_interne"
                        header="Code"
                        sortable
                        style="width: 180px"
                    >
                        <template #body="{ data }">
                            <span
                                class="font-mono text-xs font-semibold whitespace-nowrap text-muted-foreground"
                            >
                                {{ data.code_interne || '—' }}
                            </span>
                        </template>
                    </Column>

                    <!-- Produit -->
                    <Column
                        field="nom"
                        header="Produit"
                        sortable
                        style="min-width: 200px"
                    >
                        <template #body="{ data }">
                            <Link
                                :href="`/produits/${data.id}`"
                                class="flex items-center gap-1.5 underline-offset-2 hover:underline"
                            >
                                <span class="font-medium">{{ data.nom }}</span>
                                <AlertTriangle
                                    v-if="data.is_critique"
                                    class="h-3.5 w-3.5 shrink-0 text-amber-500"
                                />
                            </Link>
                        </template>
                    </Column>

                    <!-- Prix de vente -->
                    <Column
                        field="prix_vente"
                        header="Prix vente"
                        sortable
                        style="width: 140px"
                    >
                        <template #body="{ data }">
                            <span class="tabular-nums">
                                {{
                                    data.prix_vente != null
                                        ? new Intl.NumberFormat('fr-FR').format(
                                              data.prix_vente,
                                          ) + ' GNF'
                                        : '—'
                                }}
                            </span>
                        </template>
                    </Column>

                    <!-- Stock -->
                    <Column
                        field="qte_stock"
                        header="Stock"
                        sortable
                        style="width: 120px"
                    >
                        <template #body="{ data }">
                            <template v-if="!data.has_stock">
                                <span class="text-xs text-muted-foreground"
                                    >—</span
                                >
                            </template>
                            <template v-else>
                                <div class="flex items-center gap-1.5">
                                    <span
                                        class="text-sm font-medium tabular-nums"
                                        :class="
                                            data.is_low_stock
                                                ? 'text-amber-600'
                                                : 'text-foreground'
                                        "
                                        >{{ data.qte_stock ?? 0 }}</span
                                    >
                                    <AlertTriangle
                                        v-if="data.is_low_stock"
                                        class="h-3.5 w-3.5 text-amber-500"
                                    />
                                </div>
                            </template>
                        </template>
                    </Column>

                    <!-- Statut -->
                    <Column
                        field="statut"
                        header="Statut"
                        sortable
                        style="width: 120px"
                    >
                        <template #body="{ data }">
                            <StatusDot
                                :label="data.statut_label"
                                :dot-class="
                                    data.statut === 'actif'
                                        ? 'bg-emerald-500'
                                        : data.statut === 'inactif'
                                          ? 'bg-zinc-400 dark:bg-zinc-500'
                                          : 'bg-orange-400'
                                "
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
                                                :href="`/produits/${data.id}`"
                                                class="flex w-full items-center gap-2"
                                            >
                                                <Eye class="h-4 w-4" />
                                                Voir le détail
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator
                                            v-if="
                                                can('produits.update') ||
                                                can('produits.delete')
                                            "
                                        />
                                        <DropdownMenuItem
                                            v-if="can('produits.update')"
                                            as-child
                                        >
                                            <Link
                                                :href="`/produits/${data.id}/edit`"
                                                class="flex w-full items-center gap-2"
                                            >
                                                <Pencil class="h-4 w-4" />
                                                Modifier
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator
                                            v-if="
                                                can('produits.update') &&
                                                can('produits.delete')
                                            "
                                        />
                                        <DropdownMenuItem
                                            v-if="can('produits.delete')"
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

                    <!-- Empty state -->
                    <template #empty>
                        <div
                            class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
                        >
                            <Package class="h-12 w-12 opacity-30" />
                            <p class="text-sm">Aucun produit trouvé.</p>
                            <Link
                                v-if="can('produits.create')"
                                href="/produits/create"
                            >
                                <Button variant="outline" size="sm">
                                    <Plus class="mr-2 h-4 w-4" />
                                    Créer le premier produit
                                </Button>
                            </Link>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>

        <!-- ─── VUE MOBILE ─── -->
        <div class="sm:hidden">
            <div
                v-if="ruptures.length > 0 || faibles.length > 0"
                class="flex flex-col gap-2 px-4 pt-4"
            >
                <div
                    v-if="ruptures.length > 0"
                    class="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2.5 text-xs text-destructive"
                >
                    <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <div>
                        <span class="font-semibold">Rupture</span> —
                        {{ ruptures.map((p) => p.nom).join(', ') }}
                    </div>
                </div>
                <div
                    v-if="faibles.length > 0"
                    class="flex items-start gap-2 rounded-lg border border-amber-400/30 bg-amber-50 px-3 py-2.5 text-xs text-amber-700 dark:bg-amber-950/20 dark:text-amber-400"
                >
                    <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <div>
                        <span class="font-semibold">Stock faible</span> —
                        {{ faibles.map((p) => p.nom).join(', ') }}
                    </div>
                </div>
            </div>
            <ProduitsMobile
                :produits="props.produits"
                :on-delete="confirmDelete"
            />
        </div>
        <!-- Lightbox -->
        <Teleport to="body">
            <div
                v-if="lightboxUrl"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4"
                @click.self="closeLightbox"
            >
                <div class="relative max-h-full max-w-3xl">
                    <button
                        type="button"
                        class="absolute -top-3 -right-3 flex h-8 w-8 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20"
                        @click="closeLightbox"
                    >
                        <X class="h-5 w-5" />
                    </button>
                    <img
                        :src="lightboxUrl"
                        :alt="lightboxAlt"
                        class="max-h-[80vh] max-w-full rounded-xl object-contain shadow-2xl"
                    />
                    <p class="mt-2 text-center text-sm text-white/70">
                        {{ lightboxAlt }}
                    </p>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
