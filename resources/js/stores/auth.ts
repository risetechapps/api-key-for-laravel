import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import axios from 'axios';

/** Campos que o painel realmente lê; o resto do payload segue disponível. */
export interface AuthUser {
    id?: string;
    name?: string;
    email?: string;
    role?: string;
    token?: string;
    admin_abilities?: string[];
    active_plan?: {
        cancellation?: { cancelled: boolean; cancelled_at: string | null };
        dates?: { start_date?: string; end_date?: string };
        plan?: { name?: string; price?: number; formatted_price?: string };
    } | null;
    [key: string]: unknown;
}

export interface RegisterPayload {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
}

axios.defaults.baseURL = '/api/v1';
axios.defaults.withCredentials = true;
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.headers.common['Accept'] = 'application/json';
axios.defaults.headers.common['Accept-Language'] = 'pt-BR,pt;q=0.9';

// Sessão expirada ou token inválido: sem isto toda chamada seguinte falhava em
// silêncio e o usuário ficava preso numa tela quebrada, ainda "logado" para o
// front. Limpa a credencial e manda para o login uma única vez.
//
// As rotas públicas de autenticação são ignoradas de propósito: /login responde
// 401 para credencial errada, e redirecionar dali criaria um laço.
let redirectingToLogin = false;

axios.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error?.response?.status;
        const url: string = error?.config?.url ?? '';
        const isAuthRoute = /\/(login|login\/two-factor|register|forgot-password|reset-password|email\/resend)$/.test(url);

        // Admin sem 2FA: as rotas de admin recusam até ele ativar. Leva para a
        // tela de segurança em vez de deixar a tela de admin vazia.
        if (status === 403 && error?.response?.data?.code === 'two_factor_required'
            && !window.location.pathname.startsWith('/dashboard/security')) {
            window.location.assign('/dashboard/security?two_factor_required=1');
        }

        // Conta suspensa pelo admin no meio da sessão: toda chamada passa a
        // responder 403, e sem isto o painel ficava travado do mesmo jeito.
        const accountDisabled = status === 403 && error?.response?.data?.code === 'account_disabled';

        if ((status === 401 || accountDisabled) && !isAuthRoute && !redirectingToLogin) {
            redirectingToLogin = true;

            localStorage.removeItem('token');
            delete axios.defaults.headers.common['Authorization'];

            if (!window.location.pathname.startsWith('/login')) {
                window.location.assign(`/login?error=${accountDisabled ? 'account_disabled' : 'session_expired'}`);
            }
        }

        return Promise.reject(error);
    }
);

export const useAuthStore = defineStore('auth', () => {
    const user = ref<AuthUser | null>(null);
    const token = ref<string | null>(localStorage.getItem('token'));
    const loading = ref(false);
    const error = ref<string | null>(null);

    const isAuthenticated = computed(() => !!token.value && !!user.value);
    const isAdmin = computed(() => user.value?.role?.toLowerCase() === 'admin');
    // Equipe do painel (admin, finance, support) e o que cada um pode fazer.
    // A regra vale no servidor; aqui só decide o que mostrar.
    const isStaff = computed(() => (user.value?.admin_abilities ?? []).length > 0);
    function can(ability: string): boolean {
        return (user.value?.admin_abilities ?? []).includes(ability);
    }

    function setAuth(userData: AuthUser, authToken: string) {
        user.value = userData;
        token.value = authToken;
        localStorage.setItem('token', authToken);
        axios.defaults.headers.common['Authorization'] = `Bearer ${authToken}`;
    }

    function clearAuth() {
        user.value = null;
        token.value = null;
        localStorage.removeItem('token');
        delete axios.defaults.headers.common['Authorization'];
    }

    async function initializeAuth() {
        if (token.value) {
            axios.defaults.headers.common['Authorization'] = `Bearer ${token.value}`;
            try {
                const response = await axios.get('/auth/me');
                // A API retorna { success: true, data: { ...user... } }
                user.value = response.data?.data || response.data;
            } catch {
                clearAuth();
            }
        }
    }

    /**
     * Primeiro passo do login. Com 2FA ativa a API devolve `two_factor: true`
     * e um `challenge`, e o token só vem em verifyTwoFactor().
     */
    async function login(credentials: { email: string; password: string }) {
        loading.value = true;
        error.value = null;
        try {
            const response = await axios.post('/login', credentials);
            // A API retorna { success: true, data: { ...user..., token: '...' } }
            const responseData = response.data?.data || response.data;
            if (responseData?.two_factor) {
                return responseData;
            }
            setAuth(responseData, responseData.token);
            return responseData;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Erro ao fazer login';
            throw err;
        } finally {
            loading.value = false;
        }
    }

    /**
     * Cadastra o usuário.
     *
     * O registro **não autentica**. A API responde { message, api_key } — sem
     * token e sem usuário. A versão anterior chamava setAuth(data, data.token)
     * com token undefined, gravava a string "undefined" no localStorage, passava
     * a mandar `Authorization: Bearer undefined` em tudo e ainda empurrava o
     * usuário para o dashboard, onde toda chamada respondia 401.
     *
     * A `api_key` que vem na resposta é deliberadamente ignorada. Quem se
     * cadastra pode usar apenas o painel e nunca consumir a API, e entregar um
     * segredo irrecuperável na primeira tela obriga todo mundo a lidar com ele
     * antes de saber se vai precisar. Quem for usar a API gera a chave no
     * perfil, onde a exibição é única e explícita.
     *
     * Além disso o login exige e-mail verificado, então o destino após cadastrar
     * é a tela de login, nunca o painel.
     */
    async function register(userData: RegisterPayload): Promise<{ message?: string }> {
        loading.value = true;
        error.value = null;
        try {
            const response = await axios.post('/register', userData);
            const data = response.data?.data || response.data;

            return { message: data?.message };
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Erro ao registrar';
            throw err;
        } finally {
            loading.value = false;
        }
    }

    async function verifyTwoFactor(payload: { challenge: string; code?: string; recovery_code?: string }) {
        loading.value = true;
        error.value = null;
        try {
            const response = await axios.post('/login/two-factor', payload);
            const responseData = response.data?.data || response.data;
            setAuth(responseData, responseData.token);
            return responseData;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Código inválido';
            throw err;
        } finally {
            loading.value = false;
        }
    }

    async function logout() {
        loading.value = true;
        try {
            await axios.post('/logout');
        } finally {
            clearAuth();
            loading.value = false;
        }
    }

    async function fetchProfile() {
        const response = await axios.get('/dashboard/profile');
        const profileData = response.data?.data || response.data;
        // Merge into existing user so fields like `role` are not lost
        user.value = { ...user.value, ...profileData };
        return user.value;
    }

    async function updateProfile(data: Record<string, unknown>) {
        loading.value = true;
        error.value = null;
        try {
            const response = await axios.put('/dashboard/profile', data);
            // A API retorna { success: true, data: {...user...} }
            user.value = response.data?.data || response.data;
            return user.value;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Erro ao atualizar perfil';
            throw err;
        } finally {
            loading.value = false;
        }
    }

    async function updateAllowedOrigins(origins: string[]) {
        const response = await axios.post('/dashboard/profile/allowed', { allowed_origins: origins });
        // A API retorna { success: true, data: {...} }
        return response.data?.data || response.data;
    }

    async function fetchAllowedOrigins() {
        const response = await axios.get('/dashboard/profile/allowed');
        // A API retorna { success: true, data: [...] }
        return response.data?.data || response.data;
    }

    return {
        user,
        token,
        loading,
        error,
        isAuthenticated,
        isAdmin,
        isStaff,
        can,
        setAuth,
        clearAuth,
        initializeAuth,
        login,
        verifyTwoFactor,
        register,
        logout,
        fetchProfile,
        updateProfile,
        updateAllowedOrigins,
        fetchAllowedOrigins,
    };
});
