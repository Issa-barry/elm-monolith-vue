<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Save } from 'lucide-vue-next';
import Select from 'primevue/select';

interface Option {
    value: string;
    label: string;
}

defineProps<{
    type_employe_options: Option[];
    statut_options: Option[];
    sites: Option[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Employés', href: '/backoffice/employes' },
    { title: 'Nouveau', href: '#' },
];

const form = useForm({
    nom: '',
    prenom: '',
    email: '',
    telephone: '',
    type_employe: 'interne',
    site_id: null as string | null,
    statut: 'actif',
});

function submit() {
    form.post('/backoffice/employes');
}
</script>

<template>
    <Head><title>Nouvel employé</title></Head>
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6">
            <div class="mb-6 flex items-center gap-4">
                <Link href="/backoffice/employes">
                    <Button variant="ghost" size="icon"
                        ><ArrowLeft class="h-4 w-4"
                    /></Button>
                </Link>
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Nouvel employé
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Le matricule sera généré automatiquement.
                    </p>
                </div>
            </div>

            <form class="max-w-2xl space-y-6" @submit.prevent="submit">
                <div class="space-y-5 rounded-xl border bg-card p-6 shadow-sm">
                    <h3
                        class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Informations personnelles
                    </h3>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium"
                                >Prénom
                                <span class="text-destructive">*</span></label
                            >
                            <input
                                v-model="form.prenom"
                                type="text"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                :class="{
                                    'border-destructive': form.errors.prenom,
                                }"
                            />
                            <p
                                v-if="form.errors.prenom"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.prenom }}
                            </p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium"
                                >Nom
                                <span class="text-destructive">*</span></label
                            >
                            <input
                                v-model="form.nom"
                                type="text"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                :class="{
                                    'border-destructive': form.errors.nom,
                                }"
                            />
                            <p
                                v-if="form.errors.nom"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.nom }}
                            </p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium"
                                >Email</label
                            >
                            <input
                                v-model="form.email"
                                type="email"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                :class="{
                                    'border-destructive': form.errors.email,
                                }"
                            />
                            <p
                                v-if="form.errors.email"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.email }}
                            </p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium"
                                >Téléphone</label
                            >
                            <input
                                v-model="form.telephone"
                                type="tel"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            />
                        </div>
                    </div>
                </div>

                <div class="space-y-5 rounded-xl border bg-card p-6 shadow-sm">
                    <h3
                        class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Affectation
                    </h3>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium"
                                >Type d'employé
                                <span class="text-destructive">*</span></label
                            >
                            <Select
                                v-model="form.type_employe"
                                :options="type_employe_options"
                                option-label="label"
                                option-value="value"
                                class="w-full"
                            />
                            <p
                                v-if="form.errors.type_employe"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.type_employe }}
                            </p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium"
                                >Statut
                                <span class="text-destructive">*</span></label
                            >
                            <Select
                                v-model="form.statut"
                                :options="statut_options"
                                option-label="label"
                                option-value="value"
                                class="w-full"
                            />
                            <p
                                v-if="form.errors.statut"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.statut }}
                            </p>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1.5 block text-sm font-medium"
                                >Site</label
                            >
                            <Select
                                v-model="form.site_id"
                                :options="[
                                    { value: null, label: '— Aucun site —' },
                                    ...sites,
                                ]"
                                option-label="label"
                                option-value="value"
                                class="w-full"
                            />
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between">
                    <Link href="/backoffice/employes">
                        <Button type="button" variant="outline"
                            ><ArrowLeft class="mr-2 h-4 w-4" />Annuler</Button
                        >
                    </Link>
                    <Button type="submit" :disabled="form.processing">
                        <Save class="mr-2 h-4 w-4" />
                        {{
                            form.processing
                                ? 'Enregistrement…'
                                : "Créer l'employé"
                        }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
