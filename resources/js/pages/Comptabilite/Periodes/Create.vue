<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import Dropdown from 'primevue/dropdown';
import Textarea from 'primevue/textarea';
import { ref } from 'vue';

interface Option {
    value: string;
    label: string;
}

interface Site {
    id: string;
    nom: string;
}

const props = defineProps<{
    types: Option[];
    sites: Site[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité', href: '/backoffice/comptabilite' },
    { title: 'Périodes', href: '/backoffice/comptabilite/periodes' },
    { title: 'Nouvelle période', href: '/backoffice/comptabilite/periodes/creer' },
];

const form = ref({
    type: '',
    site_id: '',
    date_debut: '',
    date_fin: '',
    observations: '',
});
const errors = ref<Record<string, string>>({});
const submitting = ref(false);

const siteOptions = [
    { label: 'Toutes les agences', value: '' },
    ...props.sites.map((s) => ({ label: s.nom, value: s.id })),
];

function submit() {
    submitting.value = true;
    errors.value = {};
    router.post('/backoffice/comptabilite/periodes', form.value, {
        onError: (e) => {
            errors.value = e;
            submitting.value = false;
        },
        onFinish: () => {
            submitting.value = false;
        },
    });
}
</script>

<template>
    <Head title="Nouvelle période de paiement" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-2xl p-6">
            <h1 class="mb-6 text-xl font-semibold">
                Nouvelle période de paiement
            </h1>

            <form class="flex flex-col gap-5" @submit.prevent="submit">
                <!-- Type -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-sm font-medium"
                        >Type de bénéficiaire
                        <span class="text-destructive">*</span></label
                    >
                    <Dropdown
                        v-model="form.type"
                        :options="types"
                        option-label="label"
                        option-value="value"
                        placeholder="Sélectionner un type"
                        class="w-full"
                    />
                    <p v-if="errors.type" class="text-xs text-destructive">
                        {{ errors.type }}
                    </p>
                </div>

                <!-- Agence -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-sm font-medium">Agence concernée</label>
                    <Dropdown
                        v-model="form.site_id"
                        :options="siteOptions"
                        option-label="label"
                        option-value="value"
                        placeholder="Toutes les agences"
                        class="w-full"
                    />
                    <p class="text-xs text-muted-foreground">
                        Laisser vide pour inclure toutes les agences.
                    </p>
                </div>

                <!-- Dates -->
                <div class="grid grid-cols-2 gap-4">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-sm font-medium"
                            >Date de début
                            <span class="text-destructive">*</span></label
                        >
                        <input
                            v-model="form.date_debut"
                            type="date"
                            class="h-10 rounded-lg border border-input bg-background px-3 text-sm focus:ring-2 focus:ring-ring focus:outline-none"
                        />
                        <p
                            v-if="errors.date_debut"
                            class="text-xs text-destructive"
                        >
                            {{ errors.date_debut }}
                        </p>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-sm font-medium"
                            >Date de fin
                            <span class="text-destructive">*</span></label
                        >
                        <input
                            v-model="form.date_fin"
                            type="date"
                            class="h-10 rounded-lg border border-input bg-background px-3 text-sm focus:ring-2 focus:ring-ring focus:outline-none"
                        />
                        <p
                            v-if="errors.date_fin"
                            class="text-xs text-destructive"
                        >
                            {{ errors.date_fin }}
                        </p>
                    </div>
                </div>

                <!-- Aide sur les dates -->
                <div
                    v-if="form.type"
                    class="rounded-lg border bg-muted/40 px-4 py-3 text-xs text-muted-foreground"
                >
                    <template v-if="form.type === 'livreur'">
                        Livreurs : cycles de 15 jours — ex : 01/06→15/06 ou
                        16/06→30/06
                    </template>
                    <template v-else-if="form.type === 'proprietaire'">
                        Propriétaires : cycle mensuel — ex : 01/06→30/06
                    </template>
                    <template v-else>
                        Salariés : cycle mensuel — ex : 01/06→30/06
                    </template>
                </div>

                <!-- Observations -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-sm font-medium">Observations</label>
                    <Textarea
                        v-model="form.observations"
                        rows="3"
                        placeholder="Remarques optionnelles…"
                        class="w-full text-sm"
                    />
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <Button type="button" variant="outline" as-child>
                        <a href="/backoffice/comptabilite/periodes">Annuler</a>
                    </Button>
                    <Button type="submit" :disabled="submitting">
                        {{ submitting ? 'Création…' : 'Créer la période' }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
