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
        }
    });

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