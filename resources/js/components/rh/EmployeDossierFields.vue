<script setup lang="ts">
import { usePermissions } from '@/composables/usePermissions';
import Select from 'primevue/select';
import { computed } from 'vue';

interface Option {
    value: string;
    label: string;
}

/**
 * Champs du dossier salarié partagés par la création et la modification d'un employé : dossier
 * (embauche, sortie, service), identité civile, contact d'urgence et, avec
 * `rh-employes.donnees_sensibles`, n° CNSS et coordonnées de paiement. Tous facultatifs.
 */
export interface DossierSalarieForm {
    service_rh_id: string | null;
    date_embauche: string;
    date_sortie: string;
    motif_sortie: string;
    date_naissance: string;
    lieu_naissance: string;
    sexe: string;
    nationalite: string;
    contact_urgence_nom: string;
    contact_urgence_lien: string;
    contact_urgence_telephone: string;
    numero_cnss: string;
    paiement_mode: string;
    paiement_etablissement: string;
    paiement_numero_compte: string;
    paiement_titulaire: string;
    errors: Partial<Record<string, string>>;
}

defineProps<{
    services: Option[];
    modePaiementOptions: Option[];
}>();

const form = defineModel<DossierSalarieForm>('form', { required: true });

const { can } = usePermissions();
const voitDonneesSensibles = computed(() =>
    can('rh-employes.donnees_sensibles'),
);

const SEXE_OPTIONS: Option[] = [
    { value: 'F', label: 'Femme' },
    { value: 'M', label: 'Homme' },
];

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
            <div>
                <label
                    for="dossier-embauche"
                    class="mb-1.5 block text-sm font-medium"
                    >Date d'embauche</label
                >
                <input
                    id="dossier-embauche"
                    v-model="form.date_embauche"
                    type="date"
                    :class="champ"
                />
            </div>
            <div>
                <label
                    for="dossier-sortie"
                    class="mb-1.5 block text-sm font-medium"
                    >Date de sortie</label
                >
                <input
                    id="dossier-sortie"
                    v-model="form.date_sortie"
                    type="date"
                    :class="[
                        champ,
                        { 'border-destructive': form.errors.date_sortie },
                    ]"
                />
                <p
                    v-if="form.errors.date_sortie"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ form.errors.date_sortie }}
                </p>
            </div>
            <div>
                <label
                    for="dossier-motif"
                    class="mb-1.5 block text-sm font-medium"
                    >Motif de sortie</label
                >
                <input
                    id="dossier-motif"
                    v-model="form.motif_sortie"
                    type="text"
                    :class="champ"
                />
            </div>
        </div>
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
                    for="dossier-sexe"
                    class="mb-1.5 block text-sm font-medium"
                    >Sexe</label
                >
                <Select
                    v-model="form.sexe"
                    input-id="dossier-sexe"
                    :options="[{ value: '', label: '—' }, ...SEXE_OPTIONS]"
                    option-label="label"
                    option-value="value"
                    class="w-full"
                />
            </div>
            <div>
                <label
                    for="dossier-nationalite"
                    class="mb-1.5 block text-sm font-medium"
                    >Nationalité</label
                >
                <input
                    id="dossier-nationalite"
                    v-model="form.nationalite"
                    type="text"
                    placeholder="Ex : Guinéenne"
                    :class="champ"
                />
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
                <label
                    for="dossier-urgence-lien"
                    class="mb-1.5 block text-sm font-medium"
                    >Lien</label
                >
                <input
                    id="dossier-urgence-lien"
                    v-model="form.contact_urgence_lien"
                    type="text"
                    placeholder="Ex : Épouse"
                    :class="champ"
                />
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
                Visibles seulement par les profils autorisés. Le numéro de
                compte est chiffré.
            </p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label
                    for="dossier-cnss"
                    class="mb-1.5 block text-sm font-medium"
                    >N° CNSS</label
                >
                <input
                    id="dossier-cnss"
                    v-model="form.numero_cnss"
                    type="text"
                    :class="champ"
                />
            </div>
            <div>
                <label
                    for="dossier-paiement-mode"
                    class="mb-1.5 block text-sm font-medium"
                    >Mode de paiement du salaire</label
                >
                <Select
                    v-model="form.paiement_mode"
                    input-id="dossier-paiement-mode"
                    :options="[
                        { value: '', label: '— Non renseigné —' },
                        ...modePaiementOptions,
                    ]"
                    option-label="label"
                    option-value="value"
                    class="w-full"
                />
            </div>
            <template
                v-if="form.paiement_mode && form.paiement_mode !== 'especes'"
            >
                <div>
                    <label
                        for="dossier-paiement-etablissement"
                        class="mb-1.5 block text-sm font-medium"
                        >{{
                            form.paiement_mode === 'mobile_money'
                                ? 'Opérateur'
                                : 'Banque'
                        }}</label
                    >
                    <input
                        id="dossier-paiement-etablissement"
                        v-model="form.paiement_etablissement"
                        type="text"
                        :class="champ"
                    />
                </div>
                <div>
                    <label
                        for="dossier-paiement-numero"
                        class="mb-1.5 block text-sm font-medium"
                        >{{
                            form.paiement_mode === 'mobile_money'
                                ? 'Numéro Mobile Money'
                                : 'N° de compte'
                        }}</label
                    >
                    <input
                        id="dossier-paiement-numero"
                        v-model="form.paiement_numero_compte"
                        type="text"
                        autocomplete="off"
                        :class="champ"
                    />
                </div>
                <div class="sm:col-span-2">
                    <label
                        for="dossier-paiement-titulaire"
                        class="mb-1.5 block text-sm font-medium"
                        >Titulaire du compte</label
                    >
                    <input
                        id="dossier-paiement-titulaire"
                        v-model="form.paiement_titulaire"
                        type="text"
                        :class="champ"
                    />
                </div>
            </template>
        </div>
    </div>
</template>
