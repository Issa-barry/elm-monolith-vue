<script setup lang="ts">
import DetailHeader from '@/components/DetailHeader.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { useFlashToast } from '@/composables/useFlashToast';
import { useUrlTab } from '@/composables/useUrlTab';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import type {
    AgentDepensesData,
    AgentFiche,
    AgentSituationData,
} from '@/types/agent-fiche';
import type { SituationPeriode } from '@/types/situation';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CircleHelp,
    KeyRound,
    Pencil,
    Receipt,
    TrendingUp,
    UserRound,
} from 'lucide-vue-next';
import { computed } from 'vue';
import AgentDepensesTab from './partials/AgentDepensesTab.vue';
import AgentMotDePasseTab from './partials/AgentMotDePasseTab.vue';
import AgentSituationTab from './partials/AgentSituationTab.vue';

// Chaque onglet sensible arrive à null quand le consulteur n'y a pas droit (calculé côté serveur).
const props = defineProps<{
    user: AgentFiche;
    is_me: boolean;
    peut_modifier: boolean;
    situation: AgentSituationData | null;
    situation_periode: SituationPeriode;
    lien_rapport: string | null;
    depenses: AgentDepensesData | null;
}>();

useFlashToast();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptes', href: '/backoffice/comptes' },
    { title: props.user.nom_complet, href: '#' },
];

type Onglet = 'informations' | 'mot-de-passe' | 'situation' | 'depenses';

const ONGLETS: Onglet[] = [
    'informations',
    ...(props.peut_modifier ? (['mot-de-passe'] as const) : []),
    ...(props.situation ? (['situation'] as const) : []),
    ...(props.depenses ? (['depenses'] as const) : []),
];

const { onglet: activeTab, choisir: choisirOnglet } = useUrlTab<Onglet>(
    ONGLETS,
    'informations',
);

// Même rendu que la fiche client pour l'en-tête ; codes lus par StatusDot dans la carte Statut.
const statut = computed(() => {
    if (props.user.is_pending_validation) {
        return {
            code: 'pending_validation',
            label: 'En attente de validation',
            point: 'bg-orange-500',
        };
    }

    return props.user.is_active
        ? { code: 'actif', label: 'Actif', point: 'bg-emerald-500' }
        : {
              code: 'inactif',
              label: 'Inactif',
              point: 'bg-zinc-400 dark:bg-zinc-500',
          };
});

const telephone = computed(() =>
    props.user.telephone
        ? formatPhoneDisplay(props.user.telephone, props.user.code_phone_pays)
        : null,
);

const localisation = computed(
    () =>
        [props.user.adresse, props.user.ville, props.user.pays]
            .filter(Boolean)
            .join(', ') || null,
);

const classeOnglet = (cible: Onglet) =>
    activeTab.value === cible
        ? 'bg-primary text-primary-foreground'
        : 'text-muted-foreground hover:bg-muted';
</script>

<template>
    <Head :title="user.nom_complet + ' — Fiche agent'" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <DetailHeader
                eyebrow="Agent"
                :title="user.nom_complet"
                :icon="UserRound"
                :status-label="statut.label"
                :status-dot-class="statut.point"
                data-testid="agent-detail-header"
            >
                <template #subtitle>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <span
                            v-if="user.role_label"
                            class="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium"
                            data-testid="agent-role-badge"
                        >
                            {{ user.role_label }}
                        </span>
                        <span
                            v-if="user.matricule"
                            class="rounded bg-muted px-2 py-0.5 font-mono text-[11px] text-muted-foreground"
                        >
                            {{ user.matricule }}
                        </span>
                        <span
                            v-if="telephone"
                            class="text-sm text-muted-foreground"
                        >
                            {{ telephone }}
                        </span>
                        <span
                            v-if="is_me"
                            class="rounded bg-muted px-1.5 py-0.5 text-[10px]"
                        >
                            Moi
                        </span>
                    </div>
                </template>

                <template #actions>
                    <Link href="/backoffice/comptes">
                        <Button variant="outline" size="sm">
                            <ArrowLeft class="mr-1.5 h-4 w-4" />
                            Liste des comptes
                        </Button>
                    </Link>
                </template>
            </DetailHeader>

            <div class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)]">
                <aside
                    class="grid h-fit grid-cols-2 gap-2 rounded-xl border bg-card p-2 lg:block lg:space-y-2"
                    aria-label="Sections de la fiche agent"
                    data-testid="agent-detail-navigation"
                >
                    <button
                        type="button"
                        class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                        :class="classeOnglet('informations')"
                        data-testid="agent-informations-tab"
                        @click="choisirOnglet('informations')"
                    >
                        <span class="inline-flex items-center gap-2">
                            <CircleHelp class="h-4 w-4" />
                            Informations
                        </span>
                    </button>

                    <button
                        v-if="peut_modifier"
                        type="button"
                        class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                        :class="classeOnglet('mot-de-passe')"
                        data-testid="agent-mot-de-passe-tab"
                        @click="choisirOnglet('mot-de-passe')"
                    >
                        <span class="inline-flex items-center gap-2">
                            <KeyRound class="h-4 w-4" />
                            Mot de passe
                        </span>
                    </button>

                    <button
                        v-if="situation"
                        type="button"
                        class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                        :class="classeOnglet('situation')"
                        data-testid="agent-situation-tab"
                        @click="choisirOnglet('situation')"
                    >
                        <span class="inline-flex items-center gap-2">
                            <TrendingUp class="h-4 w-4" />
                            Situation
                        </span>
                    </button>

                    <button
                        v-if="depenses"
                        type="button"
                        class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                        :class="classeOnglet('depenses')"
                        data-testid="agent-depenses-tab"
                        @click="choisirOnglet('depenses')"
                    >
                        <span class="inline-flex items-center gap-2">
                            <Receipt class="h-4 w-4" />
                            Dépenses
                        </span>
                        <span
                            class="inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-[11px]"
                            :class="
                                activeTab === 'depenses'
                                    ? 'bg-white/20 text-primary-foreground'
                                    : 'bg-muted text-muted-foreground'
                            "
                        >
                            {{ depenses.resume.nombre }}
                        </span>
                    </button>
                </aside>

                <AgentMotDePasseTab
                    v-if="activeTab === 'mot-de-passe' && peut_modifier"
                    :agent-id="user.id"
                />

                <AgentSituationTab
                    v-else-if="activeTab === 'situation' && situation"
                    :agent-id="user.id"
                    :periode="situation_periode"
                    :data="situation"
                    :lien-rapport="lien_rapport"
                />

                <AgentDepensesTab
                    v-else-if="activeTab === 'depenses' && depenses"
                    :data="depenses"
                />

                <div
                    v-else
                    class="rounded-xl border bg-card p-5 sm:p-6"
                    data-testid="agent-informations-panel"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h2
                            class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Informations de l'agent
                        </h2>
                        <Link
                            v-if="peut_modifier"
                            :href="`/backoffice/users/${user.id}/edit`"
                            data-testid="agent-edit-button"
                        >
                            <Button size="sm" variant="outline">
                                <Pencil class="mr-1.5 h-4 w-4" />
                                Modifier
                            </Button>
                        </Link>
                    </div>

                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Nom complet
                            </p>
                            <p
                                class="mt-1 text-sm font-medium"
                                data-testid="agent-name"
                            >
                                {{ user.nom_complet || '—' }}
                            </p>
                            <p
                                v-if="user.matricule"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                Matricule {{ user.matricule }}
                            </p>
                        </div>

                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Téléphone
                            </p>
                            <p
                                class="mt-1 text-sm font-medium"
                                data-testid="agent-phone"
                            >
                                {{ telephone ?? 'Non renseigné' }}
                            </p>
                        </div>

                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">E-mail</p>
                            <p
                                class="mt-1 text-sm font-medium break-all"
                                data-testid="agent-email"
                            >
                                {{ user.email || 'Non renseigné' }}
                            </p>
                        </div>

                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">Rôle</p>
                            <p
                                class="mt-1 text-sm font-medium"
                                data-testid="agent-role"
                            >
                                {{ user.role_label || 'Aucun rôle' }}
                            </p>
                        </div>

                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                {{
                                    user.sites.length > 1 ? 'Agences' : 'Agence'
                                }}
                            </p>
                            <ul
                                v-if="user.sites.length"
                                class="mt-1 space-y-0.5"
                                data-testid="agent-sites"
                            >
                                <li
                                    v-for="site in user.sites"
                                    :key="site.id"
                                    class="text-sm font-medium"
                                >
                                    {{ site.nom }}
                                    <span
                                        v-if="
                                            site.is_default &&
                                            user.sites.length > 1
                                        "
                                        class="text-xs font-normal text-muted-foreground"
                                    >
                                        (par défaut)
                                    </span>
                                </li>
                            </ul>
                            <p v-else class="mt-1 text-sm font-medium">
                                Aucune agence
                            </p>
                        </div>

                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Statut du compte
                            </p>
                            <StatusDot
                                class="mt-1"
                                :status="statut.code"
                                :label="statut.label"
                                data-testid="agent-statut"
                            />
                        </div>

                        <div
                            class="rounded-lg border bg-background p-4 sm:col-span-2"
                        >
                            <p class="text-xs text-muted-foreground">
                                Localisation
                            </p>
                            <p class="mt-1 text-sm font-medium">
                                {{ localisation ?? 'Non renseignée' }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
