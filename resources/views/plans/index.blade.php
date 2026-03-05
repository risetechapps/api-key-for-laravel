@extends('layout')
@section('title', 'Plans')
@section('page-title', 'Plans')

@section('header-actions')
    <button id="btn-new-plan" class="btn btn-primary">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
        </svg>
        New Plan
    </button>
@endsection

@section('content')
<div id="plans-page" v-cloak v-scope="PlansApp()">

    {{-- ── Stats Row ── --}}
    <div class="grid grid-cols-3 gap-4 mb-8">

        <div class="card p-5 flex items-center gap-4">
            <div class="w-10 h-10 rounded-lg bg-accent-glow border border-accent/20 flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
            </div>
            <div>
                <p class="text-xs font-mono text-muted uppercase tracking-wider">Total Plans</p>
                <p class="font-display text-2xl text-white mt-0.5">@{{ plans.length }}</p>
            </div>
        </div>

        <div class="card p-5 flex items-center gap-4">
            <div class="w-10 h-10 rounded-lg" style="background:rgba(96,165,250,0.08); border:1px solid rgba(96,165,250,0.2)">
                <div class="w-full h-full flex items-center justify-center">
                    <svg class="w-5 h-5 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <div>
                <p class="text-xs font-mono text-muted uppercase tracking-wider">Active</p>
                <p class="font-display text-2xl text-white mt-0.5">@{{ plans.filter(p => p.is_active).length }}</p>
            </div>
        </div>

        <div class="card p-5 flex items-center gap-4">
            <div class="w-10 h-10 rounded-lg" style="background:rgba(250,204,21,0.08); border:1px solid rgba(250,204,21,0.2)">
                <div class="w-full h-full flex items-center justify-center">
                    <svg class="w-5 h-5 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <div>
                <p class="text-xs font-mono text-muted uppercase tracking-wider">Avg. Price</p>
                <p class="font-display text-2xl text-white mt-0.5">
                    @{{ plans.length ? '$' + (plans.reduce((s,p)=>s+parseFloat(p.price),0)/plans.length).toFixed(2) : '—' }}
                </p>
            </div>
        </div>
    </div>

    {{-- ── Plans Table ── --}}
    <div class="card overflow-hidden">

        {{-- Table header bar --}}
        <div class="px-6 py-4 border-b border-border flex items-center justify-between">
            <div>
                <h2 class="font-display text-base text-white">All Plans</h2>
                <p class="text-xs text-muted mt-0.5">Manage pricing tiers and feature sets</p>
            </div>
            <div class="relative">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input v-model="search" type="text" placeholder="Search plans…"
                       class="form-input pl-9 py-2 text-xs w-52">
            </div>
        </div>

        {{-- Loading --}}
        <div v-if="loading" class="flex items-center justify-center py-20 gap-3 text-muted">
            <div class="spinner"></div>
            <span class="text-sm">Loading plans…</span>
        </div>

        {{-- Empty --}}
        <div v-else-if="!filteredPlans.length && !loading"
             class="flex flex-col items-center justify-center py-20 text-center">
            <div class="w-14 h-14 rounded-2xl bg-surface-2 border border-border flex items-center justify-center mb-4">
                <svg class="w-7 h-7 text-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
            </div>
            <p class="text-sm text-slate-400 font-medium">No plans found</p>
            <p class="text-xs text-muted mt-1">Create your first plan to get started</p>
        </div>

        {{-- Table --}}
        <div v-else class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Plan</th>
                        <th>Price</th>
                        <th>Billing</th>
                        <th>Request Limit</th>
                        <th>Features</th>
                        <th>Status</th>
                        <th style="width:80px"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="plan in filteredPlans" :key="plan.id">
                        <td>
                            <div>
                                <p class="text-white font-medium text-sm">@{{ plan.name }}</p>
                                <p class="font-mono text-xs text-muted mt-0.5">@{{ plan.id.slice(0,8) }}…</p>
                            </div>
                        </td>
                        <td>
                            <span class="font-mono text-accent font-semibold">$@{{ parseFloat(plan.price).toFixed(2) }}</span>
                        </td>
                        <td>
                            <span :class="['inline-flex items-center px-2 py-0.5 rounded-md text-xs font-mono font-medium', 'badge-' + plan.billing_cycle]">
                                @{{ plan.billing_cycle }}
                            </span>
                        </td>
                        <td>
                            <span class="font-mono text-sm text-slate-300">
                                @{{ plan.request_limit === 0 ? '∞ unlimited' : plan.request_limit.toLocaleString() + ' req' }}
                            </span>
                        </td>
                        <td>
                            <div class="flex flex-wrap gap-1">
                                <span v-for="feat in (plan.features || []).slice(0,3)" :key="feat"
                                      class="inline-flex px-1.5 py-0.5 rounded text-xs bg-surface-3 text-slate-400 border border-border">
                                    @{{ feat }}
                                </span>
                                <span v-if="(plan.features||[]).length > 3"
                                      class="inline-flex px-1.5 py-0.5 rounded text-xs text-muted">
                                    +@{{ plan.features.length - 3 }}
                                </span>
                            </div>
                        </td>
                        <td>
                            <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium', plan.is_active ? 'badge-active' : 'badge-inactive']">
                                <span class="w-1.5 h-1.5 rounded-full mr-1.5" :class="plan.is_active ? 'bg-accent' : 'bg-red-400'"></span>
                                @{{ plan.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td>
                            <div class="flex items-center gap-1">
                                <button @click="openEdit(plan)"
                                        class="w-7 h-7 rounded-md hover:bg-surface-3 flex items-center justify-center text-muted hover:text-slate-300 transition-colors" title="Edit">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                </button>
                                <button @click="confirmDelete(plan)"
                                        class="w-7 h-7 rounded-md hover:bg-red-500/10 flex items-center justify-center text-muted hover:text-red-400 transition-colors" title="Delete">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── PLAN DRAWER (Create / Edit) ── --}}
    <template v-if="drawer.open">
        <div class="panel-overlay" @click="closeDrawer"></div>
        <div class="panel-drawer flex flex-col">

            {{-- Drawer header --}}
            <div class="px-6 py-5 border-b border-border flex items-center justify-between flex-shrink-0">
                <div>
                    <h2 class="font-display text-lg text-white">@{{ drawer.isEdit ? 'Edit Plan' : 'New Plan' }}</h2>
                    <p class="text-xs text-muted mt-0.5">@{{ drawer.isEdit ? 'Update plan details and features' : 'Define pricing, limits, and features' }}</p>
                </div>
                <button @click="closeDrawer"
                        class="w-8 h-8 rounded-lg hover:bg-surface-3 flex items-center justify-center text-muted hover:text-slate-300 transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            {{-- Drawer body --}}
            <div class="flex-1 overflow-y-auto px-6 py-6 space-y-5">

                {{-- Name --}}
                <div>
                    <label class="block text-xs font-mono text-muted uppercase tracking-wider mb-1.5">Plan Name *</label>
                    <input v-model="form.name" type="text"
                           :class="['form-input', formErrors.name ? 'error':'']"
                           placeholder="e.g. Professional">
                    <p v-if="formErrors.name" class="mt-1 text-xs text-red-400">@{{ formErrors.name }}</p>
                </div>

                {{-- Description --}}
                <div>
                    <label class="block text-xs font-mono text-muted uppercase tracking-wider mb-1.5">Description</label>
                    <textarea v-model="form.description" rows="2"
                              class="form-input resize-none"
                              placeholder="Brief description of this plan…"></textarea>
                </div>

                {{-- Price + Billing --}}
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-mono text-muted uppercase tracking-wider mb-1.5">Price ($) *</label>
                        <input v-model="form.price" type="number" step="0.01" min="0.01"
                               :class="['form-input font-mono', formErrors.price ? 'error':'']"
                               placeholder="9.99">
                        <p v-if="formErrors.price" class="mt-1 text-xs text-red-400">@{{ formErrors.price }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-mono text-muted uppercase tracking-wider mb-1.5">Billing Cycle *</label>
                        <select v-model="form.billing_cycle"
                                :class="['form-input', formErrors.billing_cycle ? 'error':'']">
                            <option value="">Select…</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="annually">Annually</option>
                        </select>
                        <p v-if="formErrors.billing_cycle" class="mt-1 text-xs text-red-400">@{{ formErrors.billing_cycle }}</p>
                    </div>
                </div>

                {{-- Request Limit --}}
                <div>
                    <label class="block text-xs font-mono text-muted uppercase tracking-wider mb-1.5">
                        Request Limit <span class="text-muted normal-case">(0 = unlimited)</span>
                    </label>
                    <input v-model.number="form.request_limit" type="number" min="0"
                           :class="['form-input font-mono', formErrors.request_limit ? 'error':'']"
                           placeholder="0">
                    <p v-if="formErrors.request_limit" class="mt-1 text-xs text-red-400">@{{ formErrors.request_limit }}</p>
                </div>

                {{-- Status --}}
                <div>
                    <label class="block text-xs font-mono text-muted uppercase tracking-wider mb-2">Status *</label>
                    <div class="flex items-center gap-3">
                        <button @click="form.is_active = true"
                                :class="['px-4 py-2 rounded-lg text-sm transition-all border', form.is_active ? 'bg-accent-glow border-accent/40 text-accent' : 'bg-surface-2 border-border text-muted hover:text-slate-300']">
                            Active
                        </button>
                        <button @click="form.is_active = false"
                                :class="['px-4 py-2 rounded-lg text-sm transition-all border', !form.is_active ? 'bg-red-500/10 border-red-500/30 text-red-400' : 'bg-surface-2 border-border text-muted hover:text-slate-300']">
                            Inactive
                        </button>
                    </div>
                </div>

                {{-- Features --}}
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="text-xs font-mono text-muted uppercase tracking-wider">Features *</label>
                        <span class="text-xs text-muted">@{{ form.features.length }} added</span>
                    </div>

                    {{-- Feature input --}}
                    <div class="flex gap-2 mb-3">
                        <input v-model="newFeature" type="text"
                               @keyup.enter="addFeature"
                               class="form-input text-sm"
                               placeholder="e.g. priority_support">
                        <button @click="addFeature"
                                class="btn btn-secondary flex-shrink-0 px-3"
                                :disabled="!newFeature.trim()">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                            </svg>
                        </button>
                    </div>

                    {{-- Feature tags --}}
                    <div class="flex flex-wrap gap-2 min-h-[36px] p-3 rounded-lg bg-surface border border-border">
                        <span v-if="!form.features.length" class="text-xs text-muted italic">No features added yet…</span>
                        <span v-for="(feat, i) in form.features" :key="i"
                              class="inline-flex items-center gap-1.5 pl-2.5 pr-1.5 py-1 rounded-lg text-xs font-mono bg-surface-3 border border-border text-slate-300">
                            @{{ feat }}
                            <button @click="removeFeature(i)"
                                    class="w-4 h-4 rounded flex items-center justify-center text-muted hover:text-red-400 transition-colors">
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </span>
                    </div>
                    <p v-if="formErrors.features" class="mt-1 text-xs text-red-400">@{{ formErrors.features }}</p>
                </div>

                {{-- Global error --}}
                <div v-if="submitError"
                     class="px-4 py-3 rounded-lg bg-red-500/10 border border-red-500/20 text-red-400 text-sm">
                    @{{ submitError }}
                </div>
            </div>

            {{-- Drawer footer --}}
            <div class="px-6 py-4 border-t border-border flex items-center justify-end gap-3 flex-shrink-0">
                <button @click="closeDrawer" class="btn btn-secondary">Cancel</button>
                <button @click="savePlan" :disabled="saving" class="btn btn-primary min-w-[120px] justify-center">
                    <div v-if="saving" class="spinner"></div>
                    <span v-else>@{{ drawer.isEdit ? 'Save Changes' : 'Create Plan' }}</span>
                </button>
            </div>
        </div>
    </template>

    {{-- ── Delete Confirmation ── --}}
    <template v-if="deleteModal.open">
        <div class="panel-overlay" @click="deleteModal.open = false"></div>
        <div class="fixed inset-0 flex items-center justify-center z-50 p-4">
            <div class="card w-full max-w-sm p-6">
                <div class="w-11 h-11 rounded-xl bg-red-500/10 border border-red-500/20 flex items-center justify-center mb-4">
                    <svg class="w-5 h-5 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                </div>
                <h3 class="font-display text-lg text-white">Delete Plan</h3>
                <p class="text-sm text-slate-400 mt-2">
                    Are you sure you want to delete <strong class="text-white">@{{ deleteModal.plan?.name }}</strong>?
                    This action cannot be undone.
                </p>
                <div class="flex gap-3 mt-6">
                    <button @click="deleteModal.open = false" class="btn btn-secondary flex-1 justify-center">Cancel</button>
                    <button @click="deletePlan" :disabled="deleting" class="btn btn-danger flex-1 justify-center">
                        <div v-if="deleting" class="spinner" style="border-top-color:#f87171; border-color:rgba(248,113,113,0.2)"></div>
                        <span v-else>Delete</span>
                    </button>
                </div>
            </div>
        </div>
    </template>

</div>{{-- /plans-page --}}
@endsection

@push('scripts')
<script>
    function PlansApp() {
        return {
            plans:       [],
            loading:     true,
            search:      '',
            drawer:      { open: false, isEdit: false },
            deleteModal: { open: false, plan: null },
            saving:      false,
            deleting:    false,
            submitError: null,
            newFeature:  '',
            form:        {},
            formErrors:  {},

            get filteredPlans() {
                const q = this.search.toLowerCase();
                if (!q) return this.plans;
                return this.plans.filter(p =>
                    p.name.toLowerCase().includes(q) ||
                    (p.billing_cycle||'').includes(q)
                );
            },

            blankForm() {
                return {
                    name: '', description: '', price: '',
                    billing_cycle: '', request_limit: 0,
                    is_active: true, features: [],
                };
            },

            async mounted() {
                await this.loadPlans();

                // Hook "New Plan" button in header
                document.getElementById('btn-new-plan')?.addEventListener('click', () => this.openCreate());
            },

            async loadPlans() {
                this.loading = true;
                const r = await api('GET', 'dashboard/plans');
                if (r.ok) {
                    this.plans = r.data.data ?? [];
                } else {
                    Toast.show(r.data.message || 'Error loading plans.', 'error');
                }
                this.loading = false;
            },

            openCreate() {
                this.form        = this.blankForm();
                this.formErrors  = {};
                this.submitError = null;
                this.newFeature  = '';
                this.drawer      = { open: true, isEdit: false };
            },

            openEdit(plan) {
                this.form = {
                    id:            plan.id,
                    name:          plan.name,
                    description:   plan.description ?? '',
                    price:         plan.price,
                    billing_cycle: plan.billing_cycle,
                    request_limit: plan.request_limit,
                    is_active:     plan.is_active,
                    features:      [...(plan.features || [])],
                };
                this.formErrors  = {};
                this.submitError = null;
                this.newFeature  = '';
                this.drawer      = { open: true, isEdit: true };
            },

            closeDrawer() {
                this.drawer.open = false;
            },

            addFeature() {
                const f = this.newFeature.trim();
                if (f && !this.form.features.includes(f)) {
                    this.form.features.push(f);
                }
                this.newFeature = '';
            },

            removeFeature(i) {
                this.form.features.splice(i, 1);
            },

            validate() {
                const errs = {};
                if (!this.form.name?.trim())          errs.name          = 'Plan name is required.';
                if (!this.form.price)                 errs.price         = 'Price is required.';
                if (!this.form.billing_cycle)         errs.billing_cycle = 'Billing cycle is required.';
                if (this.form.request_limit === '' || this.form.request_limit < 0)
                                                      errs.request_limit = 'Request limit must be 0 or more.';
                if (!this.form.features.length)       errs.features      = 'Add at least one feature.';
                this.formErrors = errs;
                return !Object.keys(errs).length;
            },

            async savePlan() {
                if (!this.validate()) return;
                this.saving      = true;
                this.submitError = null;

                const payload = { ...this.form };
                const isEdit  = this.drawer.isEdit;
                const id      = this.form.id;
                if (isEdit) delete payload.id;

                const r = isEdit
                    ? await api('PUT',  `dashboard/plans/${id}`, payload)
                    : await api('POST', 'dashboard/plans',        payload);

                if (r.ok) {
                    Toast.show(isEdit ? 'Plan updated!' : 'Plan created!');
                    this.closeDrawer();
                    await this.loadPlans();
                } else if (r.status === 422) {
                    const serverErrors = r.data.errors ?? {};
                    this.formErrors = Object.fromEntries(
                        Object.entries(serverErrors).map(([k,v]) => [k, Array.isArray(v) ? v[0] : v])
                    );
                } else {
                    this.submitError = r.data.message || 'Error saving plan.';
                }
                this.saving = false;
            },

            confirmDelete(plan) {
                this.deleteModal = { open: true, plan };
            },

            async deletePlan() {
                this.deleting = true;
                const r = await api('DELETE', `dashboard/plans/${this.deleteModal.plan.id}`);
                if (r.ok) {
                    Toast.show('Plan deleted.');
                    this.deleteModal.open = false;
                    this.plans = this.plans.filter(p => p.id !== this.deleteModal.plan.id);
                } else {
                    Toast.show(r.data.message || 'Error deleting plan.', 'error');
                }
                this.deleting = false;
            },
        };
    }

    PetiteVue.createApp({ PlansApp }).mount('#plans-page');
</script>
@endpush
