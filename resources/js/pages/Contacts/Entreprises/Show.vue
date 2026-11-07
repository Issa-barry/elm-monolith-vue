<script setup lang="ts">
/**
 * Fiche Entreprise du référentiel Contacts (ADR 0060) : consultation (lot 2), ajout explicite
 * d'une fonction (lot 3) et gestion des interlocuteurs (lot 4 : ajouter, modifier fonction et
 * notes, désigner le principal, désactiver, réactiver). Les actions suivent la permission
 * `contacts.gerer_interlocuteurs` transmise par le serveur, qui la revérifie à chaque requête.
 */
import DetailHeader from '@/components/DetailHeader.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useFlashToast } from '@/composables/useFlashToast';
import { useUrlTab } from '@/composables/useUrlTab';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Building2,
    CircleHelp,
    Layers,
    MoreVertical,
    Plus,
    Users,
} from 'lucide-vue-next';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';
import AjouterFonctionDialog from '../partials/AjouterFonctionDialog.vue';
import AjouterInterlocuteurDialog from '../partials/AjouterInterlocuteurDialog.vue';
import BadgesFonctions from '../partials/BadgesFonctions.vue';
import Coordonnees from '../partials/Coordonnees.vue';
import CopierTelephone from '../partials/CopierTelephone.vue';
import ListeFonctions from '../partials/ListeFonctions.vue';
import ModifierInterlocuteurDialog from '../partials/ModifierInterlocuteurDialog.vue';
import type {
    EntrepriseFiche,
    FonctionContact,
    InterlocuteurFiche,
    OptionFiltre,
} from '../partials/types';

const props = defineProps<{
    entreprise: EntrepriseFiche;
    fonctions: FonctionContact[];
    interlocuteurs: InterlocuteurFiche[];
    /** Lot 3 : fonctions que l'utilisateur peut ajouter et que la fiche ne porte pas. */
    fonctions_ajoutables: OptionFiltre[];
    types_prestataire: OptionFiltre[];
    /** Lot 4 : permission `contacts.gerer_interlocuteurs` (revérifiée côté serveur). */
    peut_gerer_interlocuteurs: boolean;
}>();

useFlashToast();
const confirm = useConfirm();
const toast = useToast();

const ajoutVisible = ref(false);
const ajoutInterlocuteurVisible = ref(false);
const modificationVisible = ref(false);
const interlocuteurModifie = ref<InterlocuteurFiche | null>(null);
const actionEnCours = ref<string | null>(null);

const sansPrincipal = computed(
    () => !props.interlocuteurs.some((i) => i.is_active && i.est_principal),
);

function executer(i: InterlocuteurFiche, action: string) {
    if (actionEnCours.value) return;
    actionEnCours.value = i.id;
    router.post(
        `/backoffice/contacts/entreprises/${props.entreprise.id}/interlocuteurs/${i.id}/${action}`,
        {},
        {
            preserveScroll: true,
            onError: (erreurs) => {
                toast.add({
                    group: 'top',
                    severity: 'error',
                    summary: 'Action impossible',
                    detail:
                        Object.values(erreurs)[0] ??
                        'L’action n’a pas pu être réalisée.',
                    life: 6000,
                });
            },
            onFinish: () => {
                actionEnCours.value = null;
            },
        },
    );
}

function modifier(i: InterlocuteurFiche) {
    interlocuteurModifie.value = i;
    modificationVisible.value = true;
}

function desactiver(i: InterlocuteurFiche) {
    confirm.require({
        message: i.est_principal
            ? `Désactiver « ${i.nom} » ? L'entreprise n'aura plus d'interlocuteur principal. Le lien et son historique sont conservés.`
            : `Désactiver « ${i.nom} » ? Le lien et son historique sont conservés.`,
        header: 'Désactiver l’interlocuteur',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Désactiver',
        acceptClass: 'p-button-danger',
        accept: () => executer(i, 'desactiver'),
    });
}

const ONGLETS = ['informations', 'fonctions', 'interlocuteurs'] as const;
const { onglet, choisir } = useUrlTab(ONGLETS, 'informations');

const onglets = [
    { key: 'informations' as const, label: 'Informations', icon: CircleHelp },
    { key: 'fonctions' as const, label: 'Fonctions', icon: Layers },
    { key: 'interlocuteurs' as const, label: 'Interlocuteurs', icon: Users },
];

function ongletClass(o: (typeof ONGLETS)[number]): string {
    return onglet.value === o
        ? 'bg-primary text-primary-foreground'
        : 'text-muted-foreground hover:bg-muted';
}

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Entreprises', href: '/backoffice/contacts/entreprises' },
    { title: props.entreprise.raison_sociale, href: '#' },
]);

function telephone(valeur: string | null, code: string | null): string {
    return formatPhoneDisplay(valeur, code) ?? '—';
}
</script>

<template>
    <Head :title="`${entreprise.raison_sociale} — Entreprise`" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <DetailHeader
                eyebrow="Entreprise"
                :title="entreprise.raison_sociale"
                :icon="Building2"
                :status-label="entreprise.is_active ? 'Active' : 'Inactive'"
                :status-dot-class="
                    entreprise.is_active
                        ? 'bg-emerald-500'
                        : 'bg-zinc-400 dark:bg-zinc-500'
                "
                data-testid="entreprise-detail-header"
            >
                <template #subtitle>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ entreprise.nom_commercial || '' }}
                    </p>
                    <BadgesFonctions class="mt-2" :fonctions="fonctions" />
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
                    <Link href="/backoffice/contacts/entreprises">
                        <Button variant="outline" size="sm">
                            <ArrowLeft class="mr-1.5 h-4 w-4" />
                            Entreprises
                        </Button>
                    </Link>
                </template>
            </DetailHeader>

            <div class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)]">
                <aside
                    class="grid h-fit grid-cols-2 gap-2 rounded-xl border bg-card p-2 lg:block lg:space-y-2"
                    aria-label="Sections de la fiche entreprise"
                >
                    <button
                        v-for="o in onglets"
                        :key="o.key"
                        type="button"
                        class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                        :class="ongletClass(o.key)"
                        :data-testid="`entreprise-${o.key}-tab`"
                        @click="choisir(o.key)"
                    >
                        <component :is="o.icon" class="h-4 w-4" />
                        {{ o.label }}
                    </button>
                </aside>

                <div v-if="onglet === 'informations'">
                    <section class="rounded-xl border bg-card p-5 sm:p-6">
                        <h2
                            class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Identification
                        </h2>
                        <dl
                            class="mb-6 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2"
                        >
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Raison sociale
                                </dt>
                                <dd class="mt-0.5">
                                    {{ entreprise.raison_sociale }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Nom commercial
                                </dt>
                                <dd class="mt-0.5">
                                    {{ entreprise.nom_commercial || '—' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    RCCM
                                </dt>
                                <dd class="mt-0.5">
                                    {{ entreprise.rccm || '—' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    NIF
                                </dt>
                                <dd class="mt-0.5">
                                    {{ entreprise.identifiant_fiscal || '—' }}
                                </dd>
                            </div>
                        </dl>
                        <h2
                            class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Coordonnées
                        </h2>
                        <Coordonnees
                            :telephone="entreprise.telephone"
                            :telephone-secondaire="
                                entreprise.telephone_secondaire
                            "
                            :code-phone-pays="entreprise.code_phone_pays"
                            :email="entreprise.email"
                            :pays="entreprise.pays"
                            :ville="entreprise.ville"
                            :quartier="entreprise.quartier"
                            :adresse="entreprise.adresse"
                            :latitude="entreprise.latitude"
                            :longitude="entreprise.longitude"
                        />
                    </section>
                </div>

                <div v-else-if="onglet === 'fonctions'">
                    <ListeFonctions :fonctions="fonctions" />
                </div>

                <div v-else-if="onglet === 'interlocuteurs'" class="space-y-3">
                    <div
                        v-if="peut_gerer_interlocuteurs"
                        class="flex flex-wrap items-center justify-end gap-3"
                    >
                        <p
                            v-if="!entreprise.is_active"
                            class="text-sm text-muted-foreground"
                            data-testid="interlocuteur-entreprise-inactive"
                        >
                            Entreprise désactivée : aucun interlocuteur ne peut
                            être ajouté.
                        </p>
                        <Button
                            v-else
                            size="sm"
                            data-testid="interlocuteur-ajouter"
                            @click="ajoutInterlocuteurVisible = true"
                        >
                            <Plus class="mr-1.5 h-4 w-4" />
                            Ajouter un interlocuteur
                        </Button>
                    </div>
                    <ul class="divide-y rounded-xl border bg-card">
                        <li
                            v-for="i in interlocuteurs"
                            :key="i.id"
                            class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                            data-testid="interlocuteur-ligne"
                        >
                            <div class="min-w-0">
                                <Link
                                    :href="`/backoffice/contacts/personnes/${i.personne_id}`"
                                    class="text-sm font-medium hover:underline"
                                >
                                    {{ i.nom }}
                                </Link>
                                <span
                                    v-if="i.est_principal"
                                    class="ml-2 text-xs font-medium text-primary"
                                    data-testid="interlocuteur-principal"
                                    >Principal</span
                                >
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        i.fonction || 'Fonction non renseignée'
                                    }}
                                    <span v-if="i.email"> · {{ i.email }}</span>
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                <span
                                    class="flex items-center gap-1 text-sm tabular-nums"
                                >
                                    {{
                                        telephone(
                                            i.telephone,
                                            i.code_phone_pays,
                                        )
                                    }}
                                    <CopierTelephone
                                        v-if="i.telephone"
                                        :telephone="i.telephone"
                                    />
                                </span>
                                <StatusDot
                                    class="text-xs text-muted-foreground"
                                    :status="i.is_active ? 'actif' : 'inactif'"
                                    :label="
                                        i.is_active
                                            ? 'Actif'
                                            : `Désactivé${i.desactive_le ? ` le ${i.desactive_le}` : ''}`
                                    "
                                />
                                <DropdownMenu v-if="peut_gerer_interlocuteurs">
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="h-8 w-8"
                                            :disabled="actionEnCours === i.id"
                                            :aria-label="`Actions pour ${i.nom}`"
                                            data-testid="interlocuteur-actions"
                                        >
                                            <MoreVertical class="h-4 w-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem
                                            data-testid="interlocuteur-action-modifier"
                                            @click="modifier(i)"
                                        >
                                            Modifier la fonction et les notes
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="
                                                i.is_active && !i.est_principal
                                            "
                                            data-testid="interlocuteur-action-principal"
                                            @click="executer(i, 'principal')"
                                        >
                                            Définir comme principal
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            v-if="i.is_active"
                                            class="text-destructive"
                                            data-testid="interlocuteur-action-desactiver"
                                            @click="desactiver(i)"
                                        >
                                            Désactiver
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-else
                                            data-testid="interlocuteur-action-reactiver"
                                            @click="executer(i, 'reactiver')"
                                        >
                                            Réactiver
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </li>
                        <li
                            v-if="interlocuteurs.length === 0"
                            class="px-4 py-8 text-center text-sm text-muted-foreground"
                        >
                            Aucun interlocuteur enregistré.
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <AjouterFonctionDialog
            v-if="fonctions_ajoutables.length > 0"
            v-model:visible="ajoutVisible"
            :url="`/backoffice/contacts/entreprises/${entreprise.id}/fonctions`"
            :nom-fiche="entreprise.raison_sociale"
            :fonctions="fonctions_ajoutables"
            :types-prestataire="types_prestataire"
        />

        <template v-if="peut_gerer_interlocuteurs">
            <AjouterInterlocuteurDialog
                v-if="entreprise.is_active"
                v-model:visible="ajoutInterlocuteurVisible"
                :entreprise-id="entreprise.id"
                :nom-entreprise="entreprise.raison_sociale"
                :sans-principal="sansPrincipal"
            />
            <ModifierInterlocuteurDialog
                v-model:visible="modificationVisible"
                :entreprise-id="entreprise.id"
                :interlocuteur="interlocuteurModifie"
            />
        </template>
    </AppLayout>
</template>
