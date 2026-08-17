<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import InputText from 'primevue/inputtext';
import { useConfirm } from 'primevue/useconfirm';
import { ref } from 'vue';

interface GroupeCapaciteRow {
    id: string;
    nom: string;
    produits_count: number;
}

const props = defineProps<{
    groupes: GroupeCapaciteRow[];
}>();

const confirm = useConfirm();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Véhicules', href: '/backoffice/vehicules' },
    { title: 'Groupes de capacité', href: '#' },
];

// ── Création ──────────────────────────────────────────────────────────────
const creating = ref(false);
const createForm = useForm({ nom: '' });

function submitCreate() {
    createForm.post('/backoffice/vehicules/groupes-capacite', {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset();
            creating.value = false;
        },
    });
}

// ── Édition ───────────────────────────────────────────────────────────────
const editingId = ref<string | null>(null);
const editForm = useForm({ nom: '' });

function startEdit(g: GroupeCapaciteRow) {
    editingId.value = g.id;
    editForm.nom = g.nom;
    editForm.clearErrors();
}

function submitEdit(id: string) {
    editForm.put(`/backoffice/vehicules/groupes-capacite/${id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editingId.value = null;
        },
    });
}

// ── Suppression ───────────────────────────────────────────────────────────
function destroyGroupe(g: GroupeCapaciteRow) {
    confirm.require({
        message: `Supprimer le groupe de capacité « ${g.nom} » ?`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            useForm({}).delete(
                `/backoffice/vehicules/groupes-capacite/${g.id}`,
                { preserveScroll: true },
            );
        },
    });
}
</script>

<template>
    <Head title="Groupes de capacité" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-2xl space-y-6 p-4 sm:p-6">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Groupes de capacité
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Unités de chargement (ex : Sachets, Bouteilles) utilisées
                    pour plafonner la capacité des véhicules — indépendantes
                    des catégories du catalogue produit. Rattachez vos
                    produits à un groupe depuis leur fiche pour qu'ils soient
                    pris en compte dans le contrôle de capacité.
                </p>
            </div>

            <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
                <div v-if="groupes.length === 0 && !creating" class="py-10 text-center">
                    <p class="text-sm text-muted-foreground">
                        Aucun groupe de capacité défini.
                    </p>
                </div>

                <div v-if="groupes.length > 0" class="divide-y rounded-lg border">
                    <div
                        v-for="g in groupes"
                        :key="g.id"
                        class="flex items-center justify-between gap-3 px-3 py-2.5"
                    >
                        <template v-if="editingId === g.id">
                            <InputText
                                v-model="editForm.nom"
                                class="flex-1"
                                :class="{ 'p-invalid': editForm.errors.nom }"
                                autofocus
                                @keyup.enter="submitEdit(g.id)"
                            />
                            <div class="flex shrink-0 gap-2">
                                <Button
                                    size="sm"
                                    :disabled="editForm.processing"
                                    @click="submitEdit(g.id)"
                                    >OK</Button
                                >
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    @click="editingId = null"
                                    >Annuler</Button
                                >
                            </div>
                        </template>
                        <template v-else>
                            <div class="min-w-0">
                                <span class="text-sm font-medium">{{
                                    g.nom
                                }}</span>
                                <span
                                    class="ml-2 text-xs text-muted-foreground"
                                    >{{ g.produits_count }} produit(s)</span
                                >
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="startEdit(g)"
                                    >Renommer</Button
                                >
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="h-8 w-8 text-destructive"
                                    @click="destroyGroupe(g)"
                                >
                                    <Trash2 class="h-3.5 w-3.5" />
                                </Button>
                            </div>
                        </template>
                    </div>
                </div>

                <div
                    v-if="creating"
                    class="mt-4 flex items-end gap-2 rounded-lg border bg-muted/30 p-3"
                >
                    <div class="flex-1">
                        <Label class="mb-1.5 block text-xs">Nom</Label>
                        <InputText
                            v-model="createForm.nom"
                            class="w-full"
                            :class="{ 'p-invalid': createForm.errors.nom }"
                            placeholder="Ex : Sachets"
                            autofocus
                            @keyup.enter="submitCreate"
                        />
                        <p
                            v-if="createForm.errors.nom"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ createForm.errors.nom }}
                        </p>
                    </div>
                    <Button
                        :disabled="!createForm.nom.trim() || createForm.processing"
                        @click="submitCreate"
                        >Ajouter</Button
                    >
                    <Button variant="ghost" @click="creating = false"
                        >Annuler</Button
                    >
                </div>
                <Button
                    v-else
                    variant="outline"
                    size="sm"
                    class="mt-4"
                    @click="creating = true"
                >
                    <Plus class="mr-1.5 h-3.5 w-3.5" />
                    Nouveau groupe de capacité
                </Button>
            </div>
        </div>
    </AppLayout>
</template>
