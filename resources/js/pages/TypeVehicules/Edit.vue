<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';

interface TypeVehiculeData {
    id: string;
    nom: string;
    description: string | null;
    categorie_tarifaire: string | null;
    is_active: boolean;
}

interface CategorieTarifaireOption {
    value: string;
    label: string;
}

const props = defineProps<{
    type: TypeVehiculeData;
    categoriesTarifaires: CategorieTarifaireOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Types de véhicules', href: '/backoffice/type-vehicules' },
    { title: props.type.nom, href: '#' },
];

const form = useForm({
    _method: 'PUT',
    nom: props.type.nom,
    description: props.type.description ?? '',
    categorie_tarifaire: props.type.categorie_tarifaire,
    is_active: props.type.is_active,
});

function submit() {
    form.post(`/backoffice/type-vehicules/${props.type.id}`);
}
</script>

<template>
    <Head :title="`Modifier — ${type.nom}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-2xl space-y-6 p-4 sm:p-6">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Modifier le type
                </h1>
                <p class="mt-1 text-sm font-medium text-muted-foreground">
                    {{ type.nom }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Un type de véhicule n'est qu'une classification (nom) — la
                    capacité maximale de chargement se règle individuellement
                    sur chaque véhicule.
                </p>
            </div>

            <form
                class="space-y-5 rounded-xl border bg-card p-4 shadow-sm sm:p-6"
                @submit.prevent="submit"
            >
                <div>
                    <Label for="nom" class="mb-1.5 block"
                        >Nom <span class="text-destructive">*</span></Label
                    >
                    <InputText
                        id="nom"
                        v-model="form.nom"
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
                    <Label for="description" class="mb-1.5 block"
                        >Description</Label
                    >
                    <InputText
                        id="description"
                        v-model="form.description"
                        class="w-full"
                        placeholder="Optionnel"
                    />
                </div>

                <div>
                    <Label for="categorie_tarifaire" class="mb-1.5 block"
                        >Catégorie tarifaire</Label
                    >
                    <Select
                        input-id="categorie_tarifaire"
                        v-model="form.categorie_tarifaire"
                        :options="props.categoriesTarifaires"
                        option-label="label"
                        option-value="value"
                        show-clear
                        placeholder="Non classé (tarif « Autres véhicules »)"
                        class="w-full"
                        :class="{
                            'p-invalid': form.errors.categorie_tarifaire,
                        }"
                    />
                    <p class="mt-1 text-xs text-muted-foreground">
                        Détermine le prix usine appliqué automatiquement sur les
                        commandes livrées par ce type de véhicule (cf.
                        tarification des produits).
                    </p>
                    <p
                        v-if="form.errors.categorie_tarifaire"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ form.errors.categorie_tarifaire }}
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <Checkbox
                        id="is_active"
                        :model-value="form.is_active"
                        @update:model-value="form.is_active = $event === true"
                    />
                    <Label for="is_active" class="cursor-pointer">Actif</Label>
                </div>

                <div class="flex items-center justify-between pt-2">
                    <a href="/backoffice/type-vehicules">
                        <Button type="button" variant="outline">Retour</Button>
                    </a>
                    <Button type="submit" :disabled="form.processing">
                        {{
                            form.processing ? 'Enregistrement…' : 'Enregistrer'
                        }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
