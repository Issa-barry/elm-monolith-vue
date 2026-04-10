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
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Car,
    MoreVertical,
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
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

interface EquipeMembre {
    livreur_nom: string;
    taux_commission: number;
    role: string;
}

interface Vehicule {
    id: number;
    nom_vehicule: string;
    marque: string | null;
    modele: string | null;
    immatriculation: string;
    type_label: string;
    capacite_packs: number | null;
    proprietaire_nom: string | null;
    proprietaire_telephone: string | null;
    proprietaire_code_phone_pays: string | null;
    equipe_nom: string | null;
    livreur_principal_nom: string | null;
    equipe_membres: EquipeMembre[];
    photo_url: string | null;
    is_active: boolean;
}

const props = defineProps<{ vehicules: Vehicule[] }>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();

const search = ref('');
const filters = ref({ global: { value: '', matchMode: 'contains' } });
watch(search, (val) => {
    filters.value.global.value = val;
});

const mobileFiltered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.vehicules;
    return props.vehicules.filter(
        (v) =>
            v.nom_vehicule.toLowerCase().includes(q) ||
            v.immatriculation.toLowerCase().includes(q) ||
            v.type_label.toLowerCase().includes(q) ||
            (v.proprietaire_nom ?? '').toLowerCase().includes(q) ||
            (v.equipe_nom ?? '').toLowerCase().includes(q),
    );
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Véhicules', href: '/vehicules' },
];

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
onUnmounted(() => document.removeEventListener('keydown', onKeydown));

function confirmDelete(v: Vehicule) {
    confirm.require({
        message: `Supprimer « ${v.nom_vehicule} (${v.immatriculation}) » ? Cette action est irréversible.`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/vehicules/${v.id}`, {
                onSuccess: () =>
                    toast.add({
                        severity: 'success',
                        summary: 'Supprimé',
                        detail: `${v.nom_vehicule} a été supprimé.`,
                        life: 3000,
                    }),
            });
        },
    });
}
</script>

<template>
    <Head title="Véhicules" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- Mobile (< sm) -->
        <div class="flex flex-col sm:hidden">
            <!-- Sticky header -->
            <div
                class="sticky top-0 z-10 flex items-center gap-2 border-b bg-background px-3 py-2"
            >
                <Link href="/dashboard">
                    <Button
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8 shrink-0"
                    >
                        <ArrowLeft class="h-4 w-4" />
                    </Button>
                </Link>
                <span class="flex-1 text-center text-sm font-semibold"
                    >Véhicules</span
                >
                <Link v-if="can('vehicules.create')" href="/vehicules/create">
                    <Button size="sm" class="h-8 px-3 text-xs">
                        <Plus class="mr-1 h-3.5 w-3.5" />
                        Nouveau
                    </Button>
                </Link>
                <div v-else class="h-8 w-[72px]" />
            </div>

            <!-- Search -->
            <div class="px-3 py-2">
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Rechercher..."
                        class="w-full rounded-lg border bg-background py-2 pr-3 pl-9 text-sm outline-none focus:ring-2 focus:ring-ring"
                    />
                </div>
            </div>

            <!-- Card list -->
            <div class="divide-y">
                <div
                    v-for="v in mobileFiltered"
                    :key="v.id"
                    class="flex items-center gap-3.5 px-4 py-3.5 transition-colors active:bg-muted/40"
                >
                    <!-- Photo or icon -->
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-muted/30"
                        :class="v.photo_url ? 'cursor-zoom-in' : ''"
                        @click="
                            v.photo_url &&
                            openLightbox(v.photo_url, v.nom_vehicule)
                        "
                    >
                        <img
                            v-if="v.photo_url"
                            :src="v.photo_url"
                            :alt="v.nom_vehicule"
                            class="h-full w-full object-cover"
                        />
                        <Car v-else class="h-5 w-5 text-muted-foreground" />
                    </div>

                    <!-- Info -->
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium">
                            {{ v.nom_vehicule }}
                        </div>
                        <div class="font-mono text-xs text-muted-foreground">
                            {{ v.immatriculation }}
                        </div>
                        <span
                            class="mt-0.5 inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium"
                        >
                            {{ v.type_label }}
                        </span>
                    </div>

                    <!-- Status dot -->
                    <StatusDot
                        :label="v.is_active ? 'Actif' : 'Inactif'"
                        :dot-class="
                            v.is_active
                                ? 'bg-emerald-500'
                                : 'bg-zinc-400 dark:bg-zinc-500'
                        "
                        class="shrink-0 text-xs text-muted-foreground"
                    />

                    <!-- Dropdown -->
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="h-8 w-8 shrink-0"
                            >
                                <MoreVertical class="h-4 w-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-44">
                            <DropdownMenuItem
                                v-if="can('vehicules.update')"
                                as-child
                            >
                                <Link
                                    :href="`/vehicules/${v.id}/edit`"
                                    class="flex w-full items-center gap-2"
                                >
                                    <Pencil class="h-4 w-4" />
                                    Modifier
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator
                                v-if="
                                    can('vehicules.update') &&
                                    can('vehicules.delete')
                                "
                            />
                            <DropdownMenuItem
                                v-if="can('vehicules.delete')"
                                class="cursor-pointer text-destructive focus:text-destructive"
                                @click="confirmDelete(v)"
                            >
                                <Trash2 class="h-4 w-4" />
                                Supprimer
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            <!-- Empty state -->
            <div
                v-if="mobileFiltered.length === 0"
                class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
            >
                <Car class="h-12 w-12 opacity-30" />
                <p class="text-sm">Aucun véhicule trouvé.</p>
                <Link v-if="can('vehicules.create')" href="/vehicules/create">
                    <Button variant="outline" size="sm">
                        <Plus class="mr-2 h-4 w-4" />
                        Ajouter le premier véhicule
                    </Button>
                </Link>
            </div>
        </div>

        <!-- Desktop (>= sm) -->
        <div class="hidden flex-col gap-6 p-6 sm:flex">
            <!-- En-tête -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Véhicules
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ vehicules.length }} véhicule{{
                            vehicules.length !== 1 ? 's' : ''
                        }}
                    </p>
                </div>
                <Link v-if="can('vehicules.create')" href="/vehicules/create">
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Nouveau véhicule
                    </Button>
                </Link>
            </div>

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="vehicules"
                    :paginator="vehicules.length > 20"
                    :rows="20"
                    :global-filter-fields="[
                        'nom_vehicule',
                        'immatriculation',
                        'type_label',
                        'proprietaire_nom',
                        'equipe_nom',
                    ]"
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
                                    placeholder="Rechercher..."
                                    class="w-full text-sm"
                                />
                            </IconField>
                            <span class="text-xs text-muted-foreground"
                                >{{ vehicules.length }} résultat{{
                                    vehicules.length !== 1 ? 's' : ''
                                }}</span
                            >
                        </div>
                    </template>

                    <!-- Photo -->
                    <Column header="Photo" style="width: 72px">
                        <template #body="{ data }">
                            <div
                                class="h-10 w-10 overflow-hidden rounded-lg border bg-muted"
                                :class="data.photo_url ? 'cursor-zoom-in' : ''"
                                @click="
                                    data.photo_url &&
                                    openLightbox(
                                        data.photo_url,
                                        data.nom_vehicule,
                                    )
                                "
                            >
                                <img
                                    v-if="data.photo_url"
                                    :src="data.photo_url"
                                    :alt="data.nom_vehicule"
                                    class="h-full w-full object-cover"
                                />
                                <div
                                    v-else
                                    class="flex h-full w-full items-center justify-center"
                                >
                                    <Car class="h-5 w-5 text-muted-foreground/40" />
                                </div>
                            </div>
                        </template>
                    </Column>

                    <!-- Véhicule -->
                    <Column
                        field="nom_vehicule"
                        header="Véhicule"
                        sortable
                        style="min-width: 260px"
                    >
                        <template #body="{ data }">
                            <div class="leading-tight">
                                <div class="font-medium">
                                    {{ data.nom_vehicule }}
                                </div>
                                <div
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    {{ data.immatriculation }}
                                </div>
                            </div>
                        </template>
                    </Column>

                    <!-- Type -->
                    <Column
                        field="type_label"
                        header="Type"
                        sortable
                        style="width: 140px"
                    >
                        <template #body="{ data }">
                            <span
                                class="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium"
                            >
                                {{ data.type_label }}
                            </span>
                        </template>
                    </Column>

                    <!-- Capacité -->
                    <Column
                        field="capacite_packs"
                        header="Capacité"
                        sortable
                        style="width: 130px"
                    >
                        <template #body="{ data }">
                            <span
                                class="whitespace-nowrap text-muted-foreground tabular-nums"
                            >
                                {{
                                    data.capacite_packs != null
                                        ? `${data.capacite_packs} packs`
                                        : '—'
                                }}
                            </span>
                        </template>
                    </Column>

                    <!-- Propriétaire -->
                    <Column
                        field="proprietaire_nom"
                        header="Propriétaire"
                        style="min-width: 180px"
                    >
                        <template #body="{ data }">
                            <div class="leading-tight">
                                <div class="text-muted-foreground">
                                    {{ data.proprietaire_nom ?? '—' }}
                                </div>
                                <div
                                    v-if="data.proprietaire_telephone"
                                    class="mt-1 text-xs text-muted-foreground/80 tabular-nums"
                                >
                                    {{
                                        formatPhoneDisplay(
                                            data.proprietaire_telephone,
                                            data.proprietaire_code_phone_pays,
                                        )
                                    }}
                                </div>
                            </div>
                        </template>
                    </Column>

                    <!-- Équipe -->
                    <Column
                        field="equipe_nom"
                        header="Équipe"
                        style="min-width: 180px"
                    >
                        <template #body="{ data }">
                            <div class="leading-tight">
                                <div class="text-muted-foreground">
                                    {{ data.equipe_nom ?? '—' }}
                                </div>
                                <div
                                    v-if="data.livreur_principal_nom"
                                    class="mt-0.5 text-xs text-muted-foreground/70"
                                >
                                    {{ data.livreur_principal_nom }}
                                </div>
                            </div>
                        </template>
                    </Column>

                    <!-- Statut -->
                    <Column
                        field="is_active"
                        header="Statut"
                        sortable
                        style="width: 110px"
                    >
                        <template #body="{ data }">
                            <StatusDot
                                :label="data.is_active ? 'Actif' : 'Inactif'"
                                :dot-class="
                                    data.is_active
                                        ? 'bg-emerald-500'
                                        : 'bg-zinc-400 dark:bg-zinc-500'
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
                                        <DropdownMenuItem
                                            v-if="can('vehicules.update')"
                                            as-child
                                        >
                                            <Link
                                                :href="`/vehicules/${data.id}/edit`"
                                                class="flex w-full items-center gap-2"
                                            >
                                                <Pencil class="h-4 w-4" />
                                                Modifier
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator
                                            v-if="
                                                can('vehicules.update') &&
                                                can('vehicules.delete')
                                            "
                                        />
                                        <DropdownMenuItem
                                            v-if="can('vehicules.delete')"
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
                        <div
                            class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
                        >
                            <Car class="h-12 w-12 opacity-30" />
                            <p class="text-sm">Aucun véhicule trouvé.</p>
                            <Link
                                v-if="can('vehicules.create')"
                                href="/vehicules/create"
                            >
                                <Button variant="outline" size="sm">
                                    <Plus class="mr-2 h-4 w-4" />
                                    Ajouter le premier véhicule
                                </Button>
                            </Link>
                        </div>
                    </template>
                </DataTable>
            </div>
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
