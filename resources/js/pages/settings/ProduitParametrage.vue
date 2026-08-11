<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { Head, router } from '@inertiajs/vue3';
import {
    Check,
    CheckCheck,
    ChevronDown,
    ChevronUp,
    Lock,
    Minus,
    Package,
    Save,
    Shield,
} from 'lucide-vue-next';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

interface SiteItem {
    id: string;
    nom: string;
    code: string;
}

interface RoleConfig {
    role_name: string;
    peut_augmenter: boolean;
    peut_diminuer: boolean;
    perimetre: 'toutes_agences' | 'agences_selectionnees';
    sites: string[];
}

const props = defineProps<{
    config: RoleConfig[];
    sites: SiteItem[];
}>();

const ADMIN_ROLES = new Set(['super_admin', 'admin_entreprise']);

function isAdminRole(name: string): boolean {
    return ADMIN_ROLES.has(name);
}

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

const ACTIONS = ['peut_augmenter', 'peut_diminuer'] as const;
type Action = (typeof ACTIONS)[number];

const actionMeta: Record<Action, { label: string; color: string }> = {
    peut_augmenter: {
        label: 'Augmenter',
        color: 'text-emerald-600 dark:text-emerald-400',
    },
    peut_diminuer: {
        label: 'Diminuer',
        color: 'text-red-600 dark:text-red-400',
    },
};

const form = ref<RoleConfig[]>(
    props.config.map((c) => ({ ...c, sites: [...c.sites] })),
);

const nonAdminRows = computed(() =>
    form.value.filter((r) => !isAdminRole(r.role_name)),
);

function isChecked(entry: RoleConfig, action: Action): boolean {
    return entry[action] as boolean;
}

function toggleAction(entry: RoleConfig, action: Action) {
    (entry[action] as boolean) = !entry[action];
    // Si plus aucune action active → réinitialiser la portée
    if (!entry.peut_augmenter && !entry.peut_diminuer) {
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
        const real = form.value.find((r) => r.role_name === entry.role_name)!;
        (real[action] as boolean) = state !== 'all';
        if (state === 'all' && !real.peut_augmenter && !real.peut_diminuer) {
            real.sites = [];
        }
    });
}

const expandedPortee = ref<Set<string>>(new Set());

function togglePortee(roleName: string) {
    if (expandedPortee.value.has(roleName))
        expandedPortee.value.delete(roleName);
    else expandedPortee.value.add(roleName);
}

function isPorteeExpanded(roleName: string) {
    return expandedPortee.value.has(roleName);
}

function hasAnyAction(entry: RoleConfig): boolean {
    return entry.peut_augmenter || entry.peut_diminuer;
}

function setPerimetre(
    entry: RoleConfig,
    p: 'toutes_agences' | 'agences_selectionnees',
) {
    entry.perimetre = p;
    if (p === 'toutes_agences') entry.sites = [];
}

function toggleSite(entry: RoleConfig, siteId: string) {
    const idx = entry.sites.indexOf(siteId);
    if (idx === -1) entry.sites.push(siteId);
    else entry.sites.splice(idx, 1);
}

function hasSite(entry: RoleConfig, siteId: string) {
    return entry.sites.includes(siteId);
}

const toast = useToast();
const saving = ref(false);
const flashSuccess = ref(false);

function save() {
    saving.value = true;
    router.put(
        '/settings/produits',
        { config: form.value },
        {
            onSuccess: () => {
                flashSuccess.value = true;
                setTimeout(() => (flashSuccess.value = false), 3000);
                toast.add({
                    severity: 'success',
                    summary: 'Paramètres produits mis à jour',
                    life: 3000,
                });
            },
            onFinish: () => (saving.value = false),
        },
    );
}
</script>

<template>
    <Head title="Paramètres produits" />

    <AppLayout>
        <SettingsLayout :wide="true">
            <div class="space-y-6">
                <HeadingSmall
                    title="Paramètres produits"
                    description="Configuration des droits et des périmètres agences pour le module Produits."
                />

                <div
                    class="overflow-hidden rounded-xl border bg-card shadow-sm"
                >
                    <!-- En-tête -->
                    <div
                        class="flex items-center justify-between border-b bg-muted/30 px-6 py-3"
                    >
                        <div class="flex items-center gap-2">
                            <Package class="h-4 w-4 text-muted-foreground" />
                            <p class="text-sm font-medium">
                                Droits d'ajustement de stock
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <transition name="fade">
                                <span
                                    v-if="flashSuccess"
                                    class="flex items-center gap-1.5 text-sm text-emerald-600 dark:text-emerald-400"
                                >
                                    <CheckCheck class="h-4 w-4" />
                                    Sauvegardé
                                </span>
                            </transition>
                            <Button size="sm" :disabled="saving" @click="save">
                                <Save class="mr-2 h-4 w-4" />
                                {{ saving ? 'Enregistrement…' : 'Enregistrer' }}
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
                                    <!-- Colonnes Augmenter / Diminuer avec toggle-all -->
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
                                                {{ actionMeta[action].label }}
                                            </span>
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
                                                @click="toggleColumn(action)"
                                            >
                                                <Check
                                                    v-if="
                                                        columnState(action) ===
                                                        'all'
                                                    "
                                                    class="h-4 w-4"
                                                />
                                                <Minus
                                                    v-else-if="
                                                        columnState(action) ===
                                                        'partial'
                                                    "
                                                    class="h-4 w-4 text-primary"
                                                />
                                            </button>
                                        </div>
                                    </th>
                                    <!-- Portée agences -->
                                    <th
                                        class="px-6 py-4 text-left"
                                        style="min-width: 160px"
                                    >
                                        <span
                                            class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                            >Portée agences</span
                                        >
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                <template
                                    v-for="entry in form"
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

                                        <!-- Cellule Augmenter -->
                                        <td class="px-8 py-4 text-center">
                                            <div class="flex justify-center">
                                                <div
                                                    v-if="
                                                        isAdminRole(
                                                            entry.role_name,
                                                        )
                                                    "
                                                    class="flex h-5 w-5 items-center justify-center rounded border-2 border-primary bg-primary text-primary-foreground opacity-60"
                                                >
                                                    <Check class="h-3 w-3" />
                                                </div>
                                                <button
                                                    v-else
                                                    type="button"
                                                    class="flex h-5 w-5 items-center justify-center rounded border-2 transition-all"
                                                    :class="
                                                        entry.peut_augmenter
                                                            ? 'border-emerald-500 bg-emerald-500 text-white'
                                                            : 'border-border bg-background hover:border-emerald-400/60'
                                                    "
                                                    @click="
                                                        toggleAction(
                                                            entry,
                                                            'peut_augmenter',
                                                        )
                                                    "
                                                >
                                                    <Check
                                                        v-if="
                                                            entry.peut_augmenter
                                                        "
                                                        class="h-3 w-3"
                                                    />
                                                </button>
                                            </div>
                                        </td>

                                        <!-- Cellule Diminuer -->
                                        <td class="px-8 py-4 text-center">
                                            <div class="flex justify-center">
                                                <div
                                                    v-if="
                                                        isAdminRole(
                                                            entry.role_name,
                                                        )
                                                    "
                                                    class="flex h-5 w-5 items-center justify-center rounded border-2 border-primary bg-primary text-primary-foreground opacity-60"
                                                >
                                                    <Check class="h-3 w-3" />
                                                </div>
                                                <button
                                                    v-else
                                                    type="button"
                                                    class="flex h-5 w-5 items-center justify-center rounded border-2 transition-all"
                                                    :class="
                                                        entry.peut_diminuer
                                                            ? 'border-red-500 bg-red-500 text-white'
                                                            : 'border-border bg-background hover:border-red-400/60'
                                                    "
                                                    @click="
                                                        toggleAction(
                                                            entry,
                                                            'peut_diminuer',
                                                        )
                                                    "
                                                >
                                                    <Check
                                                        v-if="
                                                            entry.peut_diminuer
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
                                                    isAdminRole(entry.role_name)
                                                "
                                                class="text-xs text-muted-foreground italic"
                                            >
                                                Toutes les agences
                                            </div>
                                            <button
                                                v-else-if="hasAnyAction(entry)"
                                                type="button"
                                                class="flex items-center gap-1.5 text-xs text-muted-foreground transition-colors hover:text-foreground"
                                                @click="
                                                    togglePortee(
                                                        entry.role_name,
                                                    )
                                                "
                                            >
                                                <span class="font-medium">{{
                                                    entry.perimetre ===
                                                    'toutes_agences'
                                                        ? 'Toutes les agences'
                                                        : `${entry.sites.length} agence(s)`
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
                                            hasAnyAction(entry) &&
                                            isPorteeExpanded(entry.role_name)
                                        "
                                        :key="entry.role_name + '-portee'"
                                        class="border-b bg-muted/5"
                                    >
                                        <td colspan="4" class="px-8 py-4">
                                            <div class="space-y-3">
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
                                                        Toutes les agences
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
                                                        Agences sélectionnées
                                                    </button>
                                                </div>

                                                <div
                                                    v-if="
                                                        entry.perimetre ===
                                                        'agences_selectionnees'
                                                    "
                                                    class="rounded-lg border border-dashed bg-muted/20 px-4 py-3"
                                                >
                                                    <div
                                                        v-if="
                                                            sites.length === 0
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
                                                                v-if="site.code"
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
                                class="inline-block h-2 w-2 rounded-sm bg-emerald-500"
                            />
                            Augmenter accordé
                        </span>
                        <span class="flex items-center gap-1.5">
                            <span
                                class="inline-block h-2 w-2 rounded-sm bg-red-500"
                            />
                            Diminuer accordé
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
        </SettingsLayout>
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
