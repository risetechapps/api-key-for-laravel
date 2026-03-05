@extends('layout')
@section('title', 'Overview')
@section('page-title', 'Overview')

@section('content')
<div id="overview-page" v-cloak v-scope="OverviewApp()">

    {{-- ── Welcome bar ── --}}
    <div class="flex items-center justify-between mb-8">
        <div>
            <h2 class="font-display text-2xl text-white">
                Good to see you, <span class="text-accent">@{{ user.name ?? '...' }}</span>
            </h2>
            <p class="text-sm text-muted mt-1">Here's what's happening with your API today.</p>
        </div>
        <div class="flex items-center gap-2">
            <div class="w-2 h-2 rounded-full bg-accent animate-pulse"></div>
            <span class="text-xs font-mono text-accent">Live</span>
        </div>
    </div>

    {{-- ── Stats Cards ── --}}
    <div class="grid grid-cols-4 gap-4 mb-8">

        {{-- Active Plan --}}
        <div class="card p-5 col-span-1">
            <div class="flex items-center justify-between mb-3">
                <p class="text-xs font-mono text-muted uppercase tracking-wider">Active Plan</p>
                <div class="w-8 h-8 rounded-lg bg-accent-glow border border-accent/20 flex items-center justify-center">
                    <svg class="w-4 h-4 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <p class="font-display text-xl text-white truncate">
                @{{ activePlan ? activePlan : '—' }}
            </p>
            <p class="text-xs text-muted mt-1">
                @{{ planExpiry ? 'Expires ' + planExpiry : 'No active subscription' }}
            </p>
        </div>

        {{-- Requests Used --}}
        <div class="card p-5 col-span-1">
            <div class="flex items-center justify-between mb-3">
                <p class="text-xs font-mono text-muted uppercase tracking-wider">Requests Used</p>
                <div class="w-8 h-8 rounded-lg" style="background:rgba(96,165,250,0.08);border:1px solid rgba(96,165,250,0.2)">
                    <div class="w-full h-full flex items-center justify-center">
                        <svg class="w-4 h-4 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                    </div>
                </div>
            </div>
            <p class="font-display text-xl text-white">
                @{{ requestsUsed !== null ? requestsUsed.toLocaleString() : '—' }}
            </p>
            <p class="text-xs text-muted mt-1">Current billing cycle</p>
        </div>

        {{-- Total Requests Logged --}}
        <div class="card p-5 col-span-1">
            <div class="flex items-center justify-between mb-3">
                <p class="text-xs font-mono text-muted uppercase tracking-wider">Total Logged</p>
                <div class="w-8 h-8 rounded-lg" style="background:rgba(168,85,247,0.08);border:1px solid rgba(168,85,247,0.2)">
                    <div class="w-full h-full flex items-center justify-center">
                        <svg class="w-4 h-4 text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                    </div>
                </div>
            </div>
            <p class="font-display text-xl text-white">@{{ logs.length.toLocaleString() }}</p>
            <p class="text-xs text-muted mt-1">All time</p>
        </div>

        {{-- API Key Status --}}
        <div class="card p-5 col-span-1">
            <div class="flex items-center justify-between mb-3">
                <p class="text-xs font-mono text-muted uppercase tracking-wider">API Key</p>
                <div class="w-8 h-8 rounded-lg" style="background:rgba(250,204,21,0.08);border:1px solid rgba(250,204,21,0.2)">
                    <div class="w-full h-full flex items-center justify-center">
                        <svg class="w-4 h-4 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                        </svg>
                    </div>
                </div>
            </div>
            <p class="font-display text-xl text-white">
                @{{ keyActive ? 'Active' : 'Inactive' }}
            </p>
            <p class="text-xs text-muted mt-1 font-mono truncate">@{{ maskedKey }}</p>
        </div>
    </div>

    {{-- ── Middle Row ── --}}
    <div class="grid grid-cols-3 gap-6 mb-6">

        {{-- Current Plan card --}}
        <div class="card p-6 col-span-1 flex flex-col">
            <div class="flex items-center justify-between mb-5">
                <h3 class="font-display text-sm text-white">Current Plan</h3>
                <a href="{{ route('dashboard.plans') }}"
                   class="text-xs text-accent hover:text-accent-dim transition-colors font-mono">
                    Browse plans →
                </a>
            </div>

            <div v-if="loadingHistory" class="flex items-center gap-2 text-muted text-sm">
                <div class="spinner"></div> Loading…
            </div>

            <div v-else-if="!currentPlanDetail" class="flex-1 flex flex-col items-center justify-center text-center py-4">
                <div class="w-12 h-12 rounded-xl bg-surface-2 border border-border flex items-center justify-center mb-3">
                    <svg class="w-6 h-6 text-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <p class="text-sm text-slate-400 mb-1">No active plan</p>
                <p class="text-xs text-muted mb-4">Subscribe to unlock features</p>
                <button @click="openUpgrade" class="btn btn-primary py-2 text-xs">
                    Choose a Plan
                </button>
            </div>

            <div v-else class="flex-1 flex flex-col">
                <div class="p-4 rounded-xl bg-accent-glow border border-accent/20 mb-4">
                    <p class="font-display text-lg text-white">@{{ currentPlanDetail.name ?? activePlan }}</p>
                    <div class="flex items-center gap-2 mt-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-accent"></span>
                        <span class="text-xs text-accent font-mono">Active</span>
                    </div>
                </div>

                {{-- Usage bar --}}
                <div v-if="requestsUsed !== null && currentPlanDetail?.request_limit > 0" class="mb-4">
                    <div class="flex justify-between text-xs text-muted mb-1.5">
                        <span>Requests used</span>
                        <span class="font-mono">@{{ requestsUsed.toLocaleString() }} / @{{ currentPlanDetail.request_limit.toLocaleString() }}</span>
                    </div>
                    <div class="h-1.5 rounded-full bg-surface-3">
                        <div class="h-full rounded-full transition-all duration-500"
                             :class="usagePercent > 80 ? 'bg-red-400' : usagePercent > 50 ? 'bg-yellow-400' : 'bg-accent'"
                             :style="'width:' + Math.min(usagePercent, 100) + '%'">
                        </div>
                    </div>
                    <p class="text-xs text-muted mt-1">@{{ usagePercent.toFixed(1) }}% used</p>
                </div>

                <div v-if="planExpiry" class="text-xs text-muted">
                    <span class="font-mono">Renews:</span> @{{ planExpiry }}
                </div>
            </div>
        </div>

        {{-- Subscription History --}}
        <div class="card col-span-2 flex flex-col overflow-hidden">
            <div class="px-6 py-4 border-b border-border flex items-center justify-between flex-shrink-0">
                <h3 class="font-display text-sm text-white">Subscription History</h3>
                <span class="text-xs font-mono text-muted">@{{ history.length }} record(s)</span>
            </div>

            <div v-if="loadingHistory" class="flex items-center justify-center py-10 gap-3 text-muted">
                <div class="spinner"></div>
                <span class="text-sm">Loading…</span>
            </div>

            <div v-else-if="!history.length"
                 class="flex flex-col items-center justify-center py-10 text-center">
                <p class="text-sm text-slate-400">No subscriptions yet</p>
                <p class="text-xs text-muted mt-1">Your plan history will appear here</p>
            </div>

            <div v-else class="overflow-y-auto flex-1">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Start</th>
                            <th>End</th>
                            <th>Requests Used</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(h, i) in history" :key="i">
                            <td class="font-mono text-xs text-slate-300">
                                @{{ h.start_date ? new Date(h.start_date).toLocaleDateString() : '—' }}
                            </td>
                            <td class="font-mono text-xs text-slate-300">
                                @{{ h.end_date ? new Date(h.end_date).toLocaleDateString() : '—' }}
                            </td>
                            <td class="font-mono text-xs text-slate-300">
                                @{{ h.requests_used?.toLocaleString() ?? '0' }}
                            </td>
                            <td>
                                <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium',
                                               h.active ? 'badge-active' : 'badge-inactive']">
                                    <span class="w-1.5 h-1.5 rounded-full mr-1.5"
                                          :class="h.active ? 'bg-accent' : 'bg-red-400'"></span>
                                    @{{ h.active ? 'Active' : 'Expired' }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ── Recent Requests ── --}}
    <div class="card overflow-hidden">
        <div class="px-6 py-4 border-b border-border flex items-center justify-between">
            <div>
                <h3 class="font-display text-sm text-white">Recent Requests</h3>
                <p class="text-xs text-muted mt-0.5">Last 10 API calls</p>
            </div>
            <a href="{{ route('dashboard.keys') }}" class="text-xs text-accent hover:text-accent-dim transition-colors font-mono">
                View full log →
            </a>
        </div>

        <div v-if="loadingLogs" class="flex items-center justify-center py-10 gap-3 text-muted">
            <div class="spinner"></div>
            <span class="text-sm">Loading…</span>
        </div>

        <div v-else-if="!logs.length"
             class="flex flex-col items-center justify-center py-12 text-center">
            <div class="w-12 h-12 rounded-xl bg-surface-2 border border-border flex items-center justify-center mb-3">
                <svg class="w-6 h-6 text-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <p class="text-sm text-slate-400">No requests logged yet</p>
        </div>

        <div v-else class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Endpoint</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(log, i) in logs.slice(0, 10)" :key="i">
                        <td><code class="font-mono text-xs text-slate-300">@{{ log.endpoint ?? '—' }}</code></td>
                        <td>
                            <span :class="['font-mono text-xs px-2 py-0.5 rounded', methodClass(log.method)]">
                                @{{ log.method ?? '—' }}
                            </span>
                        </td>
                        <td>
                            <span :class="['font-mono text-xs font-semibold', statusClass(log.response_code)]">
                                @{{ log.response_code ?? '—' }}
                            </span>
                        </td>
                        <td>
                            <span class="text-xs text-muted">
                                @{{ log.requested_at ? new Date(log.requested_at).toLocaleString() : '—' }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
    function OverviewApp() {
        return {
            user:              {},
            history:           [],
            logs:              [],
            currentPlanDetail: null,
            loadingHistory:    true,
            loadingLogs:       true,
            keyActive:         false,
            keyValue:          '',

            get activePlan() {
                const active = this.history.find(h => h.active);
                return active ? (active.plan_name ?? 'Active Plan') : null;
            },

            get planExpiry() {
                const active = this.history.find(h => h.active);
                if (!active?.end_date) return null;
                return new Date(active.end_date).toLocaleDateString();
            },

            get requestsUsed() {
                const active = this.history.find(h => h.active);
                return active ? (active.requests_used ?? 0) : null;
            },

            get usagePercent() {
                if (!this.requestsUsed || !this.currentPlanDetail?.request_limit) return 0;
                return (this.requestsUsed / this.currentPlanDetail.request_limit) * 100;
            },

            get maskedKey() {
                const k = this.keyValue;
                if (!k) return '—';
                return k.slice(0, 6) + '••••••••' + k.slice(-4);
            },

            methodClass(m) {
                const map = {
                    GET:    'bg-blue-500/10 text-blue-400',
                    POST:   'bg-green-500/10 text-green-400',
                    PUT:    'bg-yellow-500/10 text-yellow-400',
                    PATCH:  'bg-orange-500/10 text-orange-400',
                    DELETE: 'bg-red-500/10 text-red-400',
                };
                return map[m?.toUpperCase()] ?? 'bg-surface-3 text-muted';
            },

            statusClass(code) {
                if (!code) return 'text-muted';
                if (code < 300) return 'text-accent';
                if (code < 400) return 'text-yellow-400';
                return 'text-red-400';
            },

            openUpgrade() {
                UpgradeModal.open();
            },

            async mounted() {
                // User info
                const rMe = await api('GET', 'auth/me');
                if (rMe.ok) this.user = rMe.data.data ?? {};

                // Profile (API key)
                const rProfile = await api('GET', 'dashboard/profile');
                if (rProfile.ok) {
                    const d = rProfile.data.data ?? {};
                    this.keyValue  = d.app_key ?? '';
                    this.keyActive = !!this.keyValue;
                }

                // Subscription history
                const rHistory = await api('GET', 'dashboard/history');
                if (rHistory.ok) {
                    this.history = rHistory.data.data ?? [];

                    // Load current plan detail if active
                    const activePlanId = this.history.find(h => h.active)?.plan_id;
                    if (activePlanId) {
                        const rPlan = await api('GET', `dashboard/plans/${activePlanId}`);
                        if (rPlan.ok) this.currentPlanDetail = rPlan.data.data ?? null;
                    }
                }
                this.loadingHistory = false;

                // Request logs
                const rLogs = await api('GET', 'dashboard/log');
                if (rLogs.ok) this.logs = rLogs.data.data ?? [];
                this.loadingLogs = false;
            },
        };
    }

    PetiteVue.createApp({ OverviewApp }).mount('#overview-page');
</script>
@endpush
