import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { gunzipSync } from 'node:zlib';
import { mkdtemp, rm } from 'node:fs/promises';
import { spawn } from 'node:child_process';
import { createRequire } from 'node:module';
import { createServer } from 'node:net';
import { fileURLToPath } from 'node:url';
import { findRecordedReplayViewport, normalizeRrwebEvent } from './video-helpers.mjs';
import { isAllowedReplayImageRequest, resolveReplayImageOrigin } from './video-network.mjs';

const require = createRequire(import.meta.url);
const requireCandidates = [
  path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../frontend/dashboard/node_modules/ws'),
  path.resolve(process.cwd(), '../frontend/dashboard/node_modules/ws'),
  path.resolve(process.cwd(), 'node_modules/ws'),
];
let WebSocketImpl = null;
for (const candidate of requireCandidates) {
  try {
    const loaded = require(candidate);
    WebSocketImpl = loaded?.WebSocket || loaded?.default || loaded;
    if (typeof WebSocketImpl === 'function') break;
  } catch {
    // Try the other supported deployment locations.
  }
}
if (typeof WebSocketImpl !== 'function') WebSocketImpl = globalThis.WebSocket;

const manifestPath = process.argv[2];
if (!manifestPath || !fs.existsSync(manifestPath)) throw new Error('replay_video_manifest_missing');
const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
const outputPath = String(manifest.output_path || '');
const tempRoot = await mkdtemp(path.join(os.tmpdir(), 'elchat-replay-video-'));
let chromiumProcess;
let ffmpegProcess;
let browserProfile;
let shuttingDown = false;

function emitProgress(progress, phase, message) {
  process.stderr.write(`ELCHAT_PROGRESS:${JSON.stringify({ progress, phase, message })}\n`);
}

function wait(milliseconds) {
  return new Promise(resolve => setTimeout(resolve, milliseconds));
}

function chromiumCandidates(explicit) {
  const candidates = [
    explicit,
    '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable',
    '/usr/local/bin/chromium', '/usr/lib/chromium/chromium',
    '/usr/lib/chromium/chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser',
    '/snap/chromium/current/usr/lib/chromium/chrome',
    process.env.ProgramFiles ? path.join(process.env.ProgramFiles, 'Google', 'Chrome', 'Application', 'chrome.exe') : '',
    process.env['ProgramFiles(x86)'] ? path.join(process.env['ProgramFiles(x86)'], 'Google', 'Chrome', 'Application', 'chrome.exe') : '',
    process.env.LOCALAPPDATA ? path.join(process.env.LOCALAPPDATA, 'Google', 'Chrome', 'Application', 'chrome.exe') : '',
    process.env.ProgramFiles ? path.join(process.env.ProgramFiles, 'Microsoft', 'Edge', 'Application', 'msedge.exe') : '',
  ].filter(Boolean);
  const existing = candidates.filter(candidate => fs.existsSync(candidate));
  const direct = existing.filter(candidate => {
    try {
      const real = fs.realpathSync(candidate);
      if (real.startsWith('/snap/') || real === '/usr/bin/snap') return false;
      const descriptor = fs.openSync(candidate, 'r');
      const header = Buffer.alloc(8192);
      let bytesRead = 0;
      try { bytesRead = fs.readSync(descriptor, header, 0, header.length, 0); }
      finally { fs.closeSync(descriptor); }
      const source = header.subarray(0, bytesRead).toString('utf8');
      return !source.includes('/snap/bin/snap')
        && !/snap\s+(run|exec)\s+chromium/.test(source)
        && !source.includes('snap.chromium.chromium');
    } catch {
      return true;
    }
  });
  return direct[0] || null;
}

function freePort() {
  return new Promise((resolve, reject) => {
    const server = createServer();
    server.once('error', reject);
    server.listen({ host: '127.0.0.1', port: 0 }, () => {
      const address = server.address();
      const port = typeof address === 'object' && address ? address.port : null;
      server.close(error => error ? reject(error) : resolve(port));
    });
  });
}

function processInfo(child, stderr, spawnError = null) {
  if (spawnError) return String(spawnError.message || spawnError).slice(0, 500);
  const detail = String(stderr || '').replace(/\s+/g, ' ').trim();
  return detail.slice(-500) || (child?.exitCode !== null && child?.exitCode !== undefined ? `exit_code_${child.exitCode}` : '');
}

async function launchChromium(chrome, width, height, imagePolicy = null) {
  let lastError;
  for (const headlessFlag of ['--headless=new', '--headless']) {
    const profile = await mkdtemp(path.join(os.tmpdir(), 'elchat-rrweb-video-profile-'));
    const port = await freePort();
    let stderr = '';
    let spawnError = null;
    const child = spawn(chrome, [
      headlessFlag,
      '--disable-gpu', '--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage',
      '--disable-extensions', '--disable-background-networking', '--disable-crash-reporter',
      '--no-first-run', '--no-default-browser-check', '--hide-scrollbars',
      ...(imagePolicy ? [`--host-resolver-rules=MAP ${imagePolicy.hostname} ${imagePolicy.address}`] : []),
      '--remote-allow-origins=*', '--remote-debugging-address=127.0.0.1', `--remote-debugging-port=${port}`,
      `--user-data-dir=${profile}`, 'about:blank',
    ], { stdio: ['ignore', 'ignore', 'pipe'], windowsHide: true });
    child.stderr?.on('data', chunk => { stderr = (stderr + String(chunk)).slice(-3000); });
    child.once('error', error => { spawnError = error; });

    try {
      const started = Date.now();
      while (Date.now() - started < 15000) {
        if (spawnError) throw new Error(`chromium_spawn_failed:${processInfo(child, stderr, spawnError)}`);
        if (child.exitCode !== null) throw new Error(`chromium_process_exit:${processInfo(child, stderr)}`);
        try {
          const response = await fetch(`http://127.0.0.1:${port}/json/version`);
          if (response.ok) {
            const version = await response.json();
            browserProfile = profile;
            chromiumProcess = child;
            return { child, profile, port, version, width, height };
          }
        } catch {
          // Chromium is still starting.
        }
        await wait(100);
      }
      throw new Error(`chromium_debug_endpoint_timeout:${processInfo(child, stderr)}`);
    } catch (error) {
      lastError = error;
      try { child.kill(); } catch {}
      await rm(profile, { recursive: true, force: true }).catch(() => {});
      await wait(150);
    }
  }
  throw lastError || new Error('chromium_start_failed');
}

class CdpClient {
  constructor(socket) {
    this.socket = socket;
    this.nextId = 0;
    this.pending = new Map();
    this.listeners = new Map();
    const handleMessage = data => {
      if (data?.data !== undefined) data = data.data;
      let message;
      try { message = JSON.parse(String(data)); } catch { return; }
      if (message.method) {
        for (const listener of this.listeners.get(message.method) || []) {
          Promise.resolve(listener(message.params || {}, message.sessionId)).catch(() => {});
        }
        return;
      }
      const pending = this.pending.get(message.id);
      if (!pending) return;
      this.pending.delete(message.id);
      message.error ? pending.reject(new Error(message.error.message || 'cdp_error')) : pending.resolve(message);
    };
    if (typeof socket.on === 'function') socket.on('message', handleMessage);
    else socket.addEventListener('message', handleMessage);
  }

  static connect(socket) {
    return new Promise((resolve, reject) => {
      if (typeof socket.once === 'function') {
        socket.once('error', reject);
        socket.once('open', () => resolve(new CdpClient(socket)));
      } else {
        socket.addEventListener('error', reject, { once: true });
        socket.addEventListener('open', () => resolve(new CdpClient(socket)), { once: true });
      }
    });
  }

  call(method, params = {}, sessionId) {
    const id = ++this.nextId;
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        this.pending.delete(id);
        reject(new Error(`cdp_timeout:${method}`));
      }, 30000);
      this.pending.set(id, {
        resolve: value => { clearTimeout(timer); resolve(value); },
        reject: error => { clearTimeout(timer); reject(error); },
      });
      const message = { id, method, params };
      if (sessionId) message.sessionId = sessionId;
      this.socket.send(JSON.stringify(message));
    });
  }

  on(method, listener) {
    if (!this.listeners.has(method)) this.listeners.set(method, new Set());
    this.listeners.get(method).add(listener);
  }

  async evaluate(expression, sessionId, awaitPromise = false) {
    const result = await this.call('Runtime.evaluate', {
      expression,
      awaitPromise,
      returnByValue: true,
      userGesture: true,
    }, sessionId);
    const detail = result.result?.exceptionDetails;
    if (detail) throw new Error(detail.exception?.description || detail.text || 'replay_evaluation_failed');
    return result.result?.result?.value;
  }
}

function decodeEvents() {
  const maxDecodedBytes = Math.max(1024 * 1024, Number(manifest.max_decoded_bytes || 0));
  const maxEvents = Math.max(2, Number(manifest.max_events || 0));
  let decodedBytes = 0;
  let eventOrder = 0;
  const events = [];

  for (const chunk of Array.isArray(manifest.chunks) ? manifest.chunks : []) {
    if (!chunk?.path || !fs.existsSync(chunk.path)) throw new Error('replay_video_chunk_missing');
    const compressed = fs.readFileSync(chunk.path);
    const remaining = maxDecodedBytes - decodedBytes;
    if (remaining <= 0) throw new Error('replay_video_decoded_payload_too_large');
    let decoded;
    try {
      decoded = gunzipSync(compressed, { maxOutputLength: remaining });
    } catch {
      throw new Error('replay_video_chunk_decode_failed');
    }
    decodedBytes += decoded.byteLength;
    let items;
    try { items = JSON.parse(decoded.toString('utf8')); } catch { throw new Error('replay_video_chunk_json_invalid'); }
    if (!Array.isArray(items)) throw new Error('replay_video_chunk_shape_invalid');
    for (const event of items) {
      if (!event || !Number.isFinite(Number(event.timestamp)) || !Number.isFinite(Number(event.type))) continue;
      events.push({ event, order: eventOrder++ });
      if (events.length > maxEvents) throw new Error('replay_video_too_many_events');
    }
  }

  events.sort((left, right) => Number(left.event.timestamp) - Number(right.event.timestamp) || left.order - right.order);
  const result = events.map(item => normalizeRrwebEvent(item.event));
  if (result.length < 2) throw new Error('replay_video_events_missing');
  if (!result.some(event => Number(event.type) === 2)) throw new Error('replay_video_initial_snapshot_missing');
  return { events: result, decodedBytes };
}

async function waitForExit(child, name) {
  return new Promise((resolve, reject) => {
    let stderr = '';
    let spawnError = null;
    child.stderr?.on('data', data => { stderr = (stderr + String(data)).slice(-5000); });
    child.once('error', error => { spawnError = error; });
    child.once('close', code => {
      if (spawnError) reject(new Error(`${name}_spawn_failed:${processInfo(child, stderr, spawnError)}`));
      else if (code !== 0) reject(new Error(`${name}_failed:${processInfo(child, stderr) || `exit_code_${code}`}`));
      else resolve();
    });
  });
}

async function waitForDrain(writable) {
  if (!writable.writableNeedDrain) return;
  await new Promise((resolve, reject) => {
    writable.once('drain', resolve);
    writable.once('error', reject);
  });
}

function activeWidgetAt(widgetEvents, timestamp) {
  let open = false;
  let frame = null;
  for (const event of widgetEvents) {
    if (Number(event.timestamp) > timestamp) break;
    if (event.kind === 'open') open = true;
    else if (event.kind === 'close') open = false;
    else if (event.kind === 'frame') frame = event;
  }
  return open ? frame : null;
}

function buildReplayTimeMap(events, skipInactive) {
  const firstTimestamp = Number(events[0].timestamp);
  const finalTimestamp = Number(events[events.length - 1].timestamp);
  if (!skipInactive) return { duration: Math.max(0, finalTimestamp - firstTimestamp), toOriginal: value => Math.max(0, Math.min(finalTimestamp - firstTimestamp, value)) };

  const interactionTimes = [];
  for (const event of events) {
    if (Number(event.type) !== 3) continue;
    const source = Number(event.data?.source);
    if (source > 2 && source <= 7) interactionTimes.push(Number(event.timestamp) - firstTimestamp);
  }
  const uniqueInteractions = [...new Set(interactionTimes)].filter(value => value >= 0).sort((left, right) => left - right);
  if (!uniqueInteractions.length || uniqueInteractions[0] > 0) uniqueInteractions.unshift(0);
  if (uniqueInteractions[uniqueInteractions.length - 1] < finalTimestamp - firstTimestamp) uniqueInteractions.push(finalTimestamp - firstTimestamp);

  const maxFastForward = 8;
  const inactivityThreshold = 10000;
  const segments = [];
  let compressedElapsed = 0;
  for (let index = 0; index < uniqueInteractions.length - 1; index++) {
    const originalStart = uniqueInteractions[index];
    const originalEnd = uniqueInteractions[index + 1];
    const gap = Math.max(0, originalEnd - originalStart);
    const normal = Math.min(gap, inactivityThreshold);
    const compressedDuration = normal + Math.max(0, gap - normal) / maxFastForward;
    segments.push({ originalStart, originalEnd, compressedStart: compressedElapsed, compressedEnd: compressedElapsed + compressedDuration, normal });
    compressedElapsed += compressedDuration;
  }

  return {
    duration: compressedElapsed,
    toOriginal(value) {
      const time = Math.max(0, Math.min(compressedElapsed, value));
      const segment = segments.find(item => time <= item.compressedEnd) || segments[segments.length - 1];
      if (!segment) return time;
      const compressedOffset = time - segment.compressedStart;
      const originalOffset = compressedOffset <= segment.normal
        ? compressedOffset
        : segment.normal + (compressedOffset - segment.normal) * maxFastForward;
      return Math.max(0, Math.min(finalTimestamp - firstTimestamp, segment.originalStart + originalOffset));
    },
  };
}

function overlayScript(frame, imageData) {
  const state = frame ? {
    key: frame.key,
    image: imageData,
    left: Math.max(0, Math.min(100, Number(frame.left) / Number(frame.host_width) * 100)),
    top: Math.max(0, Math.min(100, Number(frame.top) / Number(frame.host_height) * 100)),
    width: Math.max(0, Math.min(100, Number(frame.width) / Number(frame.host_width) * 100)),
    height: Math.max(0, Math.min(100, Number(frame.height) / Number(frame.host_height) * 100)),
  } : null;
  return `(async function(){
    var box=document.getElementById('elchat-widget-overlay');
    if(!box){box=document.createElement('div');box.id='elchat-widget-overlay';box.innerHTML='<img alt="" />';document.body.appendChild(box);}
    if(!${state ? 'true' : 'false'}){box.style.display='none';return true;}
    var state=${JSON.stringify(state)};var image=box.querySelector('img');
    if(state.image){image.src=state.image;box.dataset.frame=state.key;}
    if(!image.src){box.style.display='none';return true;}
    try{if(typeof image.decode==='function')await image.decode();else if(!image.complete)await new Promise(function(resolve){image.addEventListener('load',resolve,{once:true});image.addEventListener('error',resolve,{once:true});});}catch(_){}
    box.style.left=state.left+'%';box.style.top=state.top+'%';box.style.width=state.width+'%';box.style.height=state.height+'%';box.style.display='block';
    return true;
  })()`;
}

async function ensureFfmpeg(binary) {
  await new Promise((resolve, reject) => {
    let stderr = '';
    let child;
    try { child = spawn(binary, ['-version'], { stdio: ['ignore', 'ignore', 'pipe'], windowsHide: true }); }
    catch (error) { reject(new Error(`ffmpeg_unavailable:${String(error?.message || error).slice(0, 300)}`)); return; }
    child.stderr?.on('data', data => { stderr = (stderr + String(data)).slice(-1000); });
    child.once('error', error => reject(new Error(`ffmpeg_unavailable:${String(error?.message || error).slice(0, 300)}`)));
    child.once('close', code => code === 0 ? resolve() : reject(new Error(`ffmpeg_unavailable:${stderr.slice(-300) || `exit_code_${code}`}`)));
  });
}

async function main() {
  if (typeof WebSocketImpl !== 'function') throw new Error('rrweb_video_websocket_runtime_missing');
  if (!outputPath || !Array.isArray(manifest.chunks) || !manifest.chunks.length) throw new Error('replay_video_input_invalid');

  const { events, decodedBytes } = decodeEvents();
  const firstTimestamp = Number(events[0].timestamp);
  const lastTimestamp = Number(events[events.length - 1].timestamp);
  const totalTime = Math.max(0, lastTimestamp - firstTimestamp);
  const maxDuration = Math.max(1, Number(manifest.max_duration_seconds || 600)) * 1000;
  if (totalTime <= 0 || totalTime > maxDuration) throw new Error('replay_video_duration_out_of_range');

  const viewport = findRecordedReplayViewport(events, {
    maxOutputDimension: manifest.max_output_dimension,
  });
  const { sourceWidth, sourceHeight, deviceScaleFactor, width, height } = viewport;
  const fps = Math.max(6, Math.min(20, Math.round(Number(manifest.fps || 12))));
  const speed = Math.max(0.5, Math.min(4, Number(manifest.speed || 1)));
  const timeMap = buildReplayTimeMap(events, Boolean(manifest.skip_inactive));
  const outputDuration = timeMap.duration / speed;
  const frameCount = Math.max(2, Math.ceil(outputDuration * fps / 1000) + 1);
  if (frameCount > maxDuration / 1000 * fps * 2 + 2) throw new Error('replay_video_frame_limit_exceeded');

  const chrome = chromiumCandidates(manifest.chromium_path);
  if (!chrome) throw new Error('chromium_direct_binary_missing');
  const stylePath = path.join(path.dirname(String(manifest.replay_umd_path || '')), 'style.css');
  const bundlePath = String(manifest.replay_umd_path || '');
  if (!fs.existsSync(bundlePath) || !fs.existsSync(stylePath)) throw new Error('rrweb_replay_bundle_or_styles_missing');
  await ensureFfmpeg(String(manifest.ffmpeg_binary || 'ffmpeg'));

  emitProgress(8, 'preparation', 'Données rrweb validées.');
  // Older rrweb captures keep only image URLs. Let the same rrweb Replayer
  // resolve those images, but pin the site's DNS and intercept every request:
  // only GET image resources to the exact public site origin can leave Chrome.
  const imagePolicy = await resolveReplayImageOrigin(manifest.site_origin);
  const browser = await launchChromium(chrome, sourceWidth, sourceHeight, imagePolicy);
  const socket = new WebSocketImpl(browser.version.webSocketDebuggerUrl);
  const client = await CdpClient.connect(socket);
  const target = await client.call('Target.createTarget', { url: 'about:blank' });
  const attached = await client.call('Target.attachToTarget', { targetId: target.result.targetId, flatten: true });
  const sessionId = attached.result.sessionId;
  await client.call('Page.enable', {}, sessionId);
  await client.call('Runtime.enable', {}, sessionId);
  await client.call('Network.enable', {}, sessionId);
  // All HTTP(S) requests are paused and denied by default. Only the site's
  // pinned public origin may serve GET image resources to the replay.
  client.on('Fetch.requestPaused', async (request, pausedSessionId) => {
    const allowed = pausedSessionId === sessionId && isAllowedReplayImageRequest(request, imagePolicy);
    try {
      await client.call(
        allowed ? 'Fetch.continueRequest' : 'Fetch.failRequest',
        allowed ? { requestId: request.requestId } : { requestId: request.requestId, errorReason: 'BlockedByClient' },
        pausedSessionId,
      );
    } catch {
      if (allowed) {
        try {
          await client.call('Fetch.failRequest', { requestId: request.requestId, errorReason: 'BlockedByClient' }, pausedSessionId);
        } catch { /* fail closed if the interception cannot be resolved */ }
      }
    }
  });
  await client.call('Network.setBlockedURLs', { urls: ['file://*/*', 'ftp://*/*', 'ws://*/*', 'wss://*/*'] }, sessionId);
  await client.call('Fetch.enable', {
    patterns: [
      { urlPattern: 'http://*/*', requestStage: 'Request' },
      { urlPattern: 'https://*/*', requestStage: 'Request' },
    ],
  }, sessionId);
  await client.call('Emulation.setDeviceMetricsOverride', {
    width: sourceWidth,
    height: sourceHeight,
    deviceScaleFactor,
    screenWidth: sourceWidth,
    screenHeight: sourceHeight,
    // Mobile emulation without a top-level viewport meta tag makes Chrome
    // shrink an about:blank renderer surface to its legacy 980px layout width.
    // The replayed document already supplies its own viewport metadata.
    mobile: false,
  }, sessionId);

  const css = fs.readFileSync(stylePath, 'utf8');
  const bundle = fs.readFileSync(bundlePath, 'utf8');
  const setup = [
    'var module=undefined;var exports=undefined;var define=undefined;',
    `var rrwebStyle=document.createElement('style');rrwebStyle.textContent=${JSON.stringify(css)};document.head.appendChild(rrwebStyle);`,
    `var rrwebExportStyle=document.createElement('style');rrwebExportStyle.textContent=${JSON.stringify('html,body{margin:0!important;width:100%;height:100%;overflow:hidden!important;background:#fff!important}.replayer-wrapper{margin:0!important;max-width:none!important;max-height:none!important;overflow:hidden!important;transform:none!important}.replayer-wrapper iframe{display:block!important;max-width:none!important;max-height:none!important;border:0!important}#elchat-widget-overlay{position:fixed;z-index:2147483647;overflow:hidden;border-radius:12px;box-shadow:0 10px 24px rgba(20,24,70,.28);pointer-events:none}#elchat-widget-overlay img{display:block;width:100%;height:100%;max-width:none!important;max-height:none!important;object-fit:fill}')};document.head.appendChild(rrwebExportStyle);`,
    bundle,
    'globalThis.__elchatReplay=globalThis.rrwebReplay||globalThis.rrweb||globalThis.__elchatReplay;',
    'globalThis.__elchatEvents=[];',
    'globalThis.__elchatReplayer=null;',
  ].join('\n');
  await client.evaluate(setup, sessionId);
  const batchSize = 1000;
  for (let offset = 0; offset < events.length; offset += batchSize) {
    const batch = events.slice(offset, offset + batchSize);
    await client.evaluate(`globalThis.__elchatEvents.push(...${JSON.stringify(batch)});true;`, sessionId);
    if (offset === 0 || offset + batchSize >= events.length) {
      emitProgress(8 + Math.round(Math.min(events.length, offset + batch.length) / events.length * 2), 'preparation', 'Chargement des événements dans le moteur rrweb.');
    }
  }
  const replayerResult = await client.evaluate('(function(){var Replayer=globalThis.__elchatReplay&&globalThis.__elchatReplay.Replayer;if(!Replayer)return "replayer_constructor_missing";globalThis.__elchatReplayer=new Replayer(globalThis.__elchatEvents,{root:document.body,useVirtualDom:true,skipInactive:false,inactivePeriodThreshold:10000,showWarning:false,showDebug:false,mouseTail:{duration:650,lineCap:"round",lineWidth:4,strokeStyle:"#ef3340"},triggerFocus:false,pauseAnimation:false,blockClass:"rr-block"});globalThis.__elchatReplayer.pause(0);return "ok";})()', sessionId);
  if (replayerResult !== 'ok') throw new Error(String(replayerResult || 'rrweb_replayer_setup_failed'));

  // H.264 4:2:0 requires even dimensions. Use 4:4:4 for an odd-sized
  // recorded viewport so the exported raster remains pixel-exact (no padding).
  const pixelFormat = width % 2 === 0 && height % 2 === 0 ? 'yuv420p' : 'yuv444p';
  const ffmpeg = spawn(String(manifest.ffmpeg_binary || 'ffmpeg'), [
    '-hide_banner', '-loglevel', 'error', '-y',
    '-f', 'image2pipe', '-vcodec', 'mjpeg', '-framerate', String(fps), '-i', 'pipe:0',
    '-an', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', pixelFormat,
    '-movflags', '+faststart', outputPath,
  ], { stdio: ['pipe', 'ignore', 'pipe'], windowsHide: true });
  ffmpegProcess = ffmpeg;
  const ffmpegDone = waitForExit(ffmpeg, 'ffmpeg');
  const widgetEvents = (Array.isArray(manifest.widget_events) ? manifest.widget_events : [])
    .filter(event => Number.isFinite(Number(event.timestamp)))
    .sort((left, right) => Number(left.timestamp) - Number(right.timestamp));
  const imageCache = new Map();
  let lastFrameKey = null;
  const reportEvery = Math.max(1, Math.floor(frameCount / 20));
  emitProgress(10, 'rendering', 'Rendu du replay rrweb.');

  const waitForInlineImages = `(
    async function(){
      var documents=[document];
      document.querySelectorAll('iframe').forEach(function(frame){try{if(frame.contentDocument)documents.push(frame.contentDocument);}catch(_){}});
      var images=documents.flatMap(function(doc){return Array.from(doc.images||[]).filter(function(image){return Boolean(image.currentSrc||image.getAttribute('src'));});});
      await Promise.race([
        Promise.all(images.map(function(image){return new Promise(function(resolve){var done=false;var finish=function(){if(done)return;done=true;image.removeEventListener('load',finish);image.removeEventListener('error',finish);resolve();};if(image.complete){Promise.resolve(typeof image.decode==='function'?image.decode():undefined).catch(function(){}).then(finish);}else{image.addEventListener('load',finish,{once:true});image.addEventListener('error',finish,{once:true});setTimeout(finish,6000);}});})),
        new Promise(function(resolve){setTimeout(resolve,6000);})
      ]);
      return true;
    }
  )()`;

  for (let frameIndex = 0; frameIndex < frameCount; frameIndex++) {
    const playbackTime = Math.min(timeMap.duration, frameIndex * 1000 / fps * speed);
    const replayOffset = Math.min(totalTime, timeMap.toOriginal(playbackTime));
    const absoluteTimestamp = firstTimestamp + replayOffset;
    const widgetFrame = activeWidgetAt(widgetEvents, absoluteTimestamp);
    let imageData = null;
    let frameKey = null;
    if (widgetFrame) {
      frameKey = String(widgetFrame.key);
      if (frameKey !== lastFrameKey) {
        if (!imageCache.has(frameKey)) {
          const image = fs.readFileSync(widgetFrame.path);
          if (image.byteLength > 524288) throw new Error('replay_video_widget_frame_too_large');
          const ext = path.extname(widgetFrame.path).toLowerCase();
          const mime = ext === '.png' ? 'image/png' : ext === '.webp' ? 'image/webp' : 'image/jpeg';
          imageCache.set(frameKey, `data:${mime};base64,${image.toString('base64')}`);
        }
        imageData = imageCache.get(frameKey);
      }
      lastFrameKey = frameKey;
    } else {
      lastFrameKey = null;
    }

    const seekAndPaint = [
      '(async function(){',
      `globalThis.__elchatReplayer.pause(${JSON.stringify(replayOffset)});`,
      'await new Promise(function(resolve){requestAnimationFrame(function(){requestAnimationFrame(resolve);});});',
      `await (${waitForInlineImages});`,
      `return await ${overlayScript(widgetFrame, imageData)};`,
      '})()',
    ].join('\n');
    await client.evaluate(seekAndPaint, sessionId, true);
    const shot = await client.call('Page.captureScreenshot', { format: 'jpeg', quality: 82, fromSurface: true, captureBeyondViewport: false }, sessionId);
    const frame = Buffer.from(String(shot.result?.data || ''), 'base64');
    if (frame.byteLength < 256) throw new Error('replay_video_frame_capture_failed');
    if (!ffmpeg.stdin.write(frame)) await waitForDrain(ffmpeg.stdin);

    if (frameIndex % reportEvery === 0 || frameIndex === frameCount - 1) {
      const renderProgress = 10 + Math.round((frameIndex + 1) / frameCount * 82);
      emitProgress(Math.min(92, renderProgress), 'rendering', `Rendu des images vidéo (${frameIndex + 1}/${frameCount}).`);
    }
  }

  emitProgress(94, 'encoding', 'Encodage du fichier MP4.');
  ffmpeg.stdin.end();
  await ffmpegDone;
  if (!fs.existsSync(outputPath) || fs.statSync(outputPath).size < 1024) throw new Error('replay_video_output_invalid');
  emitProgress(99, 'finalizing', 'Vérification du fichier vidéo.');
  return { status: 'ready', duration_ms: Math.round(outputDuration), frame_count: frameCount, fps, width, height, decoded_bytes: decodedBytes };
}

async function cleanup() {
  if (shuttingDown) return;
  shuttingDown = true;
  try { ffmpegProcess?.kill('SIGTERM'); } catch {}
  try { chromiumProcess?.kill('SIGTERM'); } catch {}
  if (browserProfile) await rm(browserProfile, { recursive: true, force: true }).catch(() => {});
  await rm(tempRoot, { recursive: true, force: true }).catch(() => {});
}

process.once('SIGTERM', () => { void cleanup().finally(() => { process.exitCode = 143; }); });
process.once('SIGINT', () => { void cleanup().finally(() => { process.exitCode = 130; }); });

try {
  const result = await main();
  process.stdout.write(JSON.stringify(result));
} catch (error) {
  process.stderr.write(`ELCHAT_ERROR:${String(error?.message || error).replace(/\s+/g, ' ').slice(0, 1000)}\n`);
  process.exitCode = 1;
} finally {
  await cleanup();
}
