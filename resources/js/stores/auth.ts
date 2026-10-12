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
    /** Equipe: papel de quem está logado e a conta em que age. */
    team?: { role: TeamRole; is_member: boolean; account: { id: string; name: string } };
    active_plan?: {
        cancellation?: { cancelled: boolean; cancelled_at: string | null };
        dates?: { start_date?: string; end_date?: string };
        plan?: { name?: string; price?: number; formatted_price?: string };
    } | null;
    [key: string]: unknown;
}

/** owner: dono da conta; admin: tudo menos cobrança; agent: só o liberado a atendentes. */
export type TeamRole = 'owner' | 'admin' | 'agent';

const TEAM_RANK: Record<TeamRole, number> = { agent: 1, admin: 2, owner: 3 };

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

    // Equipe da conta (ver Support\Team no pacote). Sem o campo, é o dono.
    const teamRole = computed<TeamRole>(() => user.value?.team?.role ?? 'owner');
    const isTeamMember = computed(() => !!user.value?.team?.is_member);
    /** O papel de quem está logado alcança o mínimo? (agent < admin < owner) */
    function teamCan(minimum: TeamRole = 'admin'): boolean {
        return TEAM_RANK[teamRole.value] >= TEAM_RANK[minimum];
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
     * Completa o usuário com o /auth/me depois do login: é o endpoint que traz
     * o plano e a equipe. Falhar aqui não derruba a sessão recém-aberta.
     */
    async function hydrateAfterLogin() {
        try {
            const response = await axios.get('/auth/me');
            user.value = response.data?.data || response.data;
        } catch {
            // Mantém o payload do login; o próximo carregamento completa.
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
            // O /login devolve o usuário cru: plano e equipe vêm do /auth/me.
            await hydrateAfterLogin();
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
            await hydrateAfterLogin();
            return responseData;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Código inválido';
            throw err;
        } finally {
            loading.value = false;
        }
    }

    /** Aceita o convite para a equipe: cria a conta do membro e já entra. */
    async function acceptInvitation(inviteToken: string, payload: { name?: string; password: string; password_confirmation: string }) {
        const response = await axios.post(`/team/invitations/${inviteToken}/accept`, payload);
        const data = response.data?.data || response.data;
        setAuth(data.user, data.token);
        await hydrateAfterLogin();
        return data;
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
        teamRole,
        isTeamMember,
        teamCan,
        acceptInvitation,
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
