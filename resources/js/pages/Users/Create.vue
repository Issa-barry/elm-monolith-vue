<script setup lang="ts">
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, KeyRound, Save, UserCog } from 'lucide-vue-next';
import { ref } from 'vue';
import UserForm from './partials/UserForm.vue';

interface RoleOption {
    value: string;
    label: string;
}

interface SiteOption {
    value: number;
    label: string;
}

defineProps<{ roles: RoleOption[]; sites: SiteOption[] }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Utilisateurs', href: '/users' },
    { title: 'Nouveau compte', href: '#' },
];

// ── Onglets ───────────────────────────────────────────────────────────────────
type Tab = 'info' | 'password';
const activeTab = ref<Tab>('info');

// ── Formulaire unique (soumis sur l'onglet mot de passe) ──────────────────────
const form = useForm({
    prenom: '',
    nom: '',
    email: '',
    telephone: null as string | null,
    code_pays: 'GN' as string | null,
    code_phone_pays: '+224' as string | null,
    ville: null as string | null,
    adresse: null as string | null,
    role: '',
    site_id: null as number | null,
    password: '',
    password_confirmation: '',
    is_active: true,
});

function goToPassword() {
    form.clearErrors();

    if (!form.prenom.trim()) form.setError('prenom', 'Le prénom est obligatoire.');
    if (!form.nom.trim()) form.setError('nom', 'Le nom est obligatoire.');
    if (!form.telephone) form.setError('telephone', 'Le numéro de téléphone est obligatoire.');
    if (!form.role) form.setError('role', 'Le rôle est obligatoire.');
    if (!form.site_id) form.setError('site_id', 'Le site est obligatoire.');

    if (Object.keys(form.errors).length === 0) {
        activeTab.value = 'password';
    }
}

function submit() {
    form.clearErrors('password', 'password_confirmation');

    if (!form.password) {
        form.setError('password', 'Le mot de passe est obligatoire.');
        return;
    }
    if (form.password.length < 8) {
        form.setError('password', 'Le mot de passe doit contenir au moins 8 caractères.');
        return;
    }
    if (!/[a-zA-Z]/.test(form.password) || !/[0-9]/.test(form.password)) {
        form.setError('password', 'Le mot de passe doit contenir des lettres et des chiffres.');
        return;
    }
    if (form.password !== form.password_confirmation) {
        form.setError('password_confirmation', 'La confirmation ne correspond pas.');
        return;
    }

    form.post('/users');
}
</script>

<template>
    <Head>
        <title>Nouveau compte</title>
    </Head>
    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- Header mobile -->
        <div
            class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden"
        >
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link
                    href="/users"
                    class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div class="text-center">
                    <h1 class="text-[17px] leading-tight font-semibold">
                        Nouveau compte
                    </h1>
                </div>
            </div>
        </div>

        <div class="pb-6 sm:p-6">
            <!-- Titre desktop -->
            <div class="hidden px-6 pt-6 pb-0 sm:block">
                <div class="mb-6">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Nouveau compte utilisateur
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Créez un compte staff pour votre organisation.
                    </p>
                </div>
            </div>

            <!-- Layout 2 colonnes style Settings -->
            <div class="flex flex-col px-4 sm:px-6 lg:flex-row lg:gap-12">

                <!-- Sidebar nav -->
                <aside class="w-full lg:w-44">
                    <nav class="flex gap-1 overflow-x-auto pb-4 lg:flex-col lg:space-y-1 lg:overflow-x-visible lg:pb-0">
                        <button
                            type="button"
                            class="inline-flex w-auto shrink-0 items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium transition-colors hover:bg-muted lg:w-full lg:justify-start"
                            :class="activeTab === 'info' ? 'bg-muted text-foreground' : 'text-muted-foreground'"
                            @click="activeTab = 'info'"
                        >
                            <UserCog class="h-4 w-4" />
                            Informations
                        </button>
                        <button
                            type="button"
                            class="inline-flex w-auto shrink-0 items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium transition-colors hover:bg-muted lg:w-full lg:justify-start"
                            :class="activeTab === 'password' ? 'bg-muted text-foreground' : 'text-muted-foreground'"
                            @click="activeTab = 'password'"
                        >
                            <KeyRound class="h-4 w-4" />
                            Mot de passe
                        </button>
                    </nav>
                </aside>

                <!-- Contenu -->
                <div class="min-w-0 flex-1">

                    <!-- Onglet Informations -->
                    <UserForm
                        v-if="activeTab === 'info'"
                        :form="form"
                        :errors="form.errors"
                        :processing="form.processing"
                        :roles="$props.roles"
                        :sites="$props.sites"
                        :is-edit="false"
                        :show-password="false"
                        submit-label="Continuer"
                        back-href="/users"
                        @submit="goToPassword"
                        @update:form="Object.assign(form, $event)"
                        @clear-error="form.clearErrors($event as any)"
                    />

                    <!-- Onglet Mot de passe -->
                    <form
                        v-else
                        id="user-form"
                        class="space-y-4 sm:space-y-6"
                        autocomplete="off"
                        @submit.prevent="submit"
                    >
                        <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
                            <h3
                                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
                            >
                                Mot de passe
                            </h3>
                            <div class="grid gap-5 sm:grid-cols-2">
                                <div>
                                    <label
                                        for="password"
                                        class="mb-1.5 block text-sm font-medium"
                                    >
                                        Mot de passe
                                        <span class="text-destructive">*</span>
                                    </label>
                                    <input
                                        id="password"
                                        v-model="form.password"
                                        type="password"
                                        autocomplete="new-password"
                                        class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                        :class="{ 'border-destructive': form.errors.password }"
                                    />
                                    <p
                                        v-if="form.errors.password"
                                        class="mt-1 text-xs text-destructive"
                                    >
                                        {{ form.errors.password }}
                                    </p>
                                </div>
                                <div>
                                    <label
                                        for="password_confirmation"
                                        class="mb-1.5 block text-sm font-medium"
                                    >
                                        Confirmer
                                        <span class="text-destructive">*</span>
                                    </label>
                                    <input
                                        id="password_confirmation"
                                        v-model="form.password_confirmation"
                                        type="password"
                                        autocomplete="new-password"
                                        class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- Actions desktop -->
                        <div class="hidden items-center justify-between sm:flex">
                            <button
                                type="button"
                                class="inline-flex h-10 items-center justify-center rounded-md border border-input bg-background px-4 text-sm font-medium transition-colors hover:bg-accent"
                                @click="activeTab = 'info'"
                            >
                                <ArrowLeft class="mr-2 h-4 w-4" />
                                Retour
                            </button>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="inline-flex h-10 items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90 disabled:opacity-60"
                            >
                                <Save class="mr-2 h-4 w-4" />
                                {{ form.processing ? 'Création…' : 'Créer le compte' }}
                            </button>
                        </div>
                        <div class="h-20 sm:hidden" />
                    </form>

                </div><!-- /flex-1 -->
            </div><!-- /flex row -->
        </div>

        <!-- Footer mobile -->
        <div
            class="fixed right-0 bottom-0 left-0 z-30 border-t border-border/60 bg-background/95 px-4 py-3 backdrop-blur-sm sm:hidden"
        >
            <button
                type="submit"
                form="user-form"
                :disabled="form.processing"
                class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-3 text-sm font-semibold text-primary-foreground shadow-sm transition-transform active:scale-[0.98] disabled:opacity-60"
            >
                <Spinner v-if="form.processing" class="h-4 w-4" />
                <Save v-else class="h-4 w-4" />
                {{ form.processing ? 'Création…' : activeTab === 'info' ? 'Continuer' : 'Créer le compte' }}
            </button>
        </div>
    </AppLayout>
</template>
