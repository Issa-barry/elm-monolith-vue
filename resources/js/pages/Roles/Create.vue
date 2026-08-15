<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import InputText from 'primevue/inputtext';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Rôles & Permissions', href: '/backoffice/roles' },
    { title: 'Nouveau rôle', href: '#' },
];

const form = useForm({
    name: '',
    code: '',
});

function submit() {
    form.post('/backoffice/roles');
}
</script>

<template>
    <Head title="Nouveau rôle" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-2xl space-y-6 p-4 sm:p-6">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Nouveau rôle
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Le rôle démarre sans aucune permission — vous les
                    accorderez juste après, à l'écran suivant.
                </p>
            </div>

            <form
                class="space-y-5 rounded-xl border bg-card p-4 shadow-sm sm:p-6"
                @submit.prevent="submit"
            >
                <div>
                    <Label for="name" class="mb-1.5 block"
                        >Nom technique
                        <span class="text-destructive">*</span></Label
                    >
                    <InputText
                        id="name"
                        v-model="form.name"
                        class="w-full"
                        :class="{ 'p-invalid': form.errors.name }"
                        placeholder="Ex: chef_agence"
                    />
                    <p
                        v-if="form.errors.name"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ form.errors.name }}
                    </p>
                    <p v-else class="mt-1 text-xs text-muted-foreground">
                        Minuscules, sans espace ni accent — utilisé partout
                        dans le code, ne pourra plus être changé une fois des
                        utilisateurs rattachés.
                    </p>
                </div>

                <div>
                    <Label for="code" class="mb-1.5 block"
                        >Trinôme / abréviation</Label
                    >
                    <InputText
                        id="code"
                        v-model="form.code"
                        class="w-full max-w-[10rem]"
                        :class="{ 'p-invalid': form.errors.code }"
                        placeholder="Ex: CA"
                        maxlength="10"
                    />
                    <p
                        v-if="form.errors.code"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ form.errors.code }}
                    </p>
                    <p v-else class="mt-1 text-xs text-muted-foreground">
                        Optionnel — affiché à côté du nom du rôle, purement
                        informatif.
                    </p>
                </div>

                <div class="flex items-center justify-between pt-2">
                    <a href="/backoffice/roles">
                        <Button type="button" variant="outline">Retour</Button>
                    </a>
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Création…' : 'Créer' }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
