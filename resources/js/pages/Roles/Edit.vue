<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
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
import InputText from 'primevue/inputtext';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

interface RoleData {
    id: number;
    name: string;
    label: string;
    code: string | null;
    is_system: boolean;
    permissions: string[];
    users_count: number;
}

const props = defineProps<{
    role: RoleData;
    resources: string[];
    actions: string[];
}>();

const toast = useToast();

const resourceLabels: Record<string, string> = {
    // Personnes
    clients: 'Clients',
    prestataires: 'Prestataires',
    livreurs: 'Livreurs',
    proprietaires: 'Propriétaires',
    // Véhicules & terrain
    vehicules: 'Véhicules',
    'equipes-livraison': 'Équipes livraison',
    sites: 'Sites',
    // Commerce
    produits: 'Produits',
    packings: 'Packings',
    ventes: 'Ventes',
    achats: 'Achats',
    factures: 'Factures',
    commissions: 'Commissions',
    cashback: 'Cashback',
    pdv: 'PDV',
    // Opérations
    logistique: 'Logistique',
    transferts: 'Transferts',
    receptions: 'Réceptions',
    // Finances
    depenses: 'Dépenses',
    comptabilite: 'Comptabilité',
    'journal-financier': 'Journal financier',
    // RH
    'rh-employes': 'RH — Employés',
    'rh-contrats': 'RH — Contrats',
    'rh-paie': 'Paie / Salaires',
    // Administration
    users: 'Utilisateurs',
    // Paramètres
    parametres: 'Paramètres',
    'parametres-produits': 'Paramètres produits',
    'parametres-depenses': 'Paramètres dépenses',
    'parametres-ventes': 'Paramètres ventes',
    'parametres-systeme': 'Paramètres système',
    'modules-metier': 'Modules métier',
};

const actionLabels: Record<string, { label: string; color: string }> = {
    create: { label: 'Créer', color: 'text-emerald-600 dark:text-emerald-400' },
    read: { label: 'Lire', color: 'text-blue-600 dark:text-blue-400' },
    update: { label: 'Modifier', color: 'text-amber-600 dark:text-amber-400' },
    delete: { label: 'Supprimer', color: 'text-red-600 dark:text-red-400' },
};

const isSuperAdmin = computed(() => props.role.name === 'super_admin');

const activePermissions = ref<Set<string>>(
    new Set(
        isSuperAdmin.value
            ? props.resources.flatMap((r) =>
                  props.actions.map((a) => `${r}.${a}`),
              )
            : props.role.permissions,
    ),
);

const codeInput = ref(props.role.code ?? '');
const labelInput = ref(props.role.label);
const identityErrors = ref<{ code?: string; label?: string }>({});

const saving = ref(false);
const flashSuccess = ref(false);

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

const totalChecked = computed(() => activePermissions.value.size);
const totalPossible = computed(
    () => props.resources.length * props.actions.length,
);

function save() {
    saving.value = true;
    identityErrors.value = {};

    const payload: Record<string, unknown> = {
        permissions: [...activePermissions.value],
    };
    // Rôle protégé (super_admin) : ni libellé ni trinôme envoyés — le backend les rejette de
    // toute façon (règle "prohibited"), autant ne jamais les inclure côté client.
    if (!isSuperAdmin.value) {
        payload.label = labelInput.value;
        payload.code = codeInput.value || null;
    }

    router.put(`/backoffice/roles/${props.role.id}`, payload, {
        onSuccess: () => {
            flashSuccess.value = true;
            setTimeout(() => {
                flashSuccess.value = false;
            }, 3000);
            toast.add({
                severity: 'success',
                summary: 'Rôle mis à jour',
                life: 3000,
            });
        },
        onError: (errors) => {
            identityErrors.value = {
                code: errors.code,
                label: errors.label,
            };
        },
        onFinish: () => {
            saving.value = false;
        },
    });
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/backoffice/dashboard' },
    { title: 'Rôles & Permissions', href: '/backoffice/roles' },
    { title: role.label, href: '#' },
];
</script>

<template>
    <Head :title="`Permissions — ${role.label}`" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <div
            class="sticky top-0 z-10 flex items-center gap-3 border-b bg-background px-4 py-3 sm:hidden"
        >
            <Link href="/backoffice/roles">
                <Button variant="ghost" size="icon" class="h-8 w-8 shrink-0">
                    <ArrowLeft class="h-4 w-4" />
                </Button>
            </Link>
            <p class="flex-1 truncate text-center text-sm font-semibold">
                Permissions — {{ role.label }}
            </p>
            <div class="w-8 shrink-0" />
        </div>

        <div class="flex flex-col gap-6 p-4 sm:p-6">
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="flex items-center gap-4">
                    <Link href="/backoffice/roles">
                        <Button variant="ghost" size="icon" class="h-8 w-8">
                            <ArrowLeft class="h-4 w-4" />
                        </Button>
                    </Link>

                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-lg bg-muted"
                    >
                        <ShieldCheck
                            v-if="isSuperAdmin"
                            class="h-5 w-5 text-muted-foreground"
                        />
                        <Shield
                            v-else-if="role.name === 'admin_entreprise'"
                            class="h-5 w-5 text-muted-foreground"
                        />
                        <Users
                            v-else-if="role.name === 'commerciale'"
                            class="h-5 w-5 text-muted-foreground"
                        />
                        <Lock v-else class="h-5 w-5 text-muted-foreground" />
                    </div>

                    <div>
                        <div class="flex items-center gap-2">
                            <h1 class="text-xl font-semibold">
                                {{ role.label }}
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
            </div>

            <!-- Identité du rôle -->
            <div
                class="flex flex-col gap-4 rounded-xl border bg-card p-4 shadow-sm sm:flex-row sm:items-start sm:p-6"
            >
                <template v-if="!isSuperAdmin">
                    <div class="w-full sm:max-w-xs">
                        <Label for="role-label" class="mb-1.5 block text-xs"
                            >Nom du rôle</Label
                        >
                        <InputText
                            id="role-label"
                            v-model="labelInput"
                            class="w-full"
                            :class="{ 'p-invalid': identityErrors.label }"
                        />
                        <p
                            v-if="identityErrors.label"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ identityErrors.label }}
                        </p>
                    </div>

                    <div class="w-full max-w-[10rem]">
                        <Label for="role-code" class="mb-1.5 block text-xs"
                            >Trinôme / abréviation</Label
                        >
                        <InputText
                            id="role-code"
                            v-model="codeInput"
                            class="w-full"
                            :class="{ 'p-invalid': identityErrors.code }"
                            placeholder="Ex: PDG"
                            maxlength="10"
                        />
                        <p
                            v-if="identityErrors.code"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ identityErrors.code }}
                        </p>
                    </div>
                </template>
                <p v-else class="text-xs text-muted-foreground italic">
                    Rôle système — ni le nom ni le trinôme ne peuvent être
                    modifiés.
                </p>
            </div>

            <!-- Matrice des permissions -->
            <div
                class="overflow-hidden overflow-x-auto rounded-xl border bg-card shadow-sm"
            >
                <div
                    class="flex items-center justify-between border-b bg-muted/30 px-6 py-3"
                >
                    <p class="text-sm font-medium">Matrice des permissions</p>
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

                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b bg-muted/20">
                                <th class="w-52 px-6 py-4 text-left">
                                    <span
                                        class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                    >
                                        Ressource
                                    </span>
                                </th>
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
                                            :title="`Tout ${columnState(action) === 'all' ? 'désactiver' : 'activer'} — ${actionLabels[action]?.label}`"
                                            @click="toggleColumn(action)"
                                        >
                                            <CheckSquare
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
                                            <Square
                                                v-else
                                                class="h-4 w-4 text-muted-foreground/40"
                                            />
                                        </button>
                                    </div>
                                </th>
                            </tr>
                        </thead>

                        <tbody class="divide-y">
                            <tr
                                v-for="(resource, idx) in resources"
                                :key="resource"
                                class="group transition-colors hover:bg-muted/30"
                                :class="{ 'bg-muted/10': idx % 2 === 0 }"
                            >
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
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
                                            :title="`${rowState(resource) === 'all' ? 'Tout retirer' : 'Tout accorder'} — ${resourceLabels[resource] ?? resource}`"
                                            @click="toggleRow(resource)"
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
                    <p
                        v-if="isSuperAdmin"
                        class="text-xs text-muted-foreground italic"
                    >
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
