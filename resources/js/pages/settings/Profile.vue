<script setup lang="ts">
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import DeleteUser from '@/components/DeleteUser.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface Props {
    mustVerifyEmail: boolean;
    status?: string;
}

defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Paramètres du profil',
        href: edit().url,
    },
];

const page = usePage();
const authUser = computed(() => page.props.auth.user);
const isSuperAdmin = computed(() => (page.props.auth.roles as string[]).includes('super_admin'));

const prenom    = ref(authUser.value.prenom    ?? '');
const nom       = ref(authUser.value.nom       ?? '');
const email     = ref(authUser.value.email     ?? '');
const telephone = ref(authUser.value.telephone ?? '');

watch(authUser, (u) => {
    prenom.value    = u.prenom    ?? '';
    nom.value       = u.nom       ?? '';
    email.value     = u.email     ?? '';
    telephone.value = u.telephone ?? '';
});

function formatPhone(phone: string | null): string {
    if (!phone) return '';
    const match = phone.match(/^(\+\d{1,3})(\d+)$/);
    if (!match) return phone;
    const [, prefix, local] = match;
    const groups = local.match(/.{1,3}/g) ?? [local];
    return `${prefix} ${groups.join(' ')}`;
}

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: 'long', year: 'numeric' }).format(new Date(iso));
}

const ROLE_LABELS: Record<string, string> = {
    super_admin:      'Super Admin',
    admin_entreprise: 'Admin entreprise',
    manager:          'Manager',
    commerciale:      'Commercial(e)',
    comptable:        'Comptable',
    client:           'Client',
};

const roles = computed(() => (page.props.auth.roles as string[]).map(r => ROLE_LABELS[r] ?? r));
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Paramètres du profil" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <HeadingSmall
                    title="Informations du profil"
                    description="Mettez à jour vos informations personnelles"
                />

                <Form
                    v-bind="ProfileController.update.form()"
                    class="space-y-6"
                    v-slot="{ errors, processing, recentlySuccessful }"
                >
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="prenom">Prénom <span class="text-destructive">*</span></Label>
                            <Input
                                id="prenom"
                                class="mt-1 block w-full"
                                name="prenom"
                                v-model="prenom"
                                required
                                autocomplete="given-name"
                                placeholder="Prénom"
                            />
                            <InputError class="mt-2" :message="errors.prenom" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="nom">Nom <span class="text-destructive">*</span></Label>
                            <Input
                                id="nom"
                                class="mt-1 block w-full"
                                name="nom"
                                v-model="nom"
                                required
                                autocomplete="family-name"
                                placeholder="Nom"
                            />
                            <InputError class="mt-2" :message="errors.nom" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="email">Adresse e-mail</Label>
                        <Input
                            id="email"
                            type="email"
                            class="mt-1 block w-full"
                            name="email"
                            v-model="email"
                            autocomplete="username"
                            placeholder="Adresse e-mail"
                        />
                        <InputError class="mt-2" :message="errors.email" />
                    </div>

                    <!-- Téléphone -->
                    <div class="grid gap-2">
                        <Label>Téléphone</Label>
                        <template v-if="isSuperAdmin">
                            <Input
                                type="tel"
                                class="mt-1 block w-full"
                                name="telephone"
                                v-model="telephone"
                                autocomplete="tel"
                                placeholder="+224620000000"
                            />
                        </template>
                        <template v-else>
                            <p class="flex h-9 items-center rounded-md border border-input bg-muted/40 px-3 text-sm text-muted-foreground">
                                {{ formatPhone(authUser.telephone) || '—' }}
                            </p>
                        </template>
                    </div>

                    <!-- Informations du compte (lecture seule) -->
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <!-- Organisation -->
                        <div class="grid gap-2">
                            <Label>Organisation</Label>
                            <p class="flex h-9 items-center rounded-md border border-input bg-muted/40 px-3 text-sm text-muted-foreground">
                                {{ authUser.organization?.name ?? '—' }}
                            </p>
                        </div>

                        <!-- Rôle(s) -->
                        <div class="grid gap-2">
                            <Label>Rôle(s)</Label>
                            <p class="flex h-9 items-center gap-2 rounded-md border border-input bg-muted/40 px-3 text-sm">
                                <span
                                    v-for="role in roles"
                                    :key="role"
                                    class="rounded bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary"
                                >{{ role }}</span>
                                <span v-if="!roles.length" class="text-muted-foreground">—</span>
                            </p>
                        </div>

                        <!-- Membre depuis -->
                        <div class="grid gap-2">
                            <Label>Membre depuis</Label>
                            <p class="flex h-9 items-center rounded-md border border-input bg-muted/40 px-3 text-sm text-muted-foreground">
                                {{ formatDate(authUser.created_at) }}
                            </p>
                        </div>

                        <!-- Statut email -->
                        <div class="grid gap-2">
                            <Label>E-mail vérifié</Label>
                            <p class="flex h-9 items-center gap-2 rounded-md border border-input bg-muted/40 px-3 text-sm">
                                <template v-if="authUser.email_verified_at">
                                    <span class="h-2 w-2 rounded-full bg-green-500"></span>
                                    <span class="text-muted-foreground">{{ formatDate(authUser.email_verified_at) }}</span>
                                </template>
                                <template v-else>
                                    <span class="h-2 w-2 rounded-full bg-amber-400"></span>
                                    <span class="text-muted-foreground">Non vérifié</span>
                                </template>
                            </p>
                        </div>
                    </div>

                    <div v-if="mustVerifyEmail && !authUser.email_verified_at">
                        <p class="-mt-4 text-sm text-muted-foreground">
                            Votre adresse e-mail n'est pas vérifiée.
                            <Link
                                :href="send()"
                                as="button"
                                class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                            >
                                Cliquez ici pour renvoyer l'e-mail de vérification.
                            </Link>
                        </p>

                        <div
                            v-if="status === 'verification-link-sent'"
                            class="mt-2 text-sm font-medium text-green-600"
                        >
                            Un nouveau lien de vérification a été envoyé à votre adresse e-mail.
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <Button
                            :disabled="processing"
                            data-test="update-profile-button"
                            >Enregistrer</Button
                        >

                        <Transition
                            enter-active-class="transition ease-in-out"
                            enter-from-class="opacity-0"
                            leave-active-class="transition ease-in-out"
                            leave-to-class="opacity-0"
                        >
                            <p
                                v-show="recentlySuccessful"
                                class="text-sm text-neutral-600"
                            >
                                Enregistré.
                            </p>
                        </Transition>
                    </div>
                </Form>
            </div>

            <DeleteUser />
        </SettingsLayout>
    </AppLayout>
</template>
