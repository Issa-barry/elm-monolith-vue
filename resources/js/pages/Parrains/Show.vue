<script setup lang="ts">
/**
 * Fiche parrain du back-office (ADR 0050). L'onglet Commissions n'existe que si le serveur l'a
 * rempli (droit Comptabilité et module actif). L'identité se modifie depuis le véhicule (PARRAIN-005).
 */
import DetailHeader from '@/components/DetailHeader.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { useFlashToast } from '@/composables/useFlashToast';
import { usePermissions } from '@/composables/usePermissions';
import { useUrlTab } from '@/composables/useUrlTab';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF, formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Car,
    CircleHelp,
    KeyRound,
    Link2,
    ReceiptText,
    Unlink,
    UserRound,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import DetachementCompteDialog from './partials/DetachementCompteDialog.vue';
import RattachementCompteDialog from './partials/RattachementCompteDialog.vue';
import {
    libelleRole,
    type CommissionsParrain,
    type CompteRattache,
    type EvenementCompte,
    type ParrainIdentite,
    type VehiculeParraine,
} from './partials/types';

const props = defineProps<{
    parrain: ParrainIdentite;
    compte: CompteRattache | null;
    historique_compte: EvenementCompte[];
    vehicules: VehiculeParraine[];
    commissions: CommissionsParrain | null;
    perimetre_limite: boolean;
}>();

const { can } = usePermissions();
useFlashToast();

const ONGLETS = ['informations', 'compte', 'vehicules', 'commissions'] as const;
const { onglet: activeTab, choisir: choisirOnglet } = useUrlTab(
    ONGLETS,
    'informations',
);

const ongletsVisibles = computed(() => [
    { key: 'informations' as const, label: 'Informations', icon: CircleHelp },
    { key: 'compte' as const, label: 'Compte', icon: KeyRound },
    { key: 'vehicules' as const, label: 'Véhicules', icon: Car },
    ...(props.commissions
        ? [
              {
                  key: 'commissions' as const,
                  label: 'Commissions',
                  icon: ReceiptText,
              },
          ]
        : []),
]);

function ongletClass(onglet: (typeof ONGLETS)[number]): string {
    return activeTab.value === onglet
        ? 'bg-primary text-primary-foreground'
        : 'text-muted-foreground hover:bg-muted';
}

const nom = computed(() => props.parrain.nom_complet || 'Parrain sans nom');

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Parrains', href: '/backoffice/parrains' },
    { title: nom.value, href: '#' },
]);

const telephone = computed(
    () =>
        formatPhoneDisplay(
            props.parrain.telephone,
            props.parrain.code_phone_pays,
        ) ?? '—',
);

const localisation = computed(
    () =>
        [props.parrain.adresse, props.parrain.ville, props.parrain.pays]
            .filter(Boolean)
            .join(', ') || '—',
);

const peutGererCompte = computed(() => can('rattachements-comptes.gerer'));
const showRattachement = ref(false);
const showDetachement = ref(false);

const LIBELLES_PART: Record<string, string> = {
    a_definir: 'À définir',
    definie: 'Définie',
};
</script>

<template>
    <Head :title="`${nom} — Parrain`" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <DetailHeader
                eyebrow="Parrain"
                :title="nom"
                :icon="UserRound"
                :status-label="parrain.is_active ? 'Actif' : 'Inactif'"
                :status-dot-class="
                    parrain.is_active
                        ? 'bg-emerald-500'
                        : 'bg-zinc-400 dark:bg-zinc-500'
                "
                data-testid="parrain-detail-header"
            >
                <template #subtitle>
                    <p class="mt-1 text-sm text-muted-foreground tabular-nums">
                        {{ telephone }}
                    </p>
                </template>
                <template #actions>
                    <Link href="/backoffice/parrains">
                        <Button variant="outline" size="sm">
                            <ArrowLeft class="mr-1.5 h-4 w-4" />
                            Liste des parrains
                        </Button>
                    </Link>
                </template>
            </DetailHeader>

            <div class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)]">
                <aside
                    class="grid h-fit grid-cols-2 gap-2 rounded-xl border bg-card p-2 lg:block lg:space-y-2"
                    aria-label="Sections de la fiche parrain"
                >
                    <button
                        v-for="o in ongletsVisibles"
                        :key="o.key"
                        type="button"
                        class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                        :class="ongletClass(o.key)"
                        :data-testid="`parrain-${o.key}-tab`"
                        @click="choisirOnglet(o.key)"
                    >
                        <component :is="o.icon" class="h-4 w-4" />
                        {{ o.label }}
                    </button>
                </aside>

                <!-- ── Compte ─────────────────────────────────────────── -->
                <div
                    v-if="activeTab === 'compte'"
                    class="space-y-6"
                    data-testid="parrain-compte-panel"
                >
                    <div class="rounded-xl border bg-card p-5 sm:p-6">
                        <div class="flex items-center justify-between gap-3">
                            <h2
                                class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                Compte rattaché
                            </h2>
                            <template v-if="peutGererCompte">
                                <Button
                                    v-if="compte"
                                    size="sm"
                                    variant="outline"
                                    data-testid="parrain-detacher"
                                    @click="showDetachement = true"
                                >
                                    <Unlink class="mr-1.5 h-4 w-4" />
                                    Détacher
                                </Button>
                                <Button
                                    v-else-if="parrain.is_active"
                                    size="sm"
                                    data-testid="parrain-rattacher"
                                    @click="showRattachement = true"
                                >
                                    <Link2 class="mr-1.5 h-4 w-4" />
                                    Rattacher un compte
                                </Button>
                            </template>
                        </div>

                        <div
                            v-if="compte"
                            class="mt-5 grid gap-4 sm:grid-cols-2"
                            data-testid="parrain-compte"
                        >
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Nom saisi
                                </p>
                                <p class="mt-1 text-sm font-medium">
                                    {{ compte.nom ?? 'Non renseigné' }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Téléphone
                                </p>
                                <p
                                    class="mt-1 text-sm font-medium tabular-nums"
                                >
                                    {{
                                        formatPhoneDisplay(
                                            compte.telephone,
                                            null,
                                        ) ?? '—'
                                    }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    E-mail
                                </p>
                                <p class="mt-1 text-sm font-medium">
                                    {{ compte.email ?? '—' }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Inscrit le
                                </p>
                                <p class="mt-1 text-sm font-medium">
                                    {{ compte.inscrit_le ?? '—' }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Rôles
                                </p>
                                <p class="mt-1 text-sm font-medium">
                                    {{
                                        compte.roles
                                            .map(libelleRole)
                                            .join(', ') || '—'
                                    }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    État du compte
                                </p>
                                <StatusDot
                                    class="mt-1 text-sm font-medium"
                                    :status="compte.actif ? 'actif' : 'inactif'"
                                    :label="
                                        compte.actif
                                            ? 'Actif'
                                            : 'Désactivé ou non activé'
                                    "
                                />
                            </div>
                        </div>

                        <p
                            v-else
                            class="mt-5 rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
                            data-testid="parrain-sans-compte"
                        >
                            Aucun compte rattaché. Ce parrain ne peut pas encore
                            consulter ses commissions.
                        </p>

                        <p
                            v-if="!compte && !parrain.is_active"
                            class="mt-3 text-sm text-muted-foreground"
                        >
                            Une fiche désactivée ne peut pas être rattachée.
                        </p>
                    </div>

                    <div class="rounded-xl border bg-card p-5 sm:p-6">
                        <h2
                            class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Historique
                        </h2>
                        <ul
                            v-if="historique_compte.length"
                            class="mt-4 divide-y text-sm"
                            data-testid="parrain-historique-compte"
                        >
                            <li
                                v-for="e in historique_compte"
                                :key="e.id"
                                class="flex flex-col gap-0.5 py-2.5 sm:flex-row sm:justify-between sm:gap-4"
                            >
                                <span>
                                    <span class="font-medium">{{
                                        e.evenement
                                    }}</span>
                                    <span
                                        v-if="
                                            e.compte_nom || e.compte_telephone
                                        "
                                        class="text-muted-foreground"
                                    >
                                        —
                                        {{ e.compte_nom ?? e.compte_telephone }}
                                    </span>
                                </span>
                                <span class="text-xs text-muted-foreground">
                                    {{ e.date }}
                                    <template v-if="e.auteur">
                                        · {{ e.auteur }}
                                    </template>
                                </span>
                            </li>
                        </ul>
                        <p v-else class="mt-4 text-sm text-muted-foreground">
                            Aucun rattachement ni détachement pour le moment.
                        </p>
                    </div>
                </div>

                <!-- ── Véhicules ──────────────────────────────────────── -->
                <div
                    v-else-if="activeTab === 'vehicules'"
                    class="rounded-xl border bg-card p-5 sm:p-6"
                    data-testid="parrain-vehicules-panel"
                >
                    <h2
                        class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Véhicules parrainés
                    </h2>
                    <p
                        v-if="perimetre_limite"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        Limité à vos agences.
                    </p>
                    <ul v-if="vehicules.length" class="mt-4 divide-y text-sm">
                        <li
                            v-for="v in vehicules"
                            :key="v.id"
                            class="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                        >
                            <div class="min-w-0">
                                <Link
                                    v-if="v.url"
                                    :href="v.url"
                                    class="font-medium hover:underline"
                                >
                                    {{ v.nom }}
                                </Link>
                                <span v-else class="font-medium">{{
                                    v.nom
                                }}</span>
                                <span
                                    v-if="v.immatriculation"
                                    class="ml-2 font-mono text-xs text-muted-foreground"
                                    >{{ v.immatriculation }}</span
                                >
                                <p class="text-xs text-muted-foreground">
                                    {{ v.site_nom ?? 'Sans agence' }}
                                </p>
                            </div>
                            <div class="flex items-center gap-4 text-xs">
                                <span
                                    v-if="v.part"
                                    class="text-muted-foreground"
                                >
                                    Part :
                                    {{ LIBELLES_PART[v.part] ?? v.part }}
                                </span>
                                <StatusDot
                                    class="text-muted-foreground"
                                    :status="v.is_active ? 'actif' : 'inactif'"
                                    :label="v.is_active ? 'Actif' : 'Inactif'"
                                />
                            </div>
                        </li>
                    </ul>
                    <p
                        v-else
                        class="mt-4 rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
                    >
                        Aucun véhicule parrainé actuellement.
                    </p>
                </div>

                <!-- ── Commissions ────────────────────────────────────── -->
                <div
                    v-else-if="activeTab === 'commissions' && commissions"
                    class="space-y-6"
                    data-testid="parrain-commissions-panel"
                >
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="rounded-xl border bg-card p-5">
                            <p class="text-sm text-muted-foreground">
                                Total des fiches
                            </p>
                            <p class="mt-1 text-xl font-semibold tabular-nums">
                                {{ formatGNF(commissions.total_net) }}
                            </p>
                        </div>
                        <div class="rounded-xl border bg-card p-5">
                            <p class="text-sm text-muted-foreground">
                                Déjà payé
                            </p>
                            <p class="mt-1 text-xl font-semibold tabular-nums">
                                {{ formatGNF(commissions.total_paye) }}
                            </p>
                        </div>
                        <div class="rounded-xl border bg-card p-5">
                            <p class="text-sm text-muted-foreground">
                                Reste à payer
                            </p>
                            <p
                                class="mt-1 text-xl font-semibold tabular-nums"
                                data-testid="parrain-reste-a-payer"
                            >
                                {{ formatGNF(commissions.total_restant) }}
                            </p>
                        </div>
                    </div>

                    <div class="rounded-xl border bg-card p-5 sm:p-6">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2
                                    class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                                >
                                    Fiches de paiement
                                </h2>
                                <p
                                    v-if="perimetre_limite"
                                    class="mt-1 text-xs text-muted-foreground"
                                >
                                    Limité à vos agences.
                                </p>
                            </div>
                            <Link
                                v-if="
                                    commissions.nb_fiches >
                                    commissions.fiches.length
                                "
                                :href="commissions.toutes_les_fiches_url"
                                class="text-sm font-medium text-primary hover:underline"
                            >
                                Voir les {{ commissions.nb_fiches }} fiches
                            </Link>
                        </div>
                        <div
                            v-if="commissions.fiches.length"
                            class="mt-4 overflow-x-auto"
                        >
                            <table class="w-full min-w-[640px] text-sm">
                                <thead>
                                    <tr
                                        class="border-b text-left text-xs text-muted-foreground"
                                    >
                                        <th class="py-2 pr-4 font-medium">
                                            Période
                                        </th>
                                        <th class="py-2 pr-4 font-medium">
                                            Agence
                                        </th>
                                        <th
                                            class="py-2 pr-4 text-right font-medium"
                                        >
                                            Net
                                        </th>
                                        <th
                                            class="py-2 pr-4 text-right font-medium"
                                        >
                                            Payé
                                        </th>
                                        <th
                                            class="py-2 pr-4 text-right font-medium"
                                        >
                                            Reste
                                        </th>
                                        <th class="py-2 font-medium">Statut</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <tr
                                        v-for="f in commissions.fiches"
                                        :key="f.id"
                                    >
                                        <td class="py-2.5 pr-4">
                                            <Link
                                                :href="f.url"
                                                class="font-medium hover:underline"
                                            >
                                                {{ f.periode ?? f.reference }}
                                            </Link>
                                        </td>
                                        <td
                                            class="py-2.5 pr-4 text-muted-foreground"
                                        >
                                            {{ f.site_nom ?? '—' }}
                                        </td>
                                        <td
                                            class="py-2.5 pr-4 text-right tabular-nums"
                                        >
                                            {{ formatGNF(f.montant_net) }}
                                        </td>
                                        <td
                                            class="py-2.5 pr-4 text-right tabular-nums"
                                        >
                                            {{ formatGNF(f.montant_paye) }}
                                        </td>
                                        <td
                                            class="py-2.5 pr-4 text-right tabular-nums"
                                        >
                                            {{ formatGNF(f.montant_restant) }}
                                        </td>
                                        <td class="py-2.5">
                                            <StatusDot
                                                :status="f.display_status"
                                                :label="f.display_label"
                                            />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else class="mt-4 text-sm text-muted-foreground">
                            Aucune fiche de paiement pour ce parrain.
                        </p>
                    </div>

                    <div class="rounded-xl border bg-card p-5 sm:p-6">
                        <h2
                            class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Derniers paiements
                        </h2>
                        <ul
                            v-if="commissions.paiements.length"
                            class="mt-4 divide-y text-sm"
                        >
                            <li
                                v-for="p in commissions.paiements"
                                :key="p.id"
                                class="flex items-start justify-between gap-4 py-2.5"
                            >
                                <div class="min-w-0">
                                    <p class="font-medium">{{ p.date }}</p>
                                    <p
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{
                                            [
                                                p.mode_paiement,
                                                p.support,
                                                p.reference_paiement,
                                                p.fiche_reference,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')
                                        }}
                                    </p>
                                </div>
                                <span
                                    class="font-medium whitespace-nowrap tabular-nums"
                                    >{{ formatGNF(p.montant) }}</span
                                >
                            </li>
                        </ul>
                        <p v-else class="mt-4 text-sm text-muted-foreground">
                            Aucun paiement enregistré.
                        </p>
                    </div>
                </div>

                <!-- ── Informations ───────────────────────────────────── -->
                <div
                    v-else
                    class="rounded-xl border bg-card p-5 sm:p-6"
                    data-testid="parrain-informations-panel"
                >
                    <h2
                        class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Identité du parrain
                    </h2>
                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Nom complet
                            </p>
                            <p class="mt-1 text-sm font-medium">{{ nom }}</p>
                        </div>
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Téléphone
                            </p>
                            <p class="mt-1 text-sm font-medium tabular-nums">
                                {{ telephone }}
                            </p>
                        </div>
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">E-mail</p>
                            <p class="mt-1 text-sm font-medium">
                                {{ parrain.email ?? '—' }}
                            </p>
                        </div>
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Localisation
                            </p>
                            <p class="mt-1 text-sm font-medium">
                                {{ localisation }}
                            </p>
                        </div>
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Parrain depuis le
                            </p>
                            <p class="mt-1 text-sm font-medium">
                                {{ parrain.cree_le ?? '—' }}
                            </p>
                        </div>
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">Statut</p>
                            <StatusDot
                                class="mt-1 text-sm font-medium"
                                :status="
                                    parrain.is_active ? 'actif' : 'inactif'
                                "
                                :label="parrain.is_active ? 'Actif' : 'Inactif'"
                            />
                        </div>
                    </div>
                    <p class="mt-4 text-xs text-muted-foreground">
                        L'identité se modifie depuis l'onglet Parrain de l'un de
                        ses véhicules.
                    </p>
                </div>
            </div>
        </div>

        <RattachementCompteDialog
            v-if="peutGererCompte && !compte"
            v-model:visible="showRattachement"
            :parrain-id="parrain.id"
            :telephone-parrain="parrain.telephone"
        />
        <DetachementCompteDialog
            v-if="peutGererCompte && compte"
            v-model:visible="showDetachement"
            :parrain-id="parrain.id"
            :nom-compte="compte.nom"
        />
    </AppLayout>
</template>
