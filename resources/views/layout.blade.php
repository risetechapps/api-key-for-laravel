<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'API Key Manager') — KeyForge</title>

    {{-- Tailwind CSS --}}
    <script src="https://cdn.tailwindcss.com"></script>

    {{-- Google Fonts: Syne (display) + JetBrains Mono (code/data) + DM Sans (body) --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=JetBrains+Mono:wght@400;500;600&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        display: ['Syne', 'sans-serif'],
                        mono:    ['JetBrains Mono', 'monospace'],
                        body:    ['DM Sans', 'sans-serif'],
                    },
                    colors: {
                        surface: {
                            DEFAULT: '#0d0f14',
                            1: '#13161e',
                            2: '#1a1e29',
                            3: '#222736',
                        },
                        accent: {
                            DEFAULT: '#4ade80',
                            dim:     '#22c55e',
                            muted:   '#166534',
                            glow:    'rgba(74,222,128,0.12)',
                        },
                        muted: '#4b5468',
                        border: '#1f2535',
                    },
                    boxShadow: {
                        glow: '0 0 24px rgba(74,222,128,0.18)',
                        card: '0 1px 0 0 rgba(255,255,255,0.04), 0 4px 24px 0 rgba(0,0,0,0.4)',
                    }
                }
            }
        }
    </script>

    <style>
        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: 'DM Sans', sans-serif;
            background-color: #0d0f14;
            color: #e2e8f0;
            min-height: 100vh;
        }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #13161e; }
        ::-webkit-scrollbar-thumb { background: #1f2535; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #2d3448; }

        /* Grid background texture */
        .bg-grid {
            background-image:
                linear-gradient(rgba(255,255,255,0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.02) 1px, transparent 1px);
            background-size: 40px 40px;
        }

        /* Petite-Vue cloak — hide until mounted */
        [v-cloak] { display: none !important; }

        /* Sidebar nav item active */
        .nav-item.active { background: rgba(74,222,128,0.08); color: #4ade80; }
        .nav-item.active .nav-icon { color: #4ade80; }
        .nav-item { transition: all 0.15s ease; }
        .nav-item:hover:not(.active) { background: rgba(255,255,255,0.04); }

        /* Button base */
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.875rem; font-weight: 500;
            border-radius: 8px; cursor: pointer;
            transition: all 0.15s ease;
            border: none; outline: none;
            padding: 0.5rem 1rem;
        }
        .btn-primary {
            background: #4ade80; color: #0d0f14;
            box-shadow: 0 0 16px rgba(74,222,128,0.25);
        }
        .btn-primary:hover { background: #22c55e; box-shadow: 0 0 24px rgba(74,222,128,0.4); transform: translateY(-1px); }
        .btn-primary:active { transform: translateY(0); }
        .btn-secondary { background: #1a1e29; color: #94a3b8; border: 1px solid #1f2535; }
        .btn-secondary:hover { background: #222736; color: #e2e8f0; border-color: #2d3448; }
        .btn-danger { background: rgba(239,68,68,0.1); color: #f87171; border: 1px solid rgba(239,68,68,0.2); }
        .btn-danger:hover { background: rgba(239,68,68,0.2); }

        /* Form inputs */
        .form-input {
            width: 100%;
            background: #13161e;
            border: 1px solid #1f2535;
            border-radius: 8px;
            padding: 0.625rem 0.875rem;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.875rem;
            color: #e2e8f0;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
            outline: none;
        }
        .form-input:focus { border-color: #4ade80; box-shadow: 0 0 0 3px rgba(74,222,128,0.08); }
        .form-input::placeholder { color: #4b5468; }
        .form-input.error { border-color: #f87171; }

        /* Cards */
        .card {
            background: #13161e;
            border: 1px solid #1f2535;
            border-radius: 12px;
            box-shadow: 0 1px 0 0 rgba(255,255,255,0.04), 0 4px 24px 0 rgba(0,0,0,0.4);
        }

        /* Status badge */
        .badge-active   { background: rgba(74,222,128,0.1); color: #4ade80; border: 1px solid rgba(74,222,128,0.2); }
        .badge-inactive { background: rgba(239,68,68,0.1);  color: #f87171; border: 1px solid rgba(239,68,68,0.2); }
        .badge-weekly   { background: rgba(168,85,247,0.1); color: #c084fc; border: 1px solid rgba(168,85,247,0.2); }
        .badge-monthly  { background: rgba(59,130,246,0.1); color: #60a5fa; border: 1px solid rgba(59,130,246,0.2); }
        .badge-annually { background: rgba(234,179,8,0.1);  color: #facc15; border: 1px solid rgba(234,179,8,0.2); }

        /* Slide-in panel */
        .panel-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,0.7);
            backdrop-filter: blur(4px); z-index: 40;
            animation: fadeIn 0.2s ease;
        }
        .panel-drawer {
            position: fixed; top: 0; right: 0; bottom: 0;
            width: min(480px, 100vw);
            background: #13161e; border-left: 1px solid #1f2535;
            z-index: 50; overflow-y: auto;
            animation: slideIn 0.25s ease;
        }
        @keyframes fadeIn  { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideIn { from { transform: translateX(100%); } to { transform: translateX(0); } }

        /* Toast */
        .toast {
            position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 9999;
            padding: 0.75rem 1.25rem; border-radius: 10px;
            font-size: 0.875rem; font-weight: 500;
            animation: toastIn 0.3s ease;
            max-width: 360px;
        }
        .toast-success { background: #13161e; color: #4ade80; border: 1px solid rgba(74,222,128,0.3); box-shadow: 0 0 20px rgba(74,222,128,0.15); }
        .toast-error   { background: #13161e; color: #f87171; border: 1px solid rgba(239,68,68,0.3); box-shadow: 0 0 20px rgba(239,68,68,0.15); }
        @keyframes toastIn { from { opacity:0; transform: translateY(12px); } to { opacity:1; transform: translateY(0); } }

        /* Spinner */
        .spinner {
            width: 16px; height: 16px;
            border: 2px solid rgba(74,222,128,0.2);
            border-top-color: #4ade80;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Table */
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.65rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.08em;
            color: #4b5468; padding: 0.75rem 1rem;
            border-bottom: 1px solid #1f2535; text-align: left;
        }
        .data-table td { padding: 0.875rem 1rem; border-bottom: 1px solid rgba(31,37,53,0.6); font-size: 0.875rem; }
        .data-table tr:last-child td { border-bottom: none; }
        .data-table tbody tr { transition: background 0.1s ease; }
        .data-table tbody tr:hover td { background: rgba(255,255,255,0.02); }

        /* Petite-Vue transition helpers */
        .fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
        .fade-enter-from, .fade-leave-to { opacity: 0; }
    </style>

    @stack('head')
</head>
<body class="bg-surface h-full">
<div class="bg-grid min-h-screen" id="app">

    @auth
    {{-- ── SIDEBAR ── --}}
    <aside class="fixed left-0 top-0 bottom-0 w-60 bg-surface-1 border-r border-border flex flex-col z-30">

        {{-- Logo --}}
        <div class="px-6 py-5 border-b border-border">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-accent-glow border border-accent/30 flex items-center justify-center">
                    <svg class="w-4 h-4 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                    </svg>
                </div>
                <span class="font-display text-lg text-white tracking-tight">KeyForge</span>
            </div>
        </div>

        {{-- Navigation --}}
        <nav class="flex-1 px-3 py-4 space-y-0.5 overflow-y-auto">

            <p class="px-3 pb-2 pt-1 text-xs font-mono text-muted uppercase tracking-widest">Dashboard</p>

            <a href="{{ route('dashboard.overview') }}"
               class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-slate-400 {{ request()->routeIs('dashboard.overview') ? 'active' : '' }}">
                <svg class="nav-icon w-4 h-4 text-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                Overview
            </a>

            <a href="{{ route('dashboard.keys') }}"
               class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-slate-400 {{ request()->routeIs('dashboard.keys') ? 'active' : '' }}">
                <svg class="nav-icon w-4 h-4 text-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                </svg>
                API Keys
            </a>

            <a href="{{ route('dashboard.history') }}"
               class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-slate-400 {{ request()->routeIs('dashboard.history') ? 'active' : '' }}">
                <svg class="nav-icon w-4 h-4 text-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                History
            </a>

            <p class="px-3 pb-2 pt-4 text-xs font-mono text-muted uppercase tracking-widest">Admin</p>

            <a href="{{ route('dashboard.plans') }}"
               class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-slate-400 {{ request()->routeIs('dashboard.plans') ? 'active' : '' }}">
                <svg class="nav-icon w-4 h-4 text-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                </svg>
                Plans
            </a>

            <a href="{{ route('dashboard.coupons') }}"
               class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-slate-400 {{ request()->routeIs('dashboard.coupons') ? 'active' : '' }}">
                <svg class="nav-icon w-4 h-4 text-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                </svg>
                Coupons
            </a>
        </nav>

        {{-- User Footer --}}
        <div class="px-4 py-4 border-t border-border">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-full bg-surface-3 border border-border overflow-hidden flex-shrink-0">
                    @if(auth()->user()->getMedia('profile')->first())
                        <img src="{{ auth()->user()->getMedia('profile')->first()->getFullUrl() }}" alt="Avatar" class="w-full h-full object-cover">
                    @else
                        <div class="w-full h-full flex items-center justify-center text-accent font-display text-sm">
                            {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                        </div>
                    @endif
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm text-white font-medium truncate">{{ auth()->user()->name }}</p>
                    <p class="text-xs text-muted truncate">{{ auth()->user()->email }}</p>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="w-7 h-7 rounded-md hover:bg-surface-2 flex items-center justify-center text-muted hover:text-slate-300 transition-colors" title="Logout">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                        </svg>
                    </button>
                </form>
            </div>
        </div>
    </aside>

    {{-- ── MAIN CONTENT ── --}}
    <main class="ml-60 min-h-screen">
        {{-- Top bar --}}
        <header class="h-14 bg-surface-1/80 backdrop-blur-sm border-b border-border flex items-center px-8 gap-4 sticky top-0 z-20">
            <div class="flex-1">
                <h1 class="font-display text-lg text-white">@yield('page-title', 'Dashboard')</h1>
            </div>
            <div class="flex items-center gap-2">
                @yield('header-actions')
            </div>
        </header>

        <div class="p-8">
            @yield('content')
        </div>
    </main>

    @else
    {{-- Guest: just yield content (login page handles its own layout) --}}
    @yield('content')
    @endauth

    {{-- ── UPGRADE MODAL (global, rendered from upgrade-modal component) ── --}}
    @include('api-key::components.upgrade-modal')

</div>{{-- #app --}}

{{-- Petite-Vue CDN --}}
<script src="https://unpkg.com/petite-vue@0.4.1/dist/petite-vue.iife.js"></script>

{{-- Global API helper --}}
<script>
    // ─── Auth token helper ─────────────────────────────────────────
    const Auth = {
        getToken()  { return localStorage.getItem('api_token'); },
        setToken(t) { localStorage.setItem('api_token', t); },
        clear()     { localStorage.removeItem('api_token'); localStorage.removeItem('api_user'); },
        getUser()   {
            try { return JSON.parse(localStorage.getItem('api_user') || 'null'); }
            catch { return null; }
        },
        setUser(u)  { localStorage.setItem('api_user', JSON.stringify(u)); },
        isLoggedIn(){ return !!this.getToken(); },
    };

    // ─── Fetch wrapper ─────────────────────────────────────────────
    async function api(method, path, body = null) {
        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
        };
        const token = Auth.getToken();
        if (token) headers['Authorization'] = 'Bearer ' + token;

        const opts = { method, headers };
        if (body) opts.body = JSON.stringify(body);

        const res = await fetch('/api/' + path.replace(/^\//, ''), opts);
        const data = await res.json().catch(() => ({}));

        if (res.status === 401) {
            Auth.clear();
            window.location.href = '/login';
        }

        return { ok: res.ok, status: res.status, data };
    }

    // ─── Global toast store (used by petite-vue components) ────────
    const Toast = PetiteVue.reactive({
        visible: false,
        type: 'success',
        message: '',
        _timer: null,
        show(msg, type = 'success') {
            clearTimeout(this._timer);
            this.message = msg;
            this.type = type;
            this.visible = true;
            this._timer = setTimeout(() => { this.visible = false; }, 3500);
        },
    });

    // ─── Global upgrade modal store ────────────────────────────────
    const UpgradeModal = PetiteVue.reactive({
        visible: false,
        plans: [],
        loading: false,
        async open() {
            this.visible = true;
            if (!this.plans.length) {
                this.loading = true;
                const r = await api('GET', 'dashboard/plans');
                if (r.ok) this.plans = r.data.data ?? [];
                this.loading = false;
            }
        },
        close() { this.visible = false; },
        async subscribe(planId) {
            const r = await api('POST', 'dashboard/signature', { plan: planId });
            if (r.ok) {
                Toast.show('Plan activated successfully!');
                this.close();
            } else {
                Toast.show(r.data.message || 'Error subscribing to plan.', 'error');
            }
        },
    });
</script>

@stack('scripts')

{{-- Toast component (global) --}}
<script>
    PetiteVue.createApp({
        toast: Toast,
    }).mount('#global-toast');
</script>
<div id="global-toast" v-cloak>
    <div v-if="toast.visible"
         :class="['toast', toast.type === 'success' ? 'toast-success' : 'toast-error']"
         @click="toast.visible = false"
         style="cursor:pointer">
        <span>@{{ toast.message }}</span>
    </div>
</div>

</body>
</html>
