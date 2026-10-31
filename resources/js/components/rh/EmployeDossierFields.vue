<script setup lang="ts">
import InfoTooltip from '@/components/InfoTooltip.vue';
import NationaliteSelect, {
    type NationaliteOption,
} from '@/components/rh/NationaliteSelect.vue';
import { usePermissions } from '@/composables/usePermissions';
import { LIEN_AUTRE } from '@/lib/contactUrgence';
import Select from 'primevue/select';
import { computed, watch } from 'vue';

interface Option {
    value: string;
    label: string;
}

/**
 * Champs du dossier salarié partagés par la création et la modification d'un employé : dossier
 * (service, date d'embauche en modification seulement), identité civile, contact d'urgence et,
 * avec `rh-employes.donnees_sensibles`, n° CNSS. Tous facultatifs. La date d'embauche vient du
 * premier contrat ; la sortie s'enregistre par « Enregistrer le départ » sur la fiche. Le mode de
 * paiement du salaire n'est pas saisi ici : la Comptabilité le choisit au paiement.
 */
export interface DossierSalarieForm {
    service_rh_id: string | null;
    date_embauche?: string;
    date_naissance: string;
    lieu_naissance: string;
    civilite: string;
    nationalite: string;
    contact_urgence_nom: string;
    contact_urgence_lien: string;
    contact_urgence_lien_precision: string;
    contact_urgence_telephone: string;
    numero_cnss: string;
    errors: Partial<Record<string, string>>;
}

const props = defineProps<{
    services: Option[];
    civiliteOptions: Option[];
    nationaliteOptions: NationaliteOption[];
    lienContactOptions: Option[];
    /** Création : pas de date d'embauche (reprise du premier contrat). */
    creation?: boolean;
}>();

const form = defineModel<DossierSalarieForm>('form', { required: true });

const { can } = usePermissions();
const voitDonneesSensibles = computed(() =>
    can('rh-employes.donnees_sensibles'),
);

const lienAutre = computed(
    () => form.value.contact_urgence_lien === LIEN_AUTRE,
);

// Une précision ne vaut que pour « Autre » : elle est effacée dès qu'un autre lien est choisi.
watch(
    () => form.value.contact_urgence_lien,
    (lien) => {
        if (lien !== LIEN_AUTRE) {
            form.value.contact_urgence_lien_precision = '';
        }
    },
);

const lienOptions = computed(() => [
    { value: '', label: '— Non renseigné —' },
    ...props.lienContactOptions,
]);

const champ =
    'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';
</script>

<template>
    <!-- Dossier -->
    <div class="space-y-4 rounded-xl border bg-card p-6 shadow-sm">
        <h3
            class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
        >
            Dossier
        </h3>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label
                    for="dossier-service"
                    class="mb-1.5 block text-sm font-medium"
                    >Service</label
                >
                <Select
                    v-model="form.service_rh_id"
                    input-id="dossier-service"
                    :options="[
                        { value: null, label: '— Aucun service —' },
                        ...services,
                    ]"
                    option-label="label"
                    option-value="value"
                    class="w-full"
                />
                <p
                    v-if="form.errors.service_rh_id"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ form.errors.service_rh_id }}
                </p>
            </div>
            <div v-if="!creation">
                <div class="mb-1.5 flex items-center gap-1.5">
                    <label for="dossier-embauche" class="text-sm font-medium"
                        >Date d'embauche</label
                    >
                    <InfoTooltip label="Aide : date d'embauche">
                        Début de la relation de travail avec l'entreprise
                        (ancienneté). Elle est reprise automatiquement du
                        premier contrat créé quand elle n'est pas encore
                        renseignée ; corrigez-la si le salarié a été embauché
                        avant ce contrat.
                    </InfoTooltip>
                </div>
                <input
                    id="dossier-embauche"
                    v-model="form.date_embauche"
                    type="date"
                    :class="[
                        champ,
                        { 'border-destructive': form.errors.date_embauche },
                    ]"
                />
                <p
                    v-if="form.errors.date_embauche"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ form.errors.date_embauche }}
                </p>
            </div>
        </div>
        <p v-if="creation" class="text-xs text-muted-foreground">
            La date d'embauche est reprise du premier contrat de travail. Les
            dates du contrat se saisissent dans RH → Contrats, le départ avec «
            Enregistrer le départ » sur la fiche du salarié.
        </p>
    </div>

    <!-- Identité civile -->
    <div class="space-y-4 rounded-xl border bg-card p-6 shadow-sm">
        <h3
            class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
        >
            Identité
        </h3>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label
                    for="dossier-naissance"
                    class="mb-1.5 block text-sm font-medium"
                    >Date de naissance</label
                >
                <input
                    id="dossier-naissance"
                    v-model="form.date_naissance"
                    type="date"
                    :class="[
                        champ,
                        { 'border-destructive': form.errors.date_naissance },
                    ]"
                />
                <p
                    v-if="form.errors.date_naissance"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ form.errors.date_naissance }}
                </p>
            </div>
            <div>
                <label
                    for="dossier-lieu"
                    class="mb-1.5 block text-sm font-medium"
                    >Lieu de naissance</label
                >
                <input
                    id="dossier-lieu"
                    v-model="form.lieu_naissance"
                    type="text"
                    :class="champ"
                />
            </div>
            <div>
                <label
                    for="dossier-civilite"
                    class="mb-1.5 block text-sm font-medium"
                    >Civilité</label
                >
                <Select
                    v-model="form.civilite"
                    input-id="dossier-civilite"
                    :options="[
                        { value: '', label: '— Non renseignée —' },
                        ...civiliteOptions,
                    ]"
                    option-label="label"
                    option-value="value"
                    class="w-full"
                />
                <p
                    v-if="form.errors.civilite"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ form.errors.civilite }}
                </p>
            </div>
            <div>
                <div class="mb-1.5 flex items-center gap-1.5">
                    <label for="dossier-nationalite" class="text-sm font-medium"
                        >Nationalité</label
                    >
                    <InfoTooltip label="Aide : nationalité">
                        Sélectionnez la nationalité du salarié, par exemple
                        Guinéenne ou Française. Tapez quelques lettres pour la
                        retrouver, avec ou sans accents.
                    </InfoTooltip>
                </div>
                <NationaliteSelect
                    v-model="form.nationalite"
                    input-id="dossier-nationalite"
                    :options="nationaliteOptions"
                    :invalid="!!form.errors.nationalite"
                />
                <p
                    v-if="form.errors.nationalite"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ form.errors.nationalite }}
                </p>
            </div>
        </div>
    </div>

    <!-- Contact d'urgence -->
    <div class="space-y-4 rounded-xl border bg-card p-6 shadow-sm">
        <h3
            class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
        >
            Contact d'urgence
        </h3>
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label
                    for="dossier-urgence-nom"
                    class="mb-1.5 block text-sm font-medium"
                    >Nom</label
                >
                <input
                    id="dossier-urgence-nom"
                    v-model="form.contact_urgence_nom"
                    type="text"
                    :class="champ"
                />
            </div>
            <div>
                <div class="mb-1.5 flex items-center gap-1.5">
                    <label
                        for="dossier-urgence-lien"
                        class="text-sm font-medium"
                        >Lien</label
                    >
                    <InfoTooltip label="Aide : lien avec le contact">
                        Lien entre le salarié et la personne à contacter en cas
                        d'urgence. Si aucun choix ne correspond, sélectionnez «
                        Autre » et précisez le lien.
                    </InfoTooltip>
                </div>
                <Select
                    v-model="form.contact_urgence_lien"
                    input-id="dossier-urgence-lien"
                    :options="lienOptions"
                    option-label="label"
                    option-value="value"
                    placeholder="— Non renseigné —"
                    :invalid="!!form.errors.contact_urgence_lien"
                    class="w-full"
                    data-testid="lien-contact-select"
                />
                <p
                    v-if="form.errors.contact_urgence_lien"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ form.errors.contact_urgence_lien }}
                </p>
            </div>
            <div>
                <label
                    for="dossier-urgence-tel"
                    class="mb-1.5 block text-sm font-medium"
                    >Téléphone</label
                >
                <input
                    id="dossier-urgence-tel"
                    v-model="form.contact_urgence_telephone"
                    type="tel"
                    :class="champ"
                />
            </div>
            <div v-if="lienAutre" class="sm:col-span-3">
                <label
                    for="dossier-urgence-precision"
                    class="mb-1.5 block text-sm font-medium"
                    >Précisez le lien avec le contact
                    <span class="text-destructive">*</span></label
                >
                <input
                    id="dossier-urgence-precision"
                    v-model="form.contact_urgence_lien_precision"
                    type="text"
                    maxlength="50"
                    placeholder="Ex : voisin, beau-frère, belle-sœur"
                    :class="[
                        champ,
                        {
                            'border-destructive':
                                form.errors.contact_urgence_lien_precision,
                        },
                    ]"
                />
                <p
                    v-if="form.errors.contact_urgence_lien_precision"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ form.errors.contact_urgence_lien_precision }}
                </p>
            </div>
        </div>
    </div>

    <!-- Données sensibles : seulement avec la permission dédiée -->
    <div
        v-if="voitDonneesSensibles"
        class="space-y-4 rounded-xl border bg-card p-6 shadow-sm"
        data-testid="employe-donnees-sensibles"
    >
        <div>
            <h3
                class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
            >
                Données sensibles
            </h3>
            <p class="mt-1 text-xs text-muted-foreground">
                Visibles seulement par les profils autorisés. Le mode de
                paiement du salaire est choisi par la Comptabilité au moment du
                paiement.
            </p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <div class="mb-1.5 flex items-center gap-1.5">
                    <label for="dossier-cnss" class="text-sm font-medium"
                        >N° CNSS du salarié</label
                    >
                    <InfoTooltip label="Aide : numéro CNSS">
                        Numéro personnel d'immatriculation du salarié auprès de
                        la Caisse nationale de sécurité sociale (cnss.gov.gn), à
                        ne pas confondre avec le numéro employeur de
                        l'entreprise. S'il n'est pas encore disponible, laissez
                        ce champ vide et renseignez-le plus tard.
                    </InfoTooltip>
                </div>
                <input
                    id="dossier-cnss"
                    v-model="form.numero_cnss"
                    type="text"
                    :class="champ"
                />
            </div>
        </div>
    </div>
</template>
