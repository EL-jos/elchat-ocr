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


    const ELCHAT_ORIGIN = new URL(SCRIPT_TAG.src, window.location.href).origin;
    const API_BASE = `${ELCHAT_ORIGIN}/api/v1`;
    const API_URL = `${API_BASE}/site/${SITE_ID}/widget/config`;
    const VISUAL_EVENTS_URL = `${API_BASE}/widget/site/${SITE_ID}/visitor-intelligence/events`;
    const PROACTIVE_PENDING_URL = `${API_BASE}/widget/proactive/pending/${SITE_ID}`;
    const VISUAL_FRAMES_URL = `${API_BASE}/widget/site/${SITE_ID}/visitor-intelligence/frames`;
    const REPLAY_CHUNKS_URL = `${API_BASE}/widget/site/${SITE_ID}/visitor-intelligence/replay-chunks`;
    const IFRAME_ORIGIN = new URL(`${ELCHAT_ORIGIN}/widget`).origin;
    const STORAGE_KEY = `elchat_user_opened_${SITE_ID}`;
    const SESSION_KEY = `elchat_vi_session_${SITE_ID}`;
    const VISITOR_KEY = `elchat_visitor_uuid_${SITE_ID}`;

    function createId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, char => {
            const random = Math.random() * 16 | 0;
            return (char === 'x' ? random : (random & 0x3 | 0x8)).toString(16);
        });
    }

    let SESSION_ID = null;
    try {
        SESSION_ID = sessionStorage.getItem(SESSION_KEY);
        if (!SESSION_ID) {
            SESSION_ID = createId();
            sessionStorage.setItem(SESSION_KEY, SESSION_ID);
        }
    } catch (_) {
        SESSION_ID = createId();
    }
    let HOST_VISITOR_UUID = null;
    try {
        HOST_VISITOR_UUID = localStorage.getItem(VISITOR_KEY);
        if (!HOST_VISITOR_UUID) {
            HOST_VISITOR_UUID = createId();
            localStorage.setItem(VISITOR_KEY, HOST_VISITOR_UUID);
        }
    } catch (_) {
        HOST_VISITOR_UUID = createId();
    }
    const IFRAME_URL = `${ELCHAT_ORIGIN}/widget?site_id=${encodeURIComponent(SITE_ID)}&session_id=${encodeURIComponent(SESSION_ID)}&visitor_uuid=${encodeURIComponent(HOST_VISITOR_UUID)}`;
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
        auto_open_enabled: false,
        auto_open_delay: 5
    };

    let config = DEFAULT_CONFIG;
    let btn = null;
    let iframe = null;
    let autoOpenTimer = null;
    let isOpened = false;
    let visitorUUID = HOST_VISITOR_UUID;
    let visualSequence = 0;
    let frameSequence = 0;
    let visualEvents = [];
    let visualFlushTimer = null;
    let frameTimer = null;
    let widgetFrameTimer = null;
    let frameInFlight = false;
    let frameRequestedAgain = false;
    let lastFrameCapturedAt = 0;
    let pendingFrameContext = null;
    let lastVisualContext = null;
    let visualSessionStarted = false;
    let frameFailureCount = 0;
    let html2canvasPromise = null;
    let pageScrollTimer = null;
    let pageScrollTarget = null;
    let pageLastPointerX = null;
    let pageLastPointerY = null;
    let pageLastPointerType = 'mouse';
    let pagePointerLastAt = 0;
    let pagePointerEvents = 0;
    let pageFormStarts = new WeakSet();
    let lastRecordedPageScrollX = null;
    let lastRecordedPageScrollY = null;
    let tenantLoadReadyPromise = null;
    let tenantFirstFrameCaptured = false;
    let fallbackTenantScrollX = 0;
    let fallbackTenantScrollY = 0;
    let fallbackScrollTimer = null;
    let fallbackPendingDeltaX = 0;
    let fallbackPendingDeltaY = 0;
    let touchScrollStartX = null;
    let touchScrollStartY = null;
    let touchScrollLastX = null;
    let touchScrollLastY = null;
    let rrwebLoaderPromise = null;
    let rrwebRecordApi = null;
    let rrwebStop = null;
    let rrwebRecordingStarted = false;
    let rrwebRecordingStopped = false;
    let rrwebFlushTimer = null;
    let rrwebChunkIndex = 0;
    let rrwebPendingEvents = [];
    let rrwebPendingBytes = 0;
    let rrwebPendingChunks = [];
    let rrwebUploadInFlight = false;
    let rrwebFailedRetryTimer = null;
    let pageTrackingStartedAt = 0;
    let pageLastActivityAt = 0;
    let pageLastActivitySignalAt = 0;
    let pageIdleStartedAt = null;
    let pageIdleDurationMs = 0;
    let pageInactivityCount = 0;
    let pageIdleTimer = null;
    let pageSessionEnded = false;
    let proactivePollTimer = null;
    let proactivePollInFlight = false;
    let lastProactiveMessageId = null;
    let lastWidgetObservedScrollX = null;
    let lastWidgetObservedScrollY = null;

    const VISUAL_FRAME_INTERVAL = 700;
    const VISUAL_FLUSH_SIZE = 20;
    const RRWEB_CHUNK_MAX_EVENTS = 80;
    const RRWEB_CHUNK_MAX_BYTES = 180000;
    const RRWEB_FLUSH_INTERVAL = 3500;
    const PAGE_INACTIVITY_THRESHOLD_MS = 30000;

    function deviceType() {
        const userAgent = navigator.userAgent.toLowerCase();
        if (userAgent.includes('ipad') || userAgent.includes('tablet') || (userAgent.includes('android') && !userAgent.includes('mobile'))) return 'tablet';
        return userAgent.includes('mobile') ? 'mobile' : 'desktop';
    }

    function hostEventContext(metadata) {
        return {
            ...metadata,
            device: metadata && metadata.device ? metadata.device : deviceType(),
            surface: metadata && metadata.surface ? metadata.surface : 'page',
        };
    }

    function ensureRrwebRecorder() {
        if (window.rrwebRecord?.record) return Promise.resolve(window.rrwebRecord);
        if (rrwebLoaderPromise) return rrwebLoaderPromise;

        rrwebLoaderPromise = new Promise((resolve, reject) => {
            const existing = document.querySelector('script[data-elchat-rrweb-record]');
            if (existing) {
                existing.addEventListener('load', () => window.rrwebRecord?.record ? resolve(window.rrwebRecord) : reject(new Error('rrweb recorder unavailable')), { once: true });
                existing.addEventListener('error', () => reject(new Error('rrweb recorder failed to load')), { once: true });
                return;
            }
            const script = document.createElement('script');
            script.async = true;
            script.src = `${ELCHAT_ORIGIN}/js/rrweb-record.min.js`;
            script.dataset.elchatRrwebRecord = 'true';
            script.onload = () => window.rrwebRecord?.record
                ? resolve(window.rrwebRecord)
                : reject(new Error('rrweb recorder unavailable'));
            script.onerror = () => reject(new Error('rrweb recorder failed to load'));
            document.head.appendChild(script);
        });
        return rrwebLoaderPromise;
    }

    function rrwebChunkMetadata() {
        const viewport = pageViewportState();
        return {
            page_url: window.location.href,
            path: window.location.pathname || '/',
            title: document.title || '',
            device: deviceType(),
            surface: 'page',
            viewport_width: viewport.viewportWidth,
            viewport_height: viewport.viewportHeight,
        };
    }

    function rrwebChunkEventBounds(events) {
        const timestamps = events
            .map(event => Number(event?.timestamp))
            .filter(timestamp => Number.isFinite(timestamp) && timestamp > 0);
        if (!timestamps.length) return { first: null, last: null };
        const first = Math.min(...timestamps);
        const last = Math.max(...timestamps);
        return {
            first: new Date(first).toISOString(),
            last: new Date(last).toISOString(),
        };
    }

    async function sendRrwebChunk(chunk) {
        const body = JSON.stringify(chunk);
        let lastError = null;
        for (let attempt = 0; attempt < 3; attempt++) {
            try {
                const response = await fetch(REPLAY_CHUNKS_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body,
                    keepalive: body.length <= 60000,
                });
                if (response.ok) return;
                if (response.status === 413 || response.status === 422) {
                    const error = new Error(`rrweb chunk rejected HTTP ${response.status}`);
                    error.permanent = true;
                    throw error;
                }
                throw new Error(`rrweb chunk HTTP ${response.status}`);
            } catch (error) {
                lastError = error;
                if (error?.permanent) throw error;
                if (attempt < 2) await wait(250 * (attempt + 1));
            }
        }
        throw lastError || new Error('rrweb chunk upload failed');
    }

    function pumpRrwebChunks() {
        if (rrwebUploadInFlight || !rrwebPendingChunks.length) return;
        const chunk = rrwebPendingChunks.shift();
        rrwebUploadInFlight = true;
        sendRrwebChunk(chunk)
            .catch(error => {
                if (error?.permanent) {
                    console.warn('[ELChat] Visitor Intelligence rrweb chunk discarded', error);
                    return;
                }
                rrwebPendingChunks.unshift(chunk);
                if (!rrwebFailedRetryTimer) {
                    rrwebFailedRetryTimer = setTimeout(() => {
                        rrwebFailedRetryTimer = null;
                        pumpRrwebChunks();
                    }, 5000);
                }
            })
            .finally(() => {
                rrwebUploadInFlight = false;
                if (rrwebPendingChunks.length && !rrwebFailedRetryTimer) pumpRrwebChunks();
            });
    }

    function queueRrwebChunk(force = false) {
        if (!rrwebPendingEvents.length) return;
        if (!force && rrwebPendingEvents.length < RRWEB_CHUNK_MAX_EVENTS && rrwebPendingBytes < RRWEB_CHUNK_MAX_BYTES) return;
        const events = rrwebPendingEvents;
        rrwebPendingEvents = [];
        rrwebPendingBytes = 0;
        const bounds = rrwebChunkEventBounds(events);
        rrwebPendingChunks.push({
            visitor_uuid: visitorUUID,
            session_id: SESSION_ID,
            chunk_index: rrwebChunkIndex++,
            rrweb_version: '2.0.0',
            occurred_at: bounds.first || new Date().toISOString(),
            metadata: rrwebChunkMetadata(),
            events,
        });
        pumpRrwebChunks();
    }

    function scheduleRrwebFlush() {
        if (rrwebFlushTimer) clearTimeout(rrwebFlushTimer);
        if (!rrwebRecordingStarted || rrwebRecordingStopped) return;
        rrwebFlushTimer = setTimeout(() => {
            rrwebFlushTimer = null;
            queueRrwebChunk(true);
            scheduleRrwebFlush();
        }, RRWEB_FLUSH_INTERVAL);
    }

    function stopTenantReplayRecording() {
        if (rrwebRecordingStopped) return;
        rrwebRecordingStopped = true;
        if (rrwebFlushTimer) clearTimeout(rrwebFlushTimer);
        if (rrwebFailedRetryTimer) clearTimeout(rrwebFailedRetryTimer);
        rrwebFlushTimer = null;
        rrwebFailedRetryTimer = null;
        if (typeof rrwebStop === 'function') {
            try { rrwebStop(); } catch (_) { /* tracking must never break navigation */ }
        }
        rrwebStop = null;
        queueRrwebChunk(true);
        pumpRrwebChunks();
    }

    function startTenantReplayRecording() {
        if (rrwebRecordingStarted || rrwebRecordingStopped) return;
        ensureRrwebRecorder()
            .then(api => {
                if (rrwebRecordingStarted || rrwebRecordingStopped) return;
                rrwebRecordApi = api;
                rrwebRecordingStarted = true;
                rrwebStop = api.record({
                    emit: event => {
                        if (!event || rrwebRecordingStopped) return;
                        rrwebPendingEvents.push(event);
                        rrwebPendingBytes += JSON.stringify(event).length;
                        queueRrwebChunk();
                    },
                    recordAfter: 'load',
                    checkoutEveryNms: 30000,
                    blockSelector: '#elchat-iframe, [data-recording-ignore], [data-elchat-recording-ignore]',
                    maskTextSelector: '[data-recording-mask], [data-elchat-recording-mask]',
                    maskInputOptions: {
                        password: true,
                        email: true,
                        tel: true,
                        number: true,
                        date: true,
                        search: true,
                    },
                    recordCrossOriginIframes: false,
                    recordCanvas: false,
                    collectFonts: true,
                    inlineStylesheet: true,
                    mousemoveWait: 150,
                });
                scheduleRrwebFlush();
            })
            .catch(error => console.warn('[ELChat] Visitor Intelligence rrweb recorder unavailable', error));
    }

    function addRrwebCustomEvent(event) {
        const addCustomEvent = rrwebRecordApi?.record?.addCustomEvent;
        if (typeof addCustomEvent !== 'function' || !event || event.event_type === 'pointer_move' || event.event_type === 'session_start') return;
        const metadata = event.metadata || {};
        try {
            const payload = {
                event_type: event.event_type,
                occurred_at: event.occurred_at,
                page_url: event.page_url,
                path: event.path,
                surface: metadata.surface || 'page',
            };
            if (event.label) payload.label = event.label;
            if (metadata.scroll_x !== null && metadata.scroll_x !== undefined) payload.scroll_x = metadata.scroll_x;
            if (metadata.scroll_y !== null && metadata.scroll_y !== undefined) payload.scroll_y = metadata.scroll_y;
            if (metadata.depth !== null && metadata.depth !== undefined) payload.depth = metadata.depth;
            addCustomEvent('elchat-visual-event', payload);
        } catch (_) {
            // Custom events are optional; the DOM and native rrweb stream stay authoritative.
        }
    }

    function isScrollableElement(element) {
        if (!(element instanceof HTMLElement) || element === document.body || element === document.documentElement) return false;
        const style = window.getComputedStyle(element);
        const vertical = ['auto', 'scroll', 'overlay'].includes(style.overflowY);
        const horizontal = ['auto', 'scroll', 'overlay'].includes(style.overflowX);
        return (vertical && element.scrollHeight > element.clientHeight + 1)
            || (horizontal && element.scrollWidth > element.clientWidth + 1);
    }

    function elementDepth(element) {
        let depth = 0;
        let current = element;
        while (current && current.parentElement) {
            depth++;
            current = current.parentElement;
        }
        return depth;
    }

    function elementPath(element) {
        const path = [];
        let current = element;
        while (current && current !== document.documentElement) {
            const parent = current.parentElement;
            if (!parent) return null;
            path.unshift(Array.prototype.indexOf.call(parent.children, current));
            current = parent;
        }
        return current === document.documentElement ? path : null;
    }

    function captureScrollPositions() {
        const positions = [];
        const elements = document.querySelectorAll('*');
        for (const element of elements) {
            if (!(element instanceof HTMLElement) || element === document.body || element === document.documentElement) continue;
            const left = Math.round(element.scrollLeft || 0);
            const top = Math.round(element.scrollTop || 0);
            if (!left && !top) continue;
            const path = elementPath(element);
            if (path) positions.push({ path, left, top });
            if (positions.length >= 128) break;
        }
        return positions;
    }

    function readNativePageScroll(root = document.documentElement, body = document.body) {
        const scrollingElement = document.scrollingElement;
        const visualViewport = window.visualViewport;
        const candidates = [
            { source: 'window', x: window.scrollX, y: window.scrollY },
            { source: 'page_offset', x: window.pageXOffset, y: window.pageYOffset },
            { source: 'document_element', x: root?.scrollLeft, y: root?.scrollTop },
            { source: 'body', x: body?.scrollLeft, y: body?.scrollTop },
            { source: 'scrolling_element', x: scrollingElement?.scrollLeft, y: scrollingElement?.scrollTop },
            { source: 'visual_viewport', x: visualViewport?.pageLeft, y: visualViewport?.pageTop },
        ].map(candidate => ({
            ...candidate,
            x: Number.isFinite(Number(candidate.x)) ? Math.max(0, Number(candidate.x)) : 0,
            y: Number.isFinite(Number(candidate.y)) ? Math.max(0, Number(candidate.y)) : 0,
        }));
        const nativeX = Math.max(...candidates.map(candidate => candidate.x), 0);
        const nativeY = Math.max(...candidates.map(candidate => candidate.y), 0);
        const source = candidates
            .filter(candidate => candidate.x > 0 || candidate.y > 0)
            .map(candidate => candidate.source)
            .join('+') || 'none';
        return {
            x: Math.round(nativeX),
            y: Math.round(nativeY),
            source,
        };
    }

    function pageViewportState() {
        const root = document.documentElement;
        const body = document.body;
        const viewportWidth = Math.max(1, Math.round(window.innerWidth || root.clientWidth || 1));
        const viewportHeight = Math.max(1, Math.round(window.innerHeight || root.clientHeight || 1));
        const nativePageScroll = readNativePageScroll(root, body);
        const windowScrollX = nativePageScroll.x || fallbackTenantScrollX;
        const windowScrollY = nativePageScroll.y || fallbackTenantScrollY;
        const candidates = [pageScrollTarget, ...Array.from(document.querySelectorAll('*'))]
            .filter((element, index, all) => element instanceof HTMLElement && all.indexOf(element) === index && isScrollableElement(element));
        const current = candidates
            .filter(element => Math.abs(element.scrollTop) > 0 || Math.abs(element.scrollLeft) > 0)
            .sort((left, right) => elementDepth(right) - elementDepth(left))[0];
        const scrollContainer = current || pageScrollTarget || null;
        const nestedScroll = scrollContainer && isScrollableElement(scrollContainer) && windowScrollX === 0 && windowScrollY === 0;
        const scrollX = nestedScroll ? Math.max(0, Math.round(scrollContainer.scrollLeft || 0)) : windowScrollX;
        const scrollY = nestedScroll ? Math.max(0, Math.round(scrollContainer.scrollTop || 0)) : windowScrollY;
        const pageWidth = Math.max(
            viewportWidth,
            root.scrollWidth || 0,
            body?.scrollWidth || 0,
            scrollContainer?.scrollWidth || 0,
        );
        const pageHeight = Math.max(
            viewportHeight,
            root.scrollHeight || 0,
            body?.scrollHeight || 0,
            scrollContainer?.scrollHeight || 0,
        );
        return {
            viewportWidth,
            viewportHeight,
            pageWidth,
            pageHeight,
            scrollX,
            scrollY,
            windowScrollX,
            windowScrollY,
            captureScrollX: nativePageScroll.x,
            captureScrollY: nativePageScroll.y,
            scrollSource: nestedScroll ? 'nested_element' : (nativePageScroll.source === 'none' ? 'fallback_gesture' : nativePageScroll.source),
            scrollContainer,
        };
    }

    function wait(milliseconds) {
        return new Promise(resolve => setTimeout(resolve, milliseconds));
    }

    function nextPaint() {
        return new Promise(resolve => window.requestAnimationFrame(() => resolve()));
    }

    function waitForTenantLoad() {
        if (document.readyState === 'complete') return Promise.resolve();
        if (tenantLoadReadyPromise) return tenantLoadReadyPromise;

        tenantLoadReadyPromise = new Promise(resolve => {
            let settled = false;
            const finish = () => {
                if (settled) return;
                settled = true;
                window.removeEventListener('load', finish);
                resolve();
            };
            window.addEventListener('load', finish, { once: true });
            // A SPA can keep the load event pending; never block tracking
            // indefinitely because of a third-party resource.
            setTimeout(finish, 2200);
        });
        return tenantLoadReadyPromise;
    }

    async function waitForTenantFonts() {
        if (!document.fonts?.ready) return;
        await Promise.race([
            document.fonts.ready.catch(() => undefined),
            wait(1800),
        ]);
    }

    function visibleTenantImages(viewport) {
        return Array.from(document.images).filter(image => {
            if (!image.isConnected) return false;
            const rect = image.getBoundingClientRect();
            return rect.width > 0 && rect.height > 0
                && rect.right > 0 && rect.bottom > 0
                && rect.left < viewport.viewportWidth && rect.top < viewport.viewportHeight;
        });
    }

    async function waitForVisibleTenantImages(viewport, timeout = 900) {
        const images = visibleTenantImages(viewport);
        if (!images.length) return;
        await Promise.race([
            Promise.all(images.map(image => new Promise(resolve => {
                let settled = false;
                const finish = () => {
                    if (settled) return;
                    settled = true;
                    image.removeEventListener('load', finish);
                    image.removeEventListener('error', finish);
                    resolve();
                };
                if (image.complete) {
                    const decoded = typeof image.decode === 'function' ? image.decode() : Promise.resolve();
                    Promise.resolve(decoded).catch(() => undefined).finally(finish);
                } else {
                    image.addEventListener('load', finish, { once: true });
                    image.addEventListener('error', finish, { once: true });
                }
            }))),
            wait(timeout),
        ]);
    }

    function tenantLayoutSignature(viewport) {
        const root = document.documentElement;
        const body = document.body;
        const bodyRect = body?.getBoundingClientRect();
        return [
            viewport.viewportWidth,
            viewport.viewportHeight,
            viewport.windowScrollX,
            viewport.windowScrollY,
            root.scrollWidth,
            root.scrollHeight,
            body?.scrollWidth || 0,
            body?.scrollHeight || 0,
            Math.round(bodyRect?.width || 0),
            Math.round(bodyRect?.height || 0),
        ].join(':');
    }

    async function waitForStableTenantViewport() {
        const firstCapture = !tenantFirstFrameCaptured;
        if (firstCapture) await waitForTenantLoad();
        await waitForTenantFonts();

        let viewport = pageViewportState();
        await waitForVisibleTenantImages(viewport, firstCapture ? 1200 : 500);
        await nextPaint();
        await nextPaint();
        if (firstCapture) await wait(180);

        // Re-read the viewport after fonts/images/layout have settled. If a
        // lazy image changed the document height, wait one more paint before
        // capturing so the screenshot and its scroll metadata agree.
        for (let attempt = 0; attempt < 3; attempt++) {
            const before = tenantLayoutSignature(viewport);
            await wait(firstCapture ? 90 : 40);
            await nextPaint();
            const nextViewport = pageViewportState();
            const after = tenantLayoutSignature(nextViewport);
            viewport = nextViewport;
            if (before === after) break;
        }
        return viewport;
    }

    function canvasLooksBlank(canvas) {
        const context = canvas.getContext('2d', { willReadFrequently: true });
        if (!context) return false;
        const columns = 32;
        const rows = 16;
        const colors = new Map();
        let samples = 0;
        try {
            for (let row = 0; row < rows; row++) {
                for (let column = 0; column < columns; column++) {
                    const x = Math.min(canvas.width - 1, Math.floor((column + 0.5) * canvas.width / columns));
                    const y = Math.min(canvas.height - 1, Math.floor((row + 0.5) * canvas.height / rows));
                    const pixel = context.getImageData(x, y, 1, 1).data;
                    const color = [pixel[0] >> 4, pixel[1] >> 4, pixel[2] >> 4].join(':');
                    colors.set(color, (colors.get(color) || 0) + 1);
                    samples++;
                }
            }
        } catch (_) {
            return false;
        }
        const dominant = Math.max(...colors.values(), 0);
        // A uniform white/black canvas is normally the result of capturing
        // before the tenant page rendered or after the scroll crop lost it.
        return samples > 0 && dominant / samples >= 0.985;
    }

    function pageContext(extra = {}) {
        const viewport = pageViewportState();
        return {
            ...extra,
            page_url: window.location.href,
            path: window.location.pathname || '/',
            title: document.title || '',
            referrer: document.referrer || '',
            viewport_width: viewport.viewportWidth,
            viewport_height: viewport.viewportHeight,
            page_width: viewport.pageWidth,
            page_height: viewport.pageHeight,
            scroll_x: viewport.scrollX,
            scroll_y: viewport.scrollY,
            scroll_source: viewport.scrollSource,
            cursor_x: pageLastPointerX ?? 0,
            cursor_y: pageLastPointerY ?? 0,
            cursor_page_x: pageLastPointerX === null ? 0 : pageLastPointerX + viewport.scrollX,
            cursor_page_y: pageLastPointerY === null ? 0 : pageLastPointerY + viewport.scrollY,
            pointer_type: pageLastPointerType,
            scroll_positions: JSON.stringify(captureScrollPositions()),
            device: deviceType(),
            surface: 'page',
        };
    }

    function enqueueVisualEvent(eventType, metadata = {}, options = {}) {
        if (eventType === 'session_start') {
            visualSessionStarted = true;
        } else if (!visualSessionStarted) {
            visualSessionStarted = true;
            enqueueVisualEvent('session_start', { device: deviceType(), surface: 'page' });
        }
        const occurredAt = new Date().toISOString();
        const event = {
            event_id: `${SESSION_ID}-event-${++visualSequence}`,
            event_type: eventType,
            occurred_at: occurredAt,
            page_url: window.location.href,
            path: window.location.pathname || '/',
            title: document.title || '',
            metadata: hostEventContext(metadata),
            ...(options.resource_type ? { resource_type: options.resource_type } : {}),
            ...(options.label ? { label: options.label } : {}),
        };
        visualEvents.push(event);
        lastVisualContext = event;
        addRrwebCustomEvent(event);
        if (visualEvents.length >= VISUAL_FLUSH_SIZE) flushVisualEvents();
        else scheduleVisualFlush();
        return event;
    }

    function scheduleVisualFlush() {
        if (visualFlushTimer || !visualEvents.length) return;
        visualFlushTimer = setTimeout(() => {
            visualFlushTimer = null;
            flushVisualEvents();
        }, 350);
    }

    function flushVisualEvents(keepalive = false) {
        if (!visitorUUID || !visualEvents.length) return;
        const batches = [];
        do {
            batches.push(visualEvents.splice(0, VISUAL_FLUSH_SIZE));
        } while (keepalive && visualEvents.length);

        batches.forEach(batch => {
            fetch(VISUAL_EVENTS_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ visitor_uuid: visitorUUID, session_id: SESSION_ID, events: batch }),
                keepalive,
            }).then(response => {
                if (!response.ok) throw new Error(`visual events HTTP ${response.status}`);
            }).catch(error => {
                // The endpoint is idempotent. Put the batch back at the front so a
                // short network interruption does not silently remove the replay.
                visualEvents = batch.concat(visualEvents).slice(-200);
                console.warn('[ELChat] Visitor Intelligence event relay failed', error);
                if (!keepalive) scheduleVisualFlush();
            });
        });
        if (visualEvents.length && !keepalive) scheduleVisualFlush();
    }

    function scheduleFrameCapture(delay = 260) {
        if (!visitorUUID) return;
        if (frameTimer) clearTimeout(frameTimer);
        const waitForCadence = Math.max(0, VISUAL_FRAME_INTERVAL - (Date.now() - lastFrameCapturedAt));
        frameTimer = setTimeout(() => {
            frameTimer = null;
            requestFrameCapture();
        }, Math.max(delay, waitForCadence));
    }

    function requestFrameCapture() {
        if (!visitorUUID) return;
        if (frameInFlight) {
            frameRequestedAgain = true;
            return;
        }
        frameInFlight = true;
        lastFrameCapturedAt = Date.now();
        pendingFrameContext = lastVisualContext || enqueueVisualEvent('page_view', pageContext());
        captureTenantFrame()
            .then(frame => finishFrameCapture(frame))
            .catch(error => {
                console.warn('[ELChat] Tenant page frame capture failed', error);
                finishFrameCapture(null);
            });
    }

    function requestWidgetFrameCapture(delay = 260) {
        if (!visitorUUID || !iframe || !isOpened) return;
        if (widgetFrameTimer) clearTimeout(widgetFrameTimer);
        widgetFrameTimer = setTimeout(() => {
            widgetFrameTimer = null;
            if (!iframe || !isOpened || !iframe.contentWindow) return;
            iframe.contentWindow.postMessage({
                source: 'elchat',
                type: 'WIDGET_VISUAL_FRAME_REQUEST',
            }, IFRAME_ORIGIN);
        }, Math.max(0, delay));
    }

    function receiveWidgetFrame(frame) {
        if (!visitorUUID || !iframe || !isOpened || !frame || typeof frame.data_url !== 'string') return;
        const hostViewport = pageViewportState();
        const rect = iframe.getBoundingClientRect();
        const widgetContext = {
            page_url: window.location.href,
            path: window.location.pathname || '/',
            title: document.title || '',
            metadata: {
                surface: 'widget',
                device: deviceType(),
                host_viewport_width: hostViewport.viewportWidth,
                host_viewport_height: hostViewport.viewportHeight,
                widget_left: Math.round(Math.max(0, rect.left)),
                widget_top: Math.round(Math.max(0, rect.top)),
                widget_width: Math.round(Math.max(1, rect.width)),
                widget_height: Math.round(Math.max(1, rect.height)),
            },
        };
        uploadFrame({
            ...frame,
            surface: 'widget',
            host_viewport_width: hostViewport.viewportWidth,
            host_viewport_height: hostViewport.viewportHeight,
            widget_left: Math.round(Math.max(0, rect.left)),
            widget_top: Math.round(Math.max(0, rect.top)),
            widget_width: Math.round(Math.max(1, rect.width)),
            widget_height: Math.round(Math.max(1, rect.height)),
        }, widgetContext).catch(error => {
            console.warn('[ELChat] Visitor Intelligence widget frame relay failed', error);
        });
    }

    function ensureHtml2Canvas() {
        if (typeof window.html2canvas === 'function') return Promise.resolve(window.html2canvas);
        if (html2canvasPromise) return html2canvasPromise;

        html2canvasPromise = new Promise((resolve, reject) => {
            const existing = document.querySelector('script[data-elchat-replay-capture]');
            if (existing) {
                existing.addEventListener('load', () => typeof window.html2canvas === 'function' ? resolve(window.html2canvas) : reject(new Error('html2canvas unavailable')), { once: true });
                existing.addEventListener('error', () => reject(new Error('html2canvas failed to load')), { once: true });
                return;
            }
            const script = document.createElement('script');
            script.async = true;
            script.src = `${ELCHAT_ORIGIN}/js/html2canvas.min.js`;
            script.dataset.elchatReplayCapture = 'true';
            script.onload = () => typeof window.html2canvas === 'function'
                ? resolve(window.html2canvas)
                : reject(new Error('html2canvas unavailable'));
            script.onerror = () => reject(new Error('html2canvas failed to load'));
            document.head.appendChild(script);
        });
        return html2canvasPromise;
    }

    async function captureTenantFrame() {
        const html2canvas = await ensureHtml2Canvas();
        const viewport = await waitForStableTenantViewport();
        const scale = Math.min(1, 1440 / viewport.viewportWidth, 1080 / viewport.viewportHeight);
        const sourceImages = Array.from(document.images);
        const originalFields = document.querySelectorAll('input, textarea, [contenteditable="true"]');
        const scrollPositions = captureScrollPositions();
        const options = {
            backgroundColor: '#ffffff',
            allowTaint: false,
            useCORS: true,
            imageTimeout: 1500,
            scale,
            width: viewport.viewportWidth,
            height: viewport.viewportHeight,
            windowWidth: viewport.viewportWidth,
            windowHeight: viewport.viewportHeight,
            scrollX: viewport.captureScrollX,
            scrollY: viewport.captureScrollY,
            // x/y crop the document canvas at the real browser scroll. The
            // scrollX/scrollY values are kept only for fixed-position elements
            // as intended by html2canvas; applying the page scroll again during
            // the post-render crop would shift the viewport a second time.
            x: viewport.captureScrollX,
            y: viewport.captureScrollY,
            logging: false,
            ignoreElements: element => {
                // Never copy secrets or broken image resources into the replay.
                if (element instanceof HTMLImageElement) {
                    return !element.complete || !element.naturalWidth;
                }
                return false;
            },
            onclone: clonedDocument => {
                clonedDocument.querySelectorAll('script[data-elchat-replay-capture]').forEach(script => script.remove());
                const clonedImages = Array.from(clonedDocument.images);
                clonedImages.forEach((clonedImage, index) => {
                    const sourceImage = sourceImages[index];
                    if (!sourceImage?.complete || !sourceImage.naturalWidth) {
                        clonedImage.removeAttribute('srcset');
                        clonedImage.removeAttribute('crossorigin');
                        clonedImage.removeAttribute('src');
                    }
                });
                const clonedFields = clonedDocument.querySelectorAll('input, textarea, [contenteditable="true"]');
                originalFields.forEach((original, index) => {
                    const cloned = clonedFields[index];
                    if (!cloned) return;
                    if (original.matches('input')) {
                        const input = cloned;
                        if (!['checkbox', 'radio', 'button', 'submit', 'reset'].includes(input.type)) {
                            input.value = '';
                            input.removeAttribute('value');
                        }
                    } else if (original.matches('textarea')) {
                        cloned.value = '';
                        cloned.textContent = '';
                    } else {
                        cloned.innerHTML = '';
                    }
                });
                clonedDocument.querySelectorAll('*').forEach(element => {
                    element.style.animation = 'none';
                    element.style.transition = 'none';
                });
                scrollPositions.forEach(position => {
                    let clonedElement = clonedDocument.documentElement;
                    for (const index of position.path || []) {
                        clonedElement = clonedElement?.children?.[index] || null;
                    }
                    const clonedHTMLElement = clonedDocument.defaultView?.HTMLElement;
                    if (!clonedHTMLElement || !(clonedElement instanceof clonedHTMLElement)) return;
                    clonedElement.scrollLeft = position.left;
                    clonedElement.scrollTop = position.top;
                });
            },
        };
        const renderedCanvas = await html2canvas(document.documentElement, options);
        const targetWidth = Math.max(1, Math.round(viewport.viewportWidth * scale));
        const targetHeight = Math.max(1, Math.round(viewport.viewportHeight * scale));
        let canvas = renderedCanvas;

        // html2canvas can still return the document canvas for pages with a
        // custom scrolling root. The replay contract is strictly one visible
        // viewport, so crop the rendered result before it is uploaded.
        if (renderedCanvas.width !== targetWidth || renderedCanvas.height !== targetHeight) {
            canvas = document.createElement('canvas');
            canvas.width = targetWidth;
            canvas.height = targetHeight;
            const context = canvas.getContext('2d');
            if (!context) throw new Error('viewport canvas unavailable');
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, targetWidth, targetHeight);
            context.imageSmoothingEnabled = true;
            context.imageSmoothingQuality = 'high';

            const pageWidth = Math.max(viewport.viewportWidth, viewport.pageWidth || viewport.viewportWidth);
            const pageHeight = Math.max(viewport.viewportHeight, viewport.pageHeight || viewport.viewportHeight);
            const sourceScaleX = renderedCanvas.width / pageWidth;
            const sourceScaleY = renderedCanvas.height / pageHeight;
            const sourceWidth = Math.max(1, Math.min(renderedCanvas.width, targetWidth / Math.max(0.0001, sourceScaleX)));
            const sourceHeight = Math.max(1, Math.min(renderedCanvas.height, targetHeight / Math.max(0.0001, sourceScaleY)));
            const sourceX = Math.max(0, Math.min(renderedCanvas.width - sourceWidth, viewport.captureScrollX * sourceScaleX));
            const sourceY = Math.max(0, Math.min(renderedCanvas.height - sourceHeight, viewport.captureScrollY * sourceScaleY));
            context.drawImage(renderedCanvas, sourceX, sourceY, sourceWidth, sourceHeight, 0, 0, targetWidth, targetHeight);
        }
        if (canvasLooksBlank(canvas)) throw new Error('tenant page viewport is visually blank');
        return {
            data_url: canvas.toDataURL('image/jpeg', 0.72),
            width: canvas.width,
            height: canvas.height,
            viewport_width: viewport.viewportWidth,
            viewport_height: viewport.viewportHeight,
            page_width: viewport.pageWidth,
            page_height: viewport.pageHeight,
            scroll_x: viewport.scrollX,
            scroll_y: viewport.scrollY,
            scroll_source: viewport.scrollSource,
            capture_mode: 'viewport',
            capture_scale: canvas.width / viewport.viewportWidth,
            scroll_positions: scrollPositions,
            cursor_x: pageLastPointerX,
            cursor_y: pageLastPointerY,
            cursor_page_x: pageLastPointerX === null ? null : pageLastPointerX + viewport.scrollX,
            cursor_page_y: pageLastPointerY === null ? null : pageLastPointerY + viewport.scrollY,
        };
    }

    function hashValue(value) {
        let hash = 2166136261;
        for (let index = 0; index < value.length; index++) {
            hash ^= value.charCodeAt(index);
            hash = Math.imul(hash, 16777619);
        }
        return (hash >>> 0).toString(16);
    }

    function targetDetails(target) {
        if (!(target instanceof Element)) return {};
        const element = target.closest('a,button,[role="button"],input,select,textarea,[contenteditable="true"]') || target;
        const tag = element.tagName.toLowerCase();
        const role = element.getAttribute('role') || '';
        const id = element.id ? `#${element.id.slice(0, 80)}` : '';
        const name = element.getAttribute('name') ? `[name=${element.getAttribute('name').slice(0, 80)}]` : '';
        const label = element.getAttribute('aria-label') || element.getAttribute('title') || (['a', 'button'].includes(tag) ? element.textContent?.trim().replace(/\s+/g, ' ').slice(0, 100) : '');
        const rect = element.getBoundingClientRect();
        return {
            target: `${tag}${role ? `[role=${role}]` : ''}${id}${name}`.slice(0, 255),
            selector_hash: hashValue(`${tag}|${role}|${id}|${name}`),
            label: label || undefined,
            x: Math.round(Math.max(0, rect.left)),
            y: Math.round(Math.max(0, rect.top)),
        };
    }

    function recordPageEvent(eventType, metadata = {}, options = {}, capture = true) {
        const event = enqueueVisualEvent(eventType, pageContext(metadata), options);
        if (capture) scheduleFrameCapture(eventType === 'page_view' ? 180 : 260);
        return event;
    }

    function recordNavigation(reason) {
        recordPageEvent('navigation', { reason, target: window.location.href }, { label: reason }, false);
        recordPageEvent('page_view', { reason }, {}, true);
        flushVisualEvents();
    }

    function inactivityMetadata(now, reason, durationMs = 0) {
        const sessionDurationMs = Math.max(0, now - pageTrackingStartedAt);
        const idleDurationMs = Math.max(0, pageIdleDurationMs + (
            pageIdleStartedAt === null ? 0 : now - pageIdleStartedAt
        ));
        return {
            duration_ms: Math.round(Math.max(0, durationMs)),
            session_duration_ms: Math.round(sessionDurationMs),
            idle_duration_ms: Math.round(idleDurationMs),
            active_duration_ms: Math.round(Math.max(0, sessionDurationMs - idleDurationMs)),
            inactivity_count: pageInactivityCount,
            inactivity_threshold_ms: PAGE_INACTIVITY_THRESHOLD_MS,
            reason,
        };
    }

    function beginPageInactivity(reason = 'threshold', now = Date.now()) {
        if (pageSessionEnded || pageIdleStartedAt !== null) return;
        pageIdleStartedAt = now;
        pageInactivityCount++;
        recordPageEvent('inactivity_start', inactivityMetadata(now, reason), {}, false);
    }

    function endPageInactivity(reason = 'activity', now = Date.now()) {
        if (pageIdleStartedAt === null) return;
        const durationMs = Math.max(0, now - pageIdleStartedAt);
        pageIdleDurationMs += durationMs;
        pageIdleStartedAt = null;
        recordPageEvent('inactivity_end', inactivityMetadata(now, reason, durationMs), {}, false);
    }

    function armPageInactivityTimer() {
        if (pageIdleTimer) clearTimeout(pageIdleTimer);
        pageIdleTimer = null;
        if (pageSessionEnded || pageIdleStartedAt !== null || !pageLastActivityAt) return;
        const remaining = PAGE_INACTIVITY_THRESHOLD_MS - (Date.now() - pageLastActivityAt);
        pageIdleTimer = setTimeout(() => {
            pageIdleTimer = null;
            beginPageInactivity('threshold');
        }, Math.max(250, remaining));
    }

    function markPageActivity(reason = 'activity') {
        if (pageSessionEnded) return;
        const now = Date.now();
        // Pointer/scroll events can arrive at a very high rate. While active,
        // one signal per second is enough to keep the inactivity deadline
        // accurate; an event always ends an already detected idle period.
        if (pageIdleStartedAt === null && now - pageLastActivitySignalAt < 1000) return;
        pageLastActivitySignalAt = now;
        endPageInactivity(reason, now);
        pageLastActivityAt = now;
        armPageInactivityTimer();
    }

    function finishPageTracking(reason = 'pagehide') {
        if (pageSessionEnded) return;
        pageSessionEnded = true;
        if (pageIdleTimer) clearTimeout(pageIdleTimer);
        pageIdleTimer = null;
        const now = Date.now();
        endPageInactivity(reason, now);
        if (visualSessionStarted) {
            recordPageEvent('session_end', {
                ...inactivityMetadata(now, reason),
                end_reason: reason,
            }, {}, false);
            recordPageEvent('page_exit', {}, {}, false);
        }
    }

    function recordSignificantPageScroll(source = null) {
        const viewport = pageViewportState();
        const scrollDelta = Math.max(
            Math.abs(viewport.scrollX - (lastRecordedPageScrollX ?? viewport.scrollX)),
            Math.abs(viewport.scrollY - (lastRecordedPageScrollY ?? viewport.scrollY)),
        );
        const significantDistance = Math.max(32, Math.round(viewport.viewportHeight * 0.12));
        const maxScroll = Math.max(1, viewport.pageHeight - viewport.viewportHeight);
        const atPageBoundary = viewport.scrollY <= 0 || viewport.scrollY >= maxScroll - 1;
        if (scrollDelta < significantDistance && !atPageBoundary) return false;

        lastRecordedPageScrollX = viewport.scrollX;
        lastRecordedPageScrollY = viewport.scrollY;
        const depth = Math.round(Math.max(0, Math.min(100, viewport.scrollY / maxScroll * 100)));
        recordPageEvent('scroll_depth', {
            depth,
            scroll_source: source || viewport.scrollSource,
        }, {}, true);
        return true;
    }

    function isWidgetSurfaceTarget(target) {
        if (!(target instanceof Element)) return false;
        return target === iframe
            || target.id === 'elchat-iframe'
            || !!target.closest('#elchat-iframe');
    }

    function scheduleGestureScrollFallback(deltaX, deltaY, source) {
        if (!Number.isFinite(deltaX) || !Number.isFinite(deltaY)) return;
        if (Math.max(Math.abs(deltaX), Math.abs(deltaY)) < 2) return;

        fallbackPendingDeltaX += deltaX;
        fallbackPendingDeltaY += deltaY;
        if (fallbackScrollTimer) clearTimeout(fallbackScrollTimer);
        const beforeNative = readNativePageScroll();
        const beforeViewport = pageViewportState();
        fallbackScrollTimer = setTimeout(() => {
            fallbackScrollTimer = null;
            const pendingX = fallbackPendingDeltaX;
            const pendingY = fallbackPendingDeltaY;
            fallbackPendingDeltaX = 0;
            fallbackPendingDeltaY = 0;
            if (Math.max(Math.abs(pendingX), Math.abs(pendingY)) < 2) return;

            const native = readNativePageScroll();
            if (native.x !== beforeNative.x || native.y !== beforeNative.y) {
                fallbackTenantScrollX = native.x;
                fallbackTenantScrollY = native.y;
                recordSignificantPageScroll(native.source);
                return;
            }

            // A custom tenant scroller may update its own scrollTop without
            // changing window/document/body. Let the normal viewport reader
            // win whenever that happened during the gesture.
            const afterViewport = pageViewportState();
            if (afterViewport.scrollX !== beforeViewport.scrollX || afterViewport.scrollY !== beforeViewport.scrollY) {
                recordSignificantPageScroll(afterViewport.scrollSource);
                return;
            }

            // Last resort for sites that implement scrolling through a wheel
            // handler, a transform, or a non-standard scrolling surface. This
            // is metadata only; it never scrolls the tenant DOM or the widget.
            const maxScrollX = Math.max(0, beforeViewport.pageWidth - beforeViewport.viewportWidth);
            const maxScrollY = Math.max(0, beforeViewport.pageHeight - beforeViewport.viewportHeight);
            fallbackTenantScrollX = Math.round(Math.max(0, Math.min(maxScrollX, beforeViewport.windowScrollX + pendingX)));
            fallbackTenantScrollY = Math.round(Math.max(0, Math.min(maxScrollY, beforeViewport.windowScrollY + pendingY)));
            recordSignificantPageScroll(source || 'gesture_fallback');
        }, 220);
    }

    function setupPageTracking() {
        pageTrackingStartedAt = Date.now();
        pageLastActivityAt = pageTrackingStartedAt;
        pageLastActivitySignalAt = pageTrackingStartedAt;
        recordPageEvent('session_start', {}, {}, false);
        recordPageEvent('page_view', {}, {}, true);
        startTenantReplayRecording();
        const initialViewport = pageViewportState();
        lastRecordedPageScrollX = initialViewport.scrollX;
        lastRecordedPageScrollY = initialViewport.scrollY;

        document.addEventListener('click', event => {
            markPageActivity('click');
            const details = targetDetails(event.target);
            recordPageEvent('click', details, details.label ? { label: details.label } : {}, true);
        }, true);

        document.addEventListener('pointermove', event => {
            markPageActivity('pointer_move');
            const now = Date.now();
            if (now - pagePointerLastAt < 250 || pagePointerEvents >= 1200) return;
            pagePointerLastAt = now;
            pagePointerEvents++;
            pageLastPointerX = Math.round(event.clientX);
            pageLastPointerY = Math.round(event.clientY);
            pageLastPointerType = event.pointerType || 'mouse';
            recordPageEvent('pointer_move', {}, {}, false);
        }, { passive: true, capture: true });

        const handleNativePageScroll = event => {
            markPageActivity('scroll');
            const target = event.target instanceof HTMLElement && isScrollableElement(event.target)
                ? event.target
                : document.scrollingElement;
            if (target instanceof HTMLElement) pageScrollTarget = target;
            const native = readNativePageScroll();
            fallbackTenantScrollX = native.x;
            fallbackTenantScrollY = native.y;
            fallbackPendingDeltaX = 0;
            fallbackPendingDeltaY = 0;
            if (fallbackScrollTimer) {
                clearTimeout(fallbackScrollTimer);
                fallbackScrollTimer = null;
            }
            if (pageScrollTimer) clearTimeout(pageScrollTimer);
            pageScrollTimer = setTimeout(() => {
                pageScrollTimer = null;
                recordSignificantPageScroll();
            }, 180);
        };
        document.addEventListener('scroll', handleNativePageScroll, { passive: true, capture: true });
        window.addEventListener('scroll', handleNativePageScroll, { passive: true });

        document.addEventListener('wheel', event => {
            if (isWidgetSurfaceTarget(event.target)) return;
            markPageActivity('wheel');
            const factor = event.deltaMode === 1 ? 16
                : event.deltaMode === 2 ? Math.max(1, window.innerHeight || document.documentElement.clientHeight || 1)
                    : 1;
            scheduleGestureScrollFallback(event.deltaX * factor, event.deltaY * factor, 'wheel_fallback');
        }, { passive: true, capture: true });

        document.addEventListener('touchstart', event => {
            if (isWidgetSurfaceTarget(event.target)) return;
            markPageActivity('touch');
            const touch = event.touches[0];
            if (!touch) return;
            touchScrollStartX = touch.clientX;
            touchScrollStartY = touch.clientY;
            touchScrollLastX = touch.clientX;
            touchScrollLastY = touch.clientY;
        }, { passive: true, capture: true });

        document.addEventListener('touchmove', event => {
            if (touchScrollStartX === null || touchScrollStartY === null) return;
            markPageActivity('touch');
            const touch = event.touches[0];
            if (!touch) return;
            touchScrollLastX = touch.clientX;
            touchScrollLastY = touch.clientY;
        }, { passive: true, capture: true });

        document.addEventListener('touchend', event => {
            if (touchScrollStartX === null || touchScrollStartY === null) return;
            if (!isWidgetSurfaceTarget(event.target) && touchScrollLastX !== null && touchScrollLastY !== null) {
                scheduleGestureScrollFallback(
                    touchScrollStartX - touchScrollLastX,
                    touchScrollStartY - touchScrollLastY,
                    'touch_fallback',
                );
            }
            touchScrollStartX = null;
            touchScrollStartY = null;
            touchScrollLastX = null;
            touchScrollLastY = null;
        }, { passive: true, capture: true });

        document.addEventListener('focusin', event => {
            markPageActivity('focus');
            const field = event.target instanceof Element ? event.target.closest('input,select,textarea,[contenteditable="true"]') : null;
            if (!field) return;
            const form = field.closest('form');
            if (form && pageFormStarts.has(form)) return;
            if (form) pageFormStarts.add(form);
            recordPageEvent('form_start', {
                form_id: hashValue(form?.getAttribute('id') || form?.getAttribute('name') || form?.getAttribute('action') || 'anonymous-form'),
                target: field.tagName.toLowerCase(),
            }, {}, true);
        }, true);

        document.addEventListener('submit', event => {
            markPageActivity('submit');
            const form = event.target instanceof HTMLFormElement ? event.target : null;
            recordPageEvent('form_submit', {
                form_id: hashValue(form?.getAttribute('id') || form?.getAttribute('name') || form?.getAttribute('action') || 'anonymous-form'),
            }, {}, true);
        }, true);

        document.addEventListener('input', () => markPageActivity('input'), { passive: true, capture: true });

        const originalPushState = history.pushState;
        history.pushState = function (...args) {
            const result = originalPushState.apply(this, args);
            recordNavigation('push_state');
            return result;
        };
        const originalReplaceState = history.replaceState;
        history.replaceState = function (...args) {
            const result = originalReplaceState.apply(this, args);
            recordNavigation('replace_state');
            return result;
        };
        window.addEventListener('popstate', () => recordNavigation('pop_state'));
        window.addEventListener('hashchange', () => recordNavigation('hash_change'));
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') beginPageInactivity('visibility_hidden');
            else markPageActivity('visibility_visible');
        }, { passive: true });
        window.addEventListener('focus', () => markPageActivity('window_focus'), { passive: true });
        window.addEventListener('pagehide', () => {
            finishPageTracking('pagehide');
            stopTenantReplayRecording();
            flushVisualEvents(true);
        }, { once: true });
    }

    function dataUrlToBlob(dataUrl) {
        const match = /^data:([^;,]+)?(?:;base64)?,(.*)$/s.exec(dataUrl || '');
        if (!match) return null;
        const mime = match[1] || 'image/jpeg';
        const payload = match[2];
        const binary = atob(payload);
        const bytes = new Uint8Array(binary.length);
        for (let index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index);
        return new Blob([bytes], { type: mime });
    }

    function uploadFrame(frame, frameContext = null) {
        const context = frameContext || pendingFrameContext || lastVisualContext || {};
        const surface = frame.surface || context.metadata?.surface || 'page';
        const metadata = {
            ...(context.metadata || {}),
            device: deviceType(),
            surface,
            screenshot_width: Number(frame.width) || 0,
            screenshot_height: Number(frame.height) || 0,
            viewport_width: Number(frame.viewport_width) || Number(context.metadata && context.metadata.viewport_width) || 0,
            viewport_height: Number(frame.viewport_height) || Number(context.metadata && context.metadata.viewport_height) || 0,
            page_width: Number(frame.page_width) || Number(context.metadata && context.metadata.page_width) || 0,
            page_height: Number(frame.page_height) || Number(context.metadata && context.metadata.page_height) || 0,
            scroll_x: Number(frame.scroll_x) || 0,
            scroll_y: Number(frame.scroll_y) || 0,
            scroll_source: frame.scroll_source || context.metadata?.scroll_source || 'unknown',
            capture_mode: frame.capture_mode || 'viewport',
            capture_scale: Number(frame.capture_scale) || 1,
            frame_index: frameSequence,
            cursor_x: frame.cursor_x ?? context.metadata?.cursor_x ?? null,
            cursor_y: frame.cursor_y ?? context.metadata?.cursor_y ?? null,
            cursor_page_x: frame.cursor_page_x ?? context.metadata?.cursor_page_x ?? null,
            cursor_page_y: frame.cursor_page_y ?? context.metadata?.cursor_page_y ?? null,
            scroll_positions: JSON.stringify(frame.scroll_positions || []),
        };
        [
            'host_viewport_width', 'host_viewport_height',
            'widget_left', 'widget_top', 'widget_width', 'widget_height',
        ].forEach(key => {
            if (frame[key] !== undefined && frame[key] !== null) metadata[key] = Number(frame[key]) || 0;
        });
        const blob = dataUrlToBlob(frame.data_url);
        if (!blob) return Promise.reject(new Error('invalid frame data URL'));
        const body = new FormData();
        body.append('visitor_uuid', visitorUUID);
        body.append('session_id', SESSION_ID);
        body.append('event_id', `${SESSION_ID}-frame-${++frameSequence}`);
        body.append('occurred_at', frame.occurred_at || new Date().toISOString());
        body.append('page_url', context.page_url || window.location.href);
        body.append('path', context.path || window.location.pathname || '/');
        body.append('title', context.title || document.title || '');
        body.append('metadata', JSON.stringify(metadata));
        body.append('screenshot', blob, `visitor-frame-${frameSequence}.jpg`);
        return fetch(VISUAL_FRAMES_URL, { method: 'POST', body }).then(response => {
            if (!response.ok) throw new Error(`visual frame HTTP ${response.status}`);
        });
    }

    function finishFrameCapture(frame) {
        const hasFrame = !!(frame && frame.data_url);
        const upload = hasFrame
            ? uploadFrame(frame)
            : Promise.reject(new Error('tenant page frame capture returned no image'));
        upload.then(() => {
            frameFailureCount = 0;
            tenantFirstFrameCaptured = true;
        }).catch(error => {
            frameFailureCount += 1;
            console.warn('[ELChat] Visitor Intelligence frame relay failed', error);
        })
            .finally(() => {
                frameInFlight = false;
                pendingFrameContext = null;
                if (frameRequestedAgain) {
                    frameRequestedAgain = false;
                    scheduleFrameCapture(120);
                } else if (frameFailureCount > 0 && frameFailureCount < 5) {
                    // Desktop browsers can fail the first html2canvas pass while
                    // fonts/images are settling. Retry with bounded backoff so a
                    // transient failure does not permanently disable replay.
                    scheduleFrameCapture(Math.min(4000, 350 * (2 ** (frameFailureCount - 1))));
                }
            });
    }

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

                // L'ouverture automatique globale est explicitement opt-in.
                // Cela reste désactivé si un ancien endpoint ne renvoie pas
                // encore le nouveau champ.
                config.auto_open_enabled = data.config.auto_open_enabled === true;

                const autoOpenDelay = Number(data.config.auto_open_delay);
                config.auto_open_delay = Number.isFinite(autoOpenDelay) && autoOpenDelay >= 0
                    ? autoOpenDelay
                    : DEFAULT_CONFIG.auto_open_delay;
            }
            createButton();
            setupAutoOpen();
            setupProactivePolling();
        })
        .catch(() => {
            console.warn('[ELChat] Config non trouvée → fallback par défaut');
            createButton();
            setupAutoOpen();
            setupProactivePolling();
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
        if (!config.auto_open_enabled || userClosed) return; // ✅ opt-in et ignore si l'utilisateur a fermé
        const delay = Number(config.auto_open_delay);
        if (!Number.isFinite(delay) || delay < 0) return;

        autoOpenTimer = setTimeout(() => {
            if (!isOpened) openIframe();
        }, delay * 1000);
    }

    // Livraison légère uniquement : la décision est prise côté serveur après
    // agrégation des événements Visitor Intelligence. Ce polling permet aussi
    // d'ouvrir le widget lorsque l'iframe n'était pas encore ouverte.
    function setupProactivePolling() {
        if (proactivePollTimer) return;

        const poll = async () => {
            proactivePollTimer = null;
            if (userClosed || isOpened || proactivePollInFlight) return;
            proactivePollInFlight = true;
            try {
                const response = await fetch(`${PROACTIVE_PENDING_URL}?visitor_uuid=${encodeURIComponent(visitorUUID)}`, { credentials: 'omit' });
                if (!response.ok) return;
                const payload = await response.json();
                const proactive = payload && payload.data;
                if (!proactive || proactive.id === lastProactiveMessageId) return;
                lastProactiveMessageId = proactive.id;

                if (proactive.behavior === 'auto_open') {
                    openIframe();
                } else if (btn) {
                    btn.classList.add('elchat-proactive-highlight');
                    btn.style.boxShadow = '0 0 0 6px rgba(239,156,255,.35), 0 4px 12px rgba(0,0,0,.22)';
                    btn.setAttribute('aria-label', 'ELChat a une suggestion pour vous');
                }
            } catch (_) {
                // Le transport de livraison ne doit jamais perturber le site hôte.
            } finally {
                proactivePollInFlight = false;
                if (!userClosed && !isOpened) proactivePollTimer = setTimeout(poll, 15000);
            }
        };

        poll();
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
        iframe.addEventListener('load', () => {
            // WIDGET_READY is the normal handshake. The load fallback keeps
            // replay alive when a desktop browser delays or drops a message
            // during iframe startup.
            scheduleFrameCapture(450);
            requestWidgetFrameCapture(520);
        }, { once: true });

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

        applyIframeLayout();
        document.body.appendChild(iframe);
    }

    function applyIframeLayout() {
        if (!iframe) return;
        const compact = window.matchMedia && window.matchMedia('(max-width: 680px)').matches;
        if (compact) {
            Object.assign(iframe.style, {
                width: 'min(calc(100vw - 16px), 420px)',
                height: 'min(calc(100dvh - 16px), 760px)',
                left: '8px',
                right: '8px',
                bottom: '8px',
                top: 'auto',
                borderRadius: '14px',
            });
        } else {
            iframe.style.width = '360px';
            iframe.style.height = '540px';
            iframe.style.left = '';
            iframe.style.right = '';
            iframe.style.bottom = '';
            iframe.style.top = '';
            const pos = (config.button.position || 'bottom-right').toLowerCase();
            if (pos.includes('bottom')) iframe.style.bottom = config.button.offsetY || '1rem';
            if (pos.includes('top')) iframe.style.top = config.button.offsetY || '1rem';
            if (pos.includes('right')) iframe.style.right = config.button.offsetX || '1rem';
            if (pos.includes('left')) iframe.style.left = config.button.offsetX || '1rem';
        }
    }

    /* =========================
       5️⃣ postMessage iframe ↔ parent
    ========================= */
    window.addEventListener('message', (event) => {
        if (!event.data || event.data.source !== 'elchat') return;
        if (iframe && event.source !== iframe.contentWindow) return;
        if (iframe && event.origin !== IFRAME_ORIGIN) return;

        switch (event.data.type) {
            case 'VISITOR_IDENTIFIED':
                if (typeof event.data.visitor_uuid === 'string') {
                    visitorUUID = event.data.visitor_uuid;
                    if (!visualSessionStarted) enqueueVisualEvent('session_start', { device: deviceType(), surface: 'widget' });
                    flushVisualEvents();
                }
                break;
            case 'WIDGET_READY':
                markPageActivity('widget_opened');
                enqueueVisualEvent('widget_opened', { device: deviceType(), surface: 'widget' });
                scheduleFrameCapture(80);
                requestWidgetFrameCapture(120);
                break;
            case 'WIDGET_VISUAL_FRAME':
                receiveWidgetFrame(event.data.payload || {});
                break;
            case 'VISITOR_VISUAL_EVENT': {
                markPageActivity('widget_interaction');
                const visual = event.data.payload || {};
                const tracked = enqueueVisualEvent(visual.event_type || 'pointer_move', visual.metadata || {}, {
                    resource_type: visual.resource_type,
                    label: visual.label,
                });
                if (tracked.event_type !== 'pointer_move') {
                    scheduleFrameCapture(tracked.event_type === 'page_view' ? 180 : 300);
                    requestWidgetFrameCapture(tracked.event_type === 'page_view' ? 220 : 340);
                } else if (String(visual.metadata?.surface || '').toLowerCase() === 'widget') {
                    const scrollX = Number(visual.metadata?.scroll_x);
                    const scrollY = Number(visual.metadata?.scroll_y);
                    if (
                        (Number.isFinite(scrollX) && scrollX !== lastWidgetObservedScrollX)
                        || (Number.isFinite(scrollY) && scrollY !== lastWidgetObservedScrollY)
                    ) {
                        lastWidgetObservedScrollX = Number.isFinite(scrollX) ? scrollX : lastWidgetObservedScrollX;
                        lastWidgetObservedScrollY = Number.isFinite(scrollY) ? scrollY : lastWidgetObservedScrollY;
                        requestWidgetFrameCapture(220);
                    }
                }
                if (tracked.event_type === 'page_view') flushVisualEvents();
                break;
            }
            case 'CLOSE_WIDGET':
                markPageActivity('widget_closed');
                enqueueVisualEvent('widget_close', { device: deviceType(), surface: 'widget' });
                flushVisualEvents(true);
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

    window.addEventListener('resize', applyIframeLayout, { passive: true });
    setupPageTracking();

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
        if (frameTimer) {
            clearTimeout(frameTimer);
            frameTimer = null;
        }
        if (widgetFrameTimer) {
            clearTimeout(widgetFrameTimer);
            widgetFrameTimer = null;
        }
        frameRequestedAgain = false;
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
