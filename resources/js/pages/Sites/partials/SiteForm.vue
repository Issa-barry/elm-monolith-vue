<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Save } from 'lucide-vue-next';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';

interface Option {
    value: number | string;
    label: string;
}

interface FormData {
    nom: string;
    code?: string;
    type: string | null;
    ville: string | null;
    quartier: string | null;
    telephone: string | null;
}

defineProps<{
    form: FormData;
    errors: Partial<Record<keyof FormData, string>>;
    processing: boolean;
    types: Option[];
    isCreate?: boolean;
}>();

const emit = defineEmits<{ submit: []; 'update:form': [FormData] }>();
</script>

<template>
    <form
        id="site-form"
        class="space-y-4 sm:space-y-6"
        @submit.prevent="emit('submit')"
    >
        <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <div class="grid gap-5 sm:grid-cols-2">
                <!-- Nom (full width) -->
                <div class="sm:col-span-2">
                    <Label for="nom" class="mb-1.5 block"
                        >Nom <span class="text-destructive">*</span></Label
                    >
                    <InputText
                        id="nom"
                        :model-value="form.nom"
                        @update:model-value="
                            emit('update:form', { ...form, nom: $event as string })
                        "
                        class="w-full"
                        :class="{ 'p-invalid': errors.nom }"
                        placeholder="Siège principal"
                    />
                    <p v-if="errors.nom" class="mt-1 text-xs text-destructive">
                        {{ errors.nom }}
                    </p>
                </div>

                <!-- Code (edit only) -->
                <div v-if="!isCreate" class="sm:col-span-2">
                    <Label for="code" class="mb-1.5 block">Code</Label>
                    <InputText
                        id="code"
                        :model-value="form.code"
                        class="w-full bg-muted font-mono text-muted-foreground"
                        readonly
                    />
                </div>

                <!-- Type -->
                <div class="sm:col-span-2">
                    <Label class="mb-1.5 block"
                        >Type <span class="text-destructive">*</span></Label
                    >
                    <Select
                        :model-value="form.type"
                        @update:model-value="
                            emit('update:form', { ...form, type: $event })
                        "
                        :options="types"
                        option-label="label"
                        option-value="value"
                        placeholder="Sélectionner…"
                        class="w-full"
                        :class="{ 'p-invalid': errors.type }"
                    />
                    <p v-if="errors.type" class="mt-1 text-xs text-destructive">
                        {{ errors.type }}
                    </p>
                </div>

                <!-- Ville -->
                <div>
                    <Label for="ville" class="mb-1.5 block">Ville</Label>
                    <InputText
                        id="ville"
                        :model-value="form.ville ?? ''"
                        @update:model-value="
                            emit('update:form', {
                                ...form,
                                ville: ($event as string) || null,
                            })
                        "
                        class="w-full"
                        placeholder="Conakry"
                    />
                </div>

                <!-- Quartier -->
                <div>
                    <Label for="quartier" class="mb-1.5 block">Quartier</Label>
                    <InputText
                        id="quartier"
                        :model-value="form.quartier ?? ''"
                        @update:model-value="
                            emit('update:form', {
                                ...form,
                                quartier: ($event as string) || null,
                            })
                        "
                        class="w-full"
                        placeholder="Kaloum, Matoto…"
                    />
                </div>

                <!-- Téléphone -->
                <div class="sm:col-span-2">
                    <Label for="telephone" class="mb-1.5 block">Téléphone</Label>
                    <InputText
                        id="telephone"
                        :model-value="form.telephone ?? ''"
                        @update:model-value="
                            emit('update:form', {
                                ...form,
                                telephone: ($event as string) || null,
                            })
                        "
                        class="w-full"
                        placeholder="+224 620 00 00 00"
                    />
                    <p
                        v-if="errors.telephone"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.telephone }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Pied -->
        <div class="hidden items-center justify-between sm:flex">
            <a href="/sites">
                <Button type="button" variant="outline"> Retour </Button>
            </a>
            <Button type="submit" :disabled="processing">
                <Save class="mr-2 h-4 w-4" />
                {{ processing ? 'Enregistrement…' : 'Enregistrer' }}
            </Button>
        </div>
        <div class="h-20 sm:hidden" />
    </form>
</template>
