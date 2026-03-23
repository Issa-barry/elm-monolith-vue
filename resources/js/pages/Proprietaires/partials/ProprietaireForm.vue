<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { ArrowLeft, Save } from 'lucide-vue-next';
import Dropdown from 'primevue/dropdown';
import InputText from 'primevue/inputtext';
import { computed } from 'vue';

interface CountryOption {
    label: string;
    value: string;
    code: string;
    dial: string;
}

const PAYS_OPTIONS: CountryOption[] = [
    { label: 'Guinée',              value: 'Guinée',              code: 'GN', dial: '+224' },
    { label: 'Guinée-Bissau',       value: 'Guinée-Bissau',       code: 'GW', dial: '+245' },
    { label: 'Sénégal',             value: 'Sénégal',             code: 'SN', dial: '+221' },
    { label: 'Mali',                value: 'Mali',                code: 'ML', dial: '+223' },
    { label: "Côte d'Ivoire",       value: "Côte d'Ivoire",       code: 'CI', dial: '+225' },
    { label: 'Liberia',             value: 'Liberia',             code: 'LR', dial: '+231' },
    { label: 'Sierra Leone',        value: 'Sierra Leone',        code: 'SL', dial: '+232' },
    { label: 'France',              value: 'France',              code: 'FR', dial: '+33'  },
    { label: 'Chine',               value: 'Chine',               code: 'CN', dial: '+86'  },
    { label: 'Émirats arabes unis', value: 'Émirats arabes unis', code: 'AE', dial: '+971' },
    { label: 'Inde',                value: 'Inde',                code: 'IN', dial: '+91'  },
];

function flagUrl(code: string) {
    return `https://flagcdn.com/20x15/${code.toLowerCase()}.png`;
}

interface FormData {
    nom: string;
    prenom: string;
    email: string | null;
    telephone: string | null;
    adresse: string | null;
    ville: string | null;
    pays: string | null;
    code_pays: string | null;
    code_phone_pays: string | null;
    is_active: boolean;
}

const props = defineProps<{
    form: FormData;
    errors: Partial<Record<keyof FormData, string>>;
    processing: boolean;
}>();

const emit = defineEmits<{ submit: []; 'update:form': [FormData] }>();

const selectedCountry = computed(() => PAYS_OPTIONS.find(c => c.code === props.form.code_pays));

function onPaysChange(pays: string) {
    const country = PAYS_OPTIONS.find(c => c.value === pays);
    if (country) {
        emit('update:form', {
            ...props.form,
            pays: country.value,
            code_pays: country.code,
            code_phone_pays: country.dial,
        });
    }
}
</script>

<template>
    <form class="space-y-6" @submit.prevent="emit('submit')">

        <!-- Identité -->
        <div class="rounded-xl border bg-card p-6 shadow-sm">
            <h3 class="mb-5 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                Identité
            </h3>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <Label for="prenom" class="mb-1.5 block">Prénom <span class="text-destructive">*</span></Label>
                    <InputText
                        id="prenom"
                        v-model="form.prenom"
                        class="w-full"
                        :class="{ 'p-invalid': errors.prenom }"
                    />
                    <p v-if="errors.prenom" class="mt-1 text-xs text-destructive">{{ errors.prenom }}</p>
                </div>
                <div>
                    <Label for="nom" class="mb-1.5 block">Nom <span class="text-destructive">*</span></Label>
                    <InputText
                        id="nom"
                        v-model="form.nom"
                        class="w-full"
                        :class="{ 'p-invalid': errors.nom }"
                    />
                    <p v-if="errors.nom" class="mt-1 text-xs text-destructive">{{ errors.nom }}</p>
                </div>
            </div>
        </div>

        <!-- Localisation -->
        <div class="rounded-xl border bg-card p-6 shadow-sm">
            <h3 class="mb-5 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                Localisation
            </h3>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <Label class="mb-1.5 block">Pays</Label>
                    <Dropdown
                        :model-value="form.pays"
                        @update:model-value="onPaysChange($event)"
                        :options="PAYS_OPTIONS"
                        option-label="label"
                        option-value="value"
                        placeholder="Sélectionner…"
                        class="w-full"
                        :class="{ 'p-invalid': errors.code_pays }"
                    >
                        <template #value="{ value }">
                            <div v-if="value" class="flex items-center gap-2">
                                <img :src="flagUrl(PAYS_OPTIONS.find(c => c.value === value)?.code ?? '')" class="h-4 w-auto rounded-sm shadow-sm" />
                                <span>{{ value }}</span>
                            </div>
                            <span v-else class="text-muted-foreground">Sélectionner…</span>
                        </template>
                        <template #option="{ option }">
                            <div class="flex items-center gap-2">
                                <img :src="flagUrl(option.code)" :alt="option.label" class="h-4 w-auto rounded-sm shadow-sm" />
                                <span>{{ option.label }}</span>
                            </div>
                        </template>
                    </Dropdown>
                    <p v-if="errors.code_pays" class="mt-1 text-xs text-destructive">{{ errors.code_pays }}</p>
                </div>
                <div>
                    <Label for="ville" class="mb-1.5 block">Ville</Label>
                    <InputText id="ville" v-model="form.ville" class="w-full" />
                </div>
                <div class="sm:col-span-2">
                    <Label for="adresse" class="mb-1.5 block">Adresse</Label>
                    <InputText id="adresse" v-model="form.adresse" class="w-full" />
                </div>
            </div>
        </div>

        <!-- Contact -->
        <div class="rounded-xl border bg-card p-6 shadow-sm">
            <h3 class="mb-5 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                Contact
            </h3>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <Label for="telephone" class="mb-1.5 block">Téléphone</Label>
                    <div class="flex gap-2">
                        <div class="flex h-10 w-24 shrink-0 items-center justify-center gap-1.5 rounded-md border bg-muted/40 px-2 font-mono text-sm text-muted-foreground">
                            <img v-if="selectedCountry" :src="flagUrl(selectedCountry.code)" :alt="selectedCountry.label" class="h-4 w-auto rounded-sm shadow-sm" />
                            <span>{{ form.code_phone_pays ?? '+???' }}</span>
                        </div>
                        <InputText
                            id="telephone"
                            v-model="form.telephone"
                            class="w-full"
                            :class="{ 'p-invalid': errors.telephone }"
                        />
                    </div>
                    <p v-if="errors.telephone" class="mt-1 text-xs text-destructive">{{ errors.telephone }}</p>
                </div>
                <div>
                    <Label for="email" class="mb-1.5 block">Email</Label>
                    <InputText
                        id="email"
                        v-model="form.email"
                        type="email"
                        class="w-full"
                        :class="{ 'p-invalid': errors.email }"
                    />
                    <p v-if="errors.email" class="mt-1 text-xs text-destructive">{{ errors.email }}</p>
                </div>
            </div>
        </div>

        <!-- Statut -->
        <div class="rounded-xl border bg-card p-6 shadow-sm">
            <h3 class="mb-5 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                Statut
            </h3>
            <div class="flex items-center gap-3">
                <Checkbox
                    id="is_active"
                    :checked="form.is_active"
                    @update:checked="$emit('update:form', { ...form, is_active: $event })"
                />
                <div>
                    <Label for="is_active" class="cursor-pointer font-medium">Propriétaire actif</Label>
                    <p class="text-xs text-muted-foreground">Décochez pour désactiver</p>
                </div>
            </div>
        </div>

        <!-- Pied -->
        <div class="flex items-center justify-between">
            <a href="/proprietaires">
                <Button type="button" variant="outline">
                    <ArrowLeft class="mr-2 h-4 w-4" />
                    Retour
                </Button>
            </a>
            <Button type="submit" :disabled="processing">
                <Save class="mr-2 h-4 w-4" />
                {{ processing ? 'Enregistrement…' : 'Enregistrer' }}
            </Button>
        </div>
    </form>
</template>
