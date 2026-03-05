@extends('api-key::layout')
@section('title', 'Sign In')

@section('content')
<div class="min-h-screen flex" id="login-page">

    {{-- ── Left Panel: Branding ── --}}
    <div class="hidden lg:flex w-[480px] flex-shrink-0 flex-col bg-surface-1 border-r border-border relative overflow-hidden p-12">

        {{-- Grid decoration --}}
        <div class="absolute inset-0"
             style="background-image: linear-gradient(rgba(255,255,255,0.02) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.02) 1px, transparent 1px); background-size:40px 40px;">
        </div>

        {{-- Glow orb --}}
        <div class="absolute bottom-0 left-0 w-96 h-96 rounded-full"
             style="background: radial-gradient(circle, rgba(74,222,128,0.07) 0%, transparent 70%); transform:translate(-30%,30%)">
        </div>

        <div class="relative z-10 flex flex-col h-full">

            {{-- Logo --}}
            <div class="flex items-center gap-3 mb-auto">
                <div class="w-9 h-9 rounded-xl bg-accent-glow border border-accent/30 flex items-center justify-center">
                    <svg class="w-5 h-5 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                    </svg>
                </div>
                <span class="font-display text-xl text-white">KeyForge</span>
            </div>

            {{-- Headline --}}
            <div class="mb-auto">
                <h2 class="font-display text-4xl text-white leading-tight mb-4">
                    Manage your<br>
                    <span class="text-accent">API infrastructure</span><br>
                    with precision.
                </h2>
                <p class="text-slate-400 text-sm leading-relaxed">
                    Secure key management, rate limiting, plan controls,
                    and request logging — all in one dashboard.
                </p>
            </div>

            {{-- Stats strip --}}
            <div class="grid grid-cols-3 gap-4 pt-8 border-t border-border">
                <div>
                    <p class="font-mono text-accent text-xl font-semibold">∞</p>
                    <p class="text-xs text-muted mt-0.5">API Keys</p>
                </div>
                <div>
                    <p class="font-mono text-accent text-xl font-semibold">ms</p>
                    <p class="text-xs text-muted mt-0.5">Latency</p>
                </div>
                <div>
                    <p class="font-mono text-accent text-xl font-semibold">99%</p>
                    <p class="text-xs text-muted mt-0.5">Uptime</p>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Right Panel: Login Form ── --}}
    <div class="flex-1 flex items-center justify-center p-8" v-scope="LoginApp()" v-cloak>

        <div class="w-full max-w-sm">

            {{-- Mobile logo --}}
            <div class="flex items-center gap-2 mb-10 lg:hidden">
                <div class="w-8 h-8 rounded-lg bg-accent-glow border border-accent/30 flex items-center justify-center">
                    <svg class="w-4 h-4 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                    </svg>
                </div>
                <span class="font-display text-lg text-white">KeyForge</span>
            </div>

            {{-- Title --}}
            <div class="mb-8">
                <h1 class="font-display text-2xl text-white">Welcome back</h1>
                <p class="text-slate-400 text-sm mt-1">Sign in to your dashboard</p>
            </div>

            {{-- Error banner --}}
            <div v-if="error"
                 class="mb-5 px-4 py-3 rounded-lg bg-red-500/10 border border-red-500/20 text-red-400 text-sm flex items-start gap-2">
                <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <span>@{{ error }}</span>
            </div>

            {{-- Form --}}
            <div class="space-y-4">

                <div>
                    <label class="block text-xs font-mono text-muted uppercase tracking-wider mb-1.5">Email</label>
                    <input
                        type="email"
                        v-model="form.email"
                        @keyup.enter="login"
                        :class="['form-input', fieldError('email') ? 'error' : '']"
                        placeholder="you@example.com"
                        autocomplete="email"
                    >
                    <p v-if="fieldError('email')" class="mt-1 text-xs text-red-400">@{{ fieldError('email') }}</p>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="text-xs font-mono text-muted uppercase tracking-wider">Password</label>
                        <a href="#" class="text-xs text-accent hover:text-accent-dim transition-colors">Forgot password?</a>
                    </div>
                    <div class="relative">
                        <input
                            :type="showPassword ? 'text' : 'password'"
                            v-model="form.password"
                            @keyup.enter="login"
                            :class="['form-input pr-10', fieldError('password') ? 'error' : '']"
                            placeholder="••••••••"
                            autocomplete="current-password"
                        >
                        <button
                            type="button"
                            @click="showPassword = !showPassword"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-muted hover:text-slate-300 transition-colors">
                            <svg v-if="!showPassword" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                            <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                            </svg>
                        </button>
                    </div>
                    <p v-if="fieldError('password')" class="mt-1 text-xs text-red-400">@{{ fieldError('password') }}</p>
                </div>

                <button
                    @click="login"
                    :disabled="loading"
                    class="btn btn-primary w-full justify-center py-2.5 mt-2"
                    style="font-size:0.9rem">
                    <div v-if="loading" class="spinner"></div>
                    <span v-else>Sign in</span>
                </button>
            </div>

            {{-- Register link --}}
            <p class="text-center text-sm text-muted mt-6">
                Don't have an account?
                <a href="{{ route('register') }}" class="text-accent hover:text-accent-dim transition-colors ml-1">Create account</a>
            </p>

        </div>{{-- /max-w-sm --}}
    </div>{{-- /right panel --}}
</div>{{-- /min-h-screen --}}
@endsection

@push('scripts')
<script>
    function LoginApp() {
        return {
            form: { email: '', password: '' },
            loading: false,
            error: null,
            errors: {},
            showPassword: false,

            fieldError(field) {
                return this.errors[field]?.[0] ?? null;
            },

            async login() {
                this.error  = null;
                this.errors = {};

                if (!this.form.email)    { this.errors.email    = ['Email is required.']; return; }
                if (!this.form.password) { this.errors.password = ['Password is required.']; return; }

                this.loading = true;
                try {
                    const r = await api('POST', 'login', {
                        email:    this.form.email,
                        password: this.form.password,
                    });

                    if (r.ok && r.data.data?.token) {
                        Auth.setToken(r.data.data.token);
                        Auth.setUser(r.data.data);
                        window.location.href = '{{ route("dashboard.overview") }}';
                    } else if (r.status === 422) {
                        this.errors = r.data.errors ?? {};
                    } else {
                        this.error = r.data.message ?? 'Invalid credentials. Please try again.';
                    }
                } catch (e) {
                    this.error = 'Network error. Please check your connection.';
                } finally {
                    this.loading = false;
                }
            },
        };
    }

    PetiteVue.createApp({ LoginApp }).mount('#login-page');
</script>
@endpush
