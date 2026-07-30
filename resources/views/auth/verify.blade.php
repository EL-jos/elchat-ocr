@extends('pages.layouts.blank')

@section('seo')
    <title>Vérification email — ELChat</title>
    <meta name="robots" content="noindex">
@endsection

@section('main-content')
    <main class="flex min-h-screen items-center justify-center p-4 bg-gray-50">

        <section class="w-full max-w-lg bg-white rounded-3xl shadow-xl overflow-hidden py-16 px-10">
            <div class="w-full max-w-sm mx-auto">

                {{-- Nav haut --}}
                <div class="flex items-center justify-between mb-14">
                    <a href="{{ route('auth.register') }}"
                       class="flex items-center gap-2 text-gray-400 hover:text-blue-600 transition-colors text-sm">
                        <span class="material-symbols-outlined text-lg">arrow_back</span>
                        <span class="font-semibold uppercase tracking-wider text-[10px]">Retour à l'inscription</span>
                    </a>
                    <span class="material-symbols-outlined text-blue-600 text-2xl" style="font-variation-settings:'FILL' 1">verified_user</span>
                </div>

                {{-- Header --}}
                <div class="mb-10">
                    <h1 class="text-4xl font-bold text-gray-900 tracking-tight mb-4">Vérifiez votre email</h1>
                    <p class="text-gray-500 leading-relaxed">
                        Un code à 6 chiffres a été envoyé à<br>
                        <strong class="text-gray-800" id="elc-email-display">{{ $email }}</strong><br>
                        Il est valable pendant <strong class="text-gray-800">5 minutes.</strong>
                    </p>
                </div>

                {{-- Alert --}}
                <div id="elc-alert" class="hidden mb-6 p-4 rounded-xl text-sm"></div>

                <form id="elc-verify-form" class="space-y-10" novalidate>
                    @csrf

                    {{-- Code à 6 chiffres --}}
                    <div class="grid grid-cols-6 gap-3">
                        @for($i = 0; $i < 6; $i++)
                            <input type="text" maxlength="1" placeholder="•"
                                   class="elc-code-input w-full aspect-square text-center text-2xl font-bold bg-gray-50 border border-gray-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-400 outline-none transition-all"
                                   autocomplete="one-time-code" inputmode="text"/>
                        @endfor
                    </div>

                    {{-- Timer + Renvoyer --}}
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2 text-sm text-gray-500">
                            <span class="material-symbols-outlined text-blue-600 text-lg">schedule</span>
                            <span id="elc-timer" class="font-bold text-gray-700 tabular-nums">05:00</span>
                        </div>
                        <button type="button" id="elc-resend-btn"
                                class="text-[11px] font-bold uppercase tracking-widest text-blue-600 hover:text-blue-800 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                disabled>
                            Renvoyer le code
                        </button>
                    </div>

                    {{-- Submit --}}
                    <button type="submit" id="elc-submit"
                            class="w-full bg-blue-600 text-white text-lg font-bold py-4 rounded-full shadow-lg shadow-blue-200 hover:bg-blue-700 hover:scale-[1.02] active:scale-95 transition-all flex items-center justify-center gap-2 disabled:opacity-60">
                        <span id="elc-submit-label">Vérifier</span>
                        <span id="elc-submit-spinner" class="hidden w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                    </button>

                    <p class="text-center text-sm text-gray-400">
                        Besoin d'aide ?
                        <a href="mailto:contact@elchat.io" class="text-blue-600 font-semibold hover:underline">Contacter le support</a>
                    </p>
                </form>
            </div>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <script src="{{ asset('assets/js/elchat-api.js') }}"></script>
    <script>
        const EMAIL = '{{ $email }}';

        // ── Code inputs navigation ────────────────────────────────────────────────────
        const codeInputs = document.querySelectorAll('.elc-code-input');

        codeInputs.forEach((input, idx) => {
            input.addEventListener('input', e => {
                e.target.value = e.target.value.toUpperCase().slice(-1);
                if (e.target.value && idx < codeInputs.length - 1) {
                    codeInputs[idx + 1].focus();
                }
            });
            input.addEventListener('keydown', e => {
                if (e.key === 'Backspace' && !e.target.value && idx > 0) {
                    codeInputs[idx - 1].focus();
                }
            });
            input.addEventListener('paste', e => {
                e.preventDefault();
                const pasted = (e.clipboardData.getData('text') || '').toUpperCase().replace(/\s/g, '');
                [...pasted].forEach((char, i) => {
                    if (codeInputs[idx + i]) codeInputs[idx + i].value = char;
                });
                const nextEmpty = [...codeInputs].findIndex(el => !el.value);
                if (nextEmpty !== -1) codeInputs[nextEmpty].focus();
            });
        });

        function getCode() {
            return [...codeInputs].map(i => i.value).join('');
        }

        // ── Timer ────────────────────────────────────────────────────────────────────
        let timerSeconds = 300; // 5 minutes
        let timerInterval;

        function startTimer(seconds) {
            timerSeconds = seconds;
            clearInterval(timerInterval);
            document.getElementById('elc-resend-btn').disabled = true;

            timerInterval = setInterval(() => {
                timerSeconds--;
                const m = Math.floor(timerSeconds / 60).toString().padStart(2, '0');
                const s = (timerSeconds % 60).toString().padStart(2, '0');
                document.getElementById('elc-timer').textContent = `${m}:${s}`;

                if (timerSeconds <= 0) {
                    clearInterval(timerInterval);
                    document.getElementById('elc-timer').textContent = '00:00';
                    document.getElementById('elc-resend-btn').disabled = false;
                }
            }, 1000);
        }

        startTimer(300);

        // ── Alert ────────────────────────────────────────────────────────────────────
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
            const btn = document.getElementById('elc-submit');
            btn.disabled = on;
            document.getElementById('elc-submit-label').textContent = on ? 'Vérification...' : 'Vérifier';
            document.getElementById('elc-submit-spinner').classList.toggle('hidden', !on);
        }

        // ── Submit verification ───────────────────────────────────────────────────────
        document.getElementById('elc-verify-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const code = getCode();

            if (code.length < 6) {
                showAlert('⚠️ Veuillez entrer les 6 caractères du code.');
                return;
            }

            setLoading(true);
            document.getElementById('elc-alert').classList.add('hidden');

            try {
                await ElChatAPI.auth.verifyEmail(EMAIL, code);
                showAlert('✅ Email vérifié ! Redirection...', 'success');
                setTimeout(() => { window.location.href = '/app'; }, 1200);
            } catch (err) {
                setLoading(false);
                const status = err.response?.status;
                const error  = err.response?.data?.error;

                if (status === 429 || error === 'too_many_attempts_new_code_sent') {
                    showAlert('⚠️ Trop de tentatives. Un nouveau code a été envoyé.', 'warning');
                    codeInputs.forEach(i => i.value = '');
                    codeInputs[0].focus();
                    startTimer(300);
                } else if (error === 'code_expired') {
                    showAlert('⏰ Code expiré. Cliquez sur "Renvoyer le code".', 'warning');
                } else {
                    const remaining = err.response?.data?.remaining_attempts;
                    showAlert(`❌ Code incorrect.${remaining ? ` Il vous reste ${remaining} tentative(s).` : ''}`);
                }
            }
        });

        // ── Renvoyer le code ─────────────────────────────────────────────────────────
        document.getElementById('elc-resend-btn').addEventListener('click', async () => {
            document.getElementById('elc-resend-btn').disabled = true;
            document.getElementById('elc-alert').classList.add('hidden');

            try {
                const res = await ElChatAPI.auth.resendCode(EMAIL);
                showAlert('📧 ' + (res.message || 'Nouveau code envoyé !'), 'info');
                const expiresIn = (res.expires_in ?? 1) * 60;
                startTimer(expiresIn);
                codeInputs.forEach(i => i.value = '');
                codeInputs[0].focus();
            } catch (err) {
                document.getElementById('elc-resend-btn').disabled = false;
                if (err.response?.status === 429) {
                    showAlert('⏳ Veuillez patienter avant de renvoyer un nouveau code.', 'warning');
                } else {
                    showAlert('❌ Erreur lors du renvoi. Veuillez réessayer.');
                }
            }
        });
    </script>
@endsection
