<script setup lang="ts">
import InfoTooltip from '@/components/InfoTooltip.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF, formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    Briefcase,
    Crown,
    History,
    KeyRound,
    Loader2,
    LogOut,
    Mail,
    Pencil,
    Phone,
    Plus,
    RotateCcw,
    ShieldCheck,
    UserRound,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import { computed, ref } from 'vue';
import {
    CARTE_FICHE,
    ENTETE_CARTE_FICHE,
    TITRE_CARTE_FICHE,
} from './partials/carteFiche';
import PrimesSalarie, {
    type Affectation,
    type PrimesOptions,
} from './partials/PrimesSalarie.vue';
import RemunerationSalarie, {
    type Remuneration,
} from './partials/RemunerationSalarie.vue';

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
    service_libelle: string | null;
    date_embauche: string | null;
    date_sortie: string | null;
    motif_sortie: string | null;
    date_naissance: string | null;
    lieu_naissance: string | null;
    civilite_label: string | null;
    nationalite: string | null;
    contact_urgence_nom: string | null;
    contact_urgence_lien: string | null;
    contact_urgence_telephone: string | null;
    // null sans la permission rh-employes.donnees_sensibles (jamais envoyé)
    donnees_sensibles: {
        numero_cnss: string | null;
        paiement_mode_label: string | null;
        paiement_etablissement: string | null;
        paiement_numero_compte: string | null;
        paiement_titulaire: string | null;
    } | null;
    contrat_actif: {
        type_contrat: string;
        type_contrat_label: string;
        date_debut: string | null;
        date_fin: string | null;
    } | null;
    contrats: ContratRow[];
    affectations: AffectationRow[];
    compte: CompteInfo | null;
    // Enregistrer / annuler le départ : calculé par le serveur (permissions, organisation, agence).
    peut_gerer_depart: boolean;
    // Modifier, nouveau contrat : agence de rattachement (calculé par le serveur).
    dans_perimetre_ecriture: boolean;
    // PDG de l'organisation (ADR 0048) : bouton calculé par le serveur (rh-referentiels.gerer).
    est_pdg: boolean;
    pdg_actuel: string | null;
    peut_designer_pdg: boolean;
}

const props = defineProps<{
    employe: EmployeData;
    // null sans la permission rh-affectations-remuneration.read (jamais envoyé)
    primes: Affectation[] | null;
    primes_options: PrimesOptions | null;
    // null sans rh-remunerations.read ET la lecture des primes (jamais envoyé)
    remuneration?: Remuneration | null;
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Employés', href: '/backoffice/employes' },
    { title: props.employe.nom_complet, href: '#' },
];

// Type de contrat = catégorie (pas un statut) : fond coloré autorisé.
const TYPE_CONTRAT_CLASS: Record<string, string> = {
    cdi: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
    cdd: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
};

const initiales = computed(() =>
    props.employe.nom_complet
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((mot) => mot[0]?.toUpperCase() ?? '')
        .join(''),
);

const peutCreerContrat = computed(
    () =>
        can('rh-contrats.create') &&
        !props.employe.contrat_actif &&
        props.employe.statut !== 'sorti' &&
        props.employe.dans_perimetre_ecriture,
);

const peutModifierContrat = computed(
    () => can('rh-contrats.update') && props.employe.dans_perimetre_ecriture,
);

// Départ du salarié (docs/rh-depart-salarie.md)
const aujourdhui = new Date().toLocaleDateString('en-CA');
const departOuvert = ref(false);
const annulationOuverte = ref(false);
const depart = useForm({ date_sortie: aujourdhui, motif_sortie: '' });
const annulation = useForm({});
// Refus serveur de l'annulation (paie figée, autre contrat actif) : clé `depart`.
const erreurAnnulation = computed(
    () => (annulation.errors as Record<string, string | undefined>).depart,
);

function enregistrerDepart() {
    depart.post(`/backoffice/employes/${props.employe.id}/depart`, {
        preserveScroll: true,
        onSuccess: () => {
            departOuvert.value = false;
            depart.reset();
        },
    });
}

function annulerDepart() {
    annulation.delete(`/backoffice/employes/${props.employe.id}/depart`, {
        preserveScroll: true,
        onSuccess: () => (annulationOuverte.value = false),
    });
}

// PDG de l'organisation, signataire des contrats de travail (docs/rh-contrat-pdf.md, ADR 0048).
const pdgOuvert = ref(false);
const designationPdg = useForm({});
const erreurPdg = computed(
    () => (designationPdg.errors as Record<string, string | undefined>).pdg,
);

function designerPdg() {
    designationPdg.post(
        `/backoffice/employes/${props.employe.id}/designer-pdg`,
        {
            preserveScroll: true,
            onSuccess: () => (pdgOuvert.value = false),
        },
    );
}

/** Date ISO (AAAA-MM-JJ) → JJ/MM/AAAA, sans dépendre du fuseau du navigateur. */
function dateFr(iso: string | null): string {
    if (!iso) return '—';
    const [annee, mois, jour] = iso.split('-');
    return `${jour}/${mois}/${annee}`;
}
</script>

<template>
    <Head
        ><title>{{ employe.nom_complet }}</title></Head
    >
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="@container w-full space-y-6 p-4 sm:p-6">
            <!-- ─── En-tête : identité, poste, actions ─── -->
            <section
                class="rounded-2xl border border-border/60 bg-muted/25 px-5 py-6 sm:px-8 sm:py-7"
                data-testid="employe-entete"
            >
                <div
                    class="flex flex-col gap-5 @[56rem]:flex-row @[56rem]:items-start @[56rem]:justify-between"
                >
                    <div class="flex min-w-0 items-start gap-4">
                        <div
                            class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-primary text-xl font-semibold text-primary-foreground"
                            aria-hidden="true"
                        >
                            {{ initiales }}
                        </div>
                        <div class="min-w-0">
                            <p
                                class="text-xs font-medium tracking-widest text-muted-foreground uppercase"
                            >
                                Employé
                            </p>
                            <div
                                class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1"
                            >
                                <h1
                                    class="text-[1.75rem] leading-tight font-semibold tracking-tight break-words"
                                >
                                    {{ employe.nom_complet }}
                                </h1>
                                <StatusDot
                                    :status="employe.statut"
                                    :label="employe.statut_label"
                                    class="text-sm text-muted-foreground"
                                />
                            </div>
                            <p
                                class="mt-1 text-sm font-medium text-foreground/80"
                            >
                                {{
                                    employe.fonction_libelle ?? 'Sans fonction'
                                }}
                                <span class="text-muted-foreground">
                                    · {{ employe.site ?? 'Sans agence' }}</span
                                >
                            </p>
                            <div
                                class="mt-3 flex flex-wrap items-center gap-2 text-xs"
                            >
                                <span
                                    v-if="employe.matricule"
                                    class="rounded-md border bg-background px-2 py-0.5 font-mono"
                                    title="Matricule"
                                    >{{ employe.matricule }}</span
                                >
                                <span
                                    class="rounded-md border bg-background px-2 py-0.5 text-muted-foreground"
                                    >{{ employe.type_employe_label }}</span
                                >
                                <span
                                    v-if="employe.est_pdg"
                                    class="inline-flex items-center gap-1 rounded-md border bg-background px-2 py-0.5 font-medium"
                                    data-testid="badge-pdg"
                                    ><Crown class="h-3.5 w-3.5" />PDG de
                                    l’organisation (signataire des
                                    contrats)</span
                                >
                            </div>
                        </div>
                    </div>

                    <div
                        class="grid grid-cols-2 gap-2 @[40rem]:flex @[40rem]:flex-wrap @[56rem]:justify-end"
                    >
                        <Button
                            v-if="employe.peut_designer_pdg && !employe.est_pdg"
                            variant="outline"
                            size="sm"
                            class="h-9 bg-background"
                            data-testid="designer-pdg"
                            @click="pdgOuvert = true"
                        >
                            <Crown class="mr-1.5 h-4 w-4" />
                            Désigner comme PDG
                        </Button>
                        <Button
                            v-if="
                                employe.peut_gerer_depart &&
                                employe.statut !== 'sorti'
                            "
                            variant="outline"
                            size="sm"
                            class="h-9 bg-background"
                            data-testid="enregistrer-depart"
                            @click="departOuvert = true"
                        >
                            <LogOut class="mr-1.5 h-4 w-4" />
                            Enregistrer le départ
                        </Button>
                        <Button
                            v-if="
                                employe.peut_gerer_depart &&
                                employe.statut === 'sorti'
                            "
                            variant="outline"
                            size="sm"
                            class="h-9 bg-background"
                            data-testid="annuler-depart"
                            @click="annulationOuverte = true"
                        >
                            <RotateCcw class="mr-1.5 h-4 w-4" />
                            Annuler le départ
                        </Button>
                        <Link
                            v-if="
                                can('rh-employes.update') &&
                                employe.dans_perimetre_ecriture
                            "
                            :href="`/backoffice/employes/${employe.id}/edit`"
                        >
                            <Button size="sm" class="h-9 w-full">
                                <Pencil class="mr-1.5 h-4 w-4" />
                                Modifier
                            </Button>
                        </Link>
                    </div>
                </div>

                <!-- Repères du poste : lus d'un coup d'œil, sans ouvrir de bloc -->
                <dl
                    class="mt-6 grid grid-cols-2 gap-x-6 gap-y-4 border-t border-border/60 pt-5 text-sm @[48rem]:grid-cols-4"
                >
                    <div class="min-w-0">
                        <dt class="text-xs text-muted-foreground">
                            Contrat en cours
                        </dt>
                        <dd class="mt-0.5 font-medium">
                            <template v-if="employe.contrat_actif">
                                {{ employe.contrat_actif.type_contrat_label }}
                                <span class="font-normal text-muted-foreground"
                                    >depuis le
                                    {{ employe.contrat_actif.date_debut }}</span
                                >
                            </template>
                            <span v-else class="text-muted-foreground"
                                >Aucun</span
                            >
                        </dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-xs text-muted-foreground">
                            Date d'embauche
                        </dt>
                        <dd class="mt-0.5 font-medium">
                            {{ employe.date_embauche ?? '—' }}
                        </dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-xs text-muted-foreground">Service</dt>
                        <dd class="mt-0.5 font-medium break-words">
                            {{ employe.service_libelle ?? '—' }}
                        </dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-xs text-muted-foreground">
                            Coordonnées
                        </dt>
                        <dd class="mt-0.5 space-y-0.5 font-medium">
                            <p class="flex items-center gap-1.5">
                                <Phone
                                    class="h-3.5 w-3.5 shrink-0 text-muted-foreground"
                                />
                                {{
                                    employe.telephone
                                        ? formatPhoneDisplay(employe.telephone)
                                        : '—'
                                }}
                            </p>
                            <p class="flex min-w-0 items-center gap-1.5">
                                <Mail
                                    class="h-3.5 w-3.5 shrink-0 text-muted-foreground"
                                />
                                <span
                                    class="truncate"
                                    :title="employe.email ?? ''"
                                    >{{ employe.email ?? '—' }}</span
                                >
                            </p>
                        </dd>
                    </div>
                </dl>
            </section>

            <div
                class="grid items-start gap-6 @[64rem]:grid-cols-[minmax(0,1fr)_22rem] @[80rem]:grid-cols-[minmax(0,1fr)_26rem]"
            >
                <!-- ─── Colonne principale : rémunération, contrats, primes, affectations ─── -->
                <div class="min-w-0 space-y-6">
                    <RemunerationSalarie
                        v-if="remuneration"
                        :remuneration="remuneration"
                        class="@container"
                    />

                    <section
                        :class="CARTE_FICHE"
                        data-testid="employe-contrats"
                    >
                        <div :class="ENTETE_CARTE_FICHE">
                            <h2 :class="TITRE_CARTE_FICHE">
                                <Briefcase
                                    class="h-4 w-4 text-muted-foreground"
                                />
                                Contrats
                                <span
                                    v-if="employe.contrats.length > 0"
                                    class="text-sm font-normal text-muted-foreground"
                                    >({{ employe.contrats.length }})</span
                                >
                            </h2>
                            <Link
                                v-if="peutCreerContrat"
                                :href="`/backoffice/contrats/create?employe_id=${employe.id}`"
                            >
                                <Button size="sm" data-testid="nouveau-contrat">
                                    <Plus class="mr-1.5 h-4 w-4" />
                                    Nouveau contrat
                                </Button>
                            </Link>
                        </div>
                        <p
                            v-if="employe.statut === 'sorti'"
                            class="border-b bg-muted/40 px-5 py-3 text-xs text-muted-foreground sm:px-6"
                            data-testid="reembauche-non-definie"
                        >
                            Salarié sorti : aucun nouveau contrat ne peut lui
                            être créé tant qu'une procédure de réembauche n'est
                            pas définie. « Annuler le départ » reste possible si
                            le départ a été enregistré par erreur.
                        </p>
                        <div
                            v-if="employe.contrats.length === 0"
                            class="p-8 text-center text-sm text-muted-foreground"
                        >
                            Aucun contrat enregistré.
                        </div>
                        <ul v-else class="divide-y">
                            <li
                                v-for="c in employe.contrats"
                                :key="c.id"
                                class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-4 sm:px-6"
                            >
                                <div class="min-w-0 flex-1 space-y-1">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <span
                                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold"
                                            :class="
                                                TYPE_CONTRAT_CLASS[
                                                    c.type_contrat
                                                ] ?? 'bg-muted'
                                            "
                                        >
                                            {{ c.type_contrat_label }}
                                        </span>
                                        <StatusDot
                                            :status="c.statut_contrat"
                                            :label="c.statut_contrat_label"
                                        />
                                    </div>
                                    <p class="text-sm text-muted-foreground">
                                        Du
                                        <span class="text-foreground">{{
                                            c.date_debut
                                        }}</span>
                                        <template v-if="c.date_fin">
                                            au
                                            <span class="text-foreground">{{
                                                c.date_fin
                                            }}</span></template
                                        >
                                        <template v-else>
                                            · durée indéterminée</template
                                        >
                                    </p>
                                </div>
                                <!-- Salaire : transmis seulement avec rh-remunerations.read -->
                                <div v-if="c.salaire_base" class="text-right">
                                    <p class="text-xs text-muted-foreground">
                                        Salaire de base
                                    </p>
                                    <p
                                        class="font-semibold whitespace-nowrap tabular-nums"
                                    >
                                        {{ formatGNF(Number(c.salaire_base)) }}
                                    </p>
                                </div>
                                <Link
                                    v-if="peutModifierContrat"
                                    :href="`/backoffice/contrats/${c.id}/edit`"
                                    :aria-label="`Modifier le contrat ${c.type_contrat_label}`"
                                    title="Modifier le contrat"
                                >
                                    <Button
                                        variant="outline"
                                        size="icon"
                                        class="h-8 w-8"
                                    >
                                        <Pencil class="h-3.5 w-3.5" />
                                    </Button>
                                </Link>
                            </li>
                        </ul>
                    </section>

                    <!-- Primes et avantages : transmis seulement avec rh-affectations-remuneration.read -->
                    <PrimesSalarie
                        v-if="primes !== null && primes_options !== null"
                        :key="employe.id"
                        :employe-id="employe.id"
                        :primes="primes"
                        :options="primes_options"
                    />

                    <section
                        v-if="employe.affectations.length > 0"
                        :class="CARTE_FICHE"
                        data-testid="employe-affectations"
                    >
                        <div :class="ENTETE_CARTE_FICHE">
                            <h2 :class="TITRE_CARTE_FICHE">
                                <History
                                    class="h-4 w-4 text-muted-foreground"
                                />
                                Historique d'affectation
                            </h2>
                        </div>
                        <ol class="space-y-0 px-5 py-4 sm:px-6">
                            <li
                                v-for="(a, i) in employe.affectations"
                                :key="a.id"
                                class="relative flex gap-4 pb-5 last:pb-0"
                            >
                                <span
                                    v-if="i < employe.affectations.length - 1"
                                    class="absolute top-4 left-[5px] h-full w-px bg-border"
                                    aria-hidden="true"
                                />
                                <span
                                    class="relative mt-1.5 h-[11px] w-[11px] shrink-0 rounded-full border-2"
                                    :class="
                                        a.active
                                            ? 'border-primary bg-primary'
                                            : 'border-border bg-background'
                                    "
                                    aria-hidden="true"
                                />
                                <div
                                    class="flex min-w-0 flex-1 flex-wrap items-start justify-between gap-x-3 gap-y-1 text-sm"
                                >
                                    <div class="min-w-0">
                                        <p class="font-medium">
                                            {{ a.site ?? '—' }}
                                            <span
                                                v-if="a.fonction_libelle"
                                                class="font-normal text-muted-foreground"
                                                >—
                                                {{ a.fonction_libelle }}</span
                                            >
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            Du {{ a.debut_at }}
                                            <template v-if="a.fin_at">
                                                au {{ a.fin_at }}</template
                                            >
                                        </p>
                                    </div>
                                    <StatusDot
                                        v-if="a.active"
                                        status="en_cours"
                                        label="En cours"
                                        size="sm"
                                    />
                                </div>
                            </li>
                        </ol>
                    </section>
                </div>

                <!-- ─── Colonne latérale : dossier personnel, données sensibles, compte ─── -->
                <aside class="min-w-0 space-y-6">
                    <section :class="CARTE_FICHE" data-testid="employe-dossier">
                        <div :class="ENTETE_CARTE_FICHE">
                            <h2 :class="TITRE_CARTE_FICHE">
                                <UserRound
                                    class="h-4 w-4 text-muted-foreground"
                                />
                                Informations personnelles
                            </h2>
                        </div>
                        <dl class="divide-y px-5 text-sm sm:px-6">
                            <div
                                class="flex items-start justify-between gap-4 py-3"
                            >
                                <dt class="text-muted-foreground">Civilité</dt>
                                <dd class="text-right font-medium">
                                    {{ employe.civilite_label ?? '—' }}
                                </dd>
                            </div>
                            <div
                                class="flex items-start justify-between gap-4 py-3"
                            >
                                <dt class="text-muted-foreground">
                                    Nationalité
                                </dt>
                                <dd class="text-right font-medium">
                                    {{ employe.nationalite ?? '—' }}
                                </dd>
                            </div>
                            <div
                                class="flex items-start justify-between gap-4 py-3"
                            >
                                <dt class="text-muted-foreground">Naissance</dt>
                                <dd class="text-right font-medium">
                                    {{ dateFr(employe.date_naissance) }}
                                    <span
                                        v-if="employe.lieu_naissance"
                                        class="block text-xs font-normal text-muted-foreground"
                                        >à {{ employe.lieu_naissance }}</span
                                    >
                                </dd>
                            </div>
                            <div class="py-3">
                                <dt class="text-muted-foreground">
                                    Contact d'urgence
                                </dt>
                                <dd class="mt-1 font-medium">
                                    <template
                                        v-if="employe.contact_urgence_nom"
                                    >
                                        {{ employe.contact_urgence_nom }}
                                        <span
                                            v-if="employe.contact_urgence_lien"
                                            class="font-normal text-muted-foreground"
                                            >({{
                                                employe.contact_urgence_lien
                                            }})</span
                                        >
                                        <span
                                            v-if="
                                                employe.contact_urgence_telephone
                                            "
                                            class="mt-0.5 flex items-center gap-1.5 font-normal text-muted-foreground"
                                        >
                                            <Phone class="h-3.5 w-3.5" />
                                            {{
                                                formatPhoneDisplay(
                                                    employe.contact_urgence_telephone,
                                                )
                                            }}</span
                                        >
                                    </template>
                                    <template v-else>—</template>
                                </dd>
                            </div>
                            <div
                                v-if="employe.date_sortie"
                                class="flex items-start justify-between gap-4 py-3"
                            >
                                <dt class="text-muted-foreground">
                                    Date de sortie
                                </dt>
                                <dd class="text-right font-medium">
                                    {{ dateFr(employe.date_sortie) }}
                                </dd>
                            </div>
                            <div v-if="employe.motif_sortie" class="py-3">
                                <dt class="text-muted-foreground">
                                    Motif de sortie
                                </dt>
                                <dd class="mt-1 font-medium">
                                    {{ employe.motif_sortie }}
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <!-- Données sensibles : envoyées par le serveur seulement avec la permission -->
                    <section
                        v-if="employe.donnees_sensibles"
                        :class="CARTE_FICHE"
                        data-testid="employe-donnees-sensibles"
                    >
                        <div :class="ENTETE_CARTE_FICHE">
                            <h2 :class="TITRE_CARTE_FICHE">
                                <KeyRound
                                    class="h-4 w-4 text-muted-foreground"
                                />
                                Données sensibles
                            </h2>
                        </div>
                        <dl class="divide-y px-5 text-sm sm:px-6">
                            <div
                                class="flex items-start justify-between gap-4 py-3"
                            >
                                <dt class="text-muted-foreground">N° CNSS</dt>
                                <dd class="text-right font-medium">
                                    {{
                                        employe.donnees_sensibles.numero_cnss ??
                                        '—'
                                    }}
                                </dd>
                            </div>
                            <!-- Coordonnées saisies avant le 30/10/2026 : lecture seule, le mode est désormais choisi par la Comptabilité au paiement. -->
                            <div
                                v-if="
                                    employe.donnees_sensibles
                                        .paiement_mode_label
                                "
                                class="py-3"
                            >
                                <dt class="text-muted-foreground">
                                    Paiement du salaire (ancienne saisie)
                                </dt>
                                <dd class="mt-1 font-medium break-words">
                                    {{
                                        employe.donnees_sensibles
                                            .paiement_mode_label
                                    }}
                                    <template
                                        v-if="
                                            employe.donnees_sensibles
                                                .paiement_numero_compte
                                        "
                                    >
                                        ·
                                        {{
                                            employe.donnees_sensibles
                                                .paiement_etablissement
                                        }}
                                        {{
                                            employe.donnees_sensibles
                                                .paiement_numero_compte
                                        }}
                                    </template>
                                </dd>
                            </div>
                            <div
                                v-if="
                                    employe.donnees_sensibles
                                        .paiement_mode_label &&
                                    employe.donnees_sensibles.paiement_titulaire
                                "
                                class="flex items-start justify-between gap-4 py-3"
                            >
                                <dt class="text-muted-foreground">
                                    Titulaire du compte
                                </dt>
                                <dd class="text-right font-medium">
                                    {{
                                        employe.donnees_sensibles
                                            .paiement_titulaire
                                    }}
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <!-- Compte utilisateur & profil d'accès -->
                    <section :class="CARTE_FICHE" data-testid="employe-compte">
                        <div :class="ENTETE_CARTE_FICHE">
                            <h2 :class="TITRE_CARTE_FICHE">
                                <ShieldCheck
                                    class="h-4 w-4 text-muted-foreground"
                                />
                                Compte & profil d'accès
                            </h2>
                        </div>
                        <p
                            v-if="!employe.compte"
                            class="px-5 py-4 text-sm text-muted-foreground sm:px-6"
                        >
                            Aucun compte applicatif rattaché à cet employé.
                        </p>
                        <dl v-else class="divide-y px-5 text-sm sm:px-6">
                            <div
                                class="flex items-start justify-between gap-4 py-3"
                            >
                                <dt class="text-muted-foreground">Statut</dt>
                                <dd>
                                    <StatusDot
                                        :status="employe.compte.statut"
                                        :label="employe.compte.statut_label"
                                    />
                                </dd>
                            </div>
                            <div
                                class="flex items-start justify-between gap-4 py-3"
                            >
                                <dt class="text-muted-foreground">
                                    Profil d'accès
                                </dt>
                                <dd class="text-right font-medium">
                                    {{ employe.compte.role_label ?? '—' }}
                                </dd>
                            </div>
                            <div class="py-3">
                                <dt class="text-muted-foreground">
                                    Email de connexion
                                </dt>
                                <dd class="mt-1 font-medium break-all">
                                    {{ employe.compte.email }}
                                </dd>
                            </div>
                        </dl>
                    </section>
                </aside>
            </div>
        </div>

        <Dialog
            :visible="departOuvert"
            modal
            :closable="!depart.processing"
            header="Enregistrer le départ"
            :style="{ width: 'min(30rem, 95vw)' }"
            @update:visible="
                (v: boolean) => {
                    if (!v && !depart.processing) departOuvert = false;
                }
            "
        >
            <div class="space-y-4">
                <p class="text-sm text-muted-foreground">
                    Le salarié passe en « Sorti » à cette date. Son contrat en
                    cours est terminé à la même date et la paie s'arrête à la
                    date de sortie. Le départ peut être annulé tant que la paie
                    du mois n'est pas figée.
                </p>
                <div>
                    <div class="mb-1.5 flex items-center gap-1.5">
                        <label for="depart-date" class="text-xs font-medium"
                            >Date de sortie effective</label
                        >
                        <InfoTooltip label="Aide : date de sortie effective">
                            Date à laquelle le salarié quitte effectivement
                            l'entreprise. Elle est distincte de la date de fin
                            prévue d'un CDD et ne peut pas être dans le futur.
                        </InfoTooltip>
                    </div>
                    <input
                        id="depart-date"
                        v-model="depart.date_sortie"
                        type="date"
                        :max="aujourdhui"
                        class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    />
                    <p
                        v-if="depart.errors.date_sortie"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ depart.errors.date_sortie }}
                    </p>
                </div>
                <div>
                    <div class="mb-1.5 flex items-center gap-1.5">
                        <label for="depart-motif" class="text-xs font-medium"
                            >Motif de sortie</label
                        >
                        <InfoTooltip label="Aide : motif de sortie">
                            Raison du départ, selon les procédures RH : fin de
                            CDD, démission, licenciement, départ à la retraite…
                        </InfoTooltip>
                    </div>
                    <input
                        id="depart-motif"
                        v-model="depart.motif_sortie"
                        type="text"
                        maxlength="255"
                        placeholder="Ex : fin de CDD, démission"
                        class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    />
                    <p
                        v-if="depart.errors.motif_sortie"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ depart.errors.motif_sortie }}
                    </p>
                </div>
                <div class="flex justify-end gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="depart.processing"
                        @click="departOuvert = false"
                        >Annuler</Button
                    >
                    <Button
                        size="sm"
                        :disabled="
                            depart.processing ||
                            !depart.date_sortie ||
                            !depart.motif_sortie.trim()
                        "
                        data-testid="confirmer-depart"
                        @click="enregistrerDepart"
                    >
                        <Loader2
                            v-if="depart.processing"
                            class="mr-1.5 h-3.5 w-3.5 animate-spin"
                        />
                        Enregistrer le départ
                    </Button>
                </div>
            </div>
        </Dialog>

        <Dialog
            :visible="annulationOuverte"
            modal
            :closable="!annulation.processing"
            header="Annuler le départ"
            :style="{ width: 'min(28rem, 95vw)' }"
            @update:visible="
                (v: boolean) => {
                    if (!v && !annulation.processing) annulationOuverte = false;
                }
            "
        >
            <div class="space-y-4">
                <p class="text-sm text-muted-foreground">
                    Le salarié redevient actif, la date et le motif de sortie
                    sont effacés et le contrat terminé par ce départ est rétabli
                    tel qu'il était.
                </p>
                <p
                    v-if="erreurAnnulation"
                    class="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive"
                >
                    {{ erreurAnnulation }}
                </p>
                <div class="flex justify-end gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="annulation.processing"
                        @click="annulationOuverte = false"
                        >Fermer</Button
                    >
                    <Button
                        size="sm"
                        :disabled="annulation.processing"
                        data-testid="confirmer-annulation-depart"
                        @click="annulerDepart"
                    >
                        <Loader2
                            v-if="annulation.processing"
                            class="mr-1.5 h-3.5 w-3.5 animate-spin"
                        />
                        Annuler le départ
                    </Button>
                </div>
            </div>
        </Dialog>

        <Dialog
            :visible="pdgOuvert"
            modal
            :closable="!designationPdg.processing"
            header="Désigner comme PDG"
            :style="{ width: 'min(28rem, 95vw)' }"
            @update:visible="
                (v: boolean) => {
                    if (!v && !designationPdg.processing) pdgOuvert = false;
                }
            "
        >
            <div class="space-y-4">
                <p class="text-sm text-muted-foreground">
                    {{ employe.nom_complet }} deviendra le PDG de l’organisation
                    : son nom et sa qualité (Président-directeur général)
                    figureront comme représentant de l’employeur sur tous les
                    contrats de travail.
                </p>
                <p
                    v-if="employe.pdg_actuel"
                    class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300"
                >
                    {{ employe.pdg_actuel }} ne sera plus PDG. Les contrats déjà
                    signés ne changent pas.
                </p>
                <p
                    v-if="erreurPdg"
                    class="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive"
                >
                    {{ erreurPdg }}
                </p>
                <div class="flex justify-end gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="designationPdg.processing"
                        @click="pdgOuvert = false"
                        >Annuler</Button
                    >
                    <Button
                        size="sm"
                        :disabled="designationPdg.processing"
                        data-testid="confirmer-designer-pdg"
                        @click="designerPdg"
                    >
                        <Loader2
                            v-if="designationPdg.processing"
                            class="mr-1.5 h-3.5 w-3.5 animate-spin"
                        />
                        Confirmer
                    </Button>
                </div>
            </div>
        </Dialog>
    </AppLayout>
</template>
