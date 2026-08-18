@extends('pages.layouts.blank')

@section('seo')
    <title>Connexion — ELChat</title>
    <meta name="robots" content="noindex">
@endsection

@section('main-content')
    <main class="min-h-screen flex flex-col md:flex-row p-4 md:p-6 gap-6">

        {{-- ── Image gauche ── --}}
        <section class="relative hidden md:flex w-full md:w-1/2 min-h-[400px] rounded-xl overflow-hidden group shadow-2xl">
            <img alt="ELChat IA"
                 class="absolute inset-0 w-full h-full object-cover transition-transform duration-700 group-hover:scale-110"
                 src="https://lh3.googleusercontent.com/aida-public/AB6AXuCoDmHe_kqXCGbks6i_uoEc4lJIPuLHVdg1Yq9WlOo7UTY-st7mxDih25wwR_GUysH9U_ZJKRp-YUJ7iBGxKM6W7zm1kOsRemJGyz_5QZt1ouDE_nM2JHoCvw5spyvW1j1pxiheTI4pN1A37sYGy4glmNT6H9biHTqp1QRLP8aJAWdJhtTA8tq7xgIkpMzKv47jLDkPbuXBw3O9HNLWb35ob7Y9GVMts_eCJindd5_Nk3obaIm7n_0hx933Jsg6xpk46iBwCc4A203A"/>
            <div class="absolute inset-0 bg-gradient-to-t from-blue-900/70 to-transparent"></div>
            <div class="relative mt-auto p-12 max-w-lg">
                <div class="mb-8">
                    <span class="text-white font-black text-3xl tracking-tighter">EL<span class="text-blue-400">Chat</span></span>
                </div>
                <h1 class="text-white font-bold text-5xl leading-tight mb-4">Heureux de vous revoir</h1>
                <p class="text-white/80 text-lg leading-relaxed">Accédez à votre espace ELChat en toute sécurité.</p>
            </div>
            <div class="absolute top-12 right-12 bg-white/10 backdrop-blur-md border border-white/20 px-6 py-4 rounded-lg flex items-center gap-3">
                <div class="w-2 h-2 bg-blue-400 rounded-full animate-pulse"></div>
                <span class="text-[10px] uppercase tracking-widest font-bold text-white">ELChat Live</span>
            </div>
        </section>

        {{-- ── Formulaire droite ── --}}
        <section class="flex-1 flex flex-col justify-center items-center bg-white rounded-xl p-8 md:p-16 shadow-sm">
            <div class="w-full max-w-md">

                {{-- Logo mobile --}}
                <div class="md:hidden mb-12">
                    <span class="text-3xl font-black text-blue-600 tracking-tighter uppercase">ELChat</span>
                </div>

                {{-- Header --}}
                <div class="mb-10">
                    <h2 class="text-4xl font-extrabold text-gray-900 tracking-tight mb-3">Bon retour 👋</h2>
                    <p class="text-gray-500">Connectez-vous à ELChat pour accéder à votre assistant IA.</p>
                </div>

                {{-- Banners --}}
                @if($message ?? null)
                    <div class="mb-6 p-4 bg-amber-50 border border-amber-200 rounded-lg text-amber-800 text-sm flex items-center gap-2">
                        ⚠️ {{ $message }}
                    </div>
                @endif
                <div id="elc-alert" class="hidden mb-6 p-4 rounded-lg text-sm flex items-center gap-2"></div>

                {{-- Formulaire --}}
                <form id="elc-login-form" class="space-y-7" novalidate>
                    @csrf
                    {{-- Email --}}
                    <div class="space-y-2">
                        <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-widest" for="email">
                            Email professionnel
                        </label>
                        <div class="relative">
                            <input id="email" name="email" type="email" required autocomplete="email"
                                   placeholder="nom@entreprise.com"
                                   class="w-full h-14 px-5 bg-gray-50 border border-gray-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-400 outline-none transition-all text-gray-900 placeholder:text-gray-400"/>
                            <span class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 material-symbols-outlined text-lg">mail</span>
                        </div>
                    </div>

                    {{-- Mot de passe --}}
                    <div class="space-y-2">
                        <div class="flex justify-between items-center">
                            <label class="text-[11px] font-bold text-gray-500 uppercase tracking-widest" for="password">
                                Mot de passe
                            </label>
                            <a href="{{ route('auth.forgot-password') }}"
                               class="text-[11px] font-bold text-blue-600 hover:text-blue-800 uppercase tracking-widest transition-colors">
                                Oublié ?
                            </a>
                        </div>
                        <div class="relative">
                            <input id="password" name="password" type="password" required autocomplete="current-password"
                                   placeholder="••••••••"
                                   class="w-full h-14 px-5 bg-gray-50 border border-gray-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-400 outline-none transition-all text-gray-900 placeholder:text-gray-400"/>
                            <button type="button" id="elc-toggle-pwd"
                                    class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-blue-600 transition-colors">
                                <span class="material-symbols-outlined text-lg">visibility</span>
                            </button>
                        </div>
                    </div>

                    {{-- CTA --}}
                    <div class="pt-2">
                        <button type="submit" id="elc-submit"
                                class="w-full py-4 bg-blue-600 text-white font-extrabold text-lg rounded-full shadow-lg shadow-blue-200 hover:bg-blue-700 hover:scale-[1.02] active:scale-95 transition-all duration-200 flex items-center justify-center gap-3 disabled:opacity-60 disabled:cursor-not-allowed">
                            <span id="elc-submit-label">Se connecter</span>
                            <span id="elc-submit-spinner" class="hidden w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                            <span id="elc-submit-arrow" class="material-symbols-outlined">arrow_forward</span>
                        </button>
                    </div>
                </form>

                {{-- Signup link --}}
                <div class="mt-10 text-center">
                    <p class="text-gray-500 text-sm">
                        Pas encore de compte ?
                        <a href="{{ route('auth.register') }}" class="text-blue-600 font-bold hover:underline ml-1">S'inscrire</a>
                    </p>
                </div>
            </div>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <script src="{{ asset('assets/js/elchat-api.js') }}"></script>
    <script>
        // ── Toggle password ───────────────────────────────────────────────────────────
        const pwdInput  = document.getElementById('password');
        const toggleBtn = document.getElementById('elc-toggle-pwd');
        toggleBtn.addEventListener('click', () => {
            const isText = pwdInput.type === 'text';
            pwdInput.type = isText ? 'password' : 'text';
            toggleBtn.querySelector('span').textContent = isText ? 'visibility' : 'visibility_off';
        });

        // ── Helpers UI ────────────────────────────────────────────────────────────────
        function setLoading(on) {
            const btn    = document.getElementById('elc-submit');
            const label  = document.getElementById('elc-submit-label');
            const spin   = document.getElementById('elc-submit-spinner');
            const arrow  = document.getElementById('elc-submit-arrow');
            btn.disabled = on;
            label.textContent = on ? 'Connexion...' : 'Se connecter';
            spin.classList.toggle('hidden', !on);
            arrow.classList.toggle('hidden', on);
        }

        function showAlert(msg, type = 'error') {
            const el = document.getElementById('elc-alert');
            const styles = {
                error:   'bg-red-50 border border-red-200 text-red-800',
                warning: 'bg-amber-50 border border-amber-200 text-amber-800',
                info:    'bg-blue-50 border border-blue-200 text-blue-800',
                success: 'bg-green-50 border border-green-200 text-green-800',
            };
            el.className = `mb-6 p-4 rounded-lg text-sm flex items-center gap-2 ${styles[type] || styles.error}`;
            el.innerHTML = msg;
            el.classList.remove('hidden');
        }

        // ── Submit ────────────────────────────────────────────────────────────────────
        document.getElementById('elc-login-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const email    = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;

            if (!email || !password) {
                showAlert('⚠️ Veuillez remplir tous les champs.');
                return;
            }

            setLoading(true);
            document.getElementById('elc-alert').classList.add('hidden');

            try {
                await ElChatAPI.auth.login(email, password);
                // Redirect vers l'app (ou l'URL intended)
                window.location.href = '{{ session("url.intended", "/app") }}';
            } catch (err) {
                setLoading(false);
                const status = err.response?.status;
                const error  = err.response?.data?.error;

                if (status === 403 && error === 'account_not_verified') {
                    showAlert('⚠️ Votre compte n\'est pas vérifié. Redirection...', 'warning');
                    setTimeout(() => {
                        window.location.href = `/verification?email=${encodeURIComponent(email)}`;
                    }, 1500);
                } else if (status === 401) {
                    showAlert('❌ Email ou mot de passe incorrect.');
                } else {
                    showAlert('❌ Erreur serveur. Veuillez réessayer.');
                }
            }
        });
    </script>
@endsection
