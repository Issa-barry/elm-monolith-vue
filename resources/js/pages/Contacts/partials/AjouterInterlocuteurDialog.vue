<script setup lang="ts">
/**
 * Ajout d'un interlocuteur (ADR 0060, lot 4) : téléphone puis « Rechercher ». Numéro libre →
 * identité ; personne connue et visible → confirmation explicite « même personne » ; personne non
 * visible → message neutre. Rien n'est rattaché sans validation de ce formulaire.
 */
import PhoneCountryInput from '@/components/PhoneCountryInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/vue3';
import { Loader2, Search } from 'lucide-vue-next';
import Checkbox from 'primevue/checkbox';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Textarea from 'primevue/textarea';
import { computed, ref, watch } from 'vue';
import BadgesFonctions from './BadgesFonctions.vue';
import type { FonctionContact } from './types';

interface Resultat {
    statut:
        | 'libre'
        | 'connu'
        | 'deja_interlocuteur'
        | 'indisponible'
        | 'invalide';
    message?: string;
    message_autre_personne?: string;
    telephone?: string;
    personne?: {
        id: string;
        nom: string;
        ville: string | null;
        fonctions: FonctionContact[];
    };
    lien_actif?: boolean | null;
}

const props = defineProps<{
    visible: boolean;
    entrepriseId: string;
    nomEntreprise: string;
    /** Aucun principal actif : la case « principal » est proposée cochée (décision 6). */
    sansPrincipal: boolean;
}>();

const emit = defineEmits<{ 'update:visible': [boolean] }>();

const telephoneSaisi = ref('');
const telephoneValide = ref(false);
const cleTelephone = ref(0);
const rechercheEnCours = ref(false);
const erreurRecherche = ref<string | null>(null);
const resultat = ref<Resultat | null>(null);
const autrePersonne = ref(false);

const form = useForm({
    telephone: '',
    personne_confirmee_id: null as string | null,
    nom_complet: '',
    prenom: '',
    nom: '',
    email: '',
    ville: '',
    quartier: '',
    adresse: '',
    fonction: '',
    notes: '',
    principal: props.sansPrincipal,
});

const base = computed(
    () =>
        `/backoffice/contacts/entreprises/${props.entrepriseId}/interlocuteurs`,
);

function reinitialiser() {
    resultat.value = null;
    erreurRecherche.value = null;
    autrePersonne.value = false;
    telephoneSaisi.value = '';
    cleTelephone.value++;
    form.reset();
    form.principal = props.sansPrincipal;
    form.clearErrors();
}

// La liste a pu changer depuis le dernier ajout : la proposition suit l'état courant à chaque ouverture.
watch(
    () => props.visible,
    (ouvert) => {
        if (ouvert && !form.processing) form.principal = props.sansPrincipal;
    },
);

function fermer(visible: boolean) {
    if (form.processing) return;
    if (!visible) reinitialiser();
    emit('update:visible', visible);
}

async function rechercher() {
    if (!telephoneValide.value || rechercheEnCours.value) return;
    rechercheEnCours.value = true;
    erreurRecherche.value = null;
    autrePersonne.value = false;
    try {
        const params = new URLSearchParams({ telephone: telephoneSaisi.value });
        const reponse = await fetch(
            `${base.value}/recherche-telephone?${params.toString()}`,
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
        const r = (await reponse.json()) as Resultat;
        if (r.statut === 'invalide') {
            erreurRecherche.value = r.message ?? 'Numéro invalide.';
            return;
        }
        resultat.value = r;
        form.telephone = r.telephone ?? telephoneSaisi.value;
        form.personne_confirmee_id = null;
    } catch {
        erreurRecherche.value =
            'La recherche n’a pas abouti (connexion). Réessayez dans un instant.';
    } finally {
        rechercheEnCours.value = false;
    }
}

const afficherFormulaire = computed(
    () =>
        resultat.value?.statut === 'libre' ||
        (resultat.value?.statut === 'connu' &&
            form.personne_confirmee_id !== null),
);

// Refus serveur hors champ : numéro (non visible, déjà lié, course) ou entreprise désactivée.
const erreurGenerale = computed(() => {
    const erreurs = form.errors as Record<string, string | undefined>;
    return erreurs.telephone ?? erreurs.entreprise ?? null;
});

function confirmerMemePersonne() {
    form.personne_confirmee_id = resultat.value?.personne?.id ?? null;
}

function enregistrer() {
    if (form.processing) return;
    form.post(base.value, {
        preserveScroll: true,
        onSuccess: () => {
            reinitialiser();
            emit('update:visible', false);
        },
    });
}
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        header="Ajouter un interlocuteur"
        :closable="!form.processing"
        :close-on-escape="!form.processing"
        :draggable="false"
        :style="{ width: 'min(40rem, 94vw)' }"
        @update:visible="fermer"
    >
        <div class="space-y-4 text-sm">
            <p class="text-muted-foreground">
                Interlocuteur de
                <span class="font-medium text-foreground">{{
                    nomEntreprise
                }}</span
                >. On commence par vérifier son numéro.
            </p>

            <form
                v-if="!resultat"
                class="flex flex-col gap-2 sm:flex-row"
                @submit.prevent="rechercher"
            >
                <div class="sm:w-80">
                    <PhoneCountryInput
                        id="telephone-interlocuteur"
                        :key="cleTelephone"
                        :model-value="telephoneSaisi"
                        :remember-choice="false"
                        :required="false"
                        @update:model-value="telephoneSaisi = $event"
                        @update:valid="telephoneValide = $event"
                    />
                </div>
                <Button
                    type="submit"
                    :disabled="!telephoneValide || rechercheEnCours"
                    data-testid="interlocuteur-rechercher"
                >
                    <Loader2
                        v-if="rechercheEnCours"
                        class="mr-2 h-4 w-4 animate-spin"
                    />
                    <Search v-else class="mr-2 h-4 w-4" />
                    Rechercher
                </Button>
            </form>
            <div v-else class="flex items-center justify-between gap-3">
                <p class="font-medium tabular-nums">{{ resultat.telephone }}</p>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="form.processing"
                    @click="reinitialiser"
                >
                    Changer de numéro
                </Button>
            </div>

            <p
                v-if="erreurRecherche"
                class="rounded-md bg-destructive/10 px-3 py-2 text-destructive"
            >
                {{ erreurRecherche }}
            </p>

            <!-- Personne non visible : message neutre, aucune donnée. -->
            <p
                v-if="resultat?.statut === 'indisponible'"
                class="rounded-md bg-destructive/10 px-3 py-2 text-destructive"
                data-testid="interlocuteur-indisponible"
            >
                {{ resultat.message }}
            </p>

            <!-- Déjà interlocuteur de cette entreprise. -->
            <p
                v-if="resultat?.statut === 'deja_interlocuteur'"
                class="rounded-md bg-muted px-3 py-2"
            >
                {{ resultat.personne?.nom }} est déjà interlocuteur de cette
                entreprise{{
                    resultat.lien_actif
                        ? '.'
                        : ', désactivé : réactivez-le depuis la liste.'
                }}
            </p>

            <!-- Personne connue et visible : choix explicite. -->
            <div
                v-if="
                    resultat?.statut === 'connu' &&
                    resultat.personne &&
                    form.personne_confirmee_id === null
                "
                class="space-y-3 rounded-md border p-3"
                data-testid="interlocuteur-connu"
            >
                <div>
                    <p class="text-muted-foreground">
                        Ce numéro est déjà celui de :
                    </p>
                    <p class="font-semibold">{{ resultat.personne.nom }}</p>
                    <p class="text-muted-foreground">
                        {{ resultat.personne.ville || 'Ville non renseignée' }}
                    </p>
                    <BadgesFonctions
                        class="mt-2"
                        :fonctions="resultat.personne.fonctions"
                    />
                </div>
                <p
                    v-if="autrePersonne"
                    class="rounded-md bg-destructive/10 px-3 py-2 text-destructive"
                >
                    {{ resultat.message_autre_personne }}
                </p>
                <div v-else class="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        data-testid="interlocuteur-meme-personne"
                        @click="confirmerMemePersonne"
                    >
                        C'est la même personne
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="autrePersonne = true"
                    >
                        C'est une autre personne
                    </Button>
                </div>
            </div>

            <!-- Formulaire : identité (numéro libre) puis lien. -->
            <form
                v-if="afficherFormulaire"
                id="form-interlocuteur"
                class="space-y-4"
                @submit.prevent="enregistrer"
            >
                <p
                    v-if="erreurGenerale"
                    class="rounded-md bg-destructive/10 px-3 py-2 text-destructive"
                >
                    {{ erreurGenerale }}
                </p>
                <p
                    v-if="form.personne_confirmee_id"
                    class="rounded-md bg-muted px-3 py-2"
                >
                    {{ resultat?.personne?.nom }} sera ajouté avec ses
                    coordonnées actuelles.
                </p>
                <div
                    v-if="resultat?.statut === 'libre'"
                    class="grid gap-4 sm:grid-cols-2"
                >
                    <div>
                        <Label for="i-prenom" class="mb-1.5 block"
                            >Prénom</Label
                        >
                        <InputText
                            id="i-prenom"
                            v-model="form.prenom"
                            class="w-full"
                        />
                    </div>
                    <div>
                        <Label for="i-nom" class="mb-1.5 block">Nom</Label>
                        <InputText
                            id="i-nom"
                            v-model="form.nom"
                            class="w-full"
                        />
                    </div>
                    <div class="sm:col-span-2">
                        <Label for="i-nom-complet" class="mb-1.5 block"
                            >Ou nom complet</Label
                        >
                        <InputText
                            id="i-nom-complet"
                            v-model="form.nom_complet"
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
                    <div>
                        <Label for="i-email" class="mb-1.5 block">E-mail</Label>
                        <InputText
                            id="i-email"
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
                        <Label for="i-ville" class="mb-1.5 block">Ville</Label>
                        <InputText
                            id="i-ville"
                            v-model="form.ville"
                            class="w-full"
                        />
                    </div>
                    <div>
                        <Label for="i-quartier" class="mb-1.5 block"
                            >Quartier</Label
                        >
                        <InputText
                            id="i-quartier"
                            v-model="form.quartier"
                            class="w-full"
                        />
                    </div>
                    <div>
                        <Label for="i-adresse" class="mb-1.5 block"
                            >Adresse</Label
                        >
                        <InputText
                            id="i-adresse"
                            v-model="form.adresse"
                            class="w-full"
                        />
                    </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <Label for="i-fonction" class="mb-1.5 block"
                            >Fonction dans l'entreprise</Label
                        >
                        <InputText
                            id="i-fonction"
                            v-model="form.fonction"
                            class="w-full"
                            placeholder="Responsable technique, directeur commercial…"
                        />
                    </div>
                    <div class="sm:col-span-2">
                        <Label for="i-notes" class="mb-1.5 block">Notes</Label>
                        <Textarea
                            id="i-notes"
                            v-model="form.notes"
                            rows="2"
                            auto-resize
                            class="w-full"
                        />
                    </div>
                    <div class="flex items-center gap-2 sm:col-span-2">
                        <Checkbox
                            v-model="form.principal"
                            binary
                            input-id="i-principal"
                            data-testid="interlocuteur-principal-case"
                        />
                        <label for="i-principal"
                            >Interlocuteur principal de l'entreprise</label
                        >
                    </div>
                </div>
            </form>
        </div>

        <template #footer>
            <Button
                variant="outline"
                :disabled="form.processing"
                @click="fermer(false)"
            >
                Annuler
            </Button>
            <Button
                v-if="afficherFormulaire"
                type="submit"
                form="form-interlocuteur"
                :disabled="form.processing"
                :aria-busy="form.processing"
                data-testid="interlocuteur-enregistrer"
            >
                <Loader2
                    v-if="form.processing"
                    class="mr-1.5 h-4 w-4 animate-spin"
                />
                {{ form.processing ? 'Ajout…' : "Ajouter l'interlocuteur" }}
            </Button>
        </template>
    </Dialog>
</template>
