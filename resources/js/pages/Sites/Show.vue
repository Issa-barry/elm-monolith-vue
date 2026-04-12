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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay, phoneToTelHref } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Building2,
    ChevronRight,
    MailOpen,
    MoreVertical,
    Navigation,
    Pencil,
    RefreshCw,
    Shield,
    UserPlus,
    Users,
    XCircle,
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

// ── Types ─────────────────────────────────────────────────────────────────────

interface Enfant {
    id: number;
    nom: string;
    code: string;
    type_label: string;
    statut: string | null;
    statut_label: string;
}

interface Site {
    id: number;
    nom: string;
    code: string;
    type: string | null;
    type_label: string;
    statut: string | null;
    statut_label: string;
    localisation: string | null;
    pays: string | null;
    ville: string | null;
    quartier: string | null;
    description: string | null;
    parent_id: number | null;
    parent_nom: string | null;
    latitude: number | null;
    longitude: number | null;
    telephone: string | null;
    email: string | null;
    enfants: Enfant[];
}

interface Membre {
    id: number | null;
    type: 'user' | 'invitation';
    invitation_id: number | null;
    nom_complet: string | null;
    email: string;
    telephone: string | null;
    role: string | null;
    statut:
        | 'actif'
        | 'inactif'
        | 'en_attente'
        | 'expire'
        | 'pending'
        | 'revoked'
        | 'expired'
        | 'accepted';
    statut_label: string;
    date: string | null;
    can_resend: boolean;
    can_revoke: boolean;
}

interface RoleOption {
    value: string;
    label: string;
}

// ── Props ─────────────────────────────────────────────────────────────────────

const props = defineProps<{
    site: Site;
    membres: Membre[];
    roles_disponibles: RoleOption[];
    can_invite: boolean;
}>();

// ── Setup ─────────────────────────────────────────────────────────────────────

const { can } = usePermissions();
const toast = useToast();
const page = usePage();

const flashSuccess = computed(
    () => (page.props as any).flash?.success as string | undefined,
);
watch(flashSuccess, (msg) => {
    if (msg) {
        toast.add({
            severity: 'success',
            summary: 'Succès',
            detail: msg,
            life: 3000,
        });
    }
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Sites', href: '/sites' },
    { title: props.site.nom, href: '#' },
];

// ── Helpers ───────────────────────────────────────────────────────────────────

const FLAG_CODES: Record<string, string> = {
    Guinée: 'gn',
    'Guinée-Bissau': 'gw',
    Sénégal: 'sn',
    Mali: 'ml',
    "Côte d'Ivoire": 'ci',
    Liberia: 'lr',
    'Sierra Leone': 'sl',
    France: 'fr',
    Chine: 'cn',
    'Émirats arabes unis': 'ae',
    Inde: 'in',
};

const ROLE_LABELS: Record<string, string> = {
    super_admin: 'Super administrateur',
    admin_entreprise: 'Administrateur',
    manager: 'Manager',
    commerciale: 'Commercial(e)',
    comptable: 'Comptable',
};

const ROLE_COLORS: Record<string, string> = {
    super_admin:
        'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
    admin_entreprise:
        'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    manager:
        'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
    commerciale:
        'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
    comptable:
        'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
};

function flagUrl(pays: string) {
    const code = FLAG_CODES[pays];
    return code ? `https://flagcdn.com/20x15/${code}.png` : null;
}

function mapsUrl(lat: number, lng: number) {
    return `https://www.google.com/maps?q=${lat},${lng}`;
}

function roleLabel(role: string | null) {
    return role ? (ROLE_LABELS[role] ?? role) : '—';
}

function roleColor(role: string | null) {
    return role
        ? (ROLE_COLORS[role] ?? 'bg-muted text-muted-foreground')
        : 'bg-muted text-muted-foreground';
}

function initials(name: string | null) {
    if (!name) return '?';
    return name
        .trim()
        .split(/\s+/)
        .map((w) => w[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

// ── Membres : filtres ─────────────────────────────────────────────────────────

const search = ref('');
const statutFilter = ref<string>('tous');
const roleFilter = ref<string>('tous');
const filters = ref({ global: { value: '', matchMode: 'contains' } });

watch(search, (val) => {
    filters.value.global.value = val;
});

const filteredMembres = computed(() => {
    let list = [...props.membres];

    if (statutFilter.value !== 'tous') {
        list = list.filter((m) => {
            if (statutFilter.value === 'actif') return m.statut === 'actif';
            if (statutFilter.value === 'inactif') return m.statut === 'inactif';
            if (statutFilter.value === 'en_attente')
                return m.statut === 'pending';
            if (statutFilter.value === 'expire')
                return m.statut === 'expired' || m.statut === 'revoked';
            return true;
        });
    }

    if (roleFilter.value !== 'tous') {
        list = list.filter((m) => m.role === roleFilter.value);
    }

    return list;
});

function statutDotClass(statut: string) {
    switch (statut) {
        case 'actif':
            return 'bg-emerald-500';
        case 'pending':
            return 'bg-amber-400';
        case 'expired':
        case 'revoked':
            return 'bg-zinc-400';
        case 'inactif':
            return 'bg-zinc-400';
        default:
            return 'bg-zinc-300';
    }
}

// ── Invite Dialog ─────────────────────────────────────────────────────────────

const inviteDialogVisible = ref(false);

const inviteForm = useForm({
    email: '',
    role: '',
});

function openInviteDialog() {
    inviteForm.reset();
    inviteDialogVisible.value = true;
}

function submitInvite() {
    inviteForm.post(`/sites/${props.site.id}/invitations`, {
        preserveScroll: true,
        onSuccess: () => {
            inviteDialogVisible.value = false;
            inviteForm.reset();
        },
    });
}

// ── Actions ───────────────────────────────────────────────────────────────────

function resendInvitation(invitationId: number) {
    router.post(
        `/invitations/${invitationId}/resend`,
        {},
        {
            preserveScroll: true,
            onSuccess: () =>
                toast.add({
                    severity: 'success',
                    summary: 'Renvoyée',
                    detail: 'Invitation renvoyée avec succès.',
                    life: 3000,
                }),
        },
    );
}

function revokeInvitation(invitationId: number) {
    router.delete(`/invitations/${invitationId}`, {
        preserveScroll: true,
        onSuccess: () =>
            toast.add({
                severity: 'success',
                summary: 'Révoquée',
                detail: "L'invitation a été révoquée.",
                life: 3000,
            }),
    });
}
</script>

<template>
    <Head :title="site.nom" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- Mobile sticky header -->
        <div
            class="sticky top-0 z-10 flex items-center gap-3 border-b bg-background px-4 py-3 sm:hidden"
        >
            <Link href="/sites">
                <Button variant="ghost" size="icon" class="h-8 w-8 shrink-0">
                    <ArrowLeft class="h-4 w-4" />
                </Button>
            </Link>
            <p class="flex-1 truncate text-center text-sm font-semibold">
                {{ site.nom }}
            </p>
            <Link v-if="can('sites.update')" :href="`/sites/${site.id}/edit`">
                <Button variant="ghost" size="icon" class="h-8 w-8 shrink-0">
                    <Pencil class="h-4 w-4" />
                </Button>
            </Link>
            <div v-else class="w-8 shrink-0" />
        </div>

        <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 sm:p-6">
            <!-- ── Informations du site ──────────────────────────────────────── -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <!-- En-tête section -->
                <div
                    class="flex items-center justify-between gap-4 border-b px-5 py-4"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border bg-muted/40"
                        >
                            <Building2 class="h-4 w-4 text-muted-foreground" />
                        </div>
                        <div>
                            <h1 class="text-base leading-tight font-semibold">
                                {{ site.nom }}
                            </h1>
                            <p class="text-xs text-muted-foreground">
                                Informations du site
                            </p>
                        </div>
                    </div>
                    <Link
                        v-if="can('sites.update')"
                        :href="`/sites/${site.id}/edit`"
                    >
                        <Button variant="outline" size="sm">
                            <Pencil class="mr-1.5 h-3.5 w-3.5" />
                            Modifier
                        </Button>
                    </Link>
                </div>

                <!-- Table d'informations -->
                <div class="divide-y text-sm">
                    <!-- Nom -->
                    <div
                        class="grid grid-cols-[160px_1fr] items-center gap-4 px-5 py-3 sm:grid-cols-[200px_1fr]"
                    >
                        <span class="text-muted-foreground">Nom</span>
                        <span class="font-medium">{{ site.nom }}</span>
                    </div>

                    <!-- Code -->
                    <div
                        class="grid grid-cols-[160px_1fr] items-center gap-4 px-5 py-3 sm:grid-cols-[200px_1fr]"
                    >
                        <span class="text-muted-foreground">Code</span>
                        <span class="font-mono font-medium">{{
                            site.code
                        }}</span>
                    </div>

                    <!-- Type -->
                    <div
                        class="grid grid-cols-[160px_1fr] items-center gap-4 px-5 py-3 sm:grid-cols-[200px_1fr]"
                    >
                        <span class="text-muted-foreground">Type</span>
                        <span class="font-medium">{{ site.type_label }}</span>
                    </div>

                    <!-- Statut -->
                    <div
                        class="grid grid-cols-[160px_1fr] items-center gap-4 px-5 py-3 sm:grid-cols-[200px_1fr]"
                    >
                        <span class="text-muted-foreground">Statut</span>
                        <StatusDot
                            :label="site.statut_label"
                            :dot-class="
                                site.statut === 'active'
                                    ? 'bg-emerald-500'
                                    : 'bg-zinc-400'
                            "
                        />
                    </div>

                    <!-- Téléphone -->
                    <div
                        v-if="site.telephone"
                        class="grid grid-cols-[160px_1fr] items-center gap-4 px-5 py-3 sm:grid-cols-[200px_1fr]"
                    >
                        <span class="text-muted-foreground">Téléphone</span>
                        <a
                            :href="phoneToTelHref(site.telephone)"
                            class="font-medium text-primary hover:underline"
                        >
                            {{ formatPhoneDisplay(site.telephone) }}
                        </a>
                    </div>

                    <!-- Email -->
                    <div
                        v-if="site.email"
                        class="grid grid-cols-[160px_1fr] items-center gap-4 px-5 py-3 sm:grid-cols-[200px_1fr]"
                    >
                        <span class="text-muted-foreground">Email</span>
                        <a
                            :href="`mailto:${site.email}`"
                            class="font-medium text-primary hover:underline"
                        >
                            {{ site.email }}
                        </a>
                    </div>

                    <!-- Pays -->
                    <div
                        v-if="site.pays"
                        class="grid grid-cols-[160px_1fr] items-center gap-4 px-5 py-3 sm:grid-cols-[200px_1fr]"
                    >
                        <span class="text-muted-foreground">Pays</span>
                        <span class="flex items-center gap-2 font-medium">
                            <img
                                v-if="flagUrl(site.pays)"
                                :src="flagUrl(site.pays)!"
                                class="h-3.5 w-auto rounded-sm"
                            />
                            {{ site.pays }}
                        </span>
                    </div>

                    <!-- Ville -->
                    <div
                        v-if="site.ville"
                        class="grid grid-cols-[160px_1fr] items-center gap-4 px-5 py-3 sm:grid-cols-[200px_1fr]"
                    >
                        <span class="text-muted-foreground">Ville</span>
                        <span class="font-medium">{{ site.ville }}</span>
                    </div>

                    <!-- Quartier -->
                    <div
                        v-if="site.quartier"
                        class="grid grid-cols-[160px_1fr] items-center gap-4 px-5 py-3 sm:grid-cols-[200px_1fr]"
                    >
                        <span class="text-muted-foreground">Quartier</span>
                        <span class="font-medium">{{ site.quartier }}</span>
                    </div>

                    <!-- Adresse -->
                    <div
                        v-if="site.localisation"
                        class="grid grid-cols-[160px_1fr] items-start gap-4 px-5 py-3 sm:grid-cols-[200px_1fr]"
                    >
                        <span class="text-muted-foreground">Adresse</span>
                        <span class="leading-relaxed font-medium">{{
                            site.localisation
                        }}</span>
                    </div>

                    <!-- Coordonnées GPS -->
                    <div
                        v-if="site.latitude && site.longitude"
                        class="grid grid-cols-[160px_1fr] items-center gap-4 px-5 py-3 sm:grid-cols-[200px_1fr]"
                    >
                        <span class="text-muted-foreground">Coordonnées</span>
                        <a
                            :href="mapsUrl(site.latitude, site.longitude)"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center gap-1 font-mono text-xs text-primary hover:underline"
                        >
                            <Navigation class="h-3 w-3" />
                            {{ site.latitude }}, {{ site.longitude }}
                        </a>
                    </div>

                    <!-- Site parent -->
                    <div
                        v-if="site.parent_nom"
                        class="grid grid-cols-[160px_1fr] items-center gap-4 px-5 py-3 sm:grid-cols-[200px_1fr]"
                    >
                        <span class="text-muted-foreground">Site parent</span>
                        <Link
                            :href="`/sites/${site.parent_id}`"
                            class="inline-flex items-center gap-1.5 font-medium text-primary hover:underline"
                        >
                            <Building2 class="h-3.5 w-3.5 shrink-0" />
                            {{ site.parent_nom }}
                            <ChevronRight
                                class="h-3.5 w-3.5 text-muted-foreground"
                            />
                        </Link>
                    </div>

                    <!-- Description -->
                    <div
                        v-if="site.description"
                        class="grid grid-cols-[160px_1fr] items-start gap-4 px-5 py-3 sm:grid-cols-[200px_1fr]"
                    >
                        <span class="text-muted-foreground">Description</span>
                        <span
                            class="leading-relaxed whitespace-pre-line text-foreground/80"
                            >{{ site.description }}</span
                        >
                    </div>
                </div>
            </div>

            <!-- ── Sites enfants ─────────────────────────────────────────────── -->
            <div
                v-if="site.enfants.length > 0"
                class="overflow-hidden rounded-xl border bg-card"
            >
                <!-- En-tête -->
                <div class="flex items-center gap-2 border-b px-5 py-4">
                    <h2
                        class="flex items-center gap-2 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        <Building2 class="h-4 w-4" />
                        Sites enfants
                        <span
                            class="rounded-full bg-muted px-2 py-0.5 text-xs font-normal text-muted-foreground"
                            >{{ site.enfants.length }}</span
                        >
                    </h2>
                </div>

                <!-- Lignes enfants -->
                <div class="divide-y">
                    <Link
                        v-for="enfant in site.enfants"
                        :key="enfant.id"
                        :href="`/sites/${enfant.id}`"
                        class="flex items-center gap-3 px-5 py-3 transition-colors hover:bg-muted/40"
                    >
                        <div
                            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md border bg-muted/50"
                        >
                            <Building2
                                class="h-3.5 w-3.5 text-muted-foreground"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-sm font-medium">{{
                                enfant.nom
                            }}</span>
                            <span
                                class="ml-2 rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground"
                            >
                                {{ enfant.code }}
                            </span>
                        </div>
                        <span
                            class="hidden text-xs text-muted-foreground sm:inline"
                            >{{ enfant.type_label }}</span
                        >
                        <StatusDot
                            :label="enfant.statut_label"
                            :dot-class="
                                enfant.statut === 'active'
                                    ? 'bg-emerald-500'
                                    : 'bg-zinc-400'
                            "
                            class="shrink-0"
                        />
                        <ChevronRight
                            class="h-4 w-4 shrink-0 text-muted-foreground"
                        />
                    </Link>
                </div>
            </div>

            <!-- ── Membres du site ─────────────────────────────────────────── -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <!-- En-tête section -->
                <div
                    class="flex items-center justify-between gap-4 border-b px-5 py-4"
                >
                    <h2
                        class="flex items-center gap-2 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        <Users class="h-4 w-4" />
                        Membres du site
                        <span
                            class="rounded-full bg-muted px-2 py-0.5 text-xs font-normal text-muted-foreground"
                            >{{ membres.length }}</span
                        >
                    </h2>
                    <Button
                        v-if="can_invite"
                        size="sm"
                        @click="openInviteDialog"
                    >
                        <UserPlus class="mr-2 h-4 w-4" />
                        Inviter un membre
                    </Button>
                </div>

                <!-- Barre de filtres -->
                <div
                    class="flex flex-wrap items-center gap-3 border-b bg-muted/20 px-5 py-3"
                >
                    <!-- Recherche -->
                    <IconField class="max-w-xs flex-1">
                        <InputIcon class="pointer-events-none">
                            <svg
                                class="h-4 w-4 text-muted-foreground"
                                xmlns="http://www.w3.org/2000/svg"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                                />
                            </svg>
                        </InputIcon>
                        <InputText
                            v-model="search"
                            placeholder="Nom, email…"
                            class="w-full text-sm"
                        />
                    </IconField>

                    <!-- Filtre statut -->
                    <div class="flex flex-wrap gap-1">
                        <button
                            v-for="s in [
                                { value: 'tous', label: 'Tous' },
                                { value: 'actif', label: 'Actif' },
                                {
                                    value: 'en_attente',
                                    label: 'En attente',
                                },
                                {
                                    value: 'expire',
                                    label: 'Expirée/Révoquée',
                                },
                            ]"
                            :key="s.value"
                            type="button"
                            class="rounded-md px-3 py-1.5 text-xs font-medium transition-colors"
                            :class="
                                statutFilter === s.value
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-muted text-muted-foreground hover:bg-muted/80'
                            "
                            @click="statutFilter = s.value"
                        >
                            {{ s.label }}
                        </button>
                    </div>

                    <!-- Filtre rôle -->
                    <Select
                        v-model="roleFilter"
                        :options="[
                            { value: 'tous', label: 'Tous les rôles' },
                            ...roles_disponibles,
                        ]"
                        option-label="label"
                        option-value="value"
                        class="text-sm"
                        :pt="{
                            root: { class: 'h-9 min-w-[160px]' },
                            label: {
                                class: 'flex items-center py-0 text-sm',
                            },
                        }"
                    />
                </div>

                <!-- DataTable -->
                <DataTable
                    :value="filteredMembres"
                    :paginator="membres.length > 20"
                    :rows="20"
                    :global-filter-fields="['nom_complet', 'email', 'role']"
                    v-model:filters="filters"
                    data-key="email"
                    striped-rows
                    removable-sort
                    class="text-sm"
                    table-class="w-full"
                >
                    <!-- Membre (nom / email) -->
                    <Column
                        field="nom_complet"
                        header="Membre"
                        sortable
                        style="min-width: 200px"
                    >
                        <template #body="{ data }">
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                                    :class="
                                        data.type === 'user'
                                            ? 'bg-primary/10 text-primary'
                                            : 'bg-muted text-muted-foreground'
                                    "
                                >
                                    {{
                                        data.type === 'user'
                                            ? initials(data.nom_complet)
                                            : data.email.charAt(0).toUpperCase()
                                    }}
                                </div>
                                <div class="min-w-0">
                                    <div
                                        class="truncate font-medium"
                                        :class="
                                            data.type === 'invitation'
                                                ? 'text-muted-foreground italic'
                                                : ''
                                        "
                                    >
                                        {{
                                            data.nom_complet ??
                                            'Invitation en attente'
                                        }}
                                    </div>
                                    <div
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{ data.email }}
                                    </div>
                                </div>
                            </div>
                        </template>
                    </Column>

                    <!-- Téléphone -->
                    <Column
                        field="telephone"
                        header="Téléphone"
                        style="width: 160px"
                    >
                        <template #body="{ data }">
                            <span
                                v-if="data.telephone"
                                class="text-sm text-muted-foreground"
                            >
                                {{ formatPhoneDisplay(data.telephone) }}
                            </span>
                            <span v-else class="text-xs text-muted-foreground"
                                >—</span
                            >
                        </template>
                    </Column>

                    <!-- Rôle -->
                    <Column
                        field="role"
                        header="Rôle"
                        sortable
                        style="width: 160px"
                    >
                        <template #body="{ data }">
                            <span
                                v-if="data.role"
                                class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="roleColor(data.role)"
                            >
                                <Shield class="h-3 w-3" />
                                {{ roleLabel(data.role) }}
                            </span>
                            <span v-else class="text-xs text-muted-foreground"
                                >—</span
                            >
                        </template>
                    </Column>

                    <!-- Statut -->
                    <Column
                        field="statut_label"
                        header="Statut"
                        sortable
                        style="width: 140px"
                    >
                        <template #body="{ data }">
                            <StatusDot
                                :label="data.statut_label"
                                :dot-class="statutDotClass(data.statut)"
                                class="text-muted-foreground"
                            />
                        </template>
                    </Column>

                    <!-- Date -->
                    <Column
                        field="date"
                        header="Date"
                        sortable
                        style="width: 110px"
                    >
                        <template #body="{ data }">
                            <span class="text-xs text-muted-foreground">{{
                                data.date ?? '—'
                            }}</span>
                        </template>
                    </Column>

                    <!-- Actions -->
                    <Column header="" style="width: 56px">
                        <template #body="{ data }">
                            <div
                                v-if="
                                    data.type === 'invitation' &&
                                    (data.can_resend || data.can_revoke)
                                "
                                class="flex justify-end"
                            >
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
                                            v-if="data.can_resend"
                                            class="cursor-pointer"
                                            @click="
                                                resendInvitation(
                                                    data.invitation_id,
                                                )
                                            "
                                        >
                                            <RefreshCw class="h-4 w-4" />
                                            Renvoyer
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator
                                            v-if="
                                                data.can_resend &&
                                                data.can_revoke
                                            "
                                        />
                                        <DropdownMenuItem
                                            v-if="data.can_revoke"
                                            class="cursor-pointer text-destructive focus:text-destructive"
                                            @click="
                                                revokeInvitation(
                                                    data.invitation_id,
                                                )
                                            "
                                        >
                                            <XCircle class="h-4 w-4" />
                                            Révoquer
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </template>
                    </Column>

                    <template #empty>
                        <div
                            class="flex flex-col items-center gap-3 py-12 text-muted-foreground"
                        >
                            <MailOpen class="h-10 w-10 opacity-30" />
                            <p class="text-sm">Aucun membre trouvé.</p>
                            <Button
                                v-if="can_invite"
                                variant="outline"
                                size="sm"
                                @click="openInviteDialog"
                            >
                                <UserPlus class="mr-2 h-4 w-4" />
                                Inviter le premier membre
                            </Button>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>

        <!-- ── Dialog : Inviter un membre ─────────────────────────────────── -->
        <Dialog
            v-model:visible="inviteDialogVisible"
            modal
            draggable
            header="Inviter un membre"
            :style="{ width: '480px' }"
        >
            <form class="flex flex-col gap-5" @submit.prevent="submitInvite">
                <!-- Email -->
                <div class="grid gap-2">
                    <Label for="invite-email">
                        Adresse email <span class="text-destructive">*</span>
                    </Label>
                    <Input
                        id="invite-email"
                        v-model="inviteForm.email"
                        type="email"
                        autocomplete="off"
                        placeholder="prenom.nom@exemple.com"
                        autofocus
                    />
                    <p
                        v-if="inviteForm.errors.email"
                        class="text-sm text-destructive"
                    >
                        {{ inviteForm.errors.email }}
                    </p>
                </div>

                <!-- Rôle -->
                <div class="grid gap-2">
                    <Label for="invite-role">
                        Rôle <span class="text-destructive">*</span>
                    </Label>
                    <Select
                        v-model="inviteForm.role"
                        :options="roles_disponibles"
                        option-label="label"
                        option-value="value"
                        placeholder="Choisir un rôle"
                        class="w-full"
                        :pt="{
                            root: { class: 'h-10 w-full' },
                            label: { class: 'flex items-center py-0' },
                        }"
                    />
                    <p
                        v-if="inviteForm.errors.role"
                        class="text-sm text-destructive"
                    >
                        {{ inviteForm.errors.role }}
                    </p>
                </div>

                <p class="text-xs text-muted-foreground">
                    Un email avec un lien d'invitation valable
                    <strong>24 heures</strong> sera envoyé à cette adresse.
                </p>

                <div class="flex justify-end gap-3 pt-2">
                    <Button
                        type="button"
                        variant="outline"
                        @click="inviteDialogVisible = false"
                    >
                        Annuler
                    </Button>
                    <Button
                        type="submit"
                        :disabled="
                            inviteForm.processing ||
                            !inviteForm.email ||
                            !inviteForm.role
                        "
                    >
                        Envoyer l'invitation
                    </Button>
                </div>
            </form>
        </Dialog>
    </AppLayout>
</template>
