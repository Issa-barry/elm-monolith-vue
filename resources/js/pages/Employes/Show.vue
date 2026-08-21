<script setup lang="ts">
import DetailHeader from '@/components/DetailHeader.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import {
    Briefcase,
    History,
    Pencil,
    Plus,
    ShieldCheck,
    UserRound,
    Warehouse,
} from 'lucide-vue-next';

interface ContratRow {
    id: string;
    type_contrat: string;
    type_contrat_label: string;
    statut_contrat: string;
    statut_contrat_label: string;
    date_debut: string | null;
    date_fin: string | null;
    salaire_base: string | null;
}

interface AffectationRow {
    id: string;
    site: string | null;
    fonction_libelle: string | null;
    debut_at: string | null;
    fin_at: string | null;
    active: boolean;
    motif: string | null;
}

interface CompteInfo {
    id: string;
    email: string;
    role_label: string | null;
    statut: string;
    statut_label: string;
}

interface EmployeData {
    id: string;
    matricule: string | null;
    nom_complet: string;
    email: string | null;
    telephone: string | null;
    type_employe: string;
    type_employe_label: string;
    statut: string;
    statut_label: string;
    site: string | null;
    fonction_libelle: string | null;
    contrat_actif: { type_contrat: string; type_contrat_label: string } | null;
    contrats: ContratRow[];
    affectations: AffectationRow[];
    compte: CompteInfo | null;
}

const props = defineProps<{
    employe: EmployeData;
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Employés', href: '/backoffice/employes' },
    { title: props.employe.nom_complet, href: '#' },
];

const STATUT_DOT: Record<string, string> = {
    actif: 'bg-emerald-500',
    suspendu: 'bg-amber-400',
    sorti: 'bg-zinc-400 dark:bg-zinc-500',
};

const COMPTE_STATUT_DOT: Record<string, string> = {
    actif: 'bg-emerald-500',
    inactif: 'bg-zinc-400 dark:bg-zinc-500',
    pending_validation: 'bg-amber-400',
};

const TYPE_CONTRAT_CLASS: Record<string, string> = {
    cdi: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
    cdd: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
};

const STATUT_CONTRAT_CLASS: Record<string, string> = {
    actif: 'bg-emerald-100 text-emerald-700',
    termine: 'bg-zinc-100 text-zinc-600',
    rompu: 'bg-red-100 text-red-700',
};
</script>

<template>
    <Head
        ><title>{{ employe.nom_complet }}</title></Head
    >
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <DetailHeader
                eyebrow="Employé"
                :title="employe.nom_complet"
                :icon="UserRound"
                :status-label="employe.statut_label"
                :status-dot-class="STATUT_DOT[employe.statut] ?? 'bg-zinc-400'"
            >
                <template #subtitle>
                    <div
                        class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground"
                    >
                        <span
                            v-if="employe.matricule"
                            class="rounded bg-muted px-2 py-0.5 font-mono text-xs"
                            >{{ employe.matricule }}</span
                        >
                        <span aria-hidden="true">·</span>
                        <span>{{ employe.type_employe_label }}</span>
                    </div>
                </template>
                <template #actions>
                    <Link
                        v-if="
                            can('rh-contrats.create') && !employe.contrat_actif
                        "
                        :href="`/backoffice/contrats/create?employe_id=${employe.id}`"
                    >
                        <Button variant="outline" size="sm">
                            <Plus class="mr-1.5 h-4 w-4" />
                            Nouveau contrat
                        </Button>
                    </Link>
                    <Link
                        v-if="can('rh-employes.update')"
                        :href="`/backoffice/employes/${employe.id}/edit`"
                    >
                        <Button size="sm">
                            <Pencil class="mr-1.5 h-4 w-4" />
                            Modifier
                        </Button>
                    </Link>
                </template>
            </DetailHeader>

            <div class="grid gap-6 lg:grid-cols-2">
                <!-- ── Colonne gauche : identité, fonction/site, compte, commission ── -->
                <div class="space-y-6">
                    <!-- Informations -->
                    <div class="overflow-hidden rounded-xl border bg-card">
                        <div class="border-b px-5 py-4">
                            <h3
                                class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                Informations
                            </h3>
                        </div>
                        <dl class="grid grid-cols-2 gap-4 p-5 text-sm">
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Email
                                </dt>
                                <dd class="mt-0.5 font-medium">
                                    {{ employe.email ?? '—' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Téléphone
                                </dt>
                                <dd class="mt-0.5 font-medium">
                                    {{
                                        employe.telephone
                                            ? formatPhoneDisplay(
                                                  employe.telephone,
                                              )
                                            : '—'
                                    }}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Fonction RH & Site actuel -->
                    <div class="overflow-hidden rounded-xl border bg-card">
                        <div class="border-b px-5 py-4">
                            <h3
                                class="flex items-center gap-2 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                <Warehouse class="h-4 w-4" />
                                Fonction & site actuel
                            </h3>
                        </div>
                        <dl class="grid grid-cols-2 gap-4 p-5 text-sm">
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Fonction RH
                                </dt>
                                <dd class="mt-0.5 font-medium">
                                    {{ employe.fonction_libelle ?? '—' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Site
                                </dt>
                                <dd class="mt-0.5 font-medium">
                                    {{ employe.site ?? '—' }}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Compte utilisateur & profil d'accès -->
                    <div class="overflow-hidden rounded-xl border bg-card">
                        <div class="border-b px-5 py-4">
                            <h3
                                class="flex items-center gap-2 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                <ShieldCheck class="h-4 w-4" />
                                Compte & profil d'accès
                            </h3>
                        </div>
                        <div
                            v-if="!employe.compte"
                            class="p-5 text-sm text-muted-foreground"
                        >
                            Aucun compte applicatif rattaché à cet employé.
                        </div>
                        <dl v-else class="grid grid-cols-2 gap-4 p-5 text-sm">
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Email de connexion
                                </dt>
                                <dd class="mt-0.5 font-medium">
                                    {{ employe.compte.email }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Profil d'accès
                                </dt>
                                <dd class="mt-0.5 font-medium">
                                    {{ employe.compte.role_label ?? '—' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Statut du compte
                                </dt>
                                <dd class="mt-0.5">
                                    <StatusDot
                                        :label="employe.compte.statut_label"
                                        :dot-class="
                                            COMPTE_STATUT_DOT[
                                                employe.compte.statut
                                            ] ?? 'bg-zinc-400'
                                        "
                                    />
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <!-- ── Colonne droite : contrats + historique d'affectation ── -->
                <div class="space-y-6">
                    <div class="overflow-hidden rounded-xl border bg-card">
                        <div
                            class="flex items-center justify-between gap-4 border-b px-5 py-4"
                        >
                            <h3
                                class="flex items-center gap-2 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                <Briefcase class="h-4 w-4" />
                                Contrats
                            </h3>
                        </div>
                        <div
                            v-if="employe.contrats.length === 0"
                            class="p-8 text-center text-sm text-muted-foreground"
                        >
                            Aucun contrat enregistré.
                        </div>
                        <div v-else class="divide-y">
                            <div
                                v-for="c in employe.contrats"
                                :key="c.id"
                                class="flex items-center justify-between gap-3 p-4"
                            >
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                            :class="
                                                TYPE_CONTRAT_CLASS[
                                                    c.type_contrat
                                                ]
                                            "
                                        >
                                            {{ c.type_contrat_label }}
                                        </span>
                                        <span
                                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                            :class="
                                                STATUT_CONTRAT_CLASS[
                                                    c.statut_contrat
                                                ]
                                            "
                                        >
                                            {{ c.statut_contrat_label }}
                                        </span>
                                    </div>
                                    <p class="text-xs text-muted-foreground">
                                        Du {{ c.date_debut }}
                                        <template v-if="c.date_fin">
                                            au {{ c.date_fin }}</template
                                        >
                                        <template v-else>
                                            (indéterminé)</template
                                        >
                                    </p>
                                </div>
                                <Link
                                    :href="`/backoffice/contrats/${c.id}/edit`"
                                >
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="h-8 w-8"
                                    >
                                        <Pencil class="h-3.5 w-3.5" />
                                    </Button>
                                </Link>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="employe.affectations.length > 0"
                        class="overflow-hidden rounded-xl border bg-card"
                    >
                        <div class="border-b px-5 py-4">
                            <h3
                                class="flex items-center gap-2 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                <History class="h-4 w-4" />
                                Historique d'affectation
                            </h3>
                        </div>
                        <div class="divide-y">
                            <div
                                v-for="a in employe.affectations"
                                :key="a.id"
                                class="flex items-center justify-between gap-3 p-4 text-sm"
                            >
                                <div>
                                    <p class="font-medium">
                                        {{ a.site ?? '—' }}
                                        <span
                                            v-if="a.fonction_libelle"
                                            class="font-normal text-muted-foreground"
                                            >— {{ a.fonction_libelle }}</span
                                        >
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        Du {{ a.debut_at }}
                                        <template v-if="a.fin_at">
                                            au {{ a.fin_at }}</template
                                        >
                                        <template v-else> (en cours)</template>
                                    </p>
                                </div>
                                <span
                                    v-if="a.active"
                                    class="rounded-full bg-emerald-500 px-2 py-0.5 text-[10px] font-medium text-white"
                                    >Actif</span
                                >
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
