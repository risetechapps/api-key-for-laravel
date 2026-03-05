@extends('layout')
@section('title', 'API Keys')
@section('page-title', 'API Keys')

@section('content')
<div id="keys-page" v-cloak v-scope="KeysApp()">

    {{-- ── Active Key Card ── --}}
    <div class="card mb-6 overflow-hidden">
        <div class="p-6 border-b border-border">
            <div class="flex items-start justify-between">
                <div>
                    <h2 class="font-display text-base text-white">Your API Key</h2>
                    <p class="text-xs text-muted mt-0.5">Use this key to authenticate your API requests</p>
                </div>
                <span :class="['inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium',
                               keyData.active ? 'badge-active' : 'badge-inactive']">
                    <span class="w-1.5 h-1.5 rounded-full mr-1.5"
                          :class="keyData.active ? 'bg-accent' : 'bg-red-400'"></span>
                    @{{ keyData.active ? 'Active' : 'Inactive' }}
                </span>
            </div>
        </div>

        <div class="p-6">
            <div v-if="loadingKey" class="flex items-center gap-3 text-muted">
                <div class="spinner"></div>
                <span class="text-sm">Loading key…</span>
            </div>

            <div v-else>
                {{-- Key display --}}
                <div class="relative">
                    <div class="flex items-center gap-3 p-4 rounded-xl bg-surface border border-border">
                        <svg class="w-4 h-4 text-muted flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                        </svg>
                        <code class="flex-1 font-mono text-sm text-slate-300 break-all">
                            @{{ showKey ? keyData.key : maskedKey }}
                        </code>
                        <div class="flex items-center gap-1.5 flex-shrink-0">
                            <button @click="toggleShow"
                                    class="btn btn-secondary py-1.5 px-3 text-xs">
                                @{{ showKey ? 'Hide' : 'Reveal' }}
                            </button>
                            <button @click="copyKey"
                                    class="btn btn-secondary py-1.5 px-3 text-xs">
                                @{{ copied ? '✓ Copied' : 'Copy' }}
                            </button>
                        </div>
                    </div>
                </div>

                {{-- Key metadata --}}
                <div class="grid grid-cols-3 gap-4 mt-4">
                    <div class="p-3 rounded-lg bg-surface border border-border">
                        <p class="text-xs font-mono text-muted uppercase tracking-wider">Expires</p>
                        <p class="text-sm text-slate-300 mt-1 font-medium">
                            @{{ keyData.expires_at ? new Date(keyData.expires_at).toLocaleDateString() : 'Never' }}
                        </p>
                    </div>
                    <div class="p-3 rounded-lg bg-surface border border-border">
                        <p class="text-xs font-mono text-muted uppercase tracking-wider">Created</p>
                        <p class="text-sm text-slate-300 mt-1 font-medium">
                            @{{ keyData.created_at ? new Date(keyData.created_at).toLocaleDateString() : '—' }}
                        </p>
                    </div>
                    <div class="p-3 rounded-lg bg-surface border border-border">
                        <p class="text-xs font-mono text-muted uppercase tracking-wider">Allowed Origins</p>
                        <p class="text-sm text-slate-300 mt-1 font-medium">
                            @{{ (origins.length || 0) + ' configured' }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Allowed Origins ── --}}
    <div class="card mb-6">
        <div class="px-6 py-4 border-b border-border flex items-center justify-between">
            <div>
                <h2 class="font-display text-base text-white">Allowed Origins</h2>
                <p class="text-xs text-muted mt-0.5">Restrict which domains can use your API key (leave empty to allow all)</p>
            </div>
            <button @click="saveOrigins" :disabled="savingOrigins"
                    class="btn btn-primary py-2 text-xs">
                <div v-if="savingOrigins" class="spinner"></div>
                <span v-else>Save Origins</span>
            </button>
        </div>

        <div class="p-6">
            {{-- Add origin --}}
            <div class="flex gap-2 mb-4">
                <input v-model="newOrigin" type="text"
                       @keyup.enter="addOrigin"
                       class="form-input text-sm font-mono"
                       placeholder="e.g. app.example.com  or  *.example.com">
                <button @click="addOrigin"
                        :disabled="!newOrigin.trim()"
                        class="btn btn-secondary flex-shrink-0">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    Add
                </button>
            </div>

            {{-- Origins list --}}
            <div class="space-y-2">
                <div v-if="!origins.length"
                     class="flex items-center gap-2 p-3 rounded-lg bg-surface border border-border text-sm text-muted">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064"/>
                    </svg>
                    All origins allowed — add restrictions above
                </div>

                <div v-for="(origin, i) in origins" :key="i"
                     class="flex items-center justify-between px-4 py-2.5 rounded-lg bg-surface border border-border group">
                    <div class="flex items-center gap-3">
                        <div class="w-1.5 h-1.5 rounded-full bg-accent"></div>
                        <code class="font-mono text-sm text-slate-300">@{{ origin }}</code>
                    </div>
                    <button @click="removeOrigin(i)"
                            class="opacity-0 group-hover:opacity-100 transition-opacity text-muted hover:text-red-400">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Usage Log ── --}}
    <div class="card overflow-hidden">
        <div class="px-6 py-4 border-b border-border flex items-center justify-between">
            <div>
                <h2 class="font-display text-base text-white">Request Log</h2>
                <p class="text-xs text-muted mt-0.5">Recent API requests made with your key</p>
            </div>
            <button @click="loadLog" class="btn btn-secondary py-1.5 text-xs">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Refresh
            </button>
        </div>

        <div v-if="loadingLog" class="flex items-center justify-center py-12 gap-3 text-muted">
            <div class="spinner"></div>
            <span class="text-sm">Loading log…</span>
        </div>

        <div v-else-if="!logs.length"
             class="flex flex-col items-center justify-center py-16 text-center">
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
                        <th>Route</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>IP</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(log, i) in logs" :key="i">
                        <td><code class="font-mono text-xs text-slate-300">@{{ log.route ?? '—' }}</code></td>
                        <td>
                            <span :class="['font-mono text-xs px-2 py-0.5 rounded', methodClass(log.method)]">
                                @{{ log.method ?? '—' }}
                            </span>
                        </td>
                        <td>
                            <span :class="['font-mono text-xs font-semibold', statusClass(log.status_code)]">
                                @{{ log.status_code ?? '—' }}
                            </span>
                        </td>
                        <td><code class="font-mono text-xs text-muted">@{{ log.ip_address ?? '—' }}</code></td>
                        <td><span class="text-xs text-muted">@{{ log.created_at ? new Date(log.created_at).toLocaleString() : '—' }}</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
    function KeysApp() {
        return {
            keyData:       {},
            loadingKey:    true,
            logs:          [],
            loadingLog:    true,
            origins:       [],
            newOrigin:     '',
            savingOrigins: false,
            showKey:       false,
            copied:        false,

            get maskedKey() {
                const k = this.keyData.key ?? '';
                if (!k) return '—';
                return k.slice(0, 8) + '•'.repeat(Math.max(0, k.length - 16)) + k.slice(-8);
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

            toggleShow() { this.showKey = !this.showKey; },

            async copyKey() {
                if (!this.keyData.key) return;
                await navigator.clipboard.writeText(this.keyData.key);
                this.copied = true;
                setTimeout(() => { this.copied = false; }, 2000);
                Toast.show('API key copied to clipboard!');
            },

            addOrigin() {
                const o = this.newOrigin.trim();
                if (o && !this.origins.includes(o)) this.origins.push(o);
                this.newOrigin = '';
            },

            removeOrigin(i) { this.origins.splice(i, 1); },

            async saveOrigins() {
                this.savingOrigins = true;
                const r = await api('POST', 'dashboard/profile/allowed', { allowed: this.origins });
                Toast.show(r.ok ? 'Origins saved!' : (r.data.message || 'Error saving.'), r.ok ? 'success' : 'error');
                this.savingOrigins = false;
            },

            async mounted() {
                // Load profile for API key info
                const rProfile = await api('GET', 'dashboard/profile');
                if (rProfile.ok) {
                    this.keyData = rProfile.data.data?.api_key ?? {};
                }
                this.loadingKey = false;

                // Load allowed origins
                const rOrigins = await api('GET', 'dashboard/profile/allowed');
                if (rOrigins.ok) this.origins = rOrigins.data.data ?? [];

                await this.loadLog();
            },

            async loadLog() {
                this.loadingLog = true;
                const r = await api('GET', 'dashboard/log');
                if (r.ok) this.logs = r.data.data ?? [];
                this.loadingLog = false;
            },
        };
    }

    PetiteVue.createApp({ KeysApp }).mount('#keys-page');
</script>
@endpush
