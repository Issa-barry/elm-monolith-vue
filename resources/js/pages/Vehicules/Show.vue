<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Car, CheckCircle, Pencil } from 'lucide-vue-next';
import { computed } from 'vue';

interface EquipeMembre {
    livreur_nom: string | null;
    taux_commission: number;
    role: string;
}

interface DepenseRow {
    id: string;
    libelle: string;
    montant: number;
    date_depense: string | null;
    statut: string;
    commentaire: string | null;
}

interface VehiculeData {
    id: string;
    nom_vehicule: string;
    immatriculation: string;
    type_label: string;
    type_vehicule: string | null;
    capacite_packs: number | null;
    proprietaire_nom: string | null;
    proprietaire_telephone: string | null;
    equipe_nom: string | null;
    equipe_membres: EquipeMembre[];
    pris_en_charge_par_usine: boolean;
    photo_url: string | null;
    is_active: boolean;
}

const props = defineProps<{ vehicule: VehiculeData; depenses: DepenseRow[] }>();

const { can } = usePermissions();
const page = usePage();
const flashSuccess = computed(
    () => (page.props as { flash?: { success?: string } }).flash?.success,
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Véhicules', href: '/vehicules' },
    { title: props.vehicule.nom_vehicule, href: '#' },
];

const statutLabel: Record<string, string> = {
    brouillon: 'Brouillon',
    soumis: 'Soumis',
    approuve: 'Approuvé',
    rejete: 'Rejeté',
};

const statutBadge: Record<string, string> = {
    brouillon: 'bg-muted text-muted-foreground',
    soumis: 'bg-blue-50 text-blue-700',
    approuve: 'bg-emerald-50 text-emerald-700',
    rejete: 'bg-red-50 text-red-700',
};

const totalApprouve = computed(() =>
    props.depenses
        .filter((d) => d.statut === 'approuve')
        .reduce((s, d) => s + d.montant, 0),
);

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}
</script>

<template>
    <Head :title="`${vehicule.nom_vehicule} — Détail`" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- Header mobile -->
        <div
            class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden"
        >
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link
                    href="/vehicules"
                    class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div class="text-center">
                    <h1 class="text-[17px] leading-tight font-semibold">
                        {{ vehicule.nom_vehicule }}
                    </h1>
                    <p class="font-mono text-[11px] text-muted-foreground">
                        {{ vehicule.immatriculation }}
                    </p>
                </div>
                <Link
                    v-if="can('vehicules.update')"
                    :href="`/vehicules/${vehicule.id}/edit`"
                    class="absolute right-4"
                >
                    <Button
                        size="sm"
                        variant="outline"
                        class="h-8 gap-1.5 px-3 text-xs"
                    >
                        <Pencil class="h-3.5 w-3.5" />
                        Modifier
                    </Button>
                </Link>
            </div>
        </div>

        <div class="mx-auto w-full max-w-5xl space-y-6 p-4 sm:p-6">
            <!-- Flash success -->
            <div
                v-if="flashSuccess"
                class="flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"
            >
                <CheckCircle class="h-4 w-4 shrink-0" />
                {{ flashSuccess }}
            </div>

            <!-- Header desktop -->
            <div class="hidden items-start justify-between gap-6 sm:flex">
                <div class="flex items-center gap-5">
                    <!-- Photo -->
                    <div
                        class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl border bg-muted/30"
                    >
                        <img
                            v-if="vehicule.photo_url"
                            :src="vehicule.photo_url"
                            :alt="vehicule.nom_vehicule"
                            class="h-full w-full object-cover"
                        />
                        <Car
                            v-else
                            class="h-10 w-10 text-muted-foreground/30"
                        />
                    </div>
                    <!-- Title -->
                    <div>
                        <div class="flex items-center gap-2">
                            <h1 class="text-2xl font-semibold tracking-tight">
                                {{ vehicule.nom_vehicule }}
                            </h1>
                            <StatusDot
                                :label="
                                    vehicule.is_active ? 'Actif' : 'Inactif'
                                "
                                :dot-class="
                                    vehicule.is_active
                                        ? 'bg-emerald-500'
                                        : 'bg-zinc-400 dark:bg-zinc-500'
                                "
                                class="text-sm text-muted-foreground"
                            />
                        </div>
                        <p
                            class="mt-0.5 font-mono text-sm text-muted-foreground"
                        >
                            {{ vehicule.immatriculation }}
                        </p>
                        <div class="mt-1.5 flex items-center gap-2">
                            <span
                                class="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium"
                            >
                                {{ vehicule.type_label }}
                            </span>
                            <span
                                v-if="vehicule.capacite_packs"
                                class="text-xs text-muted-foreground"
                            >
                                {{ vehicule.capacite_packs }} packs
                            </span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Link href="/vehicules">
                        <Button variant="outline" size="sm">
                            <ArrowLeft class="mr-1.5 h-4 w-4" />
                            Retour
                        </Button>
                    </Link>
                    <Link
                        v-if="can('vehicules.update')"
                        :href="`/vehicules/${vehicule.id}/edit`"
                    >
                        <Button size="sm">
                            <Pencil class="mr-1.5 h-4 w-4" />
                            Modifier
                        </Button>
                    </Link>
                </div>
            </div>

            <!-- Cards grid -->
            <div class="grid gap-4 sm:grid-cols-2 sm:gap-6">
                <!-- Affectation -->
                <div class="rounded-xl border bg-card p-4 sm:p-5">
                    <h3
                        class="mb-4 text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Affectation
                    </h3>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Équipe
                            </dt>
                            <dd class="mt-0.5 text-sm font-medium">
                                {{ vehicule.equipe_nom ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Propriétaire
                            </dt>
                            <dd class="mt-0.5 text-sm font-medium">
                                {{ vehicule.proprietaire_nom ?? '—' }}
                            </dd>
                            <dd
                                v-if="vehicule.proprietaire_telephone"
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ vehicule.proprietaire_telephone }}
                            </dd>
                        </div>
                        <div v-if="vehicule.equipe_membres.length">
                            <dt class="mb-1.5 text-xs text-muted-foreground">
                                Membres de l'équipe
                            </dt>
                            <dd>
                                <div class="space-y-1">
                                    <div
                                        v-for="(
                                            m, i
                                        ) in vehicule.equipe_membres"
                                        :key="i"
                                        class="flex items-center justify-between rounded-md bg-muted/40 px-2.5 py-1.5 text-xs"
                                    >
                                        <span class="font-medium">
                                            {{ m.livreur_nom ?? '—' }}
                                        </span>
                                        <span class="text-muted-foreground">
                                            <span
                                                v-if="m.role === 'principal'"
                                                class="mr-1.5 rounded-full bg-primary/10 px-1.5 py-0.5 text-[10px] font-medium text-primary"
                                                >Principal</span
                                            >
                                            {{ m.taux_commission }}%
                                        </span>
                                    </div>
                                </div>
                            </dd>
                        </div>
                    </dl>
                </div>

                <!-- Commission -->
                <div class="rounded-xl border bg-card p-4 sm:p-5">
                    <h3
                        class="mb-4 text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Commission
                    </h3>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Pris en charge par l'usine
                            </dt>
                            <dd class="mt-0.5">
                                <span
                                    v-if="vehicule.pris_en_charge_par_usine"
                                    class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700"
                                    >Oui</span
                                >
                                <span
                                    v-else
                                    class="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground"
                                    >Non</span
                                >
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <!-- ── Dépenses du véhicule ───────────────────────────────────── -->
            <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
                <div class="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <h3
                            class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Dépenses du véhicule
                        </h3>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            Frais opérationnels gérés via le module Dépenses.
                        </p>
                    </div>
                    <span
                        v-if="totalApprouve > 0"
                        class="shrink-0 rounded-lg bg-amber-50 px-3 py-1 text-sm font-semibold text-amber-700 tabular-nums"
                    >
                        Approuvés : {{ formatGNF(totalApprouve) }}
                    </span>
                </div>

                <!-- État vide -->
                <div
                    v-if="!depenses.length"
                    class="rounded-lg border border-dashed py-10 text-center"
                >
                    <p class="text-sm text-muted-foreground">
                        Aucune dépense enregistrée pour ce véhicule.
                    </p>
                </div>

                <!-- Liste -->
                <div v-else class="divide-y rounded-lg border">
                    <div
                        v-for="d in depenses"
                        :key="d.id"
                        class="flex items-center gap-4 px-4 py-3 hover:bg-muted/30"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-semibold tabular-nums">
                                {{ formatGNF(d.montant) }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ d.libelle }}
                                <span v-if="d.commentaire"> · {{ d.commentaire }}</span>
                            </div>
                        </div>
                        <div class="hidden text-xs text-muted-foreground sm:block">
                            {{ d.date_depense ?? '—' }}
                        </div>
                        <span
                            class="shrink-0 rounded-sm px-2 py-0.5 text-[10px] font-semibold tracking-wide uppercase"
                            :class="statutBadge[d.statut] ?? 'bg-muted text-muted-foreground'"
                        >
                            {{ statutLabel[d.statut] ?? d.statut }}
                        </span>
                        <Link
                            v-if="can('depenses.update')"
                            :href="`/depenses/${d.id}/edit`"
                            class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                        >
                            <Pencil class="h-3.5 w-3.5" />
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
