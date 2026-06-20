<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Check,
    CheckCheck,
    ChevronDown,
    ChevronUp,
    Lock,
    Minus,
    Pencil,
    Plus,
    Power,
    Receipt,
    Save,
    Search,
    Shield,
    Tags,
    Trash2,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Dialog from 'primevue/dialog';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import { useToast } from 'primevue/usetoast';
import { computed, ref, watch } from 'vue';

interface Option {
    value: string;
    label: string;
}

interface DepenseType {
    id: string;
    libelle: string;
    description: string | null;
    categorie: string;
    categorie_label: string;
    commentaire_obligatoire: boolean;
    justificatif_obligatoire: boolean;
    type_paie: string | null;
    is_active: boolean;
    depenses_count: number;
}

interface SiteItem {
    id: string;
    nom: string;
    code: string;
}

interface RoleConfig {
    role_name: string;
    is_actif: boolean;
    peut_valider: boolean;
    perimetre: 'toutes_agences' | 'son_agence' | 'agences_selectionnees';
    sites: string[];
}

const props = defineProps<{
    types: DepenseType[];
    categories: Option[];
    config: RoleConfig[];
    sites: SiteItem[];
}>();

// ── Onglets ───────────────────────────────────────────────────────────────────

const activeTab = ref<'types' | 'droits'>('types');

// ── Types de dépense ──────────────────────────────────────────────────────────

const toast = useToast();

const search = ref('');
const filters = ref({ global: { value: '', matchMode: 'contains' } });
watch(search, (val) => {
    filters.value.global.value = val;
});

const ALL = '__all__';
const selectedCategorie = ref<string>(ALL);
const selectedStatut = ref<string>(ALL);

const categorieOptions = computed(() => [
    { value: ALL, label: 'Tous les concernés' },
    ...props.categories,
]);

const statutOptions = [
    { value: ALL, label: 'Tous les statuts' },
    { value: 'actif', label: 'Actif' },
    { value: 'inactif', label: 'Inactif' },
];

const filtered = computed(() => {
    let data = props.types;
    if (selectedCategorie.value !== ALL)
        data = data.filter((t) => t.categorie === selectedCategorie.value);
    if (selectedStatut.value !== ALL)
        data = data.filter((t) =>
            selectedStatut.value === 'actif' ? t.is_active : !t.is_active,
        );
    return data;
});

const showDialog = ref(false);
const editingType = ref<DepenseType | null>(null);

const dialogTitle = computed(() =>
    editingType.value ? 'Modifier le type' : 'Nouveau type de dépense',
);

const typeForm = useForm({
    libelle: '',
    description: '',
    categorie: 'interne',
    commentaire_obligatoire: false,
    justificatif_obligatoire: false,
    type_paie: '' as string,
    is_active: true,
});

function openCreate() {
    editingType.value = null;
    typeForm.reset();
    typeForm.is_active = true;
    typeForm.categorie = 'interne';
    showDialog.value = true;
}

function openEdit(type: DepenseType) {
    editingType.value = type;
    typeForm.libelle = type.libelle;
    typeForm.description = type.description ?? '';
    typeForm.categorie = type.categorie;
    typeForm.commentaire_obligatoire = type.commentaire_obligatoire;
    typeForm.justificatif_obligatoire = type.justificatif_obligatoire;
    typeForm.type_paie = type.type_paie ?? '';
    typeForm.is_active = type.is_active;
    showDialog.value = true;
}

function handleSubmit() {
    if (editingType.value) {
        typeForm.put(`/settings/depense-types/${editingType.value.id}`, {
            onSuccess: () => {
                showDialog.value = false;
                toast.add({
                    severity: 'success',
                    summary: 'Type mis à jour',
                    life: 3000,
                });
            },
        });
    } else {
        typeForm.post('/settings/depense-types', {
            onSuccess: () => {
                showDialog.value = false;
                typeForm.reset();
                toast.add({
                    severity: 'success',
                    summary: 'Type créé',
                    life: 3000,
                });
            },
        });
    }
}

function toggleType(type: DepenseType) {
    router.patch(
        `/settings/depense-types/${type.id}/toggle`,
        {},
        { preserveScroll: true },
    );
}

function destroyType(type: DepenseType) {
    if (type.depenses_count > 0) {
        toast.add({
            severity: 'warn',
            summary: 'Suppression impossible',
            detail: `Ce type est utilisé dans ${type.depenses_count} dépense${type.depenses_count > 1 ? 's' : ''}. Désactivez-le plutôt.`,
            life: 5000,
        });
        return;
    }
    if (!confirm(`Supprimer le type « ${type.libelle} » ?`)) return;
    router.delete(`/settings/depense-types/${type.id}`, {
        preserveScroll: true,
    });
}

const categorieColors: Record<string, string> = {
    interne:
        'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    employe: 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300',
    livreur:
        'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300',
    proprietaire:
        'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300',
    vehicule:
        'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
};

// ── Droits création / validation ──────────────────────────────────────────────

const ADMIN_ROLES = ['super_admin', 'admin_entreprise'];

const droitsForm = ref<RoleConfig[]>(
    props.config.map((c) => ({ ...c, sites: [...c.sites] })),
);

const roleLabels: Record<string, string> = {
    super_admin: 'Super Admin',
    admin_entreprise: 'Admin Entreprise',
    manager: 'Manager',
    commerciale: 'Commerciale',
    comptable: 'Comptable',
    livreur: 'Livreur',
    proprietaire: 'Propriétaire',
    client: 'Client',
};

function roleLabel(name: string): string {
    return roleLabels[name] ?? name;
}

function isAdminRole(roleName: string): boolean {
    return ADMIN_ROLES.includes(roleName);
}

// Colonnes d'actions
const ACTIONS = ['peut_valider'] as const;
type Action = (typeof ACTIONS)[number];

const actionMeta: Record<Action, { label: string; color: string }> = {
    peut_valider: {
        label: 'Peut valider',
        color: 'text-blue-600 dark:text-blue-400',
    },
};

const nonAdminRows = computed(() =>
    droitsForm.value.filter((r) => !isAdminRole(r.role_name)),
);

function isChecked(entry: RoleConfig, action: Action): boolean {
    return entry[action] as boolean;
}

function toggle(entry: RoleConfig, action: Action) {
    (entry[action] as boolean) = !entry[action];
    if (action === 'peut_valider' && !entry.peut_valider) {
        entry.sites = [];
    }
}

type ColState = 'all' | 'partial' | 'none';

function columnState(action: Action): ColState {
    const rows = nonAdminRows.value;
    const checked = rows.filter((r) => isChecked(r, action)).length;
    if (checked === 0) return 'none';
    if (checked === rows.length) return 'all';
    return 'partial';
}

function toggleColumn(action: Action) {
    const state = columnState(action);
    nonAdminRows.value.forEach((entry) => {
        const real = droitsForm.value.find(
            (r) => r.role_name === entry.role_name,
        )!;
        (real[action] as boolean) = state !== 'all';
        if (action === 'peut_valider' && state === 'all') {
            real.sites = [];
        }
    });
}

// Portée expandée
const expandedPortee = ref<Set<string>>(new Set());

function togglePortee(roleName: string) {
    if (expandedPortee.value.has(roleName))
        expandedPortee.value.delete(roleName);
    else expandedPortee.value.add(roleName);
}

function isPorteeExpanded(roleName: string) {
    return expandedPortee.value.has(roleName);
}

function setPerimetre(
    entry: RoleConfig,
    p: 'toutes_agences' | 'son_agence' | 'agences_selectionnees',
) {
    entry.perimetre = p;
    if (p !== 'agences_selectionnees') entry.sites = [];
}

function perimetreLabel(entry: RoleConfig): string {
    if (entry.perimetre === 'toutes_agences') return 'Toutes les agences';
    if (entry.perimetre === 'son_agence') return 'Son agence uniquement';
    const n = entry.sites.length;
    if (n === 0) return 'Agences sélectionnées';
    return n === 1 ? '1 agence sélectionnée' : `${n} agences sélectionnées`;
}

function toggleSite(entry: RoleConfig, siteId: string) {
    const idx = entry.sites.indexOf(siteId);
    if (idx === -1) entry.sites.push(siteId);
    else entry.sites.splice(idx, 1);
}

function hasSite(entry: RoleConfig, siteId: string) {
    return entry.sites.includes(siteId);
}

const savingDroits = ref(false);
const flashDroits = ref(false);

function saveDroits() {
    savingDroits.value = true;
    router.put(
        '/settings/depenses/droits',
        { config: droitsForm.value },
        {
            onSuccess: () => {
                flashDroits.value = true;
                setTimeout(() => (flashDroits.value = false), 3000);
            },
            onFinish: () => (savingDroits.value = false),
        },
    );
}
</script>

<template>
    <Head title="Paramètres dépenses" />

    <AppLayout>
        <SettingsLayout :wide="true">
            <div class="space-y-6">
                <HeadingSmall
                    title="Paramètres dépenses"
                    description="Configuration des types de dépense et des droits de validation."
                />

                <!-- Onglets -->
                <div class="flex gap-1 border-b">
                    <button
                        type="button"
                        class="flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium transition-colors"
                        :class="
                            activeTab === 'types'
                                ? 'border-primary text-primary'
                                : 'border-transparent text-muted-foreground hover:text-foreground'
                        "
                        @click="activeTab = 'types'"
                    >
                        <Tags class="h-4 w-4" />
                        Types de dépense
                    </button>
                    <button
                        type="button"
                        class="flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium transition-colors"
                        :class="
                            activeTab === 'droits'
                                ? 'border-primary text-primary'
                                : 'border-transparent text-muted-foreground hover:text-foreground'
                        "
                        @click="activeTab = 'droits'"
                    >
                        <Receipt class="h-4 w-4" />
                        Droits de validation
                    </button>
                </div>

                <!-- ── Tab : Types de dépense ───────────────────────────────── -->
                <div v-show="activeTab === 'types'" class="space-y-4">
                    <div class="flex items-start justify-between gap-4">
                        <p class="text-sm text-muted-foreground">
                            Classement des dépenses par concerné : véhicule,
                            livreur, salarié, propriétaire ou interne.
                        </p>
                        <Button size="sm" @click="openCreate">
                            <Plus class="mr-1.5 h-3.5 w-3.5" />
                            Nouveau type
                        </Button>
                    </div>

                    <div class="overflow-hidden rounded-xl border bg-card">
                        <DataTable
                            :value="filtered"
                            :paginator="filtered.length > 15"
                            :rows="15"
                            :global-filter-fields="[
                                'libelle',
                                'categorie_label',
                                'description',
                            ]"
                            v-model:filters="filters"
                            data-key="id"
                            striped-rows
                            removable-sort
                            class="text-sm"
                            :pt="{
                                root: { class: 'w-full' },
                                header: {
                                    class: 'border-b bg-muted/30 px-4 py-3',
                                },
                                tbody: { class: 'divide-y' },
                            }"
                        >
                            <template #header>
                                <div class="flex flex-wrap items-center gap-3">
                                    <IconField class="max-w-xs flex-1">
                                        <InputIcon class="pointer-events-none">
                                            <Search
                                                class="h-4 w-4 text-muted-foreground"
                                            />
                                        </InputIcon>
                                        <InputText
                                            v-model="search"
                                            placeholder="Rechercher…"
                                            class="w-full text-sm"
                                        />
                                    </IconField>
                                    <Select
                                        v-model="selectedCategorie"
                                        :options="categorieOptions"
                                        option-label="label"
                                        option-value="value"
                                        class="w-64"
                                    />
                                    <Select
                                        v-model="selectedStatut"
                                        :options="statutOptions"
                                        option-label="label"
                                        option-value="value"
                                        class="w-44"
                                    />
                                    <span class="text-xs text-muted-foreground">
                                        {{ filtered.length }} type{{
                                            filtered.length !== 1 ? 's' : ''
                                        }}
                                    </span>
                                </div>
                            </template>

                            <Column
                                field="libelle"
                                header="Libellé"
                                sortable
                                style="min-width: 220px"
                            >
                                <template #body="{ data }">
                                    <div
                                        class="flex items-center gap-3"
                                        :class="{
                                            'opacity-50': !data.is_active,
                                        }"
                                    >
                                        <div
                                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border bg-muted/30"
                                        >
                                            <Tags
                                                class="h-4 w-4 text-muted-foreground"
                                            />
                                        </div>
                                        <span class="font-medium">{{
                                            data.libelle
                                        }}</span>
                                    </div>
                                </template>
                            </Column>

                            <Column
                                field="categorie_label"
                                header="Concerné"
                                sortable
                                style="width: 140px"
                            >
                                <template #body="{ data }">
                                    <span
                                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                        :class="
                                            categorieColors[data.categorie] ??
                                            'bg-muted text-muted-foreground'
                                        "
                                    >
                                        {{ data.categorie_label }}
                                    </span>
                                </template>
                            </Column>

                            <Column
                                header="Commentaire"
                                style="width: 120px; text-align: center"
                            >
                                <template #body="{ data }">
                                    <div class="flex justify-center">
                                        <span
                                            v-if="data.commentaire_obligatoire"
                                            class="text-xs font-medium text-amber-600"
                                            >Requis</span
                                        >
                                        <span
                                            v-else
                                            class="text-xs text-muted-foreground"
                                            >—</span
                                        >
                                    </div>
                                </template>
                            </Column>

                            <Column
                                header="Justificatif"
                                style="width: 120px; text-align: center"
                            >
                                <template #body="{ data }">
                                    <div class="flex justify-center">
                                        <span
                                            v-if="data.justificatif_obligatoire"
                                            class="text-xs font-medium text-amber-600"
                                            >Requis</span
                                        >
                                        <span
                                            v-else
                                            class="text-xs text-muted-foreground"
                                            >—</span
                                        >
                                    </div>
                                </template>
                            </Column>

                            <Column
                                field="is_active"
                                header="Statut"
                                sortable
                                style="width: 110px"
                            >
                                <template #body="{ data }">
                                    <Badge
                                        :variant="
                                            data.is_active
                                                ? 'default'
                                                : 'secondary'
                                        "
                                    >
                                        {{
                                            data.is_active ? 'Actif' : 'Inactif'
                                        }}
                                    </Badge>
                                </template>
                            </Column>

                            <Column header="" style="width: 110px">
                                <template #body="{ data }">
                                    <div class="flex justify-end gap-0.5">
                                        <button
                                            type="button"
                                            :title="
                                                data.is_active
                                                    ? 'Désactiver'
                                                    : 'Activer'
                                            "
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                            @click="toggleType(data)"
                                        >
                                            <Power class="h-3.5 w-3.5" />
                                        </button>
                                        <button
                                            type="button"
                                            title="Modifier"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                            @click="openEdit(data)"
                                        >
                                            <Pencil class="h-3.5 w-3.5" />
                                        </button>
                                        <button
                                            type="button"
                                            title="Supprimer"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                                            @click="destroyType(data)"
                                        >
                                            <Trash2 class="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                </template>
                            </Column>

                            <template #empty>
                                <div
                                    class="flex flex-col items-center gap-3 py-12 text-muted-foreground"
                                >
                                    <Tags class="h-10 w-10 opacity-30" />
                                    <p class="text-sm">
                                        Aucun type de dépense.
                                    </p>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        @click="openCreate"
                                    >
                                        <Plus class="mr-2 h-4 w-4" />
                                        Créer le premier type
                                    </Button>
                                </div>
                            </template>
                        </DataTable>
                    </div>
                </div>

                <!-- ── Tab : Droits ────────────────────────────────────────── -->
                <div v-show="activeTab === 'droits'">
                    <div
                        class="overflow-hidden rounded-xl border bg-card shadow-sm"
                    >
                        <!-- En-tête -->
                        <div
                            class="flex items-center justify-between border-b bg-muted/30 px-6 py-3"
                        >
                            <p class="text-sm font-medium">
                                Droits de validation des dépenses
                            </p>
                            <div class="flex items-center gap-3">
                                <transition name="fade">
                                    <span
                                        v-if="flashDroits"
                                        class="flex items-center gap-1.5 text-sm text-emerald-600 dark:text-emerald-400"
                                    >
                                        <CheckCheck class="h-4 w-4" />
                                        Sauvegardé
                                    </span>
                                </transition>
                                <Button
                                    size="sm"
                                    :disabled="savingDroits"
                                    @click="saveDroits"
                                >
                                    <Save class="mr-2 h-4 w-4" />
                                    {{
                                        savingDroits
                                            ? 'Enregistrement…'
                                            : 'Enregistrer'
                                    }}
                                </Button>
                            </div>
                        </div>

                        <!-- Table matrice -->
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr class="border-b bg-muted/20">
                                        <th
                                            class="px-6 py-4 text-left"
                                            style="min-width: 200px"
                                        >
                                            <span
                                                class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                                >Rôle</span
                                            >
                                        </th>
                                        <th
                                            v-for="action in ACTIONS"
                                            :key="action"
                                            class="px-8 py-4 text-center"
                                            style="width: 160px"
                                        >
                                            <div
                                                class="flex flex-col items-center gap-2"
                                            >
                                                <span
                                                    class="text-xs font-semibold tracking-wider uppercase"
                                                    :class="
                                                        actionMeta[action].color
                                                    "
                                                >
                                                    {{
                                                        actionMeta[action].label
                                                    }}
                                                </span>
                                                <!-- Bouton toggle colonne -->
                                                <button
                                                    type="button"
                                                    class="flex h-7 w-7 items-center justify-center rounded-md border-2 transition-all"
                                                    :class="[
                                                        columnState(action) ===
                                                        'none'
                                                            ? 'border-border bg-background hover:border-primary/60'
                                                            : columnState(
                                                                    action,
                                                                ) === 'all'
                                                              ? 'border-primary bg-primary text-primary-foreground'
                                                              : 'border-primary/60 bg-primary/10',
                                                    ]"
                                                    :title="`Tout ${columnState(action) === 'all' ? 'désactiver' : 'activer'} — ${actionMeta[action].label}`"
                                                    @click="
                                                        toggleColumn(action)
                                                    "
                                                >
                                                    <Check
                                                        v-if="
                                                            columnState(
                                                                action,
                                                            ) === 'all'
                                                        "
                                                        class="h-4 w-4"
                                                    />
                                                    <Minus
                                                        v-else-if="
                                                            columnState(
                                                                action,
                                                            ) === 'partial'
                                                        "
                                                        class="h-4 w-4 text-primary"
                                                    />
                                                </button>
                                            </div>
                                        </th>
                                        <!-- Colonne portée validation -->
                                        <th
                                            class="px-6 py-4 text-left"
                                            style="min-width: 220px"
                                        >
                                            <span
                                                class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                                >De quelles agences peut-il
                                                valider ?</span
                                            >
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <template
                                        v-for="entry in droitsForm"
                                        :key="entry.role_name"
                                    >
                                        <!-- Ligne principale -->
                                        <tr
                                            class="border-b transition-colors"
                                            :class="
                                                isAdminRole(entry.role_name)
                                                    ? 'bg-muted/10'
                                                    : 'hover:bg-muted/20'
                                            "
                                        >
                                            <!-- Rôle -->
                                            <td class="px-6 py-4">
                                                <div
                                                    class="flex items-center gap-3"
                                                >
                                                    <div
                                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
                                                        :class="
                                                            isAdminRole(
                                                                entry.role_name,
                                                            )
                                                                ? 'bg-blue-50 dark:bg-blue-950/40'
                                                                : 'bg-muted/40'
                                                        "
                                                    >
                                                        <Shield
                                                            v-if="
                                                                isAdminRole(
                                                                    entry.role_name,
                                                                )
                                                            "
                                                            class="h-4 w-4 text-blue-500"
                                                        />
                                                        <Lock
                                                            v-else
                                                            class="h-4 w-4 text-muted-foreground"
                                                        />
                                                    </div>
                                                    <div>
                                                        <span
                                                            class="text-sm font-medium"
                                                            >{{
                                                                roleLabel(
                                                                    entry.role_name,
                                                                )
                                                            }}</span
                                                        >
                                                        <p
                                                            v-if="
                                                                isAdminRole(
                                                                    entry.role_name,
                                                                )
                                                            "
                                                            class="text-xs text-muted-foreground"
                                                        >
                                                            Accès complet
                                                            automatique
                                                        </p>
                                                    </div>
                                                </div>
                                            </td>

                                            <!-- Cellule Validation -->
                                            <td class="px-8 py-4 text-center">
                                                <div
                                                    class="flex justify-center"
                                                >
                                                    <div
                                                        v-if="
                                                            isAdminRole(
                                                                entry.role_name,
                                                            )
                                                        "
                                                        class="flex h-5 w-5 items-center justify-center rounded border-2 border-primary bg-primary text-primary-foreground opacity-60"
                                                    >
                                                        <Check
                                                            class="h-3 w-3"
                                                        />
                                                    </div>
                                                    <button
                                                        v-else
                                                        type="button"
                                                        class="flex h-5 w-5 items-center justify-center rounded border-2 transition-all"
                                                        :class="
                                                            entry.peut_valider
                                                                ? 'border-primary bg-primary text-primary-foreground'
                                                                : 'border-border bg-background hover:border-primary/60'
                                                        "
                                                        @click="
                                                            toggle(
                                                                entry,
                                                                'peut_valider',
                                                            )
                                                        "
                                                    >
                                                        <Check
                                                            v-if="
                                                                entry.peut_valider
                                                            "
                                                            class="h-3 w-3"
                                                        />
                                                    </button>
                                                </div>
                                            </td>

                                            <!-- Portée -->
                                            <td class="px-6 py-4">
                                                <div
                                                    v-if="
                                                        isAdminRole(
                                                            entry.role_name,
                                                        )
                                                    "
                                                    class="text-xs text-muted-foreground italic"
                                                >
                                                    Toutes les agences
                                                </div>
                                                <button
                                                    v-else-if="
                                                        entry.peut_valider
                                                    "
                                                    type="button"
                                                    class="flex items-center gap-1.5 text-xs text-muted-foreground transition-colors hover:text-foreground"
                                                    @click="
                                                        togglePortee(
                                                            entry.role_name,
                                                        )
                                                    "
                                                >
                                                    <span class="font-medium">{{
                                                        perimetreLabel(entry)
                                                    }}</span>
                                                    <ChevronDown
                                                        v-if="
                                                            !isPorteeExpanded(
                                                                entry.role_name,
                                                            )
                                                        "
                                                        class="h-3.5 w-3.5"
                                                    />
                                                    <ChevronUp
                                                        v-else
                                                        class="h-3.5 w-3.5"
                                                    />
                                                </button>
                                                <span
                                                    v-else
                                                    class="text-xs text-muted-foreground/40"
                                                    >—</span
                                                >
                                            </td>
                                        </tr>

                                        <!-- Sous-ligne portée (expandable) -->
                                        <tr
                                            v-if="
                                                !isAdminRole(entry.role_name) &&
                                                entry.peut_valider &&
                                                isPorteeExpanded(
                                                    entry.role_name,
                                                )
                                            "
                                            :key="entry.role_name + '-portee'"
                                            class="border-b bg-muted/5"
                                        >
                                            <td colspan="3" class="px-8 py-4">
                                                <div class="space-y-3">
                                                    <!-- Sélecteur portée -->
                                                    <div class="space-y-1.5">
                                                        <p
                                                            class="text-xs font-medium text-muted-foreground"
                                                        >
                                                            De quelles agences
                                                            peut-il valider ?
                                                        </p>
                                                        <div
                                                            class="flex flex-wrap gap-2"
                                                        >
                                                            <button
                                                                type="button"
                                                                class="rounded-full border px-3 py-1 text-xs font-medium transition-colors"
                                                                :class="
                                                                    entry.perimetre ===
                                                                    'toutes_agences'
                                                                        ? 'border-primary bg-primary text-primary-foreground'
                                                                        : 'border-border text-muted-foreground hover:border-primary/40 hover:text-foreground'
                                                                "
                                                                @click="
                                                                    setPerimetre(
                                                                        entry,
                                                                        'toutes_agences',
                                                                    )
                                                                "
                                                            >
                                                                Toutes les
                                                                agences
                                                            </button>
                                                            <button
                                                                type="button"
                                                                class="rounded-full border px-3 py-1 text-xs font-medium transition-colors"
                                                                :class="
                                                                    entry.perimetre ===
                                                                    'son_agence'
                                                                        ? 'border-primary bg-primary text-primary-foreground'
                                                                        : 'border-border text-muted-foreground hover:border-primary/40 hover:text-foreground'
                                                                "
                                                                @click="
                                                                    setPerimetre(
                                                                        entry,
                                                                        'son_agence',
                                                                    )
                                                                "
                                                            >
                                                                Son agence
                                                                uniquement
                                                            </button>
                                                            <button
                                                                type="button"
                                                                class="rounded-full border px-3 py-1 text-xs font-medium transition-colors"
                                                                :class="
                                                                    entry.perimetre ===
                                                                    'agences_selectionnees'
                                                                        ? 'border-primary bg-primary text-primary-foreground'
                                                                        : 'border-border text-muted-foreground hover:border-primary/40 hover:text-foreground'
                                                                "
                                                                @click="
                                                                    setPerimetre(
                                                                        entry,
                                                                        'agences_selectionnees',
                                                                    )
                                                                "
                                                            >
                                                                Agences
                                                                sélectionnées
                                                            </button>
                                                        </div>
                                                    </div>

                                                    <!-- Grid agences -->
                                                    <div
                                                        v-if="
                                                            entry.perimetre ===
                                                            'agences_selectionnees'
                                                        "
                                                        class="rounded-lg border border-dashed bg-muted/20 px-4 py-3"
                                                    >
                                                        <div
                                                            v-if="
                                                                sites.length ===
                                                                0
                                                            "
                                                            class="text-xs text-muted-foreground italic"
                                                        >
                                                            Aucune agence
                                                            configurée.
                                                        </div>
                                                        <div
                                                            v-else
                                                            class="grid grid-cols-2 gap-1 sm:grid-cols-3 lg:grid-cols-4"
                                                        >
                                                            <button
                                                                v-for="site in sites"
                                                                :key="site.id"
                                                                type="button"
                                                                class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm transition-colors hover:bg-muted/50"
                                                                :class="
                                                                    hasSite(
                                                                        entry,
                                                                        site.id,
                                                                    )
                                                                        ? 'text-primary'
                                                                        : 'text-muted-foreground'
                                                                "
                                                                @click="
                                                                    toggleSite(
                                                                        entry,
                                                                        site.id,
                                                                    )
                                                                "
                                                            >
                                                                <div
                                                                    class="flex h-4 w-4 shrink-0 items-center justify-center rounded border-2 transition-all"
                                                                    :class="
                                                                        hasSite(
                                                                            entry,
                                                                            site.id,
                                                                        )
                                                                            ? 'border-primary bg-primary text-primary-foreground'
                                                                            : 'border-border'
                                                                    "
                                                                >
                                                                    <Check
                                                                        v-if="
                                                                            hasSite(
                                                                                entry,
                                                                                site.id,
                                                                            )
                                                                        "
                                                                        class="h-2.5 w-2.5"
                                                                    />
                                                                </div>
                                                                <span
                                                                    class="truncate text-xs"
                                                                    >{{
                                                                        site.nom
                                                                    }}</span
                                                                >
                                                                <span
                                                                    v-if="
                                                                        site.code
                                                                    "
                                                                    class="ml-auto shrink-0 font-mono text-xs opacity-50"
                                                                    >{{
                                                                        site.code
                                                                    }}</span
                                                                >
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <!-- Légende -->
                        <div
                            class="flex items-center gap-4 border-t bg-muted/20 px-6 py-3 text-xs text-muted-foreground"
                        >
                            <span class="flex items-center gap-1.5">
                                <span
                                    class="inline-block h-2 w-2 rounded-sm bg-primary"
                                />
                                Accordé
                            </span>
                            <span class="flex items-center gap-1.5">
                                <span
                                    class="inline-block h-2 w-2 rounded-sm border border-border bg-background"
                                />
                                Non accordé
                            </span>
                            <span class="flex items-center gap-1.5">
                                <Shield class="h-3 w-3 text-blue-500" />
                                Admin — accès automatique
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>

    <!-- Dialog Create / Edit type -->
    <Dialog
        v-model:visible="showDialog"
        modal
        :header="dialogTitle"
        :style="{ width: 'min(560px, 95vw)' }"
        :dismissable-mask="true"
    >
        <form class="space-y-4 pt-2 pb-1" @submit.prevent="handleSubmit">
            <div>
                <Label
                    for="dt-libelle"
                    class="mb-1.5 block text-xs font-medium"
                >
                    Libellé <span class="text-destructive">*</span>
                </Label>
                <Input
                    id="dt-libelle"
                    v-model="typeForm.libelle"
                    placeholder="ex: Carburant véhicule"
                    :class="{ 'border-destructive': typeForm.errors.libelle }"
                />
                <p
                    v-if="typeForm.errors.libelle"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ typeForm.errors.libelle }}
                </p>
            </div>

            <div>
                <Label
                    for="dt-categorie"
                    class="mb-1.5 block text-xs font-medium"
                >
                    Concerné <span class="text-destructive">*</span>
                </Label>
                <select
                    id="dt-categorie"
                    v-model="typeForm.categorie"
                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm"
                    :class="{ 'border-destructive': typeForm.errors.categorie }"
                >
                    <option
                        v-for="c in categories"
                        :key="c.value"
                        :value="c.value"
                    >
                        {{ c.label }}
                    </option>
                </select>
                <p
                    v-if="typeForm.errors.categorie"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ typeForm.errors.categorie }}
                </p>
            </div>

            <div>
                <Label
                    for="dt-description"
                    class="mb-1.5 block text-xs font-medium"
                    >Description</Label
                >
                <textarea
                    id="dt-description"
                    v-model="typeForm.description"
                    placeholder="Description optionnelle…"
                    rows="2"
                    class="flex min-h-[60px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                />
            </div>

            <div class="space-y-2.5 rounded-lg border bg-muted/30 p-3">
                <p class="text-xs font-medium text-muted-foreground">Options</p>
                <div class="flex items-center gap-3">
                    <Checkbox
                        id="dt-comment"
                        :model-value="typeForm.commentaire_obligatoire"
                        @update:model-value="
                            typeForm.commentaire_obligatoire = $event === true
                        "
                    />
                    <div>
                        <Label
                            for="dt-comment"
                            class="cursor-pointer text-sm font-medium"
                            >Commentaire obligatoire</Label
                        >
                        <p class="text-xs text-muted-foreground">
                            Un commentaire devra être saisi.
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <Checkbox
                        id="dt-justif"
                        :model-value="typeForm.justificatif_obligatoire"
                        @update:model-value="
                            typeForm.justificatif_obligatoire = $event === true
                        "
                    />
                    <div>
                        <Label
                            for="dt-justif"
                            class="cursor-pointer text-sm font-medium"
                            >Justificatif obligatoire</Label
                        >
                        <p class="text-xs text-muted-foreground">
                            Un justificatif (reçu, photo) devra être fourni.
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <Checkbox
                        id="dt-active"
                        :model-value="typeForm.is_active"
                        @update:model-value="
                            typeForm.is_active = $event === true
                        "
                    />
                    <div>
                        <Label
                            for="dt-active"
                            class="cursor-pointer text-sm font-medium"
                            >Actif</Label
                        >
                        <p class="text-xs text-muted-foreground">
                            Un type inactif ne peut pas être utilisé sur une
                            nouvelle dépense.
                        </p>
                    </div>
                </div>
            </div>

            <div class="flex justify-between pt-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="showDialog = false"
                    >Annuler</Button
                >
                <Button type="submit" size="sm" :disabled="typeForm.processing">
                    {{
                        typeForm.processing
                            ? 'Enregistrement…'
                            : editingType
                              ? 'Enregistrer'
                              : 'Créer'
                    }}
                </Button>
            </div>
        </form>
    </Dialog>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.3s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
