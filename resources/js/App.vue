<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

const loginForm = reactive({
    email: 'test@example.com',
    password: 'password',
});

const organizationForm = reactive({
    url: '',
});

const user = ref(null);
const organization = ref(null);
const reviews = ref([]);
const pagination = ref(null);
const currentPage = ref(1);
const authError = ref('');
const organizationError = ref('');
const reviewsError = ref('');
const isCheckingAuth = ref(true);
const isLoggingIn = ref(false);
const isSavingOrganization = ref(false);
const isClearingData = ref(false);
const isLoadingReviews = ref(false);

const isAuthenticated = computed(() => user.value !== null);
const hasOrganization = computed(() => organization.value !== null);
const isOrganizationParsing = computed(() => ['queued', 'parsing'].includes(organization.value?.parse_status));
const isOrganizationFailed = computed(() => organization.value?.parse_status === 'failed');
const totalPages = computed(() => pagination.value?.last_page ?? 1);
let organizationPollingTimer = null;

const organizationStatusMessage = computed(() => {
    if (organization.value?.parse_status === 'queued') {
        return 'Задача поставлена в очередь. Отзывы скоро начнут загружаться.';
    }

    if (organization.value?.parse_status === 'parsing') {
        return 'Отзывы загружаются. Это может занять несколько минут.';
    }

    if (organization.value?.parse_status === 'failed') {
        return organization.value?.parse_error || 'Не удалось загрузить отзывы.';
    }

    return '';
});

const visiblePages = computed(() => {
    const lastPage = totalPages.value;
    const activePage = currentPage.value;
    const start = Math.max(1, activePage - 2);
    const end = Math.min(lastPage, activePage + 2);

    return Array.from({ length: end - start + 1 }, (_, index) => start + index);
});

onMounted(async () => {
    await loadUser();

    if (isAuthenticated.value) {
        await loadOrganization();
    }

    isCheckingAuth.value = false;
});

onBeforeUnmount(() => {
    clearOrganizationPolling();
});

async function login() {
    authError.value = '';
    isLoggingIn.value = true;

    try {
        await csrfCookie();

        const response = await apiFetch('/login', {
            method: 'POST',
            body: JSON.stringify(loginForm),
        });

        if (! response?.user) {
            throw new Error('Не удалось войти. Проверьте email и пароль.');
        }

        user.value = response.user;
        await loadOrganization();
    } catch (error) {
        authError.value = error.message;
    } finally {
        isLoggingIn.value = false;
    }
}

async function logout() {
    await apiFetch('/logout', { method: 'POST' });

    clearOrganizationPolling();
    user.value = null;
    organization.value = null;
    reviews.value = [];
    pagination.value = null;
    currentPage.value = 1;
}

async function loadUser() {
    try {
        const response = await apiFetch('/api/user');
        user.value = response;
    } catch {
        user.value = null;
    }
}

async function loadOrganization(options = {}) {
    if (! options.silent) {
        organizationError.value = '';
    }

    try {
        const response = await apiFetch('/api/organization');
        organization.value = response.organization;

        if (! organization.value) {
            clearOrganizationPolling();
            reviews.value = [];
            pagination.value = null;

            return;
        }

        if (isOrganizationParsing.value) {
            reviews.value = [];
            pagination.value = null;
            scheduleOrganizationPolling();

            return;
        }

        clearOrganizationPolling();

        if (! isOrganizationFailed.value) {
            currentPage.value = 1;
            await loadReviews(1);
        }
    } catch (error) {
        if (! options.silent) {
            organizationError.value = error.message;
        }
    }
}

async function saveOrganization() {
    organizationError.value = '';
    reviewsError.value = '';
    isSavingOrganization.value = true;

    try {
        const response = await apiFetch('/api/organization', {
            method: 'POST',
            body: JSON.stringify({ url: organizationForm.url }),
        });

        organization.value = response.organization;
        currentPage.value = 1;
        reviews.value = [];
        pagination.value = null;

        if (isOrganizationParsing.value) {
            scheduleOrganizationPolling();
        } else {
            await loadReviews(1);
        }
    } catch (error) {
        organizationError.value = error.message;
    } finally {
        isSavingOrganization.value = false;
    }
}

async function clearOrganizationData() {
    organizationError.value = '';
    reviewsError.value = '';
    isClearingData.value = true;

    try {
        await apiFetch('/api/organization', {
            method: 'DELETE',
        });

        organization.value = null;
        reviews.value = [];
        pagination.value = null;
        currentPage.value = 1;
        organizationForm.url = '';
        clearOrganizationPolling();
    } catch (error) {
        organizationError.value = error.message;
    } finally {
        isClearingData.value = false;
    }
}

async function loadReviews(page) {
    if (! organization.value || isOrganizationParsing.value) {
        return;
    }

    reviewsError.value = '';
    isLoadingReviews.value = true;

    try {
        const response = await apiFetch(`/api/organizations/${organization.value.id}/reviews?page=${page}`);

        reviews.value = response.reviews.data;
        pagination.value = response.reviews;
        currentPage.value = response.reviews.current_page;
    } catch (error) {
        reviewsError.value = error.message;
    } finally {
        isLoadingReviews.value = false;
    }
}

function scheduleOrganizationPolling() {
    clearOrganizationPolling();

    organizationPollingTimer = window.setTimeout(async () => {
        await loadOrganization({ silent: true });
    }, 2000);
}

function clearOrganizationPolling() {
    if (organizationPollingTimer === null) {
        return;
    }

    window.clearTimeout(organizationPollingTimer);
    organizationPollingTimer = null;
}

async function csrfCookie() {
    await fetch('/sanctum/csrf-cookie', {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
        },
    });
}

async function apiFetch(url, options = {}) {
    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(options.body ? { 'Content-Type': 'application/json' } : {}),
        ...csrfHeader(),
        ...(options.headers ?? {}),
    };

    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers,
    });

    const contentType = response.headers.get('content-type') ?? '';
    const data = contentType.includes('application/json') ? await response.json() : null;

    if (! response.ok) {
        throw new Error(extractErrorMessage(data, response.status));
    }

    if (! data && response.status !== 204) {
        throw new Error('Сервер вернул некорректный ответ.');
    }

    return data;
}

function csrfHeader() {
    const token = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];

    return token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {};
}

function extractErrorMessage(data, status) {
    if (data?.errors) {
        const firstField = Object.keys(data.errors)[0];
        const firstMessage = data.errors[firstField]?.[0];

        if (firstMessage) {
            return translateErrorMessage(firstMessage);
        }
    }

    return translateErrorMessage(data?.message ?? `Ошибка запроса: ${status}`);
}

function translateErrorMessage(message) {
    const translations = {
        'Unauthenticated.': 'Сессия истекла. Войдите снова.',
        'CSRF token mismatch.': 'Сессия истекла. Обновите страницу и попробуйте снова.',
        'The email field is required.': 'Введите email.',
        'The email field must be a valid email address.': 'Введите корректный email.',
        'The password field is required.': 'Введите пароль.',
        'The url field is required.': 'Вставьте ссылку на карточку организации.',
        'The url field must be a valid URL.': 'Введите корректную ссылку.',
        'The url field must not be greater than 2000 characters.': 'Ссылка слишком длинная.',
        'Not Found': 'Запись не найдена.',
        'Forbidden': 'Нет доступа.',
        'Server Error': 'Ошибка сервера.',
    };

    return translations[message] ?? message ?? 'Неизвестная ошибка.';
}

function formatDate(value) {
    if (! value) {
        return 'Дата не указана';
    }

    return new Intl.DateTimeFormat('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(new Date(value));
}

function formatNumber(value) {
    return new Intl.NumberFormat('ru-RU').format(value ?? 0);
}
</script>

<template>
    <main class="min-h-screen bg-zinc-50 text-zinc-950">
        <div class="mx-auto flex min-h-screen w-full max-w-6xl flex-col px-4 py-6 sm:px-6 lg:px-8">
            <header class="mb-6 flex items-center justify-between border-b border-zinc-200 pb-4">
                <div>
                    <h1 class="text-xl font-semibold">Отзывы Яндекс Карты</h1>
                </div>

                <button
                    v-if="isAuthenticated"
                    class="rounded-md border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100"
                    type="button"
                    @click="logout"
                >
                    Выйти
                </button>
            </header>

            <div v-if="isCheckingAuth" class="rounded-md border border-zinc-200 bg-white p-4 text-sm text-zinc-600">
                Проверка сессии...
            </div>

            <section v-else-if="!isAuthenticated" class="mx-auto mt-12 w-full max-w-md">
                <form class="rounded-md border border-zinc-200 bg-white p-5 shadow-sm" @submit.prevent="login">
                    <h2 class="text-lg font-semibold">Авторизация</h2>

                    <label class="mt-5 block text-sm font-medium text-zinc-700" for="email">Email</label>
                    <input
                        id="email"
                        v-model="loginForm.email"
                        class="mt-2 w-full rounded-md border border-zinc-300 px-3 py-2 text-sm outline-none focus:border-zinc-900"
                        type="email"
                        autocomplete="email"
                    >

                    <label class="mt-4 block text-sm font-medium text-zinc-700" for="password">Пароль</label>
                    <input
                        id="password"
                        v-model="loginForm.password"
                        class="mt-2 w-full rounded-md border border-zinc-300 px-3 py-2 text-sm outline-none focus:border-zinc-900"
                        type="password"
                        autocomplete="current-password"
                    >

                    <p v-if="authError" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
                        {{ authError }}
                    </p>

                    <button
                        class="mt-5 w-full rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:bg-zinc-400"
                        type="submit"
                        :disabled="isLoggingIn"
                    >
                        {{ isLoggingIn ? 'Вход...' : 'Войти' }}
                    </button>
                </form>
            </section>

            <section v-else class="grid gap-6 lg:grid-cols-[360px_1fr]">
                <aside class="h-fit rounded-md border border-zinc-200 bg-white p-5 shadow-sm">
                    <h2 class="text-base font-semibold">Настройки</h2>

                    <form class="mt-4" @submit.prevent="saveOrganization">
                        <label class="block text-sm font-medium text-zinc-700" for="organization-url">
                            Ссылка на карточку
                        </label>
                        <textarea
                            id="organization-url"
                            v-model="organizationForm.url"
                            class="mt-2 min-h-28 w-full resize-y rounded-md border border-zinc-300 px-3 py-2 text-sm outline-none focus:border-zinc-900"
                            placeholder="https://yandex.ru/maps/..."
                        />

                        <p v-if="organizationError" class="mt-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
                            {{ organizationError }}
                        </p>

                        <button
                            class="mt-4 w-full rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:bg-zinc-400"
                            type="submit"
                            :disabled="isSavingOrganization || isOrganizationParsing"
                        >
                            {{ isSavingOrganization ? 'Поиск...' : 'Поиск' }}
                        </button>

                        <button
                            class="mt-2 w-full rounded-md border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100 disabled:cursor-not-allowed disabled:opacity-50"
                            type="button"
                            :disabled="isClearingData || isSavingOrganization"
                            @click="clearOrganizationData"
                        >
                            {{ isClearingData ? 'Очистка...' : 'Очистить' }}
                        </button>
                    </form>

                    <div v-if="hasOrganization" class="mt-6 border-t border-zinc-200 pt-5">
                        <p class="text-xs font-medium uppercase text-zinc-500">Текущая организация</p>
                        <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <dt class="text-zinc-500">Организация</dt>
                                <dd class="mt-1 font-medium">{{ organization.name || organization.yandex_oid }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500">Рейтинг</dt>
                                <dd class="mt-1 font-medium">{{ Number(organization.rating_value).toFixed(1) }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500">Оценок</dt>
                                <dd class="mt-1 font-medium">{{ formatNumber(organization.rating_count) }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500">Отзывов</dt>
                                <dd class="mt-1 font-medium">{{ formatNumber(organization.review_count) }}</dd>
                            </div>
                        </dl>

                        <p
                            v-if="isOrganizationParsing"
                            class="mt-4 rounded-md bg-blue-50 px-3 py-2 text-sm text-blue-700"
                        >
                            {{ organizationStatusMessage }}
                        </p>

                        <p
                            v-else-if="isOrganizationFailed"
                            class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700"
                        >
                            {{ organizationStatusMessage }}
                        </p>
                    </div>
                </aside>

                <section class="min-w-0 rounded-md border border-zinc-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-4">
                        <div>
                            <h2 class="text-base font-semibold">Отзывы</h2>
                            <p class="mt-1 text-sm text-zinc-500">
                                <span v-if="isOrganizationParsing">Отзывы загружаются</span>
                                <span v-else-if="pagination">Страница {{ currentPage }} из {{ totalPages }}</span>
                                <span v-else>Сохраните ссылку, чтобы загрузить отзывы</span>
                            </p>
                        </div>
                    </div>

                    <div v-if="isOrganizationParsing" class="p-5 text-sm text-zinc-500">
                        Отзывы загружаются. Страница обновится автоматически.
                    </div>

                    <div v-else-if="reviewsError" class="m-5 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
                        {{ reviewsError }}
                    </div>

                    <div v-else-if="isLoadingReviews" class="p-5 text-sm text-zinc-500">
                        Загрузка страницы отзывов...
                    </div>

                    <div v-else-if="reviews.length === 0" class="p-5 text-sm text-zinc-500">
                        Отзывы пока не загружены.
                    </div>

                    <ul v-else class="divide-y divide-zinc-200">
                        <li v-for="review in reviews" :key="review.id" class="p-5">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <p class="font-medium">{{ review.author_name || 'Автор не указан' }}</p>
                                <p class="text-sm text-zinc-500">{{ formatDate(review.reviewed_at) }}</p>
                                <p class="rounded-md bg-amber-100 px-2 py-1 text-sm font-semibold text-amber-900">
                                    {{ review.rating ?? '—' }}/5
                                </p>
                            </div>
                            <p class="mt-3 whitespace-pre-line text-sm leading-6 text-zinc-700">
                                {{ review.text || 'Текст отзыва отсутствует' }}
                            </p>
                        </li>
                    </ul>

                    <div
                        v-if="pagination && totalPages > 1"
                        class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 px-5 py-4"
                    >
                        <p class="text-sm text-zinc-500">
                            Страница {{ currentPage }} из {{ totalPages }}
                        </p>

                        <div class="flex flex-wrap gap-2">
                            <button
                                class="rounded-md border border-zinc-300 px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-40"
                                type="button"
                                :disabled="currentPage === 1 || isLoadingReviews"
                                @click="loadReviews(currentPage - 1)"
                            >
                                Назад
                            </button>

                            <button
                                v-for="page in visiblePages"
                                :key="page"
                                class="rounded-md border px-3 py-2 text-sm"
                                :class="page === currentPage ? 'border-zinc-950 bg-zinc-950 text-white' : 'border-zinc-300 text-zinc-700'"
                                type="button"
                                :disabled="isLoadingReviews"
                                @click="loadReviews(page)"
                            >
                                {{ page }}
                            </button>

                            <button
                                class="rounded-md border border-zinc-300 px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-40"
                                type="button"
                                :disabled="currentPage === totalPages || isLoadingReviews"
                                @click="loadReviews(currentPage + 1)"
                            >
                                Вперед
                            </button>
                        </div>
                    </div>
                </section>
            </section>
        </div>
    </main>
</template>
