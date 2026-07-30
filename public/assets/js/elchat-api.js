/**
 * ELChat API Service — Blade pages
 * Gère toutes les requêtes vers l'API JWT Laravel.
 *
 * Inclusion dans les vues Blade :
 *   <script src="{{ asset('assets/js/elchat-api.js') }}"></script>
 *
 * Dépendance : Axios (CDN ou npm)
 *   <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
 */

const ElChatAPI = (() => {

    // ── Config ────────────────────────────────────────────────────────────────
    const API_BASE  = window.ELCHAT_API_URL || '/api/v1';
    const TOKEN_KEY = 'elchat_jwt';
    const USER_KEY  = 'elchat_user';

    // ── Instance Axios ────────────────────────────────────────────────────────
    const http = axios.create({
        baseURL : API_BASE,
        timeout : 15000,
        headers : { 'Content-Type': 'application/json', 'Accept': 'application/json' },
    });

    // ── Intercepteur requête : injecter le token ──────────────────────────────
    http.interceptors.request.use(config => {
        const token = getToken();
        if (token) {
            config.headers['Authorization'] = `Bearer ${token}`;
        }
        return config;
    });

    // ── Intercepteur réponse : gérer le refresh et les erreurs ───────────────
    http.interceptors.response.use(
        response => {
            // Si le backend a rafraîchi le token automatiquement
            const newToken = response.headers['x-new-token'];
            if (newToken) {
                storeToken(newToken);
            }
            return response;
        },
        async error => {
            const status = error.response?.status;
            const code   = error.response?.data?.error;

            // Token expiré → tenter refresh
            if (status === 401 && code === 'token_expired') {
                try {
                    const refreshRes = await http.post('/refresh-token');
                    const newToken   = refreshRes.data.token;
                    storeToken(newToken);

                    // Rejouer la requête originale
                    error.config.headers['Authorization'] = `Bearer ${newToken}`;
                    return http.request(error.config);
                } catch (refreshErr) {
                    clearAuth();
                    redirectToLogin('Votre session a expiré.');
                    return Promise.reject(refreshErr);
                }
            }

            // Token invalide ou manquant → déconnexion
            if (status === 401 && ['token_invalid', 'unauthenticated'].includes(code)) {
                clearAuth();
                redirectToLogin();
            }

            return Promise.reject(error);
        }
    );

    // ── Token storage ─────────────────────────────────────────────────────────

    function storeToken(token) {
        localStorage.setItem(TOKEN_KEY, token);
        // Aussi en session via appel Blade pour le middleware Laravel
        syncTokenToSession(token);
    }

    function getToken() {
        return localStorage.getItem(TOKEN_KEY);
    }

    function storeUser(user) {
        localStorage.setItem(USER_KEY, JSON.stringify(user));
    }

    function getUser() {
        try {
            const u = localStorage.getItem(USER_KEY);
            return u ? JSON.parse(u) : null;
        } catch { return null; }
    }

    function clearAuth() {
        localStorage.removeItem(TOKEN_KEY);
        localStorage.removeItem(USER_KEY);
    }

    /**
     * Synchronise le token JWT avec la session Laravel
     * pour que le middleware JwtAuthMiddleware puisse le lire
     * lors des requêtes de navigation Blade normales (GET /tarifs, etc.)
     */
    function syncTokenToSession(token) {
        fetch('/auth/sync-token', {
            method  : 'POST',
            headers : {
                'Content-Type'    : 'application/json',
                'X-CSRF-TOKEN'    : document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept'          : 'application/json',
            },
            body: JSON.stringify({ token }),
        }).catch(() => {}); // Silencieux — non bloquant
    }

    function redirectToLogin(message) {
        const url = new URL('/connexion', window.location.origin);
        if (message) url.searchParams.set('message', message);
        window.location.href = url.toString();
    }

    // ── Auth endpoints ────────────────────────────────────────────────────────

    const auth = {

        /**
         * Connexion
         * POST /api/v1/login
         */
        login(email, password) {
            return http.post('/login', { email, password }).then(res => {
                storeToken(res.data.token);
                storeUser(res.data.user);
                return res.data;
            });
        },

        /**
         * Inscription
         * POST /api/v1/register
         */
        register(payload) {
            return http.post('/register', payload).then(res => res.data);
        },

        /**
         * Vérification du code email
         * POST /api/v1/verify-code
         */
        verifyEmail(email, code) {
            return http.post('/verify-code', { email, code }).then(res => {
                storeToken(res.data.token);
                storeUser(res.data.user);
                return res.data;
            });
        },

        /**
         * Renvoi du code de vérification
         * POST /api/v1/resend-code
         */
        resendCode(email) {
            return http.post('/resend-code', { email }).then(res => res.data);
        },

        /**
         * Mot de passe oublié
         * POST /api/v1/forgot-password
         */
        forgotPassword(email) {
            return http.post('/forgot-password', { email }).then(res => res.data);
        },

        /**
         * Réinitialisation du mot de passe
         * POST /api/v1/reset-password
         */
        resetPassword(payload) {
            return http.post('/reset-password', payload).then(res => res.data);
        },

        /**
         * Déconnexion
         * POST /api/v1/logout
         */
        logout() {
            return http.post('/logout').finally(() => {
                clearAuth();
                fetch('/auth/clear-session', {
                    method : 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                }).finally(() => {
                    window.location.href = '/connexion';
                });
            });
        },

        /**
         * Vérifie si l'utilisateur est connecté (côté client).
         */
        isAuthenticated() {
            return !!getToken();
        },

        /**
         * Retourne l'utilisateur stocké localement.
         */
        currentUser() {
            return getUser();
        },
    };

    // ── Subscription endpoints ────────────────────────────────────────────────

    const subscription = {

        /**
         * Infos d'abonnement courant (pour Angular ou Blade)
         * GET /api/subscription
         */
        getCurrent() {
            return http.get('/subscription').then(res => res.data);
        },
    };

    // ── Public API ────────────────────────────────────────────────────────────

    return { auth, subscription, http, getToken, getUser, clearAuth };

})();

// Exposition globale pour usage inline dans les vues Blade
window.ElChatAPI = ElChatAPI;
