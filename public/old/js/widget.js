(function () {
    const SCRIPT_TAG = document.currentScript;
    if (!SCRIPT_TAG) {
        console.error('[ELChat] Impossible de détecter la balise script');
        return;
    }
    // 1️⃣ Méthode data attribute
    let SITE_ID = SCRIPT_TAG.getAttribute('data-site-id');
    // 2️⃣ Fallback via paramètre d’URL
    if (!SITE_ID) {
        try {
            const url = new URL(SCRIPT_TAG.src);
            SITE_ID = url.searchParams.get('site_id');
        } catch (e) {
            console.error('[ELChat] Erreur lecture URL script');
        }
    }
    if (!SITE_ID) {
        console.error('[ELChat] site_id manquant');
        return;
    }
    console.log('[ELChat] SITE_ID détecté:', SITE_ID);


    const API_URL = `https://elchat.io/api/v1/site/${SITE_ID}/widget/config`;
    const IFRAME_URL = `https://elchat.io/widget?site_id=${encodeURIComponent(SITE_ID)}`;
    const STORAGE_KEY = `elchat_user_opened_${SITE_ID}`;
    let userClosed = false; // au top du script

    /* =========================
       CONFIG PAR DÉFAUT (SAFE)
    ========================= */
    const DEFAULT_CONFIG = {
        button: {
            text: '💬 Chat',
            background: '#ff9100',
            color: '#fff',
            position: 'bottom-right',
            offsetX: '1rem',
            offsetY: '1rem',
            html: '<img src="https://elchat.io/assets/icon-quickmenu-chatbot.gif" style="user-select: none; pointer-events: none" width="70" alt="Chat" />',
        },
        auto_open_delay: 5 // pas d'auto-open par défaut
    };

    let config = DEFAULT_CONFIG;
    let btn = null;
    let iframe = null;
    let autoOpenTimer = null;
    let isOpened = false;

    /* =========================
       1️⃣ Charger la config depuis backend
    ========================= */
    fetch(API_URL)
        .then(res => res.ok ? res.json() : null)
        .then(data => {
            if (data && data.success && data.config && data.config.button) {
                const b = data.config.button;
                config.button = {
                    text: b.text || DEFAULT_CONFIG.button.text,
                    background: b.background || DEFAULT_CONFIG.button.background,
                    color: b.color || DEFAULT_CONFIG.button.color,
                    position: b.position || DEFAULT_CONFIG.button.position,
                    offsetX: b.offsetX || DEFAULT_CONFIG.button.offsetX,
                    offsetY: b.offsetY || DEFAULT_CONFIG.button.offsetY
                };

                config.auto_open_delay = Number(data.config.auto_open_delay) || 0;
            }
            createButton();
            setupAutoOpen();
        })
        .catch(() => {
            console.warn('[ELChat] Config non trouvée → fallback par défaut');
            createButton();
            setupAutoOpen();
        });

    /* =========================
       2️⃣ Créer le bouton flottant
    ========================= */
    function createButton() {
        if (btn) return;

        btn = document.createElement('button');
        btn.id = 'elchat-btn';
        //btn.innerText = config.button.text;
        btn.innerHTML = config.button.html;
        btn.innerHTML = '<img src="https://elchat.io/assets/icon-quickmenu-chatbot.gif" style="user-select: none; pointer-events: none; width: 100%; height: 100%; object-fit: cover; border-radius: 50%;" alt="ELChat" />';
        btn.setAttribute('aria-label', config.button.text);

        Object.assign(btn.style, {
            position: 'fixed',
            zIndex: 9999,
            width: '60px',
            height: '60px',
            borderRadius: '50%',
            //background: config.button.background,
            background: 'linear-gradient(92.89deg, #ff9f9f 2.27%, #ef9cff 55.18%, #b8f8ff 97.46%)',
            color: config.button.color,
            border: 'none',
            cursor: 'pointer',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            boxShadow: '0 4px 6px rgba(0,0,0,0.2)',
            padding: '1px',
            overflow: 'hidden',
        });

        // position
        const pos = (config.button.position || 'bottom-right').toLowerCase();
        if (pos.includes('bottom')) btn.style.bottom = config.button.offsetY || '1rem';
        if (pos.includes('top')) btn.style.top = config.button.offsetY || '1rem';
        if (pos.includes('right')) btn.style.right = config.button.offsetX || '1rem';
        if (pos.includes('left')) btn.style.left = config.button.offsetX || '1rem';

        btn.addEventListener('click', openIframe);
        document.body.appendChild(btn);
    }

    /* =========================
       3️⃣ Auto-open configurable
    ========================= */
    function setupAutoOpen() {
        if (userClosed) return; // ✅ ignore auto-open si l'utilisateur a fermé
        const delay = Number(config.auto_open_delay) || 0;
        if (delay <= 0) return;

        autoOpenTimer = setTimeout(() => {
            if (!isOpened) openIframe();
        }, delay * 1000);
    }

    /* =========================
       4️⃣ Ouvrir iframe
    ========================= */
    function openIframe() {
        if (isOpened) return;
        isOpened = true;

        if (autoOpenTimer) {
            clearTimeout(autoOpenTimer);
            autoOpenTimer = null;
        }

        if (btn) {
            btn.remove();
            btn = null;
        }

        iframe = document.createElement('iframe');
        iframe.id = 'elchat-iframe';
        iframe.src = IFRAME_URL;
        iframe.allow = "microphone";
        iframe.allowTransparency = true;
        iframe.sandbox = "allow-scripts allow-same-origin allow-popups allow-forms"

        Object.assign(iframe.style, {
            position: 'fixed',
            //bottom: '20px',
            //right: '20px',
            width: '360px',
            height: '540px',
            border: 'none',
            borderRadius: '12px',
            boxShadow: '0 6px 20px rgba(0,0,0,.3)',
            zIndex: 9999,
            overflow: 'hidden',
            background: '#fff'
        });

        // Position identique au bouton
        const pos = (config.button.position || 'bottom-right').toLowerCase();

        if (pos.includes('bottom'))
            iframe.style.bottom = config.button.offsetY || '1rem';

        if (pos.includes('top'))
            iframe.style.top = config.button.offsetY || '1rem';

        if (pos.includes('right'))
            iframe.style.right = config.button.offsetX || '1rem';

        if (pos.includes('left'))
            iframe.style.left = config.button.offsetX || '1rem';

        document.body.appendChild(iframe);
    }

    /* =========================
       5️⃣ postMessage iframe ↔ parent
    ========================= */
    window.addEventListener('message', (event) => {
        if (!event.data || event.data.source !== 'elchat') return;

        switch (event.data.type) {
            case 'CLOSE_WIDGET':
                closeIframe();
                break;
            case 'CART_SYNC': // 🆕
                syncCartWithWooCommerce(event.data.payload);
                break;
            case 'remove_many':
                remove_many(event.data.payload);
                break;
            case 'OPEN_LINK': // 🆕
                openExternalLink(event.data.payload);
                break;
        }
    });

    /* =========================
       🆕 Synchronisation panier WooCommerce (Store API, même origine)
    ========================= */
    let wcCartNonce = null;
    let cartSyncQueue = Promise.resolve(); // 🆕 sérialise les actions pour éviter les courses sur le nonce

    async function wcStoreApiRequest(path, options = {}, retry = true) {
        try {
            const res = await fetch(`${window.location.origin}/wp-json/wc/store/v1${path}`, {
                ...options,
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    ...(wcCartNonce ? { 'Nonce': wcCartNonce } : {}),
                    ...(options.headers || {}),
                },
            });
            const nonceHeader = res.headers.get('Nonce') || res.headers.get('X-WC-Store-API-Nonce');
            if (nonceHeader) wcCartNonce = nonceHeader;

            if (!res.ok && retry && res.status !== 400) {
                // 🆕 un seul retry sur erreur transitoire (pas sur 400, qui est une
                // erreur métier — variante manquante, coupon invalide... — inutile
                // de la rejouer)
                return wcStoreApiRequest(path, options, false);
            }

            return res;
        } catch (networkError) {
            if (retry) return wcStoreApiRequest(path, options, false);
            throw networkError;
        }
    }

    async function wcFindCartItemKey(productId, variationId) {
        const res = await wcStoreApiRequest('/cart');
        const cart = await res.json();
        const target = String(variationId || productId);
        const match = (cart.items || []).find(item => String(item.id) === target);
        return match ? match.key : null;
    }

    async function performCartSync(payload) {
        if (!payload || !payload.type) return;

        if (!wcCartNonce) await wcStoreApiRequest('/cart'); // amorce le nonce

        switch (payload.type) {
            case 'add':
                await wcStoreApiRequest('/cart/add-item', {
                    method: 'POST',
                    body: JSON.stringify({
                        id: payload.variation_id || payload.product_id,
                        quantity: payload.quantity || 1,
                    }),
                });
                break;

            case 'update': {
                const key = await wcFindCartItemKey(payload.product_id, payload.variation_id);
                if (key) {
                    await wcStoreApiRequest(`/cart/items/${key}`, {
                        method: 'PUT',
                        body: JSON.stringify({ quantity: payload.quantity }),
                    });
                }
                break;
            }

            case 'remove': {
                const key = await wcFindCartItemKey(payload.product_id, payload.variation_id);
                if (key) {
                    await wcStoreApiRequest(`/cart/items/${key}`, { method: 'DELETE' });
                }
                break;
            }

            case 'clear':
                await wcStoreApiRequest('/cart/items', { method: 'DELETE' });
                break;

            // 🆕
            case 'apply_coupon':
                await wcStoreApiRequest('/cart/coupons', {
                    method: 'POST',
                    body: JSON.stringify({ code: payload.code }),
                });
                break;

            // 🆕
            case 'remove_coupon':
                await wcStoreApiRequest(`/cart/coupons/${encodeURIComponent(payload.code)}`, { method: 'DELETE' });
                break;
        }

        // Rafraîchit l'affichage panier du thème (mini-cart, compteur header...)
        if (window.jQuery) window.jQuery(document.body).trigger('wc_fragment_refresh');
        document.body.dispatchEvent(new CustomEvent('wc-blocks_added_to_cart', { detail: { preserveCartData: true } }));
    }

    /**
     * 🆕 Point d'entrée public : empile l'action dans la file au lieu de
     * l'exécuter immédiatement. Si le LLM déclenche plusieurs hops panier dans
     * le même tour (ex: ajouter 2 produits puis appliquer un coupon), les
     * appels Store API s'exécutent dans l'ordre, un par un — sans ça, deux
     * requêtes concurrentes pourraient se marcher dessus sur le nonce et créer
     * un état de panier incohérent.
     */
    function syncCartWithWooCommerce(payload) {
        cartSyncQueue = cartSyncQueue
            .then(() => performCartSync(payload))
            .catch(e => console.warn('[ELChat] Synchronisation panier WooCommerce échouée', e));
        return cartSyncQueue;
    }

    async function remove_many(payload){
        for (const item of (payload.items || [])) {
            const key = await wcFindCartItemKey(item.product_id, item.variation_id);
            if (key) {
                await wcStoreApiRequest(`/cart/items/${key}`, { method: 'DELETE' });
            }
        }
    }

    /**
     * 🆕 Redirige le navigateur du visiteur vers l'URL fournie (ex: lien de
     * paiement). Garde-fou : n'accepte que des URLs http(s) sur la MÊME
     * origine que le site hôte (le store WooCommerce), pour éviter qu'un
     * message falsifié ne redirige le visiteur vers un site tiers.
     */
    function openExternalLink(payload) {
        if (!payload || !payload.url) return;

        try {
            const target = new URL(payload.url, window.location.origin);

            if (target.protocol !== 'https:' && target.protocol !== 'http:') {
                console.warn('[ELChat] URL refusée (protocole non autorisé):', payload.url);
                return;
            }

            if (target.origin !== window.location.origin) {
                console.warn('[ELChat] URL refusée (origine différente du site):', payload.url);
                return;
            }

            window.location.href = target.href;
        } catch (e) {
            console.warn('[ELChat] URL invalide reçue pour OPEN_LINK:', payload.url);
        }
    }

    /* =========================
       6️⃣ Fermer iframe
    ========================= */
    function closeIframe() {
        if (iframe) {
            iframe.remove();
            iframe = null;
        }

        isOpened = false;
        userClosed = true; // ✅ l'utilisateur a fermé
        createButton();
        //setupAutoOpen();
    }

})();
