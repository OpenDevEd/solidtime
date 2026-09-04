<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import AuthenticationCard from '@/Components/AuthenticationCard.vue';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import Banner from '@/Components/Banner.vue';

withDefaults(
    defineProps<{
        googleAuthEnabled?: boolean;
    }>(),
    {
        googleAuthEnabled: false,
    }
);

const page = usePage<{
    flash: {
        message: string;
    };
}>();
</script>

<template>
    <Head title="Log in" />

    <AuthenticationCard>
        <template #logo>
            <AuthenticationCardLogo />
        </template>

        <Banner card />

        <div
            v-if="page.props.flash?.message"
            class="bg-red-400 text-black text-center w-full px-3 py-1 mb-4 rounded-lg">
            {{ page.props.flash?.message }}
        </div>

        <a
            v-if="googleAuthEnabled"
            :href="route('auth.google.redirect')"
            class="flex h-10 w-full items-center justify-center gap-3 rounded-lg border border-button-secondary-border bg-button-secondary-background px-4 text-sm font-medium text-text-primary shadow-sm transition hover:bg-button-secondary-background-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
            <svg class="h-5 w-5" viewBox="0 0 24 24" aria-hidden="true">
                <path
                    fill="#4285F4"
                    d="M21.6 12.23c0-.71-.06-1.4-.18-2.07H12v3.92h5.38a4.6 4.6 0 0 1-2 3.02v2.54h3.24c1.9-1.74 2.98-4.31 2.98-7.41Z" />
                <path
                    fill="#34A853"
                    d="M12 22c2.7 0 4.97-.9 6.62-2.42l-3.24-2.53c-.9.6-2.05.96-3.38.96-2.61 0-4.82-1.77-5.61-4.14H3.04v2.61A10 10 0 0 0 12 22Z" />
                <path
                    fill="#FBBC05"
                    d="M6.39 13.87A6 6 0 0 1 6.08 12c0-.65.11-1.28.31-1.87V7.52H3.04A10 10 0 0 0 2 12c0 1.61.38 3.13 1.04 4.48l3.35-2.61Z" />
                <path
                    fill="#EA4335"
                    d="M12 5.99c1.47 0 2.79.5 3.83 1.5l2.87-2.88A9.63 9.63 0 0 0 12 2a10 10 0 0 0-8.96 5.52l3.35 2.61C7.18 7.76 9.39 5.99 12 5.99Z" />
            </svg>
            Continue with Google
        </a>
    </AuthenticationCard>
</template>
