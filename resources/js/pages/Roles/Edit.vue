<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Check,
    CheckCheck,
    CheckSquare,
    Lock,
    Minus,
    Save,
    Shield,
    ShieldCheck,
    Square,
    Users,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

// ── Props ─────────────────────────────────────────────────────────────────────
interface RoleData {
    id: number;
    name: string;
    permissions: string[];
    users_count: number;
}

const props = defineProps<{
    role: RoleData;
    resources: string[];
    actions: string[];
}>();

// ── Config d'affichage ────────────────────────────────────────────────────────
const roleConfig: Record<string, { label: string; color: string; bg: string }> =
    {
        super_admin: {
            label: 'Super Admin',
            color: 'text-violet-600 dark:text-violet-400',
            bg: 'bg-violet-50 dark:bg-violet-950/40',
        },
        admin_entreprise: {
            label: 'Admin Entreprise',
            color: 'text-blue-600 dark:text-blue-400',
            bg: 'bg-blue-50 dark:bg-blue-950/40',
        },
        commerciale: {
            label: 'Commerciale',
            color: 'text-emerald-600 dark:text-emerald-400',
            bg: 'bg-emerald-50 dark:bg-emerald-950/40',
        },
        comptable: {
            label: 'Comptable',
            color: 'text-amber-600 dark:text-amber-400',
            bg: 'bg-amber-50 dark:bg-amber-950/40',
        },
    };

const resourceLabels: Record<string, string> = {
    clients: 'Clients',
    prestataires: 'Prestataires',
    livreurs: 'Livreurs',
    proprietaires: 'Propriétaires',
    vehicules: 'Véhicules',
    'equipes-livraison': 'Équipes livraison',
    sites: 'Sites',
    produits: 'Produits',
    packings: 'Packings',
    ventes: 'Ventes',
    achats: 'Achats',
    users: 'Utilisateurs',
    parametres: 'Paramètres',
};

const actionLabels: Record<string, { label: string; color: string }> = {
    create: { label: 'Créer', color: 'text-emerald-600 dark:text-emerald-400' },
    read: { label: 'Lire', color: 'text-blue-600 dark:text-blue-400' },
    update: { label: 'Modifier', color: 'text-amber-600 dark:text-amber-400' },
    delete: { label: 'Supprimer', color: 'text-red-600 dark:text-red-400' },
};

// ── État ──────────────────────────────────────────────────────────────────────
const isSuperAdmin = computed(() => props.role.name === 'super_admin');
const cfg = computed(
    () =>
        roleConfig[props.role.name] ?? {
            label: props.role.name,
            color: 'text-muted-foreground',
            bg: 'bg-muted',
        },
);

const activePermissions = ref<Set<string>>(
    new Set(
        isSuperAdmin.value
            ? props.resources.flatMap((r) =>
                  props.actions.map((a) => `${r}.${a}`),
              )
            : props.role.permissions,
    ),
);

const saving = ref(false);
const flashSuccess = ref(false);

// ── Helpers ───────────────────────────────────────────────────────────────────
function permKey(resource: string, action: string) {
    return `${resource}.${action}`;
}

function isChecked(resource: string, action: string): boolean {
    return activePermissions.value.has(permKey(resource, action));
}

function toggle(resource: string, action: string) {
    if (isSuperAdmin.value) return;
    const key = permKey(resource, action);
    const next = new Set(activePermissions.value);
    if (next.has(key)) next.delete(key);
    else next.add(key);
    activePermissions.value = next;
}

// ── État colonne (all | partial | none) ───────────────────────────────────────
type ColState = 'all' | 'partial' | 'none';

function columnState(action: string): ColState {
    const checked = props.resources.filter((r) => isChecked(r, action)).length;
    if (checked === 0) return 'none';
    if (checked === props.resources.length) return 'all';
    return 'partial';
}

function toggleColumn(action: string) {
    if (isSuperAdmin.value) return;
    const state = columnState(action);
    const next = new Set(activePermissions.value);
    props.resources.forEach((r) => {
        const key = permKey(r, action);
        if (state === 'all') next.delete(key);
        else next.add(key);
    });
    activePermissions.value = next;
}

function _columnCheckedValue(action: string): boolean | 'indeterminate' {
    const s = columnState(action);
    if (s === 'all') return true;
    if (s === 'partial') return 'indeterminate';
    return false;
}

// ── État ligne (all | partial | none) ────────────────────────────────────────
function rowState(resource: string): ColState {
    const checked = props.actions.filter((a) => isChecked(resource, a)).length;
    if (checked === 0) return 'none';
    if (checked === props.actions.length) return 'all';
    return 'partial';
}

function toggleRow(resource: string) {
    if (isSuperAdmin.value) return;
    const state = rowState(resource);
    const next = new Set(activePermissions.value);
    props.actions.forEach((a) => {
        const key = permKey(resource, a);
        if (state === 'all') next.delete(key);
        else next.add(key);
    });
    activePermissions.value = next;
}

// ── Stats globales ────────────────────────────────────────────────────────────
const totalChecked = computed(() => activePermissions.value.size);
const totalPossible = computed(
    () => props.resources.length * props.actions.length,
);

// ── Sauvegarde ────────────────────────────────────────────────────────────────
function save() {
    if (isSuperAdmin.value) return;
    saving.value = true;

    router.put(
        `/roles/${props.role.id}`,
        {
            permissions: [...activePermissions.value],
        },
        {
            onSuccess: () => {
                flashSuccess.value = true;
                setTimeout(() => {
                    flashSuccess.value = false;
                }, 3000);
            },
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Rôles & Permissions', href: '/roles' },
    { title: cfg.value.label, href: '#' },
];
</script>

<template>
    <Head :title="`Permissions — ${cfg.label}`" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- Mobile sticky header -->
        <div
            class="sticky top-0 z-10 flex items-center gap-3 border-b bg-background px-4 py-3 sm:hidden"
        >
            <Link href="/roles">
                <Button variant="ghost" size="icon" class="h-8 w-8 shrink-0">
                    <ArrowLeft class="h-4 w-4" />
                </Button>
            </Link>
            <p class="flex-1 truncate text-center text-sm font-semibold">
                Permissions — {{ cfg.label }}
            </p>
            <div class="w-8 shrink-0" />
        </div>

        <div class="flex flex-col gap-6 p-4 sm:p-6">
            <!-- En-tête ─────────────────────────────────────────────────────── -->
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="flex items-center gap-4">
                    <Link href="/roles">
                        <Button variant="ghost" size="icon" class="h-8 w-8">
                            <ArrowLeft class="h-4 w-4" />
                        </Button>
                    </Link>

                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-lg"
                        :class="cfg.bg"
                    >
                        <ShieldCheck
                            v-if="role.name === 'super_admin'"
                            class="h-5 w-5"
                            :class="cfg.color"
                        />
                        <Shield
                            v-else-if="role.name === 'admin_entreprise'"
                            class="h-5 w-5"
                            :class="cfg.color"
                        />
                        <Users
                            v-else-if="role.name === 'commerciale'"
                            class="h-5 w-5"
                            :class="cfg.color"
                        />
                        <Lock v-else class="h-5 w-5" :class="cfg.color" />
                    </div>

                    <div>
                        <div class="flex items-center gap-2">
                            <h1 class="text-xl font-semibold">
                                {{ cfg.label }}
                            </h1>
                            <Badge
                                v-if="isSuperAdmin"
                                variant="secondary"
                                class="text-[10px] text-violet-600 dark:text-violet-400"
                            >
                                Protégé · Bypass automatique
                            </Badge>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            {{ role.users_count }}
                            {{
                                role.users_count <= 1
                                    ? 'utilisateur'
                                    : 'utilisateurs'
                            }}
                            ·
                            <span class="font-medium">{{ totalChecked }}</span>
                            / {{ totalPossible }} permissions actives
                        </p>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex items-center gap-2">
                    <!-- Flash succès -->
                    <transition name="fade">
                        <span
                            v-if="flashSuccess"
                            class="flex items-center gap-1.5 text-sm text-emerald-600 dark:text-emerald-400"
                        >
                            <CheckCheck class="h-4 w-4" />
                            Sauvegardé
                        </span>
                    </transition>

                    <Button
                        v-if="!isSuperAdmin"
                        :disabled="saving"
                        @click="save"
                    >
                        <Save class="mr-2 h-4 w-4" />
                        {{ saving ? 'Enregistrement…' : 'Enregistrer' }}
                    </Button>
                </div>
            </div>

            <!-- Matrice de permissions ──────────────────────────────────────── -->
            <div
                class="overflow-hidden overflow-x-auto rounded-xl border bg-card shadow-sm"
            >
                <!-- Titre matrice -->
                <div
                    class="flex items-center justify-between border-b bg-muted/30 px-6 py-3"
                >
                    <p class="text-sm font-medium">Matrice des permissions</p>
                    <p class="text-xs text-muted-foreground">
                        Cochez les actions autorisées par ressource
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full">
                        <!-- En-têtes colonnes ─────────────────────────────── -->
                        <thead>
                            <tr class="border-b bg-muted/20">
                                <!-- Colonne ressource -->
                                <th class="w-52 px-6 py-4 text-left">
                                    <span
                                        class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                    >
                                        Ressource
                                    </span>
                                </th>

                                <!-- Colonnes actions -->
                                <th
                                    v-for="action in actions"
                                    :key="action"
                                    class="px-4 py-4 text-center"
                                >
                                    <div
                                        class="flex flex-col items-center gap-2"
                                    >
                                        <span
                                            class="text-xs font-semibold tracking-wider uppercase"
                                            :class="
                                                actionLabels[action]?.color ??
                                                'text-muted-foreground'
                                            "
                                        >
                                            {{
                                                actionLabels[action]?.label ??
                                                action
                                            }}
                                        </span>

                                        <!-- Checkbox toggle-colonne -->
                                        <button
                                            class="group flex h-7 w-7 items-center justify-center rounded-md border-2 transition-all"
                                            :class="[
                                                isSuperAdmin
                                                    ? 'cursor-default border-muted opacity-60'
                                                    : 'cursor-pointer hover:border-primary/60',
                                                columnState(action) === 'none'
                                                    ? 'border-border bg-background'
                                                    : columnState(action) ===
                                                        'all'
                                                      ? 'border-primary bg-primary text-primary-foreground'
                                                      : 'border-primary/60 bg-primary/10',
                                            ]"
                                            :disabled="isSuperAdmin"
                                            @click="toggleColumn(action)"
                                            :title="`Tout ${columnState(action) === 'all' ? 'désactiver' : 'activer'} — ${actionLabels[action]?.label}`"
                                        >
                                            <!-- État: tout coché -->
                                            <CheckSquare
                                                v-if="
                                                    columnState(action) ===
                                                    'all'
                                                "
                                                class="h-4 w-4"
                                            />
                                            <!-- État: partiel -->
                                            <Minus
                                                v-else-if="
                                                    columnState(action) ===
                                                    'partial'
                                                "
                                                class="h-4 w-4 text-primary"
                                            />
                                            <!-- État: aucun -->
                                            <Square
                                                v-else
                                                class="h-4 w-4 text-muted-foreground/40"
                                            />
                                        </button>
                                    </div>
                                </th>
                            </tr>
                        </thead>

                        <!-- Lignes ressources ────────────────────────────── -->
                        <tbody class="divide-y">
                            <tr
                                v-for="(resource, idx) in resources"
                                :key="resource"
                                class="group transition-colors hover:bg-muted/30"
                                :class="{ 'bg-muted/10': idx % 2 === 0 }"
                            >
                                <!-- Nom de la ressource + toggle ligne -->
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <!-- Bouton toggle-ligne (style Strapi) -->
                                        <button
                                            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md border-2 transition-all"
                                            :class="[
                                                isSuperAdmin
                                                    ? 'cursor-default opacity-60'
                                                    : 'cursor-pointer',
                                                rowState(resource) === 'none'
                                                    ? 'border-border bg-background hover:border-primary/40'
                                                    : rowState(resource) ===
                                                        'all'
                                                      ? 'border-primary bg-primary text-primary-foreground'
                                                      : 'border-primary/60 bg-primary/10 hover:border-primary',
                                            ]"
                                            :disabled="isSuperAdmin"
                                            @click="toggleRow(resource)"
                                            :title="`${rowState(resource) === 'all' ? 'Tout retirer' : 'Tout accorder'} — ${resourceLabels[resource] ?? resource}`"
                                        >
                                            <Minus
                                                v-if="
                                                    rowState(resource) !==
                                                    'none'
                                                "
                                                class="h-3.5 w-3.5"
                                                :class="
                                                    rowState(resource) === 'all'
                                                        ? 'text-primary-foreground'
                                                        : 'text-primary'
                                                "
                                            />
                                        </button>

                                        <span class="text-sm font-medium">
                                            {{
                                                resourceLabels[resource] ??
                                                resource
                                            }}
                                        </span>
                                    </div>
                                </td>

                                <!-- Cellules permission individuelle -->
                                <td
                                    v-for="action in actions"
                                    :key="action"
                                    class="px-4 py-4 text-center"
                                >
                                    <div class="flex justify-center">
                                        <button
                                            class="flex h-5 w-5 items-center justify-center rounded border-2 transition-all"
                                            :class="[
                                                isSuperAdmin
                                                    ? 'cursor-default opacity-70'
                                                    : 'cursor-pointer',
                                                isChecked(resource, action)
                                                    ? 'border-primary bg-primary text-primary-foreground'
                                                    : 'border-border bg-background hover:border-primary/60',
                                            ]"
                                            :disabled="isSuperAdmin"
                                            @click="toggle(resource, action)"
                                        >
                                            <Check
                                                v-if="
                                                    isChecked(resource, action)
                                                "
                                                class="h-3 w-3"
                                            />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pied de tableau -->
                <div
                    class="flex items-center justify-between border-t bg-muted/20 px-6 py-3"
                >
                    <div
                        class="flex items-center gap-4 text-xs text-muted-foreground"
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
                            <span
                                class="inline-block h-2 w-2 rounded-sm border-2 border-primary/60 bg-primary/10"
                            />
                            Partiel
                        </span>
                    </div>

                    <Button
                        v-if="!isSuperAdmin"
                        :disabled="saving"
                        @click="save"
                    >
                        <Save class="mr-2 h-4 w-4" />
                        {{
                            saving
                                ? 'Enregistrement…'
                                : 'Enregistrer les modifications'
                        }}
                    </Button>

                    <p v-else class="text-xs text-muted-foreground italic">
                        Les permissions du Super Admin sont gérées
                        automatiquement.
                    </p>
                </div>
            </div>
        </div>
    </AppLayout>
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
