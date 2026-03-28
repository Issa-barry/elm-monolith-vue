<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Save } from 'lucide-vue-next';
import Select from 'primevue/select';
import InputText from 'primevue/inputtext';
import Textarea from 'primevue/textarea';

interface Option { value: number | string; label: string }

interface CountryOption {
    label: string;
    value: string;
    code: string;
}

const PAYS_OPTIONS: CountryOption[] = [
    { label: 'Guinée',              value: 'Guinée',              code: 'GN' },
    { label: 'Guinée-Bissau',       value: 'Guinée-Bissau',       code: 'GW' },
    { label: 'Sénégal',             value: 'Sénégal',             code: 'SN' },
    { label: 'Mali',                value: 'Mali',                code: 'ML' },
    { label: "Côte d'Ivoire",       value: "Côte d'Ivoire",       code: 'CI' },
    { label: 'Liberia',             value: 'Liberia',             code: 'LR' },
    { label: 'Sierra Leone',        value: 'Sierra Leone',        code: 'SL' },
    { label: 'France',              value: 'France',              code: 'FR' },
    { label: 'Chine',               value: 'Chine',               code: 'CN' },
    { label: 'Émirats arabes unis', value: 'Émirats arabes unis', code: 'AE' },
    { label: 'Inde',                value: 'Inde',                code: 'IN' },
];

function flagUrl(code: string) {
    return `https://flagcdn.com/20x15/${code.toLowerCase()}.png`;
}

interface FormData {
    nom: string;
    code?: string;
    type: string | null;
    statut: string | null;
    localisation: string | null;
    pays: string | null;
    ville: string | null;
    description: string | null;
    parent_id: number | null;
    latitude: number | null;
    longitude: number | null;
    telephone: string | null;
    email: string | null;
}

const props = defineProps<{
    form: FormData;
    errors: Partial<Record<keyof FormData, string>>;
    processing: boolean;
    types: Option[];
    statuts: Option[];
    parentOptions: Option[];
    isCreate?: boolean;
}>();

const emit = defineEmits<{ submit: []; 'update:form': [FormData] }>();
</script>

<template>
    <form id="site-form" class="space-y-4 sm:space-y-6" @submit.prevent="emit('submit')">

        <!-- Identification -->
        <div class="rounded-xl border bg-card p-4 sm:p-6 shadow-sm">
            <h3 class="mb-4 sm:mb-5 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                Identification
            </h3>
            <div class="grid gap-5 sm:grid-cols-2">
                <!-- Nom (full width) -->
                <div class="sm:col-span-2">
                    <Label for="nom" class="mb-1.5 block">Nom <span class="text-destructive">*</span></Label>
                    <InputText
                        id="nom"
                        :model-value="form.nom"
                        @update:model-value="emit('update:form', { ...form, nom: $event as string })"
                        class="w-full"
                        :class="{ 'p-invalid': errors.nom }"
                        placeholder="Siège principal"
                    />
                    <p v-if="errors.nom" class="mt-1 text-xs text-destructive">{{ errors.nom }}</p>
                </div>

                <!-- Code (edit only — read-only, auto-generated) -->
                <div v-if="!isCreate">
                    <Label for="code" class="mb-1.5 block">Code</Label>
                    <InputText
                        id="code"
                        :model-value="form.code"
                        class="w-full font-mono bg-muted text-muted-foreground"
                        readonly
                    />
                    <p class="mt-1 text-xs text-muted-foreground">Identifiant unique généré automatiquement.</p>
                </div>

                <!-- Type -->
                <div>
                    <Label class="mb-1.5 block">Type <span class="text-destructive">*</span></Label>
                    <Select
                        :model-value="form.type"
                        @update:model-value="emit('update:form', { ...form, type: $event })"
                        :options="types"
                        option-label="label"
                        option-value="value"
                        placeholder="Sélectionner…"
                        class="w-full"
                        :class="{ 'p-invalid': errors.type }"
                    />
                    <p v-if="errors.type" class="mt-1 text-xs text-destructive">{{ errors.type }}</p>
                </div>

                <!-- Statut -->
                <div>
                    <Label class="mb-1.5 block">Statut</Label>
                    <Select
                        :model-value="form.statut"
                        @update:model-value="emit('update:form', { ...form, statut: $event })"
                        :options="statuts"
                        option-label="label"
                        option-value="value"
                        placeholder="Sélectionner…"
                        class="w-full"
                        :class="{ 'p-invalid': errors.statut }"
                    />
                    <p v-if="errors.statut" class="mt-1 text-xs text-destructive">{{ errors.statut }}</p>
                </div>
            </div>
        </div>

        <!-- Localisation -->
        <div class="rounded-xl border bg-card p-4 sm:p-6 shadow-sm">
            <h3 class="mb-4 sm:mb-5 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                Localisation
            </h3>
            <div class="grid gap-5 sm:grid-cols-2">
                <!-- Pays -->
                <div>
                    <Label class="mb-1.5 block">Pays</Label>
                    <Select
                        :model-value="form.pays"
                        @update:model-value="emit('update:form', { ...form, pays: $event })"
                        :options="PAYS_OPTIONS"
                        option-label="label"
                        option-value="value"
                        placeholder="Sélectionner…"
                        class="w-full"
                        show-clear
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
                    </Select>
                </div>

                <!-- Ville -->
                <div>
                    <Label for="ville" class="mb-1.5 block">Ville</Label>
                    <InputText
                        id="ville"
                        :model-value="form.ville ?? ''"
                        @update:model-value="emit('update:form', { ...form, ville: ($event as string) || null })"
                        class="w-full"
                    />
                </div>

                <!-- Adresse (full width) -->
                <div class="sm:col-span-2">
                    <Label for="localisation" class="mb-1.5 block">Adresse <span class="text-destructive">*</span></Label>
                    <InputText
                        id="localisation"
                        :model-value="form.localisation ?? ''"
                        @update:model-value="emit('update:form', { ...form, localisation: ($event as string) || null })"
                        class="w-full"
                        :class="{ 'p-invalid': errors.localisation }"
                        placeholder="Ex: Route de Coleah, Immeuble ABC"
                    />
                    <p v-if="errors.localisation" class="mt-1 text-xs text-destructive">{{ errors.localisation }}</p>
                </div>

                <!-- Latitude -->
                <div>
                    <Label for="latitude" class="mb-1.5 block">Latitude</Label>
                    <InputText
                        id="latitude"
                        :model-value="form.latitude !== null ? String(form.latitude) : ''"
                        @update:model-value="emit('update:form', { ...form, latitude: $event ? Number($event) : null })"
                        class="w-full font-mono"
                        placeholder="9.5370"
                    />
                    <p v-if="errors.latitude" class="mt-1 text-xs text-destructive">{{ errors.latitude }}</p>
                </div>

                <!-- Longitude -->
                <div>
                    <Label for="longitude" class="mb-1.5 block">Longitude</Label>
                    <InputText
                        id="longitude"
                        :model-value="form.longitude !== null ? String(form.longitude) : ''"
                        @update:model-value="emit('update:form', { ...form, longitude: $event ? Number($event) : null })"
                        class="w-full font-mono"
                        placeholder="-13.6773"
                    />
                    <p v-if="errors.longitude" class="mt-1 text-xs text-destructive">{{ errors.longitude }}</p>
                </div>
            </div>
        </div>

        <!-- Contact -->
        <div class="rounded-xl border bg-card p-4 sm:p-6 shadow-sm">
            <h3 class="mb-4 sm:mb-5 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                Contact
            </h3>
            <div class="grid gap-5 sm:grid-cols-2">
                <!-- Téléphone -->
                <div>
                    <Label for="telephone" class="mb-1.5 block">N° de contact</Label>
                    <InputText
                        id="telephone"
                        :model-value="form.telephone ?? ''"
                        @update:model-value="emit('update:form', { ...form, telephone: ($event as string) || null })"
                        class="w-full"
                        placeholder="+224 620 00 00 00"
                    />
                    <p v-if="errors.telephone" class="mt-1 text-xs text-destructive">{{ errors.telephone }}</p>
                </div>

                <!-- Email -->
                <div>
                    <Label for="email" class="mb-1.5 block">Email</Label>
                    <InputText
                        id="email"
                        type="email"
                        :model-value="form.email ?? ''"
                        @update:model-value="emit('update:form', { ...form, email: ($event as string) || null })"
                        class="w-full"
                        placeholder="contact@site.com"
                    />
                    <p v-if="errors.email" class="mt-1 text-xs text-destructive">{{ errors.email }}</p>
                </div>
            </div>
        </div>

        <!-- Hiérarchie -->
        <div class="rounded-xl border bg-card p-4 sm:p-6 shadow-sm">
            <h3 class="mb-4 sm:mb-5 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                Hiérarchie
            </h3>
            <div>
                <Label class="mb-1.5 block">Site parent <span class="text-xs font-normal text-muted-foreground">(optionnel)</span></Label>
                <Select
                    :model-value="form.parent_id"
                    @update:model-value="emit('update:form', { ...form, parent_id: $event ?? null })"
                    :options="parentOptions"
                    option-label="label"
                    option-value="value"
                    placeholder="Aucun (site racine)"
                    class="w-full"
                    show-clear
                    :class="{ 'p-invalid': errors.parent_id }"
                />
                <p v-if="errors.parent_id" class="mt-1 text-xs text-destructive">{{ errors.parent_id }}</p>
                <p v-else class="mt-1 text-xs text-muted-foreground">Site parent (optionnel). Laissez vide pour un site racine.</p>
            </div>
        </div>

        <!-- Informations -->
        <div class="rounded-xl border bg-card p-4 sm:p-6 shadow-sm">
            <h3 class="mb-4 sm:mb-5 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                Informations complémentaires
            </h3>
            <div>
                <Label for="description" class="mb-1.5 block">Description</Label>
                <Textarea
                    id="description"
                    :model-value="form.description ?? ''"
                    @update:model-value="emit('update:form', { ...form, description: ($event as string) || null })"
                    class="w-full"
                    rows="4"
                    placeholder="Description du site, informations utiles…"
                />
                <p v-if="errors.description" class="mt-1 text-xs text-destructive">{{ errors.description }}</p>
            </div>
        </div>

        <!-- Pied -->
        <div class="hidden items-center justify-between sm:flex">
            <a href="/sites">
                <Button type="button" variant="outline">
                    Retour
                </Button>
            </a>
            <Button type="submit" :disabled="processing">
                <Save class="mr-2 h-4 w-4" />
                {{ processing ? 'Enregistrement…' : 'Enregistrer' }}
            </Button>
        </div>
        <div class="h-20 sm:hidden" />
    </form>
</template>
