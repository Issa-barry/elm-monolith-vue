<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { ArrowLeft, Save } from 'lucide-vue-next';
import Dropdown from 'primevue/dropdown';
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
    code: string;
    type: string | null;
    statut: string | null;
    localisation: string | null;
    pays: string | null;
    ville: string | null;
    quartier: string | null;
    description: string | null;
    parent_id: number | null;
}

const props = defineProps<{
    form: FormData;
    errors: Partial<Record<keyof FormData, string>>;
    processing: boolean;
    types: Option[];
    statuts: Option[];
    parentOptions: Option[];
}>();

const emit = defineEmits<{ submit: []; 'update:form': [FormData] }>();
</script>

<template>
    <form class="space-y-6" @submit.prevent="emit('submit')">

        <!-- Identification -->
        <div class="rounded-xl border bg-card p-6 shadow-sm">
            <h3 class="mb-5 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
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

                <!-- Code -->
                <div>
                    <Label for="code" class="mb-1.5 block">Code <span class="text-destructive">*</span></Label>
                    <InputText
                        id="code"
                        :model-value="form.code"
                        @update:model-value="emit('update:form', { ...form, code: ($event as string).toUpperCase() })"
                        class="w-full font-mono"
                        :class="{ 'p-invalid': errors.code }"
                        placeholder="CONAKRY-01"
                    />
                    <p v-if="errors.code" class="mt-1 text-xs text-destructive">{{ errors.code }}</p>
                    <p v-else class="mt-1 text-xs text-muted-foreground">Majuscules, chiffres, tirets et underscores uniquement.</p>
                </div>

                <!-- Type -->
                <div>
                    <Label class="mb-1.5 block">Type <span class="text-destructive">*</span></Label>
                    <Dropdown
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
                    <Dropdown
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
        <div class="rounded-xl border bg-card p-6 shadow-sm">
            <h3 class="mb-5 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                Localisation
            </h3>
            <div class="grid gap-5 sm:grid-cols-2">
                <!-- Pays -->
                <div>
                    <Label class="mb-1.5 block">Pays</Label>
                    <Dropdown
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
                    </Dropdown>
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

                <!-- Quartier -->
                <div>
                    <Label for="quartier" class="mb-1.5 block">Quartier</Label>
                    <InputText
                        id="quartier"
                        :model-value="form.quartier ?? ''"
                        @update:model-value="emit('update:form', { ...form, quartier: ($event as string) || null })"
                        class="w-full"
                    />
                </div>

                <!-- Localisation (full width) -->
                <div class="sm:col-span-2">
                    <Label for="localisation" class="mb-1.5 block">Adresse / Localisation</Label>
                    <InputText
                        id="localisation"
                        :model-value="form.localisation ?? ''"
                        @update:model-value="emit('update:form', { ...form, localisation: ($event as string) || null })"
                        class="w-full"
                        placeholder="Ex: Route de Coleah, Immeuble ABC"
                    />
                </div>
            </div>
        </div>

        <!-- Hiérarchie -->
        <div class="rounded-xl border bg-card p-6 shadow-sm">
            <h3 class="mb-5 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                Hiérarchie
            </h3>
            <div>
                <Label class="mb-1.5 block">Site parent <span class="text-xs font-normal text-muted-foreground">(optionnel)</span></Label>
                <Dropdown
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
        <div class="rounded-xl border bg-card p-6 shadow-sm">
            <h3 class="mb-5 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
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
        <div class="flex items-center justify-between">
            <a href="/sites">
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
