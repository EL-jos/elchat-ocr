@extends('pages.layouts.blank')

@section('seo')
    <title>Mot de passe oublié — ELChat</title>
    <meta name="robots" content="noindex">
@endsection

@section('main-content')
    <main class="flex min-h-screen items-center justify-center px-6 py-12 bg-gray-50 relative overflow-hidden">

        <div class="absolute top-[-10%] right-[-5%] w-96 h-96 rounded-full bg-blue-100/50 blur-[120px]"></div>
        <div class="absolute bottom-[-10%] left-[-5%] w-72 h-72 rounded-full bg-indigo-100/50 blur-[100px]"></div>

        <div class="w-full max-w-sm bg-white p-10 rounded-2xl shadow-xl relative z-10 border border-gray-100">

            <div class="mb-8">
                <h1 class="text-2xl font-bold text-gray-900 tracking-tight mb-2">Mot de passe oublié ?</h1>
                <p class="text-gray-500 text-sm leading-relaxed">
                    Entrez votre adresse email pour recevoir un code de réinitialisation valable 15 minutes.
                </p>
            </div>

            {{-- Alert --}}
            <div id="elc-alert" class="hidden mb-5 p-4 rounded-xl text-sm"></div>

            <form id="elc-forgot-form" class="flex flex-col gap-6" novalidate>
                @csrf
                <div class="space-y-2">
                    <label class="text-[11px] font-bold text-gray-500 uppercase tracking-widest block">
                        Adresse email
                    </label>
                    <input id="email" name="email" type="email" required autocomplete="email"
                           placeholder="nom@entreprise.fr"
                           class="w-full h-12 px-5 bg-gray-50 border border-gray-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-400 outline-none transition-all text-gray-900 text-sm placeholder:text-gray-400"/>
                </div>

                <button type="submit" id="elc-submit"
                        class="w-full h-14 bg-blue-600 text-white font-bold text-base rounded-xl hover:bg-blue-700 hover:scale-[1.02] active:scale-95 transition-all flex items-center justify-center gap-2 disabled:opacity-60">
                    <span id="elc-submit-label">Envoyer le code</span>
                    <span id="elc-submit-spinner" class="hidden w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                    <span id="elc-submit-arrow" class="material-symbols-outlined">arrow_forward</span>
                </button>
            </form>

            <div class="mt-8 flex justify-center">
                <a href="{{ route('auth.login') }}"
                   class="flex items-center gap-2 text-[11px] uppercase tracking-widest font-semibold text-gray-400 hover:text-blue-600 transition-colors group">
                    <span class="material-symbols-outlined text-sm group-hover:-translate-x-1 transition-transform">arrow_back</span>
                    Retour à la connexion
                </a>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <script src="{{ asset('assets/js/elchat-api.js') }}"></script>
    <script>
        function showAlert(msg, type = 'info') {
            const styles = {
                error:   'bg-red-50 border border-red-200 text-red-800',
                warning: 'bg-amber-50 border border-amber-200 text-amber-800',
                success: 'bg-green-50 border border-green-200 text-green-800',
                info:    'bg-blue-50 border border-blue-200 text-blue-800',
            };
            const el = document.getElementById('elc-alert');
            el.className = `mb-5 p-4 rounded-xl text-sm ${styles[type]}`;
            el.innerHTML = msg;
            el.classList.remove('hidden');
        }

        function setLoading(on) {
            const btn = document.getElementById('elc-submit');
            btn.disabled = on;
            document.getElementById('elc-submit-label').textContent = on ? 'Envoi...' : 'Envoyer le code';
            document.getElementById('elc-submit-spinner').classList.toggle('hidden', !on);
            document.getElementById('elc-submit-arrow').classList.toggle('hidden', on);
        }

        document.getElementById('elc-forgot-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const email = document.getElementById('email').value.trim();

            if (!email) { showAlert('⚠️ Veuillez entrer votre adresse email.', 'warning'); return; }

            setLoading(true);
            document.getElementById('elc-alert').classList.add('hidden');

            try {
                await ElChatAPI.auth.forgotPassword(email);
                showAlert('📧 Si cet email existe, un code de réinitialisation a été envoyé.', 'success');
                setTimeout(() => {
                    window.location.href = `/nouveau-mot-de-passe?email=${encodeURIComponent(email)}`;
                }, 1800);
            } catch (err) {
                setLoading(false);
                if (err.response?.status === 429) {
                    showAlert('⏳ Veuillez patienter avant de faire une nouvelle demande.', 'warning');
                } else {
                    showAlert('❌ Erreur serveur. Veuillez réessayer.');
                }
            }
        });
    </script>
@endsection
