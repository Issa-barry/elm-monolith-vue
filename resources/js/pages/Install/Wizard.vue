<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Head, useForm } from '@inertiajs/vue3';
import { Check } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface PhoneInfo {
    telephone: string;
    code_pays: string;
    indicatif: string;
    pays: string;
    devise: string | null;
    fuseau: string | null;
}

const STEP_LABELS = [
    'Entreprise',
    'Super Administrateur',
    'Catalogue initial',
    'Résumé',
];
const currentStep = ref(1);

const form = useForm({
    organisation: { nom: '' },
    admin: {
        prenom: '',
        nom: '',
        telephone: '',
        email: '',
        password: '',
        password_confirmation: '',
    },
    catalogue: { categories: true, options: true, types_vehicule: true },
});

function errorFor(key: string): string | undefined {
    return (form.errors as Record<string, string>)[key];
}

// ── Étape 1 : Entreprise ─────────────────────────────────────────────────────
const step1Valid = computed(() => form.organisation.nom.trim().length > 0);

// ── Étape 2 : Super Admin ─────────────────────────────────────────────────────
function getCsrfToken(): string {
    return decodeURIComponent(
        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
    );
}

const phoneInfo = ref<PhoneInfo | null>(null);
const phoneInfoError = ref<string | null>(null);
const phoneChecking = ref(false);
let phoneDebounce: ReturnType<typeof setTimeout> | undefined;

watch(
    () => form.admin.telephone,
    (telephone) => {
        phoneInfo.value = null;
        phoneInfoError.value = null;
        clearTimeout(phoneDebounce);

        if (!telephone || telephone.trim().length < 8) return;

        phoneDebounce = setTimeout(async () => {
            phoneChecking.value = true;
            try {
                const response = await fetch('/install/phone-info', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': getCsrfToken(),
                    },
                    body: JSON.stringify({ telephone }),
                });
                const json = await response.json();
                if (response.ok && json.info) {
                    phoneInfo.value = json.info as PhoneInfo;
                } else {
                    phoneInfoError.value =
                        'Numéro invalide ou pays non déterminable.';
                }
            } catch {
                phoneInfoError.value = 'Impossible de vérifier ce numéro.';
            } finally {
                phoneChecking.value = false;
            }
        }, 500);
    },
);

const step2Valid = computed(
    () =>
        form.admin.prenom.trim().length > 0 &&
        form.admin.nom.trim().length > 0 &&
        phoneInfo.value !== null &&
        form.admin.password.length > 0 &&
        form.admin.password === form.admin.password_confirmation,
);

// ── Navigation ────────────────────────────────────────────────────────────────
function nextStep() {
    if (currentStep.value < STEP_LABELS.length) currentStep.value++;
}
function previousStep() {
    if (currentStep.value > 1) currentStep.value--;
}

function submit() {
    form.post('/install', { preserveScroll: true });
}
</script>

<template>
    <Head title="Installation" />

    <div class="flex min-h-dvh flex-col items-center bg-background px-4 py-10">
        <div class="w-full max-w-xl space-y-8">
            <div class="text-center">
                <h1 class="text-2xl font-semibold tracking-tight">
                    Installation de l'application
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Quelques informations pour créer votre entreprise et votre
                    compte administrateur.
                </p>
            </div>

            <!-- Stepper -->
            <div class="flex items-center justify-between">
                <template v-for="(label, index) in STEP_LABELS" :key="label">
                    <div class="flex flex-col items-center gap-1.5">
                        <div
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold transition-colors"
                            :class="
                                currentStep > index + 1
                                    ? 'bg-primary text-primary-foreground'
                                    : currentStep === index + 1
                                      ? 'border-2 border-primary text-primary'
                                      : 'border border-border text-muted-foreground'
                            "
                        >
                            <Check
                                v-if="currentStep > index + 1"
                                class="h-4 w-4"
                            />
                            <span v-else>{{ index + 1 }}</span>
                        </div>
                        <span
                            class="hidden text-center text-[11px] leading-tight text-muted-foreground sm:block"
                            >{{ label }}</span
                        >
                    </div>
                    <div
                        v-if="index < STEP_LABELS.length - 1"
                        class="mx-1 h-px flex-1"
                        :class="
                            currentStep > index + 1 ? 'bg-primary' : 'bg-border'
                        "
                    />
                </template>
            </div>

            <div class="rounded-xl border bg-card p-6 shadow-sm">
                <!-- Étape 1 : Entreprise -->
                <div v-if="currentStep === 1" class="grid gap-5">
                    <div class="grid gap-2">
                        <Label for="org-nom"
                            >Nom de l'entreprise
                            <span class="text-destructive">*</span></Label
                        >
                        <Input
                            id="org-nom"
                            v-model="form.organisation.nom"
                            autofocus
                        />
                        <p class="text-xs text-muted-foreground">
                            Si une entreprise porte déjà ce nom, elle sera
                            réutilisée plutôt que recréée.
                        </p>
                        <InputError :message="errorFor('organisation.nom')" />
                    </div>
                </div>

                <!-- Étape 2 : Super Admin -->
                <div v-else-if="currentStep === 2" class="grid gap-5">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label for="admin-prenom"
                                >Prénom
                                <span class="text-destructive">*</span></Label
                            >
                            <Input
                                id="admin-prenom"
                                v-model="form.admin.prenom"
                            />
                            <InputError :message="errorFor('admin.prenom')" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="admin-nom"
                                >Nom
                                <span class="text-destructive">*</span></Label
                            >
                            <Input id="admin-nom" v-model="form.admin.nom" />
                            <InputError :message="errorFor('admin.nom')" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="admin-telephone"
                            >Téléphone (format international)
                            <span class="text-destructive">*</span></Label
                        >
                        <Input
                            id="admin-telephone"
                            v-model="form.admin.telephone"
                            placeholder="+224622000000"
                        />
                        <InputError :message="errorFor('admin.telephone')" />
                        <p
                            v-if="phoneChecking"
                            class="text-xs text-muted-foreground"
                        >
                            Vérification…
                        </p>
                        <div
                            v-else-if="phoneInfo"
                            class="rounded-lg bg-muted/50 px-3 py-2 text-xs text-muted-foreground"
                        >
                            <p>✓ Pays détecté : {{ phoneInfo.pays }}</p>
                            <p>✓ Indicatif : {{ phoneInfo.indicatif }}</p>
                            <p>
                                ✓ Devise :
                                {{ phoneInfo.devise ?? 'non déterminée' }}
                            </p>
                            <p>
                                ✓ Fuseau horaire :
                                {{ phoneInfo.fuseau ?? 'non déterminé' }}
                            </p>
                        </div>
                        <p
                            v-else-if="phoneInfoError"
                            class="text-xs text-destructive"
                        >
                            {{ phoneInfoError }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="admin-email">Email (facultatif)</Label>
                        <Input
                            id="admin-email"
                            v-model="form.admin.email"
                            type="email"
                        />
                        <InputError :message="errorFor('admin.email')" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="admin-password"
                            >Mot de passe
                            <span class="text-destructive">*</span></Label
                        >
                        <Input
                            id="admin-password"
                            v-model="form.admin.password"
                            type="password"
                            autocomplete="new-password"
                        />
                        <p class="text-xs text-muted-foreground">
                            Min. 8 caractères, majuscule + minuscule + symbole.
                        </p>
                        <InputError :message="errorFor('admin.password')" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="admin-password-confirmation"
                            >Confirmer le mot de passe
                            <span class="text-destructive">*</span></Label
                        >
                        <Input
                            id="admin-password-confirmation"
                            v-model="form.admin.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                        />
                        <p
                            v-if="
                                form.admin.password_confirmation &&
                                form.admin.password !==
                                    form.admin.password_confirmation
                            "
                            class="text-xs text-destructive"
                        >
                            Les mots de passe ne correspondent pas.
                        </p>
                    </div>
                </div>

                <!-- Étape 3 : Catalogue initial -->
                <div v-else-if="currentStep === 3" class="grid gap-5">
                    <div class="flex items-start gap-3">
                        <Checkbox
                            id="cat-categories"
                            :model-value="form.catalogue.categories"
                            @update:model-value="
                                form.catalogue.categories = $event === true
                            "
                        />
                        <div>
                            <Label
                                for="cat-categories"
                                class="cursor-pointer font-medium"
                                >Créer les catégories prédéfinies ?</Label
                            >
                            <p class="text-xs text-muted-foreground">
                                Un catalogue de départ générique (vêtements,
                                chaussures, boissons...) — modifiable librement
                                ensuite.
                            </p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <Checkbox
                            id="cat-options"
                            :model-value="form.catalogue.options"
                            @update:model-value="
                                form.catalogue.options = $event === true
                            "
                        />
                        <div>
                            <Label
                                for="cat-options"
                                class="cursor-pointer font-medium"
                                >Installer la bibliothèque d'options prédéfinies
                                ?</Label
                            >
                            <p class="text-xs text-muted-foreground">
                                Modèles réutilisables (Couleur, Taille,
                                Pointure...) — pas de produits ni de variantes
                                créés automatiquement.
                            </p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <Checkbox
                            id="cat-types-vehicule"
                            :model-value="form.catalogue.types_vehicule"
                            @update:model-value="
                                form.catalogue.types_vehicule = $event === true
                            "
                        />
                        <div>
                            <Label
                                for="cat-types-vehicule"
                                class="cursor-pointer font-medium"
                                >Créer les types de véhicule prédéfinis ?</Label
                            >
                            <p class="text-xs text-muted-foreground">
                                Tricycle, Minibus, Camionnette, Camion, Remorque
                                — requis pour l'import de flotte et la fiche
                                véhicule, modifiable ensuite.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Étape 4 : Résumé -->
                <div v-else class="grid gap-5">
                    <div>
                        <h3
                            class="mb-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Entreprise
                        </h3>
                        <p class="text-sm">{{ form.organisation.nom }}</p>
                    </div>
                    <div>
                        <h3
                            class="mb-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Super Admin
                        </h3>
                        <p class="text-sm">
                            {{ form.admin.prenom }} {{ form.admin.nom }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ phoneInfo?.telephone ?? form.admin.telephone }}
                        </p>
                        <p
                            v-if="form.admin.email"
                            class="text-sm text-muted-foreground"
                        >
                            {{ form.admin.email }}
                        </p>
                    </div>
                    <div v-if="phoneInfo">
                        <h3
                            class="mb-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Pays
                        </h3>
                        <p class="text-sm">
                            {{ phoneInfo.pays }} —
                            {{ phoneInfo.devise ?? 'devise non déterminée' }}
                        </p>
                    </div>
                    <div>
                        <h3
                            class="mb-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Catalogue
                        </h3>
                        <p class="text-sm">
                            Catégories :
                            {{ form.catalogue.categories ? 'Oui' : 'Non' }}
                        </p>
                        <p class="text-sm">
                            Options :
                            {{ form.catalogue.options ? 'Oui' : 'Non' }}
                        </p>
                        <p class="text-sm">
                            Types de véhicule :
                            {{ form.catalogue.types_vehicule ? 'Oui' : 'Non' }}
                        </p>
                    </div>
                    <InputError :message="errorFor('organisation.nom')" />
                </div>
            </div>

            <div class="flex items-center justify-between">
                <Button
                    v-if="currentStep > 1"
                    type="button"
                    variant="outline"
                    @click="previousStep"
                >
                    Retour
                </Button>
                <div v-else />

                <Button
                    v-if="currentStep === 1"
                    type="button"
                    :disabled="!step1Valid"
                    @click="nextStep"
                >
                    Suivant
                </Button>
                <Button
                    v-else-if="currentStep === 2"
                    type="button"
                    :disabled="!step2Valid"
                    @click="nextStep"
                >
                    Suivant
                </Button>
                <Button
                    v-else-if="currentStep === 3"
                    type="button"
                    @click="nextStep"
                >
                    Suivant
                </Button>
                <Button
                    v-else
                    type="button"
                    :disabled="form.processing"
                    @click="submit"
                >
                    <Spinner v-if="form.processing" />
                    Terminer l'installation
                </Button>
            </div>
        </div>
    </div>
</template>
