<script setup lang="ts">
import PhoneCountryInput from '@/components/PhoneCountryInput.vue';
import EmployeDossierFields from '@/components/rh/EmployeDossierFields.vue';
import FonctionRhSelect from '@/components/rh/FonctionRhSelect.vue';
import { type NationaliteOption } from '@/components/rh/NationaliteSelect.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { useFlashToast } from '@/composables/useFlashToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    Eye,
    Info,
    Loader2,
    Pencil,
    Save,
    Search,
} from 'lucide-vue-next';
import Select from 'primevue/select';
import { computed, ref } from 'vue';

useFlashToast();

interface Option {
    value: string;
    label: string;
}

/** Réponse de GET /backoffice/employes/recherche-telephone (RechercheEmployeParTelephone). */
interface ResultatRecherche {
    statut: 'employe' | 'personne' | 'nouveau' | 'invalide';
    message?: string;
    telephone?: string;
    pays?: string;
    code_pays?: string;
    employes?: {
        id: string;
        nom_complet: string;
        matricule: string | null;
        statut: string;
        statut_label: string;
        site: string | null;
        peut_consulter: boolean;
        peut_modifier: boolean;
    }[];
    dossiers_archives?: number;
    personne?: {
        prenom: string | null;
        nom: string | null;
        nom_complet: string | null;
        email: string | null;
        roles: string[];
    } | null;
}

defineProps<{
    type_employe_options: Option[];
    statut_options: Option[];
    sites: Option[];
    fonctions: Option[];
    services: Option[];
    civilite_options: Option[];
    nationalite_options: NationaliteOption[];
    lien_contact_options: Option[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Employés', href: '/backoffice/employes' },
    { title: 'Nouveau', href: '#' },
];

const form = useForm({
    nom: '',
    prenom: '',
    email: '',
    telephone: '',
    type_employe: 'interne',
    site_id: null as string | null,
    fonction_rh_id: null as string | null,
    statut: 'actif',
    // Dossier salarié (facultatif)
    service_rh_id: null as string | null,
    date_naissance: '',
    lieu_naissance: '',
    civilite: '',
    nationalite: '',
    contact_urgence_nom: '',
    contact_urgence_lien: '',
    contact_urgence_lien_precision: '',
    contact_urgence_telephone: '',
    numero_cnss: '',
});

// ── Étape 1 : le téléphone d'abord ─────────────────────────────────────────────
// La recherche évite de créer un second dossier pour une personne déjà connue ; le serveur
// revérifie de toute façon à l'enregistrement (même clé, sous verrou).
const telephoneSaisi = ref('');
const telephoneValide = ref(false);
const recherche = ref<ResultatRecherche | null>(null);
const rechercheEnCours = ref(false);
const erreurRecherche = ref<string | null>(null);
// Clé du champ téléphone : la vider pour « Rechercher un autre numéro ».
const cleTelephone = ref(0);

// Pas de création sans recherche du numéro (téléphone obligatoire, revérifié par le serveur).
const peutCreer = computed(
    () =>
        recherche.value?.statut === 'nouveau' ||
        recherche.value?.statut === 'personne',
);
// Personne déjà connue sous un autre rôle : son identité est reprise, pas ressaisie.
const identiteConnue = computed(
    () =>
        recherche.value?.statut === 'personne' &&
        !!recherche.value.personne?.prenom &&
        !!recherche.value.personne?.nom,
);
const paysTelephone = computed(() => recherche.value?.code_pays ?? null);

async function rechercher() {
    if (!telephoneValide.value || rechercheEnCours.value) return;
    rechercheEnCours.value = true;
    erreurRecherche.value = null;
    try {
        const params = new URLSearchParams({ telephone: telephoneSaisi.value });
        const reponse = await fetch(
            `/backoffice/employes/recherche-telephone?${params.toString()}`,
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );
        if (!reponse.ok) {
            erreurRecherche.value =
                'La recherche n’a pas abouti. Réessayez dans un instant.';
            return;
        }
        const resultat = (await reponse.json()) as ResultatRecherche;
        if (resultat.statut === 'invalide') {
            erreurRecherche.value = resultat.message ?? 'Numéro invalide.';
            return;
        }
        recherche.value = resultat;
        form.clearErrors();
        form.telephone = resultat.telephone ?? '';
        if (resultat.statut === 'personne' && resultat.personne) {
            form.prenom = resultat.personne.prenom ?? '';
            form.nom = resultat.personne.nom ?? '';
            form.email = resultat.personne.email ?? '';
        }
    } catch {
        erreurRecherche.value =
            'La recherche n’a pas abouti (connexion). Réessayez dans un instant.';
    } finally {
        rechercheEnCours.value = false;
    }
}

function changerDeNumero() {
    recherche.value = null;
    erreurRecherche.value = null;
    form.reset();
    telephoneSaisi.value = '';
    cleTelephone.value++;
}

function submit() {
    form.post('/backoffice/employes');
}
</script>

<template>
    <Head><title>Nouvel employé</title></Head>
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-4xl p-4 sm:p-6">
            <div class="mb-6 flex items-center gap-4">
                <Link href="/backoffice/employes">
                    <Button variant="ghost" size="icon"
                        ><ArrowLeft class="h-4 w-4"
                    /></Button>
                </Link>
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Nouvel employé
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Commencez par le numéro de téléphone : l’application
                        vérifie que la personne n’est pas déjà enregistrée. Le
                        matricule sera généré automatiquement.
                    </p>
                </div>
            </div>

            <!-- Étape 1 : téléphone + Rechercher -->
            <form
                class="mb-6 space-y-4 rounded-xl border bg-card p-6 shadow-sm"
                @submit.prevent="rechercher"
            >
                <h3
                    class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    Téléphone de l’employé
                </h3>

                <div v-if="!recherche" class="space-y-3">
                    <label for="telephone" class="block text-sm font-medium"
                        >Numéro de téléphone
                        <span class="text-destructive">*</span></label
                    >
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <div class="sm:w-96">
                            <PhoneCountryInput
                                id="telephone"
                                :key="cleTelephone"
                                :model-value="telephoneSaisi"
                                :remember-choice="false"
                                :required="false"
                                autofocus
                                @update:model-value="telephoneSaisi = $event"
                                @update:valid="telephoneValide = $event"
                            />
                        </div>
                        <Button
                            type="submit"
                            :disabled="!telephoneValide || rechercheEnCours"
                            data-testid="rechercher-telephone"
                        >
                            <Loader2
                                v-if="rechercheEnCours"
                                class="mr-2 h-4 w-4 animate-spin"
                            />
                            <Search v-else class="mr-2 h-4 w-4" />
                            Rechercher
                        </Button>
                    </div>
                    <p
                        v-if="erreurRecherche"
                        class="text-xs text-destructive"
                        data-testid="erreur-recherche"
                    >
                        {{ erreurRecherche }}
                    </p>
                </div>

                <template v-else>
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <p class="text-sm">
                            <span class="font-mono font-medium">{{
                                recherche?.telephone
                            }}</span>
                            <span class="text-muted-foreground">
                                · {{ recherche?.pays }}</span
                            >
                        </p>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            data-testid="changer-numero"
                            @click="changerDeNumero"
                        >
                            Rechercher un autre numéro
                        </Button>
                    </div>

                    <!-- Un dossier employé existe déjà : aucune nouvelle création. -->
                    <div
                        v-if="recherche?.statut === 'employe'"
                        class="space-y-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300"
                        data-testid="resultat-employe"
                    >
                        <p class="flex gap-2 font-medium">
                            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                            {{
                                (recherche.employes?.length ?? 0) > 1
                                    ? 'Plusieurs dossiers employés correspondent à ce numéro : choisissez le bon.'
                                    : 'Un employé existe déjà avec ce numéro.'
                            }}
                        </p>
                        <ul class="space-y-2">
                            <li
                                v-for="e in recherche.employes"
                                :key="e.id"
                                class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-amber-200 bg-background px-3 py-2 text-foreground dark:border-amber-900/50"
                            >
                                <div class="space-y-0.5">
                                    <p class="font-medium">
                                        {{ e.nom_complet }}
                                        <span
                                            v-if="e.matricule"
                                            class="ml-1 rounded bg-muted px-1.5 py-0.5 font-mono text-xs"
                                            >{{ e.matricule }}</span
                                        >
                                    </p>
                                    <p
                                        class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                                    >
                                        <StatusDot
                                            :status="e.statut"
                                            :label="e.statut_label"
                                        />
                                        <span v-if="e.site"
                                            >· {{ e.site }}</span
                                        >
                                    </p>
                                </div>
                                <div class="flex gap-2">
                                    <Link
                                        v-if="e.peut_consulter"
                                        :href="`/backoffice/employes/${e.id}`"
                                    >
                                        <Button variant="outline" size="sm">
                                            <Eye class="mr-1.5 h-4 w-4" />Ouvrir
                                            la fiche
                                        </Button>
                                    </Link>
                                    <Link
                                        v-if="e.peut_modifier"
                                        :href="`/backoffice/employes/${e.id}/edit`"
                                    >
                                        <Button size="sm">
                                            <Pencil
                                                class="mr-1.5 h-4 w-4"
                                            />Compléter le dossier
                                        </Button>
                                    </Link>
                                </div>
                            </li>
                        </ul>
                        <p
                            v-if="
                                recherche.employes?.some(
                                    (e) => !e.peut_consulter,
                                )
                            "
                            class="text-xs"
                        >
                            Ce dossier relève d’une agence que vous ne gérez pas
                            : demandez à un responsable de cette agence.
                        </p>
                    </div>

                    <!-- Personne connue sous un autre rôle : le dossier lui sera rattaché. -->
                    <div
                        v-else-if="recherche?.statut === 'personne'"
                        class="flex gap-2 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800 dark:border-sky-900/50 dark:bg-sky-950/30 dark:text-sky-300"
                        data-testid="resultat-personne"
                    >
                        <Info class="mt-0.5 h-4 w-4 shrink-0" />
                        <span>
                            Aucun employé avec ce numéro, mais il appartient
                            déjà à
                            <strong>{{
                                recherche.personne?.nom_complet
                            }}</strong>
                            <template v-if="recherche.personne?.roles.length">
                                ({{
                                    recherche.personne.roles.join(', ')
                                }})</template
                            >. Le nouveau dossier employé sera rattaché à cette
                            personne : complétez ci-dessous.
                        </span>
                    </div>

                    <div
                        v-else
                        class="flex gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-300"
                        data-testid="resultat-nouveau"
                    >
                        <CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0" />
                        <span
                            >Aucun employé trouvé. Vous pouvez créer un nouveau
                            dossier.</span
                        >
                    </div>

                    <p
                        v-if="recherche?.dossiers_archives"
                        class="text-xs text-muted-foreground"
                    >
                        Un ancien dossier supprimé existe pour ce numéro : il
                        reste archivé, un nouveau dossier peut être créé
                        (réembauche).
                    </p>
                    <p
                        v-if="form.errors.telephone"
                        class="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive"
                    >
                        {{ form.errors.telephone }}
                    </p>
                </template>
            </form>

            <!-- Étape 2 : le reste du dossier, seulement si une création est possible. -->
            <form v-if="peutCreer" class="space-y-6" @submit.prevent="submit">
                <div class="space-y-5 rounded-xl border bg-card p-6 shadow-sm">
                    <h3
                        class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Informations personnelles
                    </h3>

                    <p
                        v-if="identiteConnue"
                        class="text-xs text-muted-foreground"
                    >
                        Identité déjà connue dans l’application : elle se
                        corrige ensuite depuis la fiche de l’employé.
                    </p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label
                                for="prenom"
                                class="mb-1.5 block text-sm font-medium"
                                >Prénom
                                <span class="text-destructive">*</span></label
                            >
                            <input
                                id="prenom"
                                v-model="form.prenom"
                                type="text"
                                :readonly="identiteConnue"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm read-only:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                :class="{
                                    'border-destructive': form.errors.prenom,
                                }"
                            />
                            <p
                                v-if="form.errors.prenom"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.prenom }}
                            </p>
                        </div>
                        <div>
                            <label
                                for="nom"
                                class="mb-1.5 block text-sm font-medium"
                                >Nom
                                <span class="text-destructive">*</span></label
                            >
                            <input
                                id="nom"
                                v-model="form.nom"
                                type="text"
                                :readonly="identiteConnue"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm read-only:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                :class="{
                                    'border-destructive': form.errors.nom,
                                }"
                            />
                            <p
                                v-if="form.errors.nom"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.nom }}
                            </p>
                        </div>
                        <div class="sm:col-span-2">
                            <label
                                for="email"
                                class="mb-1.5 block text-sm font-medium"
                                >Email</label
                            >
                            <input
                                id="email"
                                v-model="form.email"
                                type="email"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:w-1/2"
                                :class="{
                                    'border-destructive': form.errors.email,
                                }"
                            />
                            <p
                                v-if="form.errors.email"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.email }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="space-y-5 rounded-xl border bg-card p-6 shadow-sm">
                    <h3
                        class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Affectation
                    </h3>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium"
                                >Type d'employé
                                <span class="text-destructive">*</span></label
                            >
                            <Select
                                v-model="form.type_employe"
                                :options="type_employe_options"
                                option-label="label"
                                option-value="value"
                                class="w-full"
                            />
                            <p
                                v-if="form.errors.type_employe"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.type_employe }}
                            </p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium"
                                >Statut
                                <span class="text-destructive">*</span></label
                            >
                            <Select
                                v-model="form.statut"
                                :options="statut_options"
                                option-label="label"
                                option-value="value"
                                class="w-full"
                            />
                            <p
                                v-if="form.errors.statut"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.statut }}
                            </p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium"
                                >Site</label
                            >
                            <Select
                                v-model="form.site_id"
                                :options="[
                                    { value: null, label: '— Aucun site —' },
                                    ...sites,
                                ]"
                                option-label="label"
                                option-value="value"
                                class="w-full"
                            />
                            <p
                                v-if="form.errors.site_id"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.site_id }}
                            </p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium"
                                >Fonction RH</label
                            >
                            <FonctionRhSelect
                                v-model="form.fonction_rh_id"
                                :fonctions="fonctions"
                            />
                        </div>
                    </div>
                </div>

                <EmployeDossierFields
                    v-model:form="form"
                    :services="services"
                    :civilite-options="civilite_options"
                    :nationalite-options="nationalite_options"
                    :lien-contact-options="lien_contact_options"
                    :pays-telephone="paysTelephone"
                    creation
                />

                <div class="flex items-center justify-between">
                    <Link href="/backoffice/employes">
                        <Button type="button" variant="outline"
                            ><ArrowLeft class="mr-2 h-4 w-4" />Annuler</Button
                        >
                    </Link>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        data-testid="creer-employe"
                    >
                        <Save class="mr-2 h-4 w-4" />
                        {{
                            form.processing
                                ? 'Enregistrement…'
                                : "Créer l'employé"
                        }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
