<script setup lang="ts">
/**
 * « Nouveau contact » (ADR 0060, lot 3) : type, téléphone puis « Rechercher », identité, première
 * fonction. Un numéro déjà connu n'est jamais rattaché ici : la fiche trouvée est présentée et
 * l'utilisateur choisit explicitement « même personne » (ajout de fonction) ou « autre personne »
 * (arrêt). Une fiche non visible ne renvoie qu'un message neutre.
 */
import PhoneCountryInput from '@/components/PhoneCountryInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Building2,
    Loader2,
    Save,
    Search,
    UserRound,
} from 'lucide-vue-next';
import InputText from 'primevue/inputtext';
import { computed, ref } from 'vue';
import AjouterFonctionDialog from './partials/AjouterFonctionDialog.vue';
import BadgesFonctions from './partials/BadgesFonctions.vue';
import ChampsFonction from './partials/ChampsFonction.vue';
import type { FonctionContact, OptionFiltre } from './partials/types';

type TypeFiche = 'personne' | 'entreprise';

interface FicheConnue {
    id: string;
    type: TypeFiche;
    nom: string;
    ville: string | null;
    lien: string;
    fonctions: FonctionContact[];
    fonctions_ajoutables: OptionFiltre[];
}

interface ResultatRecherche {
    statut: 'libre' | 'connu' | 'indisponible' | 'invalide';
    message?: string;
    message_autre_personne?: string;
    telephone?: string;
    indicatif?: string;
    fiche?: FicheConnue;
}

const props = defineProps<{
    type: TypeFiche;
    fonctions_creables: OptionFiltre[];
    types_prestataire: OptionFiltre[];
}>();

const typeFiche = ref<TypeFiche>(props.type);
const telephoneSaisi = ref('');
const telephoneValide = ref(false);
const cleTelephone = ref(0);
const rechercheEnCours = ref(false);
const erreurRecherche = ref<string | null>(null);
const resultat = ref<ResultatRecherche | null>(null);
const autrePersonne = ref(false);
const ajoutVisible = ref(false);

const form = useForm({
    prenom: '',
    nom: '',
    nom_complet: '',
    raison_sociale: '',
    nom_commercial: '',
    rccm: '',
    identifiant_fiscal: '',
    telephone: '',
    telephone_secondaire: '',
    email: '',
    ville: '',
    quartier: '',
    adresse: '',
    latitude: '',
    longitude: '',
    fonction: (props.fonctions_creables.length === 1
        ? props.fonctions_creables[0].value
        : null) as string | null,
    qualification_ids: [] as string[],
    notes: null as string | null,
});

const libelleType = computed(() =>
    typeFiche.value === 'personne' ? 'Personne physique' : 'Entreprise',
);
const listeUrl = computed(() =>
    typeFiche.value === 'personne'
        ? '/backoffice/contacts/personnes'
        : '/backoffice/contacts/entreprises',
);
const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    {
        title:
            typeFiche.value === 'personne'
                ? 'Personnes physiques'
                : 'Entreprises',
        href: listeUrl.value,
    },
    { title: 'Nouveau contact', href: '#' },
]);

function choisirType(type: TypeFiche) {
    if (typeFiche.value === type) return;
    typeFiche.value = type;
    changerDeNumero();
}

async function rechercher() {
    if (!telephoneValide.value || rechercheEnCours.value) return;
    rechercheEnCours.value = true;
    erreurRecherche.value = null;
    autrePersonne.value = false;
    try {
        const params = new URLSearchParams({
            type: typeFiche.value,
            telephone: telephoneSaisi.value,
        });
        const reponse = await fetch(
            `/backoffice/contacts/recherche-telephone?${params.toString()}`,
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
        const r = (await reponse.json()) as ResultatRecherche;
        if (r.statut === 'invalide') {
            erreurRecherche.value = r.message ?? 'Numéro invalide.';
            return;
        }
        resultat.value = r;
        form.clearErrors();
        form.telephone = r.telephone ?? telephoneSaisi.value;
    } catch {
        erreurRecherche.value =
            'La recherche n’a pas abouti (connexion). Réessayez dans un instant.';
    } finally {
        rechercheEnCours.value = false;
    }
}

function changerDeNumero() {
    resultat.value = null;
    erreurRecherche.value = null;
    autrePersonne.value = false;
    telephoneSaisi.value = '';
    cleTelephone.value++;
}

function enregistrer() {
    if (form.processing) return;
    const url =
        typeFiche.value === 'personne'
            ? '/backoffice/contacts/personnes'
            : '/backoffice/contacts/entreprises';
    form.post(url, { preserveScroll: true });
}

const urlAjoutFonction = computed(() => {
    const fiche = resultat.value?.fiche;
    if (!fiche) return '';
    return fiche.type === 'personne'
        ? `/backoffice/contacts/personnes/${fiche.id}/fonctions`
        : `/backoffice/contacts/entreprises/${fiche.id}/fonctions`;
});
</script>

<template>
    <Head title="Nouveau contact" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-3xl space-y-6 p-4 sm:p-6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Nouveau contact
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Une seule fiche par personne ou entreprise : on commence
                        par vérifier le numéro.
                    </p>
                </div>
                <Link :href="listeUrl">
                    <Button variant="outline" size="sm">
                        <ArrowLeft class="mr-1.5 h-4 w-4" />
                        Retour
                    </Button>
                </Link>
            </div>

            <!-- 1. Type -->
            <section class="rounded-xl border bg-card p-4 sm:p-6">
                <h2
                    class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    Type de fiche
                </h2>
                <div class="grid grid-cols-2 gap-3">
                    <button
                        v-for="o in [
                            {
                                key: 'personne' as const,
                                label: 'Personne physique',
                                icon: UserRound,
                            },
                            {
                                key: 'entreprise' as const,
                                label: 'Entreprise',
                                icon: Building2,
                            },
                        ]"
                        :key="o.key"
                        type="button"
                        class="flex items-center justify-center gap-2 rounded-lg border px-3 py-3 text-sm font-medium transition-colors"
                        :class="
                            typeFiche === o.key
                                ? 'border-primary bg-primary/5 text-primary'
                                : 'text-muted-foreground hover:bg-muted'
                        "
                        :data-testid="`contact-type-${o.key}`"
                        @click="choisirType(o.key)"
                    >
                        <component :is="o.icon" class="h-4 w-4" />
                        {{ o.label }}
                    </button>
                </div>
            </section>

            <!-- 2. Téléphone -->
            <section class="rounded-xl border bg-card p-4 sm:p-6">
                <h2
                    class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    Téléphone
                </h2>
                <form
                    v-if="!resultat"
                    class="flex flex-col gap-2 sm:flex-row"
                    @submit.prevent="rechercher"
                >
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
                        data-testid="contact-rechercher"
                    >
                        <Loader2
                            v-if="rechercheEnCours"
                            class="mr-2 h-4 w-4 animate-spin"
                        />
                        <Search v-else class="mr-2 h-4 w-4" />
                        Rechercher
                    </Button>
                </form>
                <div
                    v-else
                    class="flex flex-wrap items-center justify-between gap-3"
                >
                    <p class="text-sm font-medium tabular-nums">
                        {{
                            formatPhoneDisplay(
                                resultat.telephone,
                                resultat.indicatif,
                            ) ?? resultat.telephone
                        }}
                    </p>
                    <Button
                        variant="outline"
                        size="sm"
                        data-testid="contact-changer-numero"
                        @click="changerDeNumero"
                    >
                        Changer de numéro
                    </Button>
                </div>
                <p
                    v-if="erreurRecherche"
                    class="mt-3 rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive"
                >
                    {{ erreurRecherche }}
                </p>
            </section>

            <!-- Numéro d'une fiche non visible : message neutre, aucune donnée -->
            <section
                v-if="resultat?.statut === 'indisponible'"
                class="rounded-xl border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive sm:p-6"
                data-testid="contact-indisponible"
            >
                {{ resultat.message }}
            </section>

            <!-- Numéro déjà connu : choix explicite -->
            <section
                v-if="resultat?.statut === 'connu' && resultat.fiche"
                class="space-y-4 rounded-xl border bg-card p-4 sm:p-6"
                data-testid="contact-connu"
            >
                <div>
                    <p class="text-sm text-muted-foreground">
                        Ce numéro est déjà celui de :
                    </p>
                    <p class="mt-1 text-base font-semibold">
                        {{ resultat.fiche.nom }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ resultat.fiche.ville || 'Ville non renseignée' }}
                    </p>
                    <BadgesFonctions
                        class="mt-2"
                        :fonctions="resultat.fiche.fonctions"
                    />
                </div>

                <p
                    v-if="autrePersonne"
                    class="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive"
                    data-testid="contact-autre-personne-message"
                >
                    {{ resultat.message_autre_personne }}
                </p>

                <div v-else class="flex flex-wrap gap-2">
                    <Button
                        v-if="resultat.fiche.fonctions_ajoutables.length > 0"
                        data-testid="contact-meme-personne"
                        @click="ajoutVisible = true"
                    >
                        C'est la même
                        {{
                            typeFiche === 'personne' ? 'personne' : 'entreprise'
                        }}
                        : ajouter une fonction
                    </Button>
                    <Button
                        variant="outline"
                        data-testid="contact-autre-personne"
                        @click="autrePersonne = true"
                    >
                        C'est une autre
                        {{
                            typeFiche === 'personne' ? 'personne' : 'entreprise'
                        }}
                    </Button>
                    <Link :href="resultat.fiche.lien">
                        <Button variant="ghost">Ouvrir la fiche</Button>
                    </Link>
                </div>
                <p
                    v-if="
                        !autrePersonne &&
                        resultat.fiche.fonctions_ajoutables.length === 0
                    "
                    class="text-sm text-muted-foreground"
                >
                    Cette fiche porte déjà toutes les fonctions que vous pouvez
                    créer.
                </p>

                <AjouterFonctionDialog
                    v-model:visible="ajoutVisible"
                    :url="urlAjoutFonction"
                    :nom-fiche="resultat.fiche.nom"
                    :fonctions="resultat.fiche.fonctions_ajoutables"
                    :types-prestataire="types_prestataire"
                />
            </section>

            <!-- 3. Numéro libre : identité et première fonction -->
            <form
                v-if="resultat?.statut === 'libre'"
                class="space-y-6"
                data-testid="contact-formulaire"
                @submit.prevent="enregistrer"
            >
                <section class="rounded-xl border bg-card p-4 sm:p-6">
                    <h2
                        class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        {{ libelleType }}
                    </h2>
                    <div
                        v-if="typeFiche === 'personne'"
                        class="grid gap-5 sm:grid-cols-2"
                    >
                        <div>
                            <Label for="prenom" class="mb-1.5 block"
                                >Prénom</Label
                            >
                            <InputText
                                id="prenom"
                                v-model="form.prenom"
                                class="w-full"
                                :class="{ 'p-invalid': form.errors.prenom }"
                            />
                            <p
                                v-if="form.errors.prenom"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.prenom }}
                            </p>
                        </div>
                        <div>
                            <Label for="nom" class="mb-1.5 block">Nom</Label>
                            <InputText
                                id="nom"
                                v-model="form.nom"
                                class="w-full"
                                :class="{ 'p-invalid': form.errors.nom }"
                            />
                            <p
                                v-if="form.errors.nom"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.nom }}
                            </p>
                        </div>
                        <div class="sm:col-span-2">
                            <Label for="nom_complet" class="mb-1.5 block"
                                >Ou nom complet</Label
                            >
                            <InputText
                                id="nom_complet"
                                v-model="form.nom_complet"
                                class="w-full"
                                placeholder="Si le prénom et le nom ne sont pas connus séparément"
                            />
                        </div>
                    </div>
                    <div v-else class="grid gap-5 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <Label for="raison_sociale" class="mb-1.5 block"
                                >Raison sociale
                                <span class="text-destructive">*</span></Label
                            >
                            <InputText
                                id="raison_sociale"
                                v-model="form.raison_sociale"
                                class="w-full"
                                :class="{
                                    'p-invalid': form.errors.raison_sociale,
                                }"
                            />
                            <p
                                v-if="form.errors.raison_sociale"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.raison_sociale }}
                            </p>
                        </div>
                        <div>
                            <Label for="nom_commercial" class="mb-1.5 block"
                                >Nom commercial</Label
                            >
                            <InputText
                                id="nom_commercial"
                                v-model="form.nom_commercial"
                                class="w-full"
                            />
                        </div>
                        <div>
                            <Label for="rccm" class="mb-1.5 block">RCCM</Label>
                            <InputText
                                id="rccm"
                                v-model="form.rccm"
                                class="w-full"
                            />
                        </div>
                        <div>
                            <Label for="identifiant_fiscal" class="mb-1.5 block"
                                >NIF</Label
                            >
                            <InputText
                                id="identifiant_fiscal"
                                v-model="form.identifiant_fiscal"
                                class="w-full"
                            />
                        </div>
                    </div>
                </section>

                <section class="rounded-xl border bg-card p-4 sm:p-6">
                    <h2
                        class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Coordonnées
                    </h2>
                    <p
                        v-if="form.errors.telephone"
                        class="mb-4 rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive"
                    >
                        {{ form.errors.telephone }}
                    </p>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <Label
                                for="telephone_secondaire"
                                class="mb-1.5 block"
                                >Téléphone secondaire</Label
                            >
                            <InputText
                                id="telephone_secondaire"
                                v-model="form.telephone_secondaire"
                                class="w-full"
                                inputmode="tel"
                                :class="{
                                    'p-invalid':
                                        form.errors.telephone_secondaire,
                                }"
                            />
                            <p
                                v-if="form.errors.telephone_secondaire"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.telephone_secondaire }}
                            </p>
                        </div>
                        <div>
                            <Label for="email" class="mb-1.5 block"
                                >E-mail</Label
                            >
                            <InputText
                                id="email"
                                v-model="form.email"
                                type="email"
                                class="w-full"
                                :class="{ 'p-invalid': form.errors.email }"
                            />
                            <p
                                v-if="form.errors.email"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.email }}
                            </p>
                        </div>
                        <div>
                            <Label for="ville" class="mb-1.5 block"
                                >Ville
                                <span class="text-destructive">*</span></Label
                            >
                            <InputText
                                id="ville"
                                v-model="form.ville"
                                class="w-full"
                                :class="{ 'p-invalid': form.errors.ville }"
                            />
                            <p
                                v-if="form.errors.ville"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.ville }}
                            </p>
                        </div>
                        <div>
                            <Label for="quartier" class="mb-1.5 block"
                                >Quartier</Label
                            >
                            <InputText
                                id="quartier"
                                v-model="form.quartier"
                                class="w-full"
                            />
                        </div>
                        <div class="sm:col-span-2">
                            <Label for="adresse" class="mb-1.5 block"
                                >Adresse</Label
                            >
                            <InputText
                                id="adresse"
                                v-model="form.adresse"
                                class="w-full"
                            />
                        </div>
                        <div>
                            <Label for="latitude" class="mb-1.5 block"
                                >Latitude</Label
                            >
                            <InputText
                                id="latitude"
                                v-model="form.latitude"
                                inputmode="decimal"
                                class="w-full"
                                :class="{ 'p-invalid': form.errors.latitude }"
                            />
                            <p
                                v-if="form.errors.latitude"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.latitude }}
                            </p>
                        </div>
                        <div>
                            <Label for="longitude" class="mb-1.5 block"
                                >Longitude</Label
                            >
                            <InputText
                                id="longitude"
                                v-model="form.longitude"
                                inputmode="decimal"
                                class="w-full"
                                :class="{ 'p-invalid': form.errors.longitude }"
                            />
                            <p
                                v-if="form.errors.longitude"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.longitude }}
                            </p>
                        </div>
                    </div>
                </section>

                <section class="rounded-xl border bg-card p-4 sm:p-6">
                    <h2
                        class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Fonction
                    </h2>
                    <ChampsFonction
                        v-model:fonction="form.fonction"
                        v-model:qualification-ids="form.qualification_ids"
                        v-model:notes="form.notes"
                        :fonctions="fonctions_creables"
                        :types-prestataire="types_prestataire"
                        :errors="form.errors"
                    />
                </section>

                <div class="flex justify-end">
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        :aria-busy="form.processing"
                        data-testid="contact-enregistrer"
                    >
                        <Loader2
                            v-if="form.processing"
                            class="mr-2 h-4 w-4 animate-spin"
                        />
                        <Save v-else class="mr-2 h-4 w-4" />
                        {{ form.processing ? 'Création…' : 'Créer le contact' }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
