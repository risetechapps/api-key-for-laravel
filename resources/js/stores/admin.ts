//@ts-nocheck
import { defineStore } from 'pinia';
import { ref } from 'vue';
import axios from 'axios';

export const useAdminStore = defineStore('admin', () => {
    const plans    = ref([]);
    const coupons  = ref([]);
    const features = ref<any[]>([]);
    const users    = ref<any>({ data: [], total: 0, current_page: 1, last_page: 1 });
    const refunds  = ref<any>({ data: [], total: 0, current_page: 1, last_page: 1 });
    const logs     = ref<any>({ data: [], total: 0, current_page: 1, last_page: 1, levels: [] });
    const loading  = ref(false);
    const error    = ref<string | null>(null);

    // ── Plans (package admin route: dashboard/admin/plans) ────────────────
    async function fetchPlans() {
        loading.value = true;
        try {
            const r = await axios.get('dashboard/admin/plans');
            plans.value = r.data?.data || [];
        } finally { loading.value = false; }
    }

    async function createPlan(data: any) {
        const r = await axios.post('dashboard/plans', data);
        await fetchPlans();
        return r.data;
    }

    async function updatePlan(id: string, data: any) {
        const r = await axios.put(`dashboard/plans/${id}`, data);
        await fetchPlans();
        return r.data;
    }

    async function deletePlan(id: string) {
        await axios.delete(`dashboard/plans/${id}`);
        plans.value = (plans.value as any[]).filter((p: any) => p.id !== id);
    }

    // ── Coupons (package routes: dashboard/coupons) ────────────────────────
    async function fetchCoupons() {
        loading.value = true;
        try {
            const r = await axios.get('dashboard/coupons');
            coupons.value = r.data?.data || [];
        } finally { loading.value = false; }
    }

    async function createCoupon(data: any) {
        const r = await axios.post('dashboard/coupons', data);
        await fetchCoupons();
        return r.data;
    }

    async function updateCoupon(id: string, data: any) {
        const r = await axios.put(`dashboard/coupons/${id}`, data);
        await fetchCoupons();
        return r.data;
    }

    async function deleteCoupon(id: string) {
        await axios.delete(`dashboard/coupons/${id}`);
        coupons.value = (coupons.value as any[]).filter((c: any) => c.id !== id);
    }

    // ── Features (registry: dashboard/admin/features) ────────────────────
    async function fetchFeatures() {
        const r = await axios.get('dashboard/admin/features');
        features.value = r.data?.data || [];
    }

    // ── Users (package route: dashboard/admin/users) ───────────────────────
    async function fetchUsers(params: any = {}) {
        loading.value = true;
        try {
            const r = await axios.get('dashboard/admin/users', { params });
            users.value = r.data?.data ?? r.data;
        } finally { loading.value = false; }
    }

    // ── Refunds (listing: package | process/execute: root MP) ─────────────
    async function fetchRefunds(params: any = {}) {
        loading.value = true;
        try {
            const r = await axios.get('dashboard/admin/refunds', { params });
            refunds.value = r.data?.data ?? r.data;
        } finally { loading.value = false; }
    }

    async function processRefund(id: string) {
        const r = await axios.post(`dashboard/admin/refunds/${id}`);
        await fetchRefunds();
        return r.data;
    }

    // ── Logs (dashboard/admin/logs) ───────────────────────────────────────
    // A listagem traz só nível, mensagem e origem. Contexto e stack ficam no
    // detalhe: em log de exceção eles passam de alguns KB por entrada, e trazer
    // isso para cinquenta linhas de tabela seria carregar megabytes para exibir
    // uma coluna de texto.
    async function fetchLogs(params: any = {}) {
        loading.value = true;
        try {
            const r = await axios.get('dashboard/admin/logs', { params });
            logs.value = r.data?.data ?? r.data;
        } finally { loading.value = false; }
    }

    async function fetchLog(id: string) {
        const r = await axios.get(`dashboard/admin/logs/${id}`);
        return r.data?.data ?? r.data;
    }

    return {
        plans, coupons, features, users, refunds, logs, loading, error,
        fetchPlans, createPlan, updatePlan, deletePlan,
        fetchCoupons, createCoupon, updateCoupon, deleteCoupon,
        fetchFeatures,
        fetchUsers,
        fetchRefunds, processRefund,
        fetchLogs, fetchLog,
    };
});
