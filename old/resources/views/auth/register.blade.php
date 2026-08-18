@extends('pages.layouts.blank')

@section('seo')
    <title>Inscription — ELChat</title>
    <meta name="robots" content="noindex">
@endsection

@section('main-content')
    <main class="min-h-screen flex flex-col md:flex-row p-4 md:p-6 gap-6">

        {{-- ── Formulaire gauche ── --}}
        <section class="flex-1 flex flex-col justify-center items-center bg-white rounded-xl p-8 md:p-12 shadow-sm">
            <div class="w-full max-w-md">

                <div class="md:hidden mb-10 text-center">
                    <span class="text-3xl font-black text-blue-600 tracking-tighter uppercase">ELChat</span>
                </div>

                <div class="mb-8">
                    <h2 class="text-3xl font-bold text-gray-900 mb-2">Bienvenue chez ELChat</h2>
                    <p class="text-gray-500 text-sm">Donnez à votre site web un agent intelligent disponible 24/7.</p>
                </div>

                {{-- Alert --}}
                <div id="elc-alert" class="hidden mb-5 p-4 rounded-lg text-sm"></div>

                <form id="elc-register-form" class="space-y-5" novalidate>
                    @csrf

                    {{-- Prénom + Nom --}}
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <label class="text-[10px] font-bold text-gray-500 uppercase tracking-widest block" for="firstname">Prénom</label>
                            <input id="firstname" name="firstname" type="text" required placeholder="Jean"
                                   class="w-full h-12 px-4 bg-gray-50 border border-gray-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-400 outline-none transition-all text-gray-900 text-sm placeholder:text-gray-400"/>
                        </div>
                        <div class="space-y-1">
                            <label class="text-[10px] font-bold text-gray-500 uppercase tracking-widest block" for="lastname">Nom</label>
                            <input id="lastname" name="lastname" type="text" required placeholder="Dupont"
                                   class="w-full h-12 px-4 bg-gray-50 border border-gray-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-400 outline-none transition-all text-gray-900 text-sm placeholder:text-gray-400"/>
                        </div>
                    </div>

                    {{-- Nom du compte --}}
                    <div class="space-y-1">
                        <label class="text-[10px] font-bold text-gray-500 uppercase tracking-widest block" for="account_name">Nom du compte</label>
                        <input id="account_name" name="account_name" type="text" required placeholder="Mon Entreprise SAS"
                               class="w-full h-12 px-4 bg-gray-50 border border-gray-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-400 outline-none transition-all text-gray-900 text-sm placeholder:text-gray-400"/>
                    </div>

                    {{-- Email + Téléphone --}}
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <label class="text-[10px] font-bold text-gray-500 uppercase tracking-widest block" for="email">Email</label>
                            <input id="email" name="email" type="email" required placeholder="admin@entreprise.com" autocomplete="email"
                                   class="w-full h-12 px-4 bg-gray-50 border border-gray-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-400 outline-none transition-all text-gray-900 text-sm placeholder:text-gray-400"/>
                        </div>
                        <div class="space-y-1">
                            <label class="text-[10px] font-bold text-gray-500 uppercase tracking-widest block" for="phone">Téléphone</label>
                            <input id="phone" name="phone" type="tel" required placeholder="+33 1 23 45 67 89"
                                   class="w-full h-12 px-4 bg-gray-50 border border-gray-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-400 outline-none transition-all text-gray-900 text-sm placeholder:text-gray-400"/>
                        </div>
                    </div>

                    {{-- Mot de passe + Confirmation --}}
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <label class="text-[10px] font-bold text-gray-500 uppercase tracking-widest block" for="password">Mot de passe</label>
                            <div class="relative">
                                <input id="password" name="password" type="password" required placeholder="••••••••••"
                                       autocomplete="new-password"
                                       class="w-full h-12 px-4 pr-10 bg-gray-50 border border-gray-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-400 outline-none transition-all text-gray-900 text-sm placeholder:text-gray-400"/>
                                <button type="button" class="elc-toggle-pwd absolute right-3 top-1/2 -translate-y-1/2 text-gray-400" data-target="password">
                                    <span class="material-symbols-outlined text-base">visibility</span>
                                </button>
                            </div>
                        </div>
                        <div class="space-y-1">
                            <label class="text-[10px] font-bold text-gray-500 uppercase tracking-widest block" for="password_confirmation">Confirmer</label>
                            <div class="relative">
                                <input id="password_confirmation" name="password_confirmation" type="password" required placeholder="••••••••••"
                                       autocomplete="new-password"
                                       class="w-full h-12 px-4 pr-10 bg-gray-50 border border-gray-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-400 outline-none transition-all text-gray-900 text-sm placeholder:text-gray-400"/>
                                <button type="button" class="elc-toggle-pwd absolute right-3 top-1/2 -translate-y-1/2 text-gray-400" data-target="password_confirmation">
                                    <span class="material-symbols-outlined text-base">visibility</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- CTA --}}
                    <div class="pt-2">
                        <button type="submit" id="elc-submit"
                                class="w-full bg-blue-600 text-white font-bold py-4 rounded-full shadow-lg shadow-blue-200 hover:bg-blue-700 hover:scale-[1.02] active:scale-95 transition-all flex justify-center items-center gap-2 disabled:opacity-60">
                            <span id="elc-submit-label">S'inscrire</span>
                            <span id="elc-submit-spinner" class="hidden w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                            <span id="elc-submit-arrow" class="material-symbols-outlined text-lg">arrow_forward</span>
                        </button>
                    </div>
                </form>

                <div class="mt-8 text-center">
                    <p class="text-gray-500 text-sm">
                        Vous avez déjà un compte ?
                        <a href="{{ route('auth.login') }}" class="text-blue-600 font-bold hover:underline ml-1">Se connecter</a>
                    </p>
                </div>

                <div class="mt-10 flex flex-col items-center gap-3 opacity-40">
                    <div class="h-px w-16 bg-gray-300"></div>
                    <div class="flex gap-6 text-[10px] uppercase tracking-tight text-gray-500">
                        <a href="#" class="hover:text-blue-600">Politique de confidentialité</a>
                        <a href="#" class="hover:text-blue-600">Conditions d'utilisation</a>
                    </div>
                </div>
            </div>
        </section>

        {{-- ── Image droite ── --}}
        <section class="relative hidden md:flex w-full md:w-1/2 min-h-[400px] rounded-xl overflow-hidden group shadow-2xl">
            <img alt="ELChat"
                 class="absolute inset-0 w-full h-full object-cover transition-transform duration-700 group-hover:scale-110"
                 src="https://lh3.googleusercontent.com/aida-public/AB6AXuAiVquLguae1L5VWsSi-XLm12-RWPX1Q-BJhSMZuNqpsPmHmYXyTO3Jf08c6ADAcJ7u7L5ozAu-IyZo-x0sOPwrRqJeWCB3H9L18J5sl60ve-86n6WclRYIixfZcQPGpFLXLFiV4zUzhZyQShbuYn032pSMl5Vt3n20Va_VLjHmj8REZ-Fd1wX2noE94uYz2bxLmP7TzieLYvllwybiZ7ZZXi3wyi7cgEf9mfk5aLh5MBFql2PGmBBrwqN8Fz_TiC6D_vbQv0su6m4y"/>
            <div class="absolute inset-0 bg-gradient-to-t from-blue-900/70 to-transparent"></div>
            <div class="relative mt-auto p-12 max-w-lg">
                <span class="text-white font-black text-3xl tracking-tighter">EL<span class="text-blue-400">Chat</span></span>
                <h1 class="text-white font-bold text-5xl leading-tight mt-4 mb-4">Votre site, boosté par l'IA.</h1>
                <p class="text-white/80 text-lg leading-relaxed">Déployez ELChat sur votre site et offrez à vos utilisateurs une assistance IA disponible 24/7.</p>
            </div>
            <div class="absolute top-12 right-12 bg-white/10 backdrop-blur-md border border-white/20 px-6 py-4 rounded-lg flex items-center gap-3">
                <div class="w-2 h-2 bg-blue-400 rounded-full animate-pulse"></div>
                <span class="text-[10px] uppercase tracking-widest font-bold text-white">ELChat Live</span>
            </div>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <script src="{{ asset('assets/js/elchat-api.js') }}"></script>
    <script>
        // ── Toggle passwords ──────────────────────────────────────────────────────────
        document.querySelectorAll('.elc-toggle-pwd').forEach(btn => {
            btn.addEventListener('click', () => {
                const input = document.getElementById(btn.dataset.target);
                const isText = input.type === 'text';
                input.type = isText ? 'password' : 'text';
                btn.querySelector('span').textContent = isText ? 'visibility' : 'visibility_off';
            });
        });

        // ── Alert ─────────────────────────────────────────────────────────────────────
        function showAlert(msg, type = 'error') {
            const styles = {
                error:   'bg-red-50 border border-red-200 text-red-800',
                warning: 'bg-amber-50 border border-amber-200 text-amber-800',
                info:    'bg-blue-50 border border-blue-200 text-blue-800',
                success: 'bg-green-50 border border-green-200 text-green-800',
            };
            const el = document.getElementById('elc-alert');
            el.className = `mb-5 p-4 rounded-lg text-sm ${styles[type]}`;
            el.innerHTML = msg;
            el.classList.remove('hidden');
        }

        function setLoading(on) {
            const btn = document.getElementById('elc-submit');
            btn.disabled = on;
            document.getElementById('elc-submit-label').textContent = on ? 'Création...' : 'S\'inscrire';
            document.getElementById('elc-submit-spinner').classList.toggle('hidden', !on);
            document.getElementById('elc-submit-arrow').classList.toggle('hidden', on);
        }

        // ── Submit ────────────────────────────────────────────────────────────────────
        document.getElementById('elc-register-form').addEventListener('submit', async (e) => {
            e.preventDefault();

            const payload = {
                firstname:             document.getElementById('firstname').value.trim(),
                lastname:              document.getElementById('lastname').value.trim(),
                account_name:          document.getElementById('account_name').value.trim(),
                email:                 document.getElementById('email').value.trim(),
                phone:                 document.getElementById('phone').value.trim(),
                password:              document.getElementById('password').value,
                password_confirmation: document.getElementById('password_confirmation').value,
                is_admin:              true,
            };

            // Validation client basique
            if (!payload.firstname || !payload.lastname || !payload.email || !payload.password) {
                showAlert('⚠️ Veuillez remplir tous les champs obligatoires.');
                return;
            }

            if (payload.password !== payload.password_confirmation) {
                showAlert('❌ Les mots de passe ne correspondent pas.');
                return;
            }

            if (payload.password.length < 8) {
                showAlert('❌ Le mot de passe doit contenir au moins 8 caractères.');
                return;
            }

            setLoading(true);
            document.getElementById('elc-alert').classList.add('hidden');

            try {
                const res = await ElChatAPI.auth.register(payload);
                showAlert('✅ Compte créé ! Vérifiez votre email pour le code de confirmation.', 'success');
                setTimeout(() => {
                    window.location.href = `/verification?email=${encodeURIComponent(payload.email)}`;
                }, 1500);
            } catch (err) {
                setLoading(false);
                const status = err.response?.status;
                const data   = err.response?.data;

                if (status === 409) {
                    showAlert('⚠️ Un compte existe déjà avec cet email. <a href="{{ route("auth.login") }}" class="font-bold underline">Se connecter</a>', 'warning');
                } else if (status === 422) {
                    const errors = Object.values(data?.errors ?? {}).flat().join('<br>');
                    showAlert('❌ ' + (errors || data?.message || 'Erreur de validation.'));
                } else {
                    showAlert('❌ Erreur serveur. Veuillez réessayer.');
                }
            }
        });
    </script>
@endsection
