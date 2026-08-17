<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import PhoneCountryInput from '@/components/PhoneCountryInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Head, useForm } from '@inertiajs/vue3';
import { Check, Eye, EyeOff } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface DomaineOption {
    value: string;
    label: string;
    description: string;
}

const props = defineProps<{
    domaines: DomaineOption[];
}>();

// ELM n'est pour l'instant utilisable que depuis ces deux pays (cf. PhoneCountryInput) — une
// restriction propre à l'installation d'une NOUVELLE organisation, pas à Login.vue qui doit,
// lui, rester ouvert à tous les pays pour les comptes déjà existants ailleurs.
const PAYS_INSTALL = [
    { label: 'Guinée', code: 'GN', prefix: '+224', localLength: 9 },
    { label: 'Sierra Leone', code: 'SL', prefix: '+232', localLength: 8 },
];

const STEP_LABELS = [
    'Entreprise',
    'Super Administrateur',
    "Domaine d'activité",
    'Résumé',
];
const currentStep = ref(1);

const form = useForm({
    organisation: { nom: '', domaine: '' },
    admin: {
        prenom: '',
        nom: '',
        telephone: '',
        email: '',
        password: '',
    },
});

const showPassword = ref(false);

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

interface PhoneInfo {
    telephone: string;
    code_pays: string;
    indicatif: string;
    pays: string;
    devise: string | null;
    fuseau: string | null;
}

const phoneInfo = ref<PhoneInfo | null>(null);
const phoneInfoError = ref<string | null>(null);
const phoneChecking = ref(false);
let phoneDebounce: ReturnType<typeof setTimeout> | undefined;

function onTelephoneChange(telephone: string) {
    form.admin.telephone = telephone;
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
}

const step2Valid = computed(
    () =>
        form.admin.prenom.trim().length > 0 &&
        form.admin.nom.trim().length > 0 &&
        phoneInfo.value !== null &&
        form.admin.password.length > 0,
);

// ── Étape 3 : Domaine d'activité ─────────────────────────────────────────────
const step3Valid = computed(() => form.organisation.domaine.length > 0);

const domaineLabel = computed(
    () =>
        props.domaines.find((d) => d.value === form.organisation.domaine)
            ?.label ?? '',
);

// ── Navigation ────────────────────────────────────────────────────────────────
function nextStep() {
    if (currentStep.value < STEP_LABELS.length) currentStep.value++;
}
function previousStep() {
    if (currentStep.value > 1) currentStep.value--;
}

function submit() {
    form.post('/install', {
        preserveScroll: true,
        // Les erreurs de admin.* / organisation.* sont validées côté serveur au moment du
        // submit final (étape 4, Résumé) mais leur <InputError> vit dans le markup des étapes
        // 1-3, qui ne sont pas rendues à ce moment-là — sans ce renvoi, l'erreur reste
        // invisible et le bouton "Terminer" semble ne rien faire.
        onError: (errors) => {
            const firstKey = Object.keys(errors)[0];
            if (!firstKey) return;
            if (firstKey.startsWith('admin.')) currentStep.value = 2;
            else if (firstKey === 'organisation.domaine') currentStep.value = 3;
            else if (firstKey.startsWith('organisation.'))
                currentStep.value = 1;
        },
    });
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
                <div v-else-if="currentStep === 2" class="grid gap-4">
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
                        <Label
                            >Téléphone
                            <span class="text-destructive">*</span></Label
                        >
                        <PhoneCountryInput
                            id="admin-telephone"
                            :model-value="form.admin.telephone"
                            :countries="PAYS_INSTALL"
                            @update:model-value="onTelephoneChange"
                        />
                        <InputError :message="errorFor('admin.telephone')" />
                        <p
                            v-if="phoneChecking"
                            class="text-xs text-muted-foreground"
                        >
                            Vérification…
                        </p>
                        <p
                            v-else-if="phoneInfo"
                            class="text-xs text-muted-foreground"
                        >
                            ✓ Numéro valide ({{ phoneInfo.pays }})
                        </p>
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
                        <div class="relative">
                            <Input
                                id="admin-password"
                                v-model="form.admin.password"
                                :type="showPassword ? 'text' : 'password'"
                                autocomplete="new-password"
                                class="pr-10"
                            />
                            <button
                                type="button"
                                class="absolute inset-y-0 right-0 inline-flex w-10 items-center justify-center text-muted-foreground transition-colors hover:text-foreground"
                                @click="showPassword = !showPassword"
                                :aria-label="
                                    showPassword
                                        ? 'Masquer le mot de passe'
                                        : 'Afficher le mot de passe'
                                "
                            >
                                <component
                                    :is="showPassword ? EyeOff : Eye"
                                    class="h-4 w-4"
                                />
                            </button>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Min. 8 caractères, majuscule + minuscule + symbole.
                        </p>
                        <InputError :message="errorFor('admin.password')" />
                    </div>
                </div>

                <!-- Étape 3 : Domaine d'activité -->
                <div v-else-if="currentStep === 3" class="grid gap-4">
                    <p class="text-sm font-medium">
                        Quelle est l'activité principale de votre entreprise ?
                    </p>
                    <div class="grid gap-2">
                        <button
                            v-for="d in domaines"
                            :key="d.value"
                            type="button"
                            class="rounded-lg border p-3 text-left transition-colors"
                            :class="
                                form.organisation.domaine === d.value
                                    ? 'border-primary bg-primary/5'
                                    : 'border-border hover:bg-muted/50'
                            "
                            @click="form.organisation.domaine = d.value"
                        >
                            <p class="text-sm font-semibold">{{ d.label }}</p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{ d.description }}
                            </p>
                        </button>
                    </div>
                    <InputError :message="errorFor('organisation.domaine')" />
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
                        <p class="text-sm text-muted-foreground">
                            {{ domaineLabel }}
                        </p>
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
                    <p class="text-xs text-muted-foreground">
                        Le catalogue de départ (types de produit, catégories,
                        options, types de véhicule) est créé automatiquement et
                        adapté à votre domaine d'activité — modifiable ensuite.
                        Le premier site se configure à la première connexion.
                    </p>
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
                    :disabled="!step3Valid"
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
