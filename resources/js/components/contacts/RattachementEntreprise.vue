<script setup lang="ts">
/**
 * Fiche Entreprise d'une fonction (propriétaire entreprise — lot 5 ; client professionnel —
 * lot 7b ; ADR 0060) : raison sociale, téléphone et NIF de l'entreprise, recherche des entreprises
 * qui correspondent et choix explicite (D3, D6), avertissement et confirmation D7 pour une entreprise
 * déjà reliée et partagée. Fragment d'une grille à deux colonnes du formulaire parent.
 */
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Link } from '@inertiajs/vue3';
import { Search } from 'lucide-vue-next';
import InputText from 'primevue/inputtext';
import RadioButton from 'primevue/radiobutton';
import { computed, ref, watch } from 'vue';

export interface ValeursEntreprise {
    raison_sociale: string | null;
    telephone_entreprise: string | null;
    identifiant_fiscal: string | null;
    entreprise_choix: string | null;
    confirmer_modification_entreprise?: boolean;
    code_pays?: string | null;
}

/** Entreprise déjà reliée (modification) ; le serveur décide du droit d'en modifier l'identité (D7). */
export interface EntrepriseReliee {
    id: string;
    raison_sociale: string;
    partagee: boolean;
    peut_modifier_identite: boolean;
    lien: string | null;
}

interface Correspondance {
    id: string;
    raison_sociale: string;
    identifiant_fiscal: string | null;
    telephone: string | null;
    ville: string | null;
    is_active: boolean;
    criteres: ('telephone' | 'identifiant_fiscal' | 'raison_sociale')[];
    deja_pris: boolean;
    selectionnable: boolean;
}

interface ResultatRecherche {
    statut: 'aucune' | 'correspondances' | 'indisponible';
    champ?: string;
    message?: string;
    correspondances: Correspondance[];
}

const props = defineProps<{
    valeurs: ValeursEntreprise;
    errors: Partial<Record<string, string>>;
    entreprise?: EntrepriseReliee | null;
    /** Route de recherche des correspondances (lecture seule). */
    urlRecherche: string;
    /** Paramètres ajoutés à la recherche (ex. fiche à exclure). */
    parametres?: Record<string, string>;
    /** Libellé d'une entreprise qui porte déjà la fonction (« Déjà propriétaire »…). */
    libelleDejaPris: string;
}>();

const emit = defineEmits<{ maj: [Partial<ValeursEntreprise>] }>();

function maj(champs: Partial<ValeursEntreprise>) {
    emit('maj', champs);
}

const entrepriseReliee = computed(() => props.entreprise ?? null);
const identiteVerrouillee = computed(
    () =>
        entrepriseReliee.value !== null &&
        !entrepriseReliee.value.peut_modifier_identite,
);
const identiteInitiale = {
    raison_sociale: props.valeurs.raison_sociale ?? '',
    telephone_entreprise: props.valeurs.telephone_entreprise ?? '',
    identifiant_fiscal: props.valeurs.identifiant_fiscal ?? '',
};
const identiteModifiee = computed(
    () =>
        (props.valeurs.raison_sociale ?? '') !==
            identiteInitiale.raison_sociale ||
        (props.valeurs.telephone_entreprise ?? '') !==
            identiteInitiale.telephone_entreprise ||
        (props.valeurs.identifiant_fiscal ?? '') !==
            identiteInitiale.identifiant_fiscal,
);
const confirmationRequise = computed(
    () =>
        entrepriseReliee.value?.partagee === true &&
        entrepriseReliee.value.peut_modifier_identite &&
        identiteModifiee.value,
);

const LIBELLES_CRITERES: Record<Correspondance['criteres'][number], string> = {
    telephone: 'même téléphone',
    identifiant_fiscal: 'même NIF',
    raison_sociale: 'même raison sociale',
};

const recherche = ref<ResultatRecherche | null>(null);
const rechercheEnCours = ref(false);
const erreurRecherche = ref<string | null>(null);

async function rechercherEntreprises() {
    if (rechercheEnCours.value || entrepriseReliee.value) return;
    rechercheEnCours.value = true;
    erreurRecherche.value = null;
    try {
        const params = new URLSearchParams(props.parametres ?? {});
        params.set('raison_sociale', props.valeurs.raison_sociale ?? '');
        params.set(
            'telephone_entreprise',
            props.valeurs.telephone_entreprise ?? '',
        );
        params.set(
            'identifiant_fiscal',
            props.valeurs.identifiant_fiscal ?? '',
        );
        if (props.valeurs.code_pays)
            params.set('code_pays', props.valeurs.code_pays);
        const reponse = await fetch(
            `${props.urlRecherche}?${params.toString()}`,
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
        recherche.value = (await reponse.json()) as ResultatRecherche;
    } catch {
        erreurRecherche.value =
            'La recherche n’a pas abouti (connexion). Réessayez dans un instant.';
    } finally {
        rechercheEnCours.value = false;
    }
}

// Une saisie modifiée rend le résultat et le choix caducs : il faut rechercher à nouveau.
watch(
    () => [
        props.valeurs.raison_sociale,
        props.valeurs.telephone_entreprise,
        props.valeurs.identifiant_fiscal,
    ],
    () => {
        if (entrepriseReliee.value) return;
        recherche.value = null;
        if (props.valeurs.entreprise_choix) maj({ entreprise_choix: null });
    },
);

// Le serveur exige un choix explicite quand des entreprises correspondent : on les affiche.
watch(
    () => props.errors.entreprise_choix,
    (erreur) => {
        if (erreur && !entrepriseReliee.value) rechercherEntreprises();
    },
);

function onTelephoneEntrepriseInput(value: string | null | undefined) {
    const brut = String(value ?? '');
    const propre = (brut.startsWith('+') ? '+' : '') + brut.replace(/\D/g, '');
    maj({ telephone_entreprise: propre.slice(0, 16) || null });
}
</script>

<template>
    <div class="sm:col-span-2">
        <Label for="raison_sociale" class="mb-1.5 block"
            >Raison sociale <span class="text-destructive">*</span></Label
        >
        <InputText
            id="raison_sociale"
            data-testid="raison-sociale-input"
            :model-value="valeurs.raison_sociale ?? ''"
            :readonly="identiteVerrouillee"
            @update:model-value="maj({ raison_sociale: $event || null })"
            class="w-full"
            :class="{ 'p-invalid': errors.raison_sociale }"
        />
        <p v-if="errors.raison_sociale" class="mt-1 text-xs text-destructive">
            {{ errors.raison_sociale }}
        </p>
        <p
            v-if="entrepriseReliee"
            class="mt-1 text-xs text-muted-foreground"
            data-testid="entreprise-reliee"
        >
            Fiche Entreprise reliée :
            <Link
                v-if="entrepriseReliee.lien"
                :href="entrepriseReliee.lien"
                class="font-medium text-primary hover:underline"
                >{{ entrepriseReliee.raison_sociale }}</Link
            >
            <span v-else class="font-medium">{{
                entrepriseReliee.raison_sociale
            }}</span>
            — elle fait foi pour la raison sociale.
        </p>
    </div>
    <div>
        <Label for="telephone_entreprise" class="mb-1.5 block"
            >Téléphone de l'entreprise</Label
        >
        <InputText
            id="telephone_entreprise"
            data-testid="telephone-entreprise-input"
            :model-value="valeurs.telephone_entreprise ?? ''"
            :readonly="identiteVerrouillee"
            @update:model-value="onTelephoneEntrepriseInput($event)"
            inputmode="tel"
            placeholder="Facultatif"
            class="w-full"
            :class="{
                'p-invalid': errors.telephone_entreprise,
            }"
        />
        <p
            v-if="errors.telephone_entreprise"
            class="mt-1 text-xs text-destructive"
        >
            {{ errors.telephone_entreprise }}
        </p>
        <p v-else class="mt-1 text-xs text-muted-foreground">
            Différent de celui du contact ; sans indicatif, celui du pays choisi
            est appliqué.
        </p>
    </div>
    <div>
        <Label for="identifiant_fiscal" class="mb-1.5 block">NIF</Label>
        <InputText
            id="identifiant_fiscal"
            data-testid="identifiant-fiscal-input"
            :model-value="valeurs.identifiant_fiscal ?? ''"
            :readonly="identiteVerrouillee"
            @update:model-value="maj({ identifiant_fiscal: $event || null })"
            placeholder="Facultatif"
            class="w-full"
            :class="{ 'p-invalid': errors.identifiant_fiscal }"
        />
        <p
            v-if="errors.identifiant_fiscal"
            class="mt-1 text-xs text-destructive"
        >
            {{ errors.identifiant_fiscal }}
        </p>
    </div>

    <!-- Entreprise reliée : D7 (identité partagée) -->
    <div
        v-if="entrepriseReliee && identiteVerrouillee"
        class="text-xs text-muted-foreground sm:col-span-2"
        data-testid="identite-entreprise-verrouillee"
    >
        Cette entreprise porte aussi d'autres fonctions : son identité ne peut
        être modifiée qu'avec le droit « Contacts — modifier l'identité d'une
        fiche ».
    </div>
    <div
        v-if="confirmationRequise"
        class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 sm:col-span-2 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200"
        data-testid="confirmation-entreprise-partagee"
    >
        <p>
            Cette entreprise porte aussi d'autres fonctions : la modification de
            sa raison sociale, de son téléphone ou de son NIF vaudra pour
            toutes.
        </p>
        <div class="mt-2 flex items-center gap-2">
            <Checkbox
                id="confirmer_modification_entreprise"
                :model-value="
                    Boolean(valeurs.confirmer_modification_entreprise)
                "
                @update:model-value="
                    maj({
                        confirmer_modification_entreprise: $event === true,
                    })
                "
            />
            <Label
                for="confirmer_modification_entreprise"
                class="cursor-pointer"
                >Je confirme la modification pour toutes ses fonctions</Label
            >
        </div>
    </div>
    <p
        v-if="errors.confirmer_modification_entreprise"
        class="text-xs text-destructive sm:col-span-2"
    >
        {{ errors.confirmer_modification_entreprise }}
    </p>

    <!-- Entreprise non reliée : recherche puis choix explicite (D3, D6) -->
    <div v-if="!entrepriseReliee" class="sm:col-span-2">
        <Button
            type="button"
            variant="outline"
            size="sm"
            :disabled="rechercheEnCours"
            data-testid="rechercher-entreprises"
            @click="rechercherEntreprises"
        >
            <Search class="mr-2 h-4 w-4" />
            {{
                rechercheEnCours
                    ? 'Recherche…'
                    : 'Rechercher une entreprise existante'
            }}
        </Button>
        <p v-if="erreurRecherche" class="mt-2 text-xs text-destructive">
            {{ erreurRecherche }}
        </p>
        <p v-if="errors.entreprise_choix" class="mt-2 text-xs text-destructive">
            {{ errors.entreprise_choix }}
        </p>
        <p
            v-if="recherche?.statut === 'indisponible'"
            class="mt-2 text-xs text-destructive"
            data-testid="recherche-entreprise-indisponible"
        >
            {{ recherche.message }}
        </p>
        <p
            v-else-if="recherche?.statut === 'aucune'"
            class="mt-2 text-xs text-muted-foreground"
            data-testid="recherche-entreprise-aucune"
        >
            Aucune entreprise ne correspond : une fiche Entreprise sera créée à
            l'enregistrement.
        </p>
        <div
            v-else-if="recherche?.statut === 'correspondances'"
            class="mt-3 space-y-2"
            data-testid="recherche-entreprise-correspondances"
        >
            <p class="text-xs text-muted-foreground">
                Choisissez l'entreprise de ce propriétaire, ou créez-en une
                nouvelle. L'entreprise choisie fait foi : sa raison sociale
                remplace la saisie.
            </p>
            <label
                v-for="c in recherche.correspondances"
                :key="c.id"
                class="flex items-start gap-3 rounded-lg border p-3 text-sm"
                :class="c.selectionnable ? 'cursor-pointer' : 'opacity-60'"
                data-testid="correspondance-entreprise"
            >
                <RadioButton
                    :model-value="valeurs.entreprise_choix"
                    :value="c.id"
                    :disabled="!c.selectionnable"
                    @update:model-value="maj({ entreprise_choix: $event })"
                />
                <span class="min-w-0">
                    <span class="block font-medium">{{
                        c.raison_sociale
                    }}</span>
                    <span class="block text-xs text-muted-foreground">
                        {{
                            [
                                c.criteres
                                    .map((k) => LIBELLES_CRITERES[k])
                                    .join(', '),
                                c.identifiant_fiscal
                                    ? `NIF ${c.identifiant_fiscal}`
                                    : null,
                                c.telephone,
                                c.ville,
                            ]
                                .filter(Boolean)
                                .join(' · ')
                        }}
                    </span>
                    <span
                        v-if="c.deja_pris"
                        class="block text-xs text-muted-foreground"
                        >{{ libelleDejaPris }}</span
                    >
                    <span
                        v-else-if="!c.is_active"
                        class="block text-xs text-muted-foreground"
                        >Entreprise désactivée</span
                    >
                </span>
            </label>
            <label
                class="flex cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm"
                data-testid="correspondance-nouvelle"
            >
                <RadioButton
                    :model-value="valeurs.entreprise_choix"
                    value="nouvelle"
                    @update:model-value="maj({ entreprise_choix: $event })"
                />
                <span class="font-medium">Créer une nouvelle entreprise</span>
            </label>
        </div>
    </div>
</template>
