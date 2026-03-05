{{--
    upgrade-modal.blade.php
    ─────────────────────────────────────────────────────────────────────────
    Global modal triggered when the API returns HTTP 402 (Payment Required).

    Usage in JavaScript:
        UpgradeModal.open();      — opens the modal and loads plans
        UpgradeModal.close();     — closes it
        UpgradeModal.subscribe(planId); — subscribes the user to a plan

    The `UpgradeModal` reactive store is defined in layout.blade.php.
    This file is @included at the bottom of layout.blade.php.
    ─────────────────────────────────────────────────────────────────────────
--}}
<div id="upgrade-modal" v-scope="{ modal: UpgradeModal }" v-cloak>

    <template v-if="modal.visible">

        {{-- Backdrop --}}
        <div class="panel-overlay" style="z-index:60" @click="modal.close()"></div>

        {{-- Modal container --}}
        <div class="fixed inset-0 flex items-center justify-center z-[70] p-4">
            <div class="card w-full max-w-2xl overflow-hidden"
                 style="max-height: 90vh; display:flex; flex-direction:column;">

                {{-- Header --}}
                <div class="px-6 py-5 border-b border-border flex items-start justify-between flex-shrink-0"
                     style="background: linear-gradient(135deg, rgba(74,222,128,0.04) 0%, transparent 60%)">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <div class="w-6 h-6 rounded-md bg-accent-glow border border-accent/30 flex items-center justify-center">
                                <svg class="w-3.5 h-3.5 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                </svg>
                            </div>
                            <span class="font-mono text-xs text-accent uppercase tracking-widest">Upgrade Required</span>
                        </div>
                        <h2 class="font-display text-xl text-white">Unlock this feature</h2>
                        <p class="text-sm text-slate-400 mt-1">
                            Choose a plan below to continue. Cancel anytime.
                        </p>
                    </div>
                    <button @click="modal.close()"
                            class="w-8 h-8 rounded-lg hover:bg-surface-3 flex items-center justify-center text-muted hover:text-slate-300 transition-colors flex-shrink-0">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                {{-- Body --}}
                <div class="overflow-y-auto flex-1 px-6 py-6">

                    {{-- Loading --}}
                    <div v-if="modal.loading" class="flex items-center justify-center py-12 gap-3 text-muted">
                        <div class="spinner"></div>
                        <span class="text-sm">Loading plans…</span>
                    </div>

                    {{-- Plans grid --}}
                    <div v-else-if="modal.plans.length" class="grid gap-4"
                         :class="modal.plans.length >= 3 ? 'grid-cols-3' : 'grid-cols-' + modal.plans.length">

                        <div v-for="plan in modal.plans" :key="plan.id"
                             class="relative rounded-xl border p-5 flex flex-col transition-all cursor-pointer group"
                             :class="plan.billing_cycle === 'annually'
                                 ? 'border-accent/40 bg-accent-glow'
                                 : 'border-border bg-surface-2 hover:border-slate-600'">

                            {{-- Popular badge --}}
                            <div v-if="plan.billing_cycle === 'annually'"
                                 class="absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-0.5 rounded-full text-xs font-mono font-semibold bg-accent text-surface">
                                Most Popular
                            </div>

                            {{-- Plan name + cycle --}}
                            <div class="mb-4">
                                <p class="font-display text-base text-white">@{{ plan.name }}</p>
                                <span :class="['inline-flex items-center mt-1 px-2 py-0.5 rounded-md text-xs font-mono font-medium', 'badge-' + plan.billing_cycle]">
                                    @{{ plan.billing_cycle }}
                                </span>
                            </div>

                            {{-- Price --}}
                            <div class="mb-4">
                                <div class="flex items-baseline gap-1">
                                    <span class="font-display text-3xl text-white">$@{{ parseFloat(plan.price).toFixed(0) }}</span>
                                    <span class="text-xs text-muted">
                                        .@{{ (parseFloat(plan.price) % 1).toFixed(2).slice(2) }}
                                        / @{{ plan.billing_cycle === 'annually' ? 'yr' : plan.billing_cycle === 'weekly' ? 'wk' : 'mo' }}
                                    </span>
                                </div>
                            </div>

                            {{-- Request limit --}}
                            <div class="flex items-center gap-2 mb-4 text-sm text-slate-400">
                                <svg class="w-4 h-4 text-muted flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                </svg>
                                @{{ plan.request_limit === 0 ? 'Unlimited requests' : plan.request_limit.toLocaleString() + ' req / cycle' }}
                            </div>

                            {{-- Features --}}
                            <ul class="flex-1 space-y-1.5 mb-5">
                                <li v-for="feat in (plan.features || [])" :key="feat"
                                    class="flex items-center gap-2 text-xs text-slate-400">
                                    <svg class="w-3.5 h-3.5 text-accent flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                    </svg>
                                    <span class="font-mono">@{{ feat }}</span>
                                </li>
                            </ul>

                            {{-- CTA --}}
                            <button @click="modal.subscribe(plan.id)"
                                    :class="['btn w-full justify-center py-2.5',
                                             plan.billing_cycle === 'annually' ? 'btn-primary' : 'btn-secondary']">
                                Choose @{{ plan.name }}
                            </button>
                        </div>
                    </div>

                    {{-- No plans --}}
                    <div v-else class="text-center py-12 text-muted text-sm">
                        No plans available at the moment.
                    </div>
                </div>

                {{-- Footer --}}
                <div class="px-6 py-3 border-t border-border flex-shrink-0">
                    <p class="text-xs text-center text-muted">
                        All plans include API key management and request logging.
                        <a href="#" @click.prevent="modal.close()" class="text-accent hover:underline ml-1">Dismiss</a>
                    </p>
                </div>

            </div>
        </div>
    </template>
</div>

@push('scripts')
<script>
    // ─── Auto-intercept 402 responses globally ─────────────────────────────
    // Monkey-patch the global `api()` helper to detect 402 and open modal.
    (function () {
        const _origApi = window.api;
        window.api = async function (method, path, body) {
            const result = await _origApi(method, path, body);
            if (result.status === 402) {
                UpgradeModal.open();
            }
            return result;
        };
    })();

    PetiteVue.createApp({ UpgradeModal }).mount('#upgrade-modal');
</script>
@endpush
