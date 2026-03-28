<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu, DropdownMenuContent, DropdownMenuItem,
    DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import StatusDot from '@/components/StatusDot.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, MoreVertical, Pencil, Plus, Search, Trash2, Truck } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, ref, watch } from 'vue';

function initials(name: string | null | undefined): string {
    if (!name) return '?';
    return name.trim().split(/\s+/).map(w => w[0]).slice(0, 2).join('').toUpperCase();
}

interface Livreur {
    id: number;
    nom: string;
    prenom: string;
    nom_complet: string;
    email: string | null;
    telephone: string | null;
    code_phone_pays: string | null;
    ville: string | null;
    pays: string | null;
    code_pays: string | null;
    is_active: boolean;
}

const props = defineProps<{ livreurs: Livreur[] }>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();

const search = ref('');
const filters = ref({ global: { value: '', matchMode: 'contains' } });
watch(search, (val) => { filters.value.global.value = val; });

const mobileFiltered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.livreurs;
    return props.livreurs.filter(l =>
        l.nom_complet.toLowerCase().includes(q) ||
        (l.email ?? '').toLowerCase().includes(q) ||
        (l.ville ?? '').toLowerCase().includes(q) ||
        (l.pays ?? '').toLowerCase().includes(q),
    );
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Livreurs', href: '/livreurs' },
];

function flagUrl(code: string) {
    return `https://flagcdn.com/20x15/${code.toLowerCase()}.png`;
}

function confirmDelete(l: Livreur) {
    confirm.require({
        message: `Supprimer « ${l.nom_complet} » ? Cette action est irréversible.`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/livreurs/${l.id}`, {
                onSuccess: () => toast.add({
                    severity: 'success',
                    summary: 'Supprimé',
                    detail: `${l.nom_complet} a été supprimé.`,
                    life: 3000,
                }),
            });
        },
    });
}
</script>

<template>
    <Head title="Livreurs" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">

        <!-- ── Mobile (< sm) ──────────────────────────────────────────────── -->
        <div class="flex flex-col sm:hidden">

            <!-- Sticky header -->
            <div class="sticky top-0 z-10 flex items-center gap-2 border-b bg-background px-3 py-2">
                <Link href="/dashboard">
                    <Button variant="ghost" size="icon" class="h-8 w-8 shrink-0">
                        <ArrowLeft class="h-4 w-4" />
                    </Button>
                </Link>
                <span class="flex-1 text-center text-sm font-semibold">Livreurs</span>
                <Link v-if="can('livreurs.create')" href="/livreurs/create">
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
                    <Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground pointer-events-none" />
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Rechercher..."
                        class="w-full rounded-lg border bg-background py-2 pl-9 pr-3 text-sm outline-none focus:ring-2 focus:ring-ring"
                    />
                </div>
            </div>

            <!-- Card list -->
            <div class="divide-y">
                <div
                    v-for="l in mobileFiltered"
                    :key="l.id"
                    class="flex items-center gap-3.5 px-4 py-3.5 transition-colors active:bg-muted/40"
                >
                    <!-- Avatar -->
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground text-sm font-semibold">
                        {{ initials(l.nom_complet) }}
                    </div>

                    <!-- Info -->
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-medium text-sm">{{ l.nom_complet }}</div>
                        <div v-if="l.email" class="truncate text-xs text-muted-foreground">{{ l.email }}</div>
                        <div v-if="l.ville || l.pays" class="flex items-center gap-1 mt-0.5">
                            <img v-if="l.code_pays" :src="flagUrl(l.code_pays)" class="h-3 w-auto rounded-sm" />
                            <span class="text-xs text-muted-foreground">
                                {{ [l.ville, l.pays].filter(Boolean).join(', ') }}
                            </span>
                        </div>
                    </div>

                    <!-- Status dot -->
                    <StatusDot
                        :label="l.is_active ? 'Actif' : 'Inactif'"
                        :dot-class="l.is_active ? 'bg-emerald-500' : 'bg-zinc-400 dark:bg-zinc-500'"
                        class="shrink-0 text-xs text-muted-foreground"
                    />

                    <!-- Dropdown -->
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button variant="ghost" size="icon" class="h-8 w-8 shrink-0">
                                <MoreVertical class="h-4 w-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-44">
                            <DropdownMenuItem v-if="can('livreurs.update')" as-child>
                                <Link :href="`/livreurs/${l.id}/edit`" class="flex items-center gap-2 w-full">
                                    <Pencil class="h-4 w-4" />
                                    Modifier
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator v-if="can('livreurs.update') && can('livreurs.delete')" />
                            <DropdownMenuItem
                                v-if="can('livreurs.delete')"
                                class="text-destructive focus:text-destructive cursor-pointer"
                                @click="confirmDelete(l)"
                            >
                                <Trash2 class="h-4 w-4" />
                                Supprimer
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            <!-- Empty state -->
            <div v-if="mobileFiltered.length === 0" class="flex flex-col items-center gap-3 py-16 text-muted-foreground">
                <Truck class="h-12 w-12 opacity-30" />
                <p class="text-sm">Aucun livreur trouvé.</p>
                <Link v-if="can('livreurs.create')" href="/livreurs/create">
                    <Button variant="outline" size="sm">
                        <Plus class="mr-2 h-4 w-4" />
                        Ajouter le premier livreur
                    </Button>
                </Link>
            </div>
        </div>

        <!-- ── Desktop (≥ sm) ─────────────────────────────────────────────── -->
        <div class="hidden sm:flex flex-col gap-6 p-6">

            <!-- En-tête -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Livreurs</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ livreurs.length }} livreur{{ livreurs.length !== 1 ? 's' : '' }}
                    </p>
                </div>
                <Link v-if="can('livreurs.create')" href="/livreurs/create">
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Nouveau livreur
                    </Button>
                </Link>
            </div>

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="livreurs"
                    :paginator="livreurs.length > 20"
                    :rows="20"
                    :global-filter-fields="['nom_complet', 'email', 'telephone', 'ville', 'pays']"
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
                            <span class="text-xs text-muted-foreground">{{ livreurs.length }} résultat{{ livreurs.length !== 1 ? 's' : '' }}</span>
                        </div>
                    </template>

                    <!-- Nom -->
                    <Column field="nom_complet" header="Livreur" sortable style="min-width: 320px">
                        <template #body="{ data }">
                            <div class="flex items-center gap-3">
                                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground text-xs font-semibold">
                                    {{ initials(data.nom_complet) }}
                                </div>
                                <div>
                                    <div class="font-medium">{{ data.nom_complet }}</div>
                                    <div v-if="data.email" class="text-xs text-muted-foreground">{{ data.email }}</div>
                                </div>
                            </div>
                        </template>
                    </Column>

                    <!-- Téléphone -->
                    <Column field="telephone" header="Téléphone" style="width: 190px">
                        <template #body="{ data }">
                            <span class="tabular-nums text-muted-foreground whitespace-nowrap">{{ formatPhoneDisplay(data.telephone, data.code_phone_pays) }}</span>
                        </template>
                    </Column>

                    <!-- Localisation -->
                    <Column field="ville" header="Localisation" style="min-width: 220px">
                        <template #body="{ data }">
                            <div class="flex items-center gap-2">
                                <img v-if="data.code_pays" :src="flagUrl(data.code_pays)" class="h-4 w-auto rounded-sm shadow-sm" />
                                <span class="text-muted-foreground">
                                    {{ [data.ville, data.pays].filter(Boolean).join(', ') || '—' }}
                                </span>
                            </div>
                        </template>
                    </Column>

                    <!-- Statut -->
                    <Column field="is_active" header="Statut" sortable style="width: 110px">
                        <template #body="{ data }">
                            <StatusDot
                                :label="data.is_active ? 'Actif' : 'Inactif'"
                                :dot-class="data.is_active ? 'bg-emerald-500' : 'bg-zinc-400 dark:bg-zinc-500'"
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
                                        <DropdownMenuItem v-if="can('livreurs.update')" as-child>
                                            <Link :href="`/livreurs/${data.id}/edit`" class="flex items-center gap-2 w-full">
                                                <Pencil class="h-4 w-4" />
                                                Modifier
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator v-if="can('livreurs.update') && can('livreurs.delete')" />
                                        <DropdownMenuItem
                                            v-if="can('livreurs.delete')"
                                            class="text-destructive focus:text-destructive cursor-pointer"
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
                            <Truck class="h-12 w-12 opacity-30" />
                            <p class="text-sm">Aucun livreur trouvé.</p>
                            <Link v-if="can('livreurs.create')" href="/livreurs/create">
                                <Button variant="outline" size="sm">
                                    <Plus class="mr-2 h-4 w-4" />
                                    Ajouter le premier livreur
                                </Button>
                            </Link>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
