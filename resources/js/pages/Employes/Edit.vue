<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Briefcase,
    Pencil,
    Plus,
    Save,
    Trash2,
} from 'lucide-vue-next';
import Select from 'primevue/select';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';

interface Option {
    value: string;
    label: string;
}

interface ContratRow {
    id: string;
    type_contrat: string;
    type_contrat_label: string;
    statut_contrat: string;
    statut_contrat_label: string;
    date_debut: string | null;
    date_fin: string | null;
    salaire_base: string | null;
}

interface EmployeData {
    id: string;
    matricule: string | null;
    nom: string;
    prenom: string;
    email: string | null;
    telephone: string | null;
    type_employe: string;
    statut: string;
    site_id: string | null;
    site: string | null;
    contrats: ContratRow[];
    contrat_actif: { type_contrat: string; type_contrat_label: string } | null;
}

const props = defineProps<{
    employe: EmployeData;
    type_employe_options: Option[];
    statut_options: Option[];
    sites: Option[];
}>();

const confirm = useConfirm();
const toast = useToast();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Employés', href: '/backoffice/employes' },
    { title: `${props.employe.prenom} ${props.employe.nom}`, href: '#' },
];

const form = useForm({
    nom: props.employe.nom,
    prenom: props.employe.prenom,
    email: props.employe.email ?? '',
    telephone: props.employe.telephone ?? '',
    type_employe: props.employe.type_employe,
    site_id: props.employe.site_id ?? null,
    statut: props.employe.statut,
});

function submit() {
    form.put(`/backoffice/employes/${props.employe.id}`);
}

const TYPE_CONTRAT_CLASS: Record<string, string> = {
    cdi: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
    cdd: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
};

const STATUT_CONTRAT_CLASS: Record<string, string> = {
    actif: 'bg-emerald-100 text-emerald-700',
    termine: 'bg-zinc-100 text-zinc-600',
    rompu: 'bg-red-100 text-red-700',
};

function deleteContrat(c: ContratRow) {
    confirm.require({
        message: `Supprimer ce contrat ${c.type_contrat_label} ?`,
        header: 'Confirmer',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/backoffice/contrats/${c.id}`, {
                onSuccess: () =>
                    toast.add({
                        severity: 'success',
                        summary: 'Contrat supprimé',
                        life: 3000,
                    }),
            });
        },
    });
}
</script>

<template>
    <Head
        ><title>{{ employe.prenom }} {{ employe.nom }}</title></Head
    >
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6">
            <!-- Header -->
            <div class="mb-6 flex items-start justify-between">
                <div class="flex items-center gap-4">
                    <Link href="/backoffice/employes">
                        <Button variant="ghost" size="icon"
                            ><ArrowLeft class="h-4 w-4"
                        /></Button>
                    </Link>
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">
                            {{ employe.prenom }} {{ employe.nom }}
                        </h1>
                        <div
                            class="mt-1 flex items-center gap-2 text-sm text-muted-foreground"
                        >
                            <span
                                v-if="employe.matricule"
                                class="rounded bg-muted px-2 py-0.5 font-mono text-[11px]"
                                >{{ employe.matricule }}</span
                            >
                            <span
                                v-if="employe.contrat_actif"
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
                                :class="
                                    TYPE_CONTRAT_CLASS[
                                        employe.contrat_actif.type_contrat
                                    ]
                                "
                            >
                                <Briefcase class="mr-1 h-3 w-3" />{{
                                    employe.contrat_actif.type_contrat_label
                                }}
                            </span>
                        </div>
                    </div>
                </div>
                <Link
                    v-if="!employe.contrat_actif"
                    :href="`/backoffice/contrats/create?employe_id=${employe.id}`"
                >
                    <Button variant="outline" size="sm"
                        ><Plus class="mr-1.5 h-4 w-4" />Nouveau contrat</Button
                    >
                </Link>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Formulaire -->
                <form class="space-y-5" @submit.prevent="submit">
                    <div
                        class="space-y-4 rounded-xl border bg-card p-6 shadow-sm"
                    >
                        <h3
                            class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Informations
                        </h3>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1.5 block text-sm font-medium"
                                    >Prénom
                                    <span class="text-destructive"
                                        >*</span
                                    ></label
                                >
                                <input
                                    v-model="form.prenom"
                                    type="text"
                                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    :class="{
                                        'border-destructive':
                                            form.errors.prenom,
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
                                    <span class="text-destructive"
                                        >*</span
                                    ></label
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
                                />
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
                            <div>
                                <label class="mb-1.5 block text-sm font-medium"
                                    >Type
                                    <span class="text-destructive"
                                        >*</span
                                    ></label
                                >
                                <Select
                                    v-model="form.type_employe"
                                    :options="type_employe_options"
                                    option-label="label"
                                    option-value="value"
                                    class="w-full"
                                />
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium"
                                    >Statut
                                    <span class="text-destructive"
                                        >*</span
                                    ></label
                                >
                                <Select
                                    v-model="form.statut"
                                    :options="statut_options"
                                    option-label="label"
                                    option-value="value"
                                    class="w-full"
                                />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="mb-1.5 block text-sm font-medium"
                                    >Site</label
                                >
                                <Select
                                    v-model="form.site_id"
                                    :options="[
                                        {
                                            value: null,
                                            label: '— Aucun site —',
                                        },
                                        ...sites,
                                    ]"
                                    option-label="label"
                                    option-value="value"
                                    class="w-full"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <Button type="submit" :disabled="form.processing">
                            <Save class="mr-2 h-4 w-4" />
                            {{
                                form.processing
                                    ? 'Enregistrement…'
                                    : 'Enregistrer'
                            }}
                        </Button>
                    </div>
                </form>

                <!-- Contrats -->
                <div class="rounded-xl border bg-card p-6 shadow-sm">
                    <div class="mb-4 flex items-center justify-between">
                        <h3
                            class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Contrats
                        </h3>
                        <Link
                            v-if="!employe.contrat_actif"
                            :href="`/backoffice/contrats/create?employe_id=${employe.id}`"
                        >
                            <Button variant="outline" size="sm"
                                ><Plus
                                    class="mr-1 h-3.5 w-3.5"
                                />Ajouter</Button
                            >
                        </Link>
                    </div>

                    <div
                        v-if="employe.contrats.length === 0"
                        class="py-8 text-center text-sm text-muted-foreground"
                    >
                        Aucun contrat enregistré.
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="c in employe.contrats"
                            :key="c.id"
                            class="flex items-start justify-between rounded-lg border p-3"
                        >
                            <div class="space-y-1">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="
                                            TYPE_CONTRAT_CLASS[c.type_contrat]
                                        "
                                    >
                                        {{ c.type_contrat_label }}
                                    </span>
                                    <span
                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="
                                            STATUT_CONTRAT_CLASS[
                                                c.statut_contrat
                                            ]
                                        "
                                    >
                                        {{ c.statut_contrat_label }}
                                    </span>
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    Du {{ c.date_debut }}
                                    <template v-if="c.date_fin">
                                        au {{ c.date_fin }}</template
                                    >
                                    <template v-else> (indéterminé)</template>
                                </p>
                                <p
                                    v-if="c.salaire_base"
                                    class="text-xs text-muted-foreground"
                                >
                                    Salaire :
                                    {{
                                        Number(c.salaire_base).toLocaleString(
                                            'fr-FR',
                                        )
                                    }}
                                    GNF
                                </p>
                            </div>
                            <div class="flex gap-1">
                                <Link
                                    :href="`/backoffice/contrats/${c.id}/edit`"
                                >
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="h-7 w-7"
                                    >
                                        <Pencil class="h-3.5 w-3.5" />
                                    </Button>
                                </Link>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="h-7 w-7 text-destructive hover:text-destructive"
                                    @click="deleteContrat(c)"
                                >
                                    <Trash2 class="h-3.5 w-3.5" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
