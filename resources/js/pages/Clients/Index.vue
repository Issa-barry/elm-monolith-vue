<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { Plus, Search, Trash2, UserRound } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { ref } from 'vue';

interface Client {
    id: number;
    nom: string;
    prenom: string | null;
    email: string | null;
    telephone: string | null;
    adresse: string | null;
    is_active: boolean;
}

interface Paginator {
    data: Client[];
    current_page: number;
    last_page: number;
    total: number;
}

defineProps<{ clients: Paginator }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Clients', href: '#' },
];

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();
const search = ref('');

function destroy(client: Client) {
    confirm.require({
        message: `Supprimer ${client.nom} ${client.prenom ?? ''} ?`,
        header: 'Confirmation',
        acceptLabel: 'Supprimer',
        rejectLabel: 'Annuler',
        accept: () => {
            router.delete(`/clients/${client.id}`, {
                onSuccess: () =>
                    toast.add({
                        severity: 'success',
                        summary: 'Client supprimé',
                        life: 3000,
                    }),
            });
        },
    });
}
</script>

<template>
    <Head>
        <title>Clients</title>
    </Head>

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-4 sm:p-6">
            <div class="mb-4 flex items-center justify-between">
                <h1 class="text-xl font-semibold">Clients</h1>
                <Button
                    v-if="can('clients.create')"
                    as="a"
                    href="/clients/create"
                    size="sm"
                >
                    <Plus class="mr-1 h-4 w-4" />
                    Nouveau
                </Button>
            </div>

            <div class="rounded-xl border bg-card shadow-sm">
                <div class="p-3">
                    <IconField>
                        <InputIcon><Search class="h-4 w-4" /></InputIcon>
                        <InputText
                            v-model="search"
                            placeholder="Rechercher…"
                            class="w-full sm:w-72"
                        />
                    </IconField>
                </div>

                <DataTable
                    :value="clients.data"
                    :global-filter-fields="[
                        'nom',
                        'prenom',
                        'email',
                        'telephone',
                    ]"
                    :global-filter="search"
                    striped-rows
                    size="small"
                >
                    <Column header="Client">
                        <template #body="{ data: c }">
                            <div class="flex items-center gap-2">
                                <div
                                    class="flex h-8 w-8 items-center justify-center rounded-full bg-muted"
                                >
                                    <UserRound
                                        class="h-4 w-4 text-muted-foreground"
                                    />
                                </div>
                                <span>{{ c.nom }} {{ c.prenom }}</span>
                            </div>
                        </template>
                    </Column>
                    <Column field="email" header="Email" />
                    <Column field="telephone" header="Téléphone" />
                    <Column header="" style="width: 4rem">
                        <template #body="{ data: c }">
                            <Button
                                v-if="can('clients.delete')"
                                variant="ghost"
                                size="icon"
                                class="text-destructive"
                                @click="destroy(c)"
                            >
                                <Trash2 class="h-4 w-4" />
                            </Button>
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
