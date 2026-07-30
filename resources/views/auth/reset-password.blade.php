@extends('pages.layouts.blank')

@section('seo')
    <title>Nouveau mot de passe — ELChat</title>
    <meta name="robots" content="noindex">
@endsection

@section('main-content')
    <main class="min-h-screen flex flex-col items-center justify-center pt-16 pb-12 px-6 bg-gray-50">

        <div class="fixed top-0 right-0 -z-10 w-1/3 h-full bg-gradient-to-l from-blue-50 to-transparent"></div>
        <div class="fixed bottom-0 left-0 -z-10 w-1/4 h-1/2 bg-gradient-to-tr from-indigo-50 to-transparent blur-3xl"></div>

        <div class="w-full max-w-xl bg-white rounded-2xl shadow-xl p-10 relative">

            <div class="mb-8">
                <h1 class="text-2xl font-bold text-gray-900 mb-2 tracking-tight">Nouveau mot de passe</h1>
                <p class="text-gray-500 text-sm leading-relaxed">
                    Entrez le code reçu par email et créez un nouveau mot de passe sécurisé.
                </p>
            </div>

            {{-- Alert --}}
            <div id="elc-alert" class="hidden mb-6 p-4 rounded-xl text-sm"></div>

            <form id="elc-reset-form" class="space-y-7" novalidate>
                @csrf

                {{-- Code à 6 chiffres --}}
                <div>
                    <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-widest mb-3">
                        Code reçu par email
                    </label>
                    <div class="grid grid-cols-6 gap-3">
                        @for($i = 0; $i < 6; $i++)
                            <input type="text" maxlength="1" placeholder="•" inputmode="text"
                                   class="elc-code-input w-full aspect-square text-center text-2xl font-bold bg-gray-50 border border-gray-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-400 outline-none transition-all"
                                   autocomplete="one-time-code"/>
                        @endfor
                    </div>
                </div>

                {{-- Nouveau mot de passe --}}
                <div class="space-y-2">
                    <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-widest">
                        Nouveau mot de passe
                    </label>
                    <div class="relative">
                        <input id="password" name="password" type="password" required placeholder="••••••••••••"
                               class="w-full bg-white border border-gray-200 rounded-2xl px-5 py-4 outline-none focus:ring-4 focus:ring-blue-100 focus:border-blue-400 transition-all text-gray-900 placeholder:text-gray-400"/>
                        <button type="button" id="elc-toggle-pwd"
                                class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-blue-600 transition-colors">
                            <span class="material-symbols-outlined">visibility</span>
                        </button>
                    </div>
                </div>

                {{-- Confirmer --}}
                <div class="space-y-2">
                    <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-widest">
                        Confirmer le mot de passe
                    </label>
                    <div class="relative">
                        <input id="password_confirmation" name="password_confirmation" type="password" required placeholder="••••••••••••"
                               class="w-full bg-white border border-gray-200 rounded-2xl px-5 py-4 outline-none focus:ring-4 focus:ring-blue-100 focus:border-blue-400 transition-all text-gray-900 placeholder:text-gray-400"/>
                        <button type="button" id="elc-toggle-confirm"
                                class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-blue-600 transition-colors">
                            <span class="material-symbols-outlined">visibility</span>
                        </button>
                    </div>
                </div>

                {{-- Checklist sécurité --}}
                <div class="bg-gray-50 border border-gray-100 rounded-xl p-5 space-y-3">
                    @php
                        $requirements = [
                            'length'    => 'Au moins 8 caractères',
                            'special'   => 'Un caractère spécial (@, #, !, ...)',
                            'uppercase' => 'Une lettre majuscule',
                            'number'    => 'Un chiffre',
                        ];
                    @endphp
                    @foreach($requirements as $key => $label)
                        <div class="flex items-center gap-3 text-sm transition-all" id="elc-check-{{ $key }}">
                            <span class="material-symbols-outlined text-sm text-gray-300" id="elc-check-icon-{{ $key }}">circle</span>
                            <span class="text-gray-500">{{ $label }}</span>
                        </div>
                    @endforeach
                </div>

                {{-- Submit --}}
                <button type="submit" id="elc-submit"
                        class="w-full bg-blue-600 text-white font-bold py-4 rounded-xl hover:bg-blue-700 hover:scale-[1.02] active:scale-95 transition-all text-lg flex items-center justify-center gap-2 disabled:opacity-60">
                    <span id="elc-submit-label">Mettre à jour le mot de passe</span>
                    <span id="elc-submit-spinner" class="hidden w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                </button>
            </form>

            {{-- Déco --}}
            <div class="mt-10 flex items-center justify-center gap-4 opacity-20">
                <div class="w-10 h-0.5 bg-blue-600"></div>
                <div class="w-2 h-2 rounded-full bg-blue-600"></div>
                <div class="w-10 h-0.5 bg-blue-600"></div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <script src="{{ asset('assets/js/elchat-api.js') }}"></script>
    <script>
        const EMAIL = new URLSearchParams(window.location.search).get('email') || '';
        if (!EMAIL) window.location.href = '/mot-de-passe-oublie';

        // ── Code inputs ───────────────────────────────────────────────────────────────
        const codeInputs = document.querySelectorAll('.elc-code-input');
        codeInputs.forEach((input, idx) => {
            input.addEventListener('input', e => {
                e.target.value = e.target.value.toUpperCase().slice(-1);
                if (e.target.value && idx < codeInputs.length - 1) codeInputs[idx + 1].focus();
            });
            input.addEventListener('keydown', e => {
                if (e.key === 'Backspace' && !e.target.value && idx > 0) codeInputs[idx - 1].focus();
            });
            input.addEventListener('paste', e => {
                e.preventDefault();
                const pasted = (e.clipboardData.getData('text') || '').toUpperCase().replace(/\s/g, '');
                [...pasted].forEach((char, i) => { if (codeInputs[idx + i]) codeInputs[idx + i].value = char; });
            });
        });
        function getCode() { return [...codeInputs].map(i => i.value).join(''); }

        // ── Password toggles ──────────────────────────────────────────────────────────
        [['elc-toggle-pwd','password'],['elc-toggle-confirm','password_confirmation']].forEach(([btnId, inputId]) => {
            document.getElementById(btnId).addEventListener('click', () => {
                const input = document.getElementById(inputId);
                const isText = input.type === 'text';
                input.type = isText ? 'password' : 'text';
                document.querySelector(`#${btnId} span`).textContent = isText ? 'visibility' : 'visibility_off';
            });
        });

        // ── Password requirements ─────────────────────────────────────────────────────
        const checks = { length: false, special: false, uppercase: false, number: false };

        document.getElementById('password').addEventListener('input', e => {
            const v = e.target.value;
            checks.length    = v.length >= 8;
            checks.special   = /[@#!$%^&*(),.?":{}|<>]/.test(v);
            checks.uppercase = /[A-Z]/.test(v);
            checks.number    = /[0-9]/.test(v);

            Object.entries(checks).forEach(([key, ok]) => {
                const row  = document.getElementById(`elc-check-${key}`);
                const icon = document.getElementById(`elc-check-icon-${key}`);
                icon.textContent = ok ? 'check_circle' : 'circle';
                icon.className   = `material-symbols-outlined text-sm transition-all ${ok ? 'text-green-500' : 'text-gray-300'}`;
                row.querySelector('span:last-child').className = `text-sm ${ok ? 'text-green-600 font-medium' : 'text-gray-500'}`;
            });
        });

        // ── Alert ─────────────────────────────────────────────────────────────────────
        function showAlert(msg, type = 'error') {
            const styles = {
                error:   'bg-red-50 border border-red-200 text-red-800',
                warning: 'bg-amber-50 border border-amber-200 text-amber-800',
                success: 'bg-green-50 border border-green-200 text-green-800',
                info:    'bg-blue-50 border border-blue-200 text-blue-800',
            };
            const el = document.getElementById('elc-alert');
            el.className = `mb-6 p-4 rounded-xl text-sm ${styles[type]}`;
            el.innerHTML = msg;
            el.classList.remove('hidden');
        }

        function setLoading(on) {
            document.getElementById('elc-submit').disabled = on;
            document.getElementById('elc-submit-label').textContent = on ? 'Mise à jour...' : 'Mettre à jour le mot de passe';
            document.getElementById('elc-submit-spinner').classList.toggle('hidden', !on);
        }

        // ── Submit ────────────────────────────────────────────────────────────────────
        document.getElementById('elc-reset-form').addEventListener('submit', async (e) => {
            e.preventDefault();

            const code                  = getCode();
            const password              = document.getElementById('password').value;
            const password_confirmation = document.getElementById('password_confirmation').value;

            if (code.length < 6)            { showAlert('⚠️ Veuillez entrer le code à 6 caractères.', 'warning'); return; }
            if (!Object.values(checks).every(Boolean)) { showAlert('⚠️ Le mot de passe ne remplit pas tous les critères.', 'warning'); return; }
            if (password !== password_confirmation)    { showAlert('❌ Les mots de passe ne correspondent pas.'); return; }

            setLoading(true);
            document.getElementById('elc-alert').classList.add('hidden');

            try {
                await ElChatAPI.auth.resetPassword({ email: EMAIL, code, password, password_confirmation });
                showAlert('✅ Mot de passe réinitialisé avec succès ! Redirection...', 'success');
                setTimeout(() => { window.location.href = '/connexion'; }, 1500);
            } catch (err) {
                setLoading(false);
                const status = err.response?.status;
                if (status === 429) {
                    showAlert('⚠️ Code bloqué après trop de tentatives. Demandez un nouveau code.', 'warning');
                } else if (status === 422) {
                    showAlert('❌ ' + (err.response?.data?.message || 'Code invalide ou expiré.'));
                } else {
                    showAlert('❌ Erreur serveur. Veuillez réessayer.');
                }
            }
        });
    </script>
@endsection
