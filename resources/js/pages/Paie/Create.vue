<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    mois_courant: number;
    annee_courante: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Paie', href: '/paie' },
    { title: 'Nouvelle période', href: '/paie/create' },
];

const moisLabels = [
    '',
    'Janvier',
    'Février',
    'Mars',
    'Avril',
    'Mai',
    'Juin',
    'Juillet',
    'Août',
    'Septembre',
    'Octobre',
    'Novembre',
    'Décembre',
];

const moisOptions = Array.from({ length: 12 }, (_, i) => ({
    value: i + 1,
    label: moisLabels[i + 1],
}));

const anneesOptions = Array.from({ length: 10 }, (_, i) => {
    const y = new Date().getFullYear() + 1 - i;
    return { value: y, label: String(y) };
});

const form = useForm({
    mois: props.mois_courant,
    annee: props.annee_courante,
    notes: '',
});

function submit() {
    form.post('/paie');
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Nouvelle période de paie" />

        <div class="mx-auto max-w-lg space-y-6 p-6">
            <h1 class="text-2xl font-bold">Nouvelle période de paie</h1>

            <form class="space-y-4" @submit.prevent="submit">
                <!-- Mois -->
                <div class="space-y-1">
                    <label class="text-sm font-medium">Mois</label>
                    <select
                        v-model="form.mois"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:ring-1 focus:ring-ring focus:outline-none"
                    >
                        <option
                            v-for="opt in moisOptions"
                            :key="opt.value"
                            :value="opt.value"
                        >
                            {{ opt.label }}
                        </option>
                    </select>
                    <p v-if="form.errors.mois" class="text-xs text-destructive">
                        {{ form.errors.mois }}
                    </p>
                </div>

                <!-- Année -->
                <div class="space-y-1">
                    <label class="text-sm font-medium">Année</label>
                    <select
                        v-model="form.annee"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:ring-1 focus:ring-ring focus:outline-none"
                    >
                        <option
                            v-for="opt in anneesOptions"
                            :key="opt.value"
                            :value="opt.value"
                        >
                            {{ opt.label }}
                        </option>
                    </select>
                    <p
                        v-if="form.errors.annee"
                        class="text-xs text-destructive"
                    >
                        {{ form.errors.annee }}
                    </p>
                </div>

                <!-- Notes -->
                <div class="space-y-1">
                    <label class="text-sm font-medium"
                        >Notes
                        <span class="text-muted-foreground"
                            >(optionnel)</span
                        ></label
                    >
                    <textarea
                        v-model="form.notes"
                        rows="3"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:ring-1 focus:ring-ring focus:outline-none"
                    />
                </div>

                <div class="flex gap-3">
                    <Button type="submit" :disabled="form.processing"
                        >Créer la période</Button
                    >
                    <a href="/paie">
                        <Button variant="outline" type="button">Annuler</Button>
                    </a>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
