@extends('api-key::layout')
@section('title', 'Create Account')

@section('content')
<div class="min-h-screen flex" id="register-page">

    {{-- ── Left Panel: Branding ── --}}
    <div class="hidden lg:flex w-[480px] flex-shrink-0 flex-col bg-surface-1 border-r border-border relative overflow-hidden p-12">

        <div class="absolute inset-0"
             style="background-image: linear-gradient(rgba(255,255,255,0.02) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.02) 1px, transparent 1px); background-size:40px 40px;">
        </div>

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
                    Start managing<br>
                    your APIs<br>
                    <span class="text-accent">in minutes.</span>
                </h2>
                <p class="text-slate-400 text-sm leading-relaxed">
                    Create your account, get your API key instantly,
                    and start integrating with your first request.
                </p>
            </div>

            {{-- Steps --}}
            <div class="space-y-4 pt-8 border-t border-border">
                <div class="flex items-center gap-3">
                    <div class="w-6 h-6 rounded-full bg-accent-glow border border-accent/30 flex items-center justify-center flex-shrink-0">
                        <span class="font-mono text-xs text-accent font-bold">1</span>
                    </div>
                    <p class="text-sm text-slate-400">Create your account</p>
                </div>
                <div class="flex items-center gap-3">
                    <div class="w-6 h-6 rounded-full bg-surface-3 border border-border flex items-center justify-center flex-shrink-0">
                        <span class="font-mono text-xs text-muted font-bold">2</span>
                    </div>
                    <p class="text-sm text-muted">Verify your email</p>
                </div>
                <div class="flex items-center gap-3">
                    <div class="w-6 h-6 rounded-full bg-surface-3 border border-border flex items-center justify-center flex-shrink-0">
                        <span class="font-mono text-xs text-muted font-bold">3</span>
                    </div>
                    <p class="text-sm text-muted">Get your API key &amp; start</p>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Right Panel: Register Form ── --}}
    <div class="flex-1 flex items-center justify-center p-8" v-scope="RegisterApp()" v-cloak>

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

            {{-- Success state --}}
            <div v-if="success" class="text-center">
                <div class="w-16 h-16 rounded-2xl bg-accent-glow border border-accent/30 flex items-center justify-center mx-auto mb-5">
                    <svg class="w-8 h-8 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                </div>
                <h1 class="font-display text-2xl text-white mb-2">Check your email</h1>
                <p class="text-slate-400 text-sm leading-relaxed mb-6">
                    We sent a verification link to<br>
                    <strong class="text-white">@{{ form.email }}</strong>
                </p>
                <p class="text-xs text-muted mb-6">
                    Click the link in the email to activate your account.<br>
                    Check your spam folder if you don't see it.
                </p>
                <a href="{{ route('login') }}" class="btn btn-secondary w-full justify-center">
                    Back to Sign In
                </a>
            </div>

            {{-- Form state --}}
            <div v-else>

                <div class="mb-8">
                    <h1 class="font-display text-2xl text-white">Create account</h1>
                    <p class="text-slate-400 text-sm mt-1">Free to start, upgrade anytime</p>
                </div>

                {{-- Error banner --}}
                <div v-if="error"
                     class="mb-5 px-4 py-3 rounded-lg bg-red-500/10 border border-red-500/20 text-red-400 text-sm flex items-start gap-2">
                    <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <span>@{{ error }}</span>
                </div>

                <div class="space-y-4">

                    {{-- Name --}}
                    <div>
                        <label class="block text-xs font-mono text-muted uppercase tracking-wider mb-1.5">Full Name</label>
                        <input
                            type="text"
                            v-model="form.name"
                            @keyup.enter="register"
                            :class="['form-input', fieldError('name') ? 'error' : '']"
                            placeholder="John Doe"
                            autocomplete="name"
                        >
                        <p v-if="fieldError('name')" class="mt-1 text-xs text-red-400">@{{ fieldError('name') }}</p>
                    </div>

                    {{-- Email --}}
                    <div>
                        <label class="block text-xs font-mono text-muted uppercase tracking-wider mb-1.5">Email</label>
                        <input
                            type="email"
                            v-model="form.email"
                            @keyup.enter="register"
                            :class="['form-input', fieldError('email') ? 'error' : '']"
                            placeholder="you@example.com"
                            autocomplete="email"
                        >
                        <p v-if="fieldError('email')" class="mt-1 text-xs text-red-400">@{{ fieldError('email') }}</p>
                    </div>

                    {{-- Password --}}
                    <div>
                        <label class="block text-xs font-mono text-muted uppercase tracking-wider mb-1.5">Password</label>
                        <div class="relative">
                            <input
                                :type="showPassword ? 'text' : 'password'"
                                v-model="form.password"
                                @keyup.enter="register"
                                :class="['form-input pr-10', fieldError('password') ? 'error' : '']"
                                placeholder="Min. 8 characters"
                                autocomplete="new-password"
                            >
                            <button type="button"
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

                    {{-- Password confirmation --}}
                    <div>
                        <label class="block text-xs font-mono text-muted uppercase tracking-wider mb-1.5">Confirm Password</label>
                        <input
                            :type="showPassword ? 'text' : 'password'"
                            v-model="form.password_confirmation"
                            @keyup.enter="register"
                            :class="['form-input', fieldError('password_confirmation') ? 'error' : '']"
                            placeholder="Repeat your password"
                            autocomplete="new-password"
                        >
                        <p v-if="fieldError('password_confirmation')" class="mt-1 text-xs text-red-400">@{{ fieldError('password_confirmation') }}</p>
                    </div>

                    {{-- Password strength indicator --}}
                    <div v-if="form.password">
                        <div class="flex gap-1 mb-1">
                            <div v-for="n in 4" :key="n"
                                 class="h-1 flex-1 rounded-full transition-all duration-300"
                                 :class="passwordStrength >= n ? strengthColor : 'bg-surface-3'">
                            </div>
                        </div>
                        <p class="text-xs" :class="strengthTextColor">@{{ strengthLabel }}</p>
                    </div>

                    <button
                        @click="register"
                        :disabled="loading"
                        class="btn btn-primary w-full justify-center py-2.5 mt-2"
                        style="font-size:0.9rem">
                        <div v-if="loading" class="spinner"></div>
                        <span v-else>Create Account</span>
                    </button>
                </div>

                <p class="text-center text-sm text-muted mt-6">
                    Already have an account?
                    <a href="{{ route('login') }}" class="text-accent hover:text-accent-dim transition-colors ml-1">Sign in</a>
                </p>
            </div>

        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function RegisterApp() {
        return {
            form: {
                name: '',
                email: '',
                password: '',
                password_confirmation: '',
            },
            loading:      false,
            success:      false,
            error:        null,
            errors:       {},
            showPassword: false,

            get passwordStrength() {
                const p = this.form.password;
                if (!p) return 0;
                let score = 0;
                if (p.length >= 8)           score++;
                if (/[A-Z]/.test(p))         score++;
                if (/[0-9]/.test(p))         score++;
                if (/[^A-Za-z0-9]/.test(p))  score++;
                return score;
            },

            get strengthLabel() {
                return ['', 'Weak', 'Fair', 'Good', 'Strong'][this.passwordStrength] ?? '';
            },

            get strengthColor() {
                return ['', 'bg-red-400', 'bg-yellow-400', 'bg-blue-400', 'bg-accent'][this.passwordStrength] ?? '';
            },

            get strengthTextColor() {
                return ['', 'text-red-400', 'text-yellow-400', 'text-blue-400', 'text-accent'][this.passwordStrength] ?? '';
            },

            fieldError(field) {
                return this.errors[field]?.[0] ?? null;
            },

            validate() {
                const errs = {};
                if (!this.form.name?.trim())                          errs.name  = ['Name is required.'];
                if (!this.form.email?.trim())                         errs.email = ['Email is required.'];
                if (!this.form.password)                              errs.password = ['Password is required.'];
                else if (this.form.password.length < 8)               errs.password = ['Password must be at least 8 characters.'];
                if (this.form.password !== this.form.password_confirmation)
                    errs.password_confirmation = ['Passwords do not match.'];
                this.errors = errs;
                return !Object.keys(errs).length;
            },

            async register() {
                this.error  = null;
                this.errors = {};
                if (!this.validate()) return;

                this.loading = true;
                try {
                    const r = await api('POST', 'register', {
                        name:                  this.form.name,
                        email:                 this.form.email,
                        password:              this.form.password,
                        password_confirmation: this.form.password_confirmation,
                    });

                    if (r.ok) {
                        this.success = true;
                    } else if (r.status === 422) {
                        this.errors = r.data.errors ?? {};
                    } else {
                        this.error = r.data.message ?? 'Unable to register at this time. Please try again.';
                    }
                } catch (e) {
                    this.error = 'Network error. Please check your connection.';
                } finally {
                    this.loading = false;
                }
            },
        };
    }

    PetiteVue.createApp({ RegisterApp }).mount('#register-page');
</script>
@endpush
