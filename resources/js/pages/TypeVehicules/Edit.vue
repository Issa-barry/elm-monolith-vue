<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';

interface TypeVehiculeData {
    id: string;
    nom: string;
    capacite_defaut: number;
    unite_capacite: string;
    description: string | null;
    is_active: boolean;
}

const props = defineProps<{ type: TypeVehiculeData }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Types de véhicules', href: '/type-vehicules' },
    { title: props.type.nom, href: '#' },
];

const form = useForm({
    _method: 'PUT',
    nom: props.type.nom,
    capacite_defaut: props.type.capacite_defaut,
    unite_capacite: props.type.unite_capacite,
    description: props.type.description ?? '',
    is_active: props.type.is_active,
});

function submit() {
    form.post(`/type-vehicules/${props.type.id}`);
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

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <Label for="capacite_defaut" class="mb-1.5 block">
                            Capacité par défaut
                            <span class="text-destructive">*</span>
                        </Label>
                        <InputNumber
                            id="capacite_defaut"
                            v-model="form.capacite_defaut"
                            :min="1"
                            :max="99999"
                            :use-grouping="false"
                            class="w-full"
                            :class="{
                                'p-invalid': form.errors.capacite_defaut,
                            }"
                        />
                        <p
                            v-if="form.errors.capacite_defaut"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ form.errors.capacite_defaut }}
                        </p>
                    </div>

                    <div>
                        <Label for="unite_capacite" class="mb-1.5 block"
                            >Unité
                            <span class="text-destructive">*</span></Label
                        >
                        <InputText
                            id="unite_capacite"
                            v-model="form.unite_capacite"
                            class="w-full"
                            :class="{ 'p-invalid': form.errors.unite_capacite }"
                        />
                        <p
                            v-if="form.errors.unite_capacite"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ form.errors.unite_capacite }}
                        </p>
                    </div>
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

                <div class="flex items-center gap-3">
                    <Checkbox
                        id="is_active"
                        :model-value="form.is_active"
                        @update:model-value="form.is_active = $event === true"
                    />
                    <Label for="is_active" class="cursor-pointer">Actif</Label>
                </div>

                <div class="flex items-center justify-between pt-2">
                    <a href="/type-vehicules">
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
