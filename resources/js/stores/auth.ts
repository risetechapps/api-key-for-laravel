import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import axios from 'axios';

axios.defaults.baseURL = '/api/v1';
axios.defaults.withCredentials = true;
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.headers.common['Accept'] = 'application/json';
axios.defaults.headers.common['Accept-Language'] = 'pt-BR,pt;q=0.9';

export const useAuthStore = defineStore('auth', () => {
    const user = ref(null);
    const token = ref(localStorage.getItem('token'));
    const loading = ref(false);
    const error = ref(null);

    const isAuthenticated = computed(() => !!token.value && !!user.value);
    const isAdmin = computed(() => user.value?.role?.toLowerCase() === 'admin');

    function setAuth(userData, authToken) {
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
            } catch (err) {
                clearAuth();
            }
        }
    }

    async function login(credentials) {
        loading.value = true;
        error.value = null;
        try {
            const response = await axios.post('/login', credentials);
            // A API retorna { success: true, data: { ...user..., token: '...' } }
            const responseData = response.data?.data || response.data;
            setAuth(responseData, responseData.token);
            return responseData;
        } catch (err) {
            error.value = err.response?.data?.message || 'Erro ao fazer login';
            throw err;
        } finally {
            loading.value = false;
        }
    }

    async function register(userData) {
        loading.value = true;
        error.value = null;
        try {
            const response = await axios.post('/register', userData);
            // A API retorna { success: true, data: { ...user..., token: '...' } }
            const responseData = response.data?.data || response.data;
            setAuth(responseData, responseData.token);
            return responseData;
        } catch (err) {
            error.value = err.response?.data?.message || 'Erro ao registrar';
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
        try {
            const response = await axios.get('/dashboard/profile');
            const profileData = response.data?.data || response.data;
            // Merge into existing user so fields like `role` are not lost
            user.value = { ...user.value, ...profileData };
            return user.value;
        } catch (err) {
            throw err;
        }
    }

    async function updateProfile(data) {
        loading.value = true;
        error.value = null;
        try {
            const response = await axios.put('/dashboard/profile', data);
            // A API retorna { success: true, data: {...user...} }
            user.value = response.data?.data || response.data;
            return user.value;
        } catch (err) {
            error.value = err.response?.data?.message || 'Erro ao atualizar perfil';
            throw err;
        } finally {
            loading.value = false;
        }
    }

    async function updateAllowedOrigins(origins) {
        try {
            const response = await axios.post('/dashboard/profile/allowed', { allowed_origins: origins });
            // A API retorna { success: true, data: {...} }
            return response.data?.data || response.data;
        } catch (err) {
            throw err;
        }
    }

    async function fetchAllowedOrigins() {
        try {
            const response = await axios.get('/dashboard/profile/allowed');
            // A API retorna { success: true, data: [...] }
            return response.data?.data || response.data;
        } catch (err) {
            throw err;
        }
    }

    return {
        user,
        token,
        loading,
        error,
        isAuthenticated,
        isAdmin,
        setAuth,
        clearAuth,
        initializeAuth,
        login,
        register,
        logout,
        fetchProfile,
        updateProfile,
        updateAllowedOrigins,
        fetchAllowedOrigins,
    };
});
