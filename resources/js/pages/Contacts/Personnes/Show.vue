<script setup lang="ts">
/**
 * Fiche Personne du référentiel Contacts (ADR 0060) : consultation (lot 2) et ajout explicite
 * d'une fonction (lot 3). La modification de l'identité arrive au lot 7.
 */
import DetailHeader from '@/components/DetailHeader.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { useUrlTab } from '@/composables/useUrlTab';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Building2,
    CircleHelp,
    Layers,
    Plus,
    UserRound,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import AjouterFonctionDialog from '../partials/AjouterFonctionDialog.vue';
import BadgesFonctions from '../partials/BadgesFonctions.vue';
import Coordonnees from '../partials/Coordonnees.vue';
import ListeFonctions from '../partials/ListeFonctions.vue';
import type {
    EntrepriseRepresentee,
    FonctionContact,
    OptionFiltre,
    PersonneFiche,
} from '../partials/types';

const props = defineProps<{
    personne: PersonneFiche;
    fonctions: FonctionContact[];
    entreprises: EntrepriseRepresentee[];
    /** Lot 3 : fonctions que l'utilisateur peut ajouter et que la fiche ne porte pas. */
    fonctions_ajoutables: OptionFiltre[];
    types_prestataire: OptionFiltre[];
}>();

const ajoutVisible = ref(false);

const ONGLETS = ['informations', 'fonctions', 'entreprises'] as const;
const { onglet, choisir } = useUrlTab(ONGLETS, 'informations');

const onglets = computed(() => [
    { key: 'informations' as const, label: 'Informations', icon: CircleHelp },
    { key: 'fonctions' as const, label: 'Fonctions', icon: Layers },
    ...(props.entreprises.length > 0
        ? [
              {
                  key: 'entreprises' as const,
                  label: 'Entreprises représentées',
                  icon: Building2,
              },
          ]
        : []),
]);

function ongletClass(o: (typeof ONGLETS)[number]): string {
    return onglet.value === o
        ? 'bg-primary text-primary-foreground'
        : 'text-muted-foreground hover:bg-muted';
}

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Personnes physiques', href: '/backoffice/contacts/personnes' },
    { title: props.personne.nom_affiche, href: '#' },
]);

// Les liens d'interlocuteur sont détaillés dans leur onglet ; l'en-tête n'en montre qu'un badge.
const badges = computed<FonctionContact[]>(() => [
    ...props.fonctions,
    ...(props.entreprises.length > 0
        ? [
              {
                  fonction: 'interlocuteur',
                  id: 'interlocuteur',
                  actif: props.entreprises.some((e) => e.is_active),
                  sous_categorie: null,
                  sous_categorie_libelle: null,
                  reference: null,
                  libelle: 'Interlocuteur',
                  lien: null,
              } satisfies FonctionContact,
          ]
        : []),
]);

const telephone = computed(
    () =>
        formatPhoneDisplay(
            props.personne.telephone,
            props.personne.code_phone_pays,
        ) ?? '—',
);
</script>

<template>
    <Head :title="`${personne.nom_affiche} — Personne physique`" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <DetailHeader
                eyebrow="Personne physique"
                :title="personne.nom_affiche"
                :icon="UserRound"
                data-testid="personne-detail-header"
            >
                <template #subtitle>
                    <p class="mt-1 text-sm text-muted-foreground tabular-nums">
                        {{ telephone }}
                    </p>
                    <BadgesFonctions class="mt-2" :fonctions="badges" />
                </template>
                <template #actions>
                    <Button
                        v-if="fonctions_ajoutables.length > 0"
                        size="sm"
                        data-testid="fiche-ajouter-fonction"
                        @click="ajoutVisible = true"
                    >
                        <Plus class="mr-1.5 h-4 w-4" />
                        Ajouter une fonction
                    </Button>
                    <Link href="/backoffice/contacts/personnes">
                        <Button variant="outline" size="sm">
                            <ArrowLeft class="mr-1.5 h-4 w-4" />
                            Personnes physiques
                        </Button>
                    </Link>
                </template>
            </DetailHeader>

            <div class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)]">
                <aside
                    class="grid h-fit grid-cols-2 gap-2 rounded-xl border bg-card p-2 lg:block lg:space-y-2"
                    aria-label="Sections de la fiche personne"
                >
                    <button
                        v-for="o in onglets"
                        :key="o.key"
                        type="button"
                        class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                        :class="ongletClass(o.key)"
                        :data-testid="`personne-${o.key}-tab`"
                        @click="choisir(o.key)"
                    >
                        <component :is="o.icon" class="h-4 w-4" />
                        {{ o.label }}
                    </button>
                </aside>

                <div v-if="onglet === 'informations'" class="space-y-6">
                    <section class="rounded-xl border bg-card p-5 sm:p-6">
                        <h2
                            class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Identité
                        </h2>
                        <dl
                            class="mb-6 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2"
                        >
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Prénom
                                </dt>
                                <dd class="mt-0.5">
                                    {{ personne.prenom || '—' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Nom
                                </dt>
                                <dd class="mt-0.5">
                                    {{ personne.nom || '—' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Nom complet
                                </dt>
                                <dd class="mt-0.5">
                                    {{ personne.nom_complet || '—' }}
                                </dd>
                            </div>
                            <div v-if="personne.surnom">
                                <dt class="text-xs text-muted-foreground">
                                    Surnom
                                </dt>
                                <dd class="mt-0.5">{{ personne.surnom }}</dd>
                            </div>
                        </dl>
                        <h2
                            class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Coordonnées
                        </h2>
                        <Coordonnees
                            :telephone="personne.telephone"
                            :telephone-secondaire="
                                personne.telephone_secondaire
                            "
                            :code-phone-pays="personne.code_phone_pays"
                            :email="personne.email"
                            :pays="personne.pays"
                            :ville="personne.ville"
                            :quartier="personne.quartier"
                            :adresse="personne.adresse"
                            :latitude="personne.latitude"
                            :longitude="personne.longitude"
                        />
                    </section>
                </div>

                <div v-else-if="onglet === 'fonctions'">
                    <ListeFonctions :fonctions="fonctions" />
                </div>

                <div v-else-if="onglet === 'entreprises'">
                    <ul class="divide-y rounded-xl border bg-card">
                        <li
                            v-for="e in entreprises"
                            :key="e.id"
                            class="flex items-center justify-between gap-3 px-4 py-3"
                            data-testid="entreprise-representee"
                        >
                            <div class="min-w-0">
                                <Link
                                    :href="`/backoffice/contacts/entreprises/${e.entreprise_id}`"
                                    class="text-sm font-medium hover:underline"
                                >
                                    {{ e.raison_sociale || 'Entreprise' }}
                                </Link>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        e.fonction || 'Fonction non renseignée'
                                    }}
                                    <span v-if="e.est_principal">
                                        · Interlocuteur principal</span
                                    >
                                </p>
                            </div>
                            <StatusDot
                                class="shrink-0 text-xs text-muted-foreground"
                                :status="e.is_active ? 'actif' : 'inactif'"
                                :label="e.is_active ? 'Actif' : 'Inactif'"
                            />
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <AjouterFonctionDialog
            v-if="fonctions_ajoutables.length > 0"
            v-model:visible="ajoutVisible"
            :url="`/backoffice/contacts/personnes/${personne.id}/fonctions`"
            :nom-fiche="personne.nom_affiche"
            :fonctions="fonctions_ajoutables"
            :types-prestataire="types_prestataire"
        />
    </AppLayout>
</template>
