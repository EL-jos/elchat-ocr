import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { gunzipSync } from 'node:zlib';
import { mkdtemp, rm } from 'node:fs/promises';
import { randomInt } from 'node:crypto';
import { spawn } from 'node:child_process';

const input = await readStdin();

function readStdin() {
  return new Promise((resolve, reject) => {
    let value = '';
    process.stdin.setEncoding('utf8');
    process.stdin.on('data', chunk => { value += chunk; });
    process.stdin.on('end', () => resolve(value));
    process.stdin.on('error', reject);
  });
}

function decodeChunks(chunks) {
  const events = [];
  for (const chunk of Array.isArray(chunks) ? chunks : []) {
    try {
      if (chunk.format !== 'rrweb-json-gzip-base64') continue;
      const json = gunzipSync(Buffer.from(String(chunk.payload || ''), 'base64')).toString('utf8');
      const decoded = JSON.parse(json);
      if (Array.isArray(decoded)) events.push(...decoded);
    } catch {
      // One malformed chunk must not prevent the other chunks from being used.
    }
  }
  return events
    .filter(event => event && Number.isFinite(Number(event.timestamp)))
    .sort((left, right) => Number(left.timestamp) - Number(right.timestamp));
}

function redact(value) {
  return String(value || '')
    .replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi, '[email]')
    .replace(/(?:\+?\d[\d .()-]{7,}\d)/g, '[phone]')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 240);
}

function chromiumCandidates(explicit) {
  const candidates = [];
  if (explicit) candidates.push(explicit);
  candidates.push(
    '/usr/bin/google-chrome',
    '/usr/bin/google-chrome-stable',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
    process.env.ProgramFiles ? path.join(process.env.ProgramFiles, 'Google', 'Chrome', 'Application', 'chrome.exe') : '',
    process.env['ProgramFiles(x86)'] ? path.join(process.env['ProgramFiles(x86)'], 'Google', 'Chrome', 'Application', 'chrome.exe') : '',
    process.env.LOCALAPPDATA ? path.join(process.env.LOCALAPPDATA, 'Google', 'Chrome', 'Application', 'chrome.exe') : '',
    process.env.ProgramFiles ? path.join(process.env.ProgramFiles, 'Microsoft', 'Edge', 'Application', 'msedge.exe') : '',
  );
  return candidates.filter(candidate => candidate && fs.existsSync(candidate));
}

function wait(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

async function waitForJsonVersion(port, timeoutMs = 15000) {
  const started = Date.now();
  while (Date.now() - started < timeoutMs) {
    try {
      const response = await fetch('http://127.0.0.1:' + port + '/json/version');
      if (response.ok) return await response.json();
    } catch {
      // Chrome is still starting.
    }
    await wait(100);
  }
  throw new Error('chromium_debug_endpoint_timeout');
}

function compactText(document) {
  const values = [];
  const walker = document.createTreeWalker(document.body || document.documentElement, NodeFilter.SHOW_TEXT);
  let node;
  while ((node = walker.nextNode()) && values.length < 30) {
    const text = redact(node.nodeValue);
    if (text.length >= 3) values.push(text);
  }
  return values.join(' ').slice(0, 1200);
}

function buildExtractionScript(timestamp, capture) {
  const encodedTimestamp = JSON.stringify(Number(timestamp));
  return [
    '(async function () {',
    '  var replay = globalThis.__elchatReplayer;',
    '  if (!replay) return { error: "replayer_missing" };',
    '  replay.pause(' + encodedTimestamp + ');',
    '  await new Promise(function (resolve) { setTimeout(resolve, 80); });',
    '  var iframe = document.querySelector("iframe");',
    '  var doc = iframe && iframe.contentDocument;',
    '  if (!doc || !doc.body) return { error: "replay_document_missing" };',
    '  var viewport = { width: window.innerWidth || 0, height: window.innerHeight || 0 };',
    '  var page = { width: Math.max(doc.documentElement.scrollWidth || 0, doc.body.scrollWidth || 0), height: Math.max(doc.documentElement.scrollHeight || 0, doc.body.scrollHeight || 0) };',
    '  var scroll = { x: iframe.contentWindow ? iframe.contentWindow.scrollX || 0 : 0, y: iframe.contentWindow ? iframe.contentWindow.scrollY || 0 : 0 };',
    '  var redactText = function (value) { return String(value || "").replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,}/gi, "[email]").replace(/(?:\\+?\\d[\\d .()\\-]{7,}\\d)/g, "[phone]").replace(/\\s+/g, " ").trim().slice(0, 240); };',
    '  var elements = [];',
    '  var nodes = doc.querySelectorAll("a,button,input,select,textarea,[role],h1,h2,h3,h4,[data-testid]");',
    '  for (var i = 0; i < nodes.length && elements.length < 80; i++) {',
    '    var element = nodes[i];',
    '    var rect = element.getBoundingClientRect();',
    '    if (rect.bottom <= 0 || rect.right <= 0 || rect.top >= viewport.height || rect.left >= viewport.width) continue;',
    '    var label = element.getAttribute("aria-label") || element.innerText || element.value || element.getAttribute("placeholder") || "";',
    '    label = redactText(label).slice(0, 180);',
    '    if (!label && !["INPUT", "SELECT", "TEXTAREA"].includes(element.tagName)) continue;',
    '    elements.push({ tag: element.tagName.toLowerCase(), role: element.getAttribute("role"), label: label, id: element.id || null, href: redactText(element.getAttribute("href")), rect: { left: Math.round(rect.left), top: Math.round(rect.top), width: Math.round(rect.width), height: Math.round(rect.height) } });',
    '  }',
    '  var texts = []; var walker = doc.createTreeWalker(doc.body || doc.documentElement, 4); var textNode;',
    '  while ((textNode = walker.nextNode()) && texts.length < 30) { var textValue = redactText(textNode.nodeValue); if (textValue.length >= 3) texts.push(textValue.slice(0, 240)); }',
    '  return { viewport: viewport, page: page, scroll: scroll, visible_elements: elements, visible_text: texts.join(" ").slice(0, 1200) };',
    '})()',
  ].join('\\n');
}

async function main() {
  let payload;
  try {
    payload = JSON.parse(input || '{}');
  } catch {
    return { available: false, error: 'invalid_renderer_input', moments: [] };
  }

  const events = decodeChunks(payload.chunks);
  if (!events.length) return { available: false, error: 'rrweb_events_missing', moments: [] };
  const bundlePath = String(payload.replay_umd_path || '');
  if (!bundlePath || !fs.existsSync(bundlePath)) {
    return { available: false, error: 'rrweb_replay_bundle_missing', moments: [] };
  }
  const chrome = chromiumCandidates(payload.chromium_path)[0];
  if (!chrome) return { available: false, error: 'chromium_missing', moments: [] };

  const profile = await mkdtemp(path.join(os.tmpdir(), 'elchat-rrweb-'));
  const port = randomInt(10000, 40000);
  const child = spawn(chrome, [
    '--headless=new', '--disable-gpu', '--no-sandbox', '--disable-dev-shm-usage',
    '--remote-allow-origins=*', '--remote-debugging-port=' + port,
    '--user-data-dir=' + profile, 'about:blank',
  ], { stdio: 'ignore', windowsHide: true });

  let ws;
  try {
    const version = await waitForJsonVersion(port);
    ws = new WebSocket(version.webSocketDebuggerUrl);
    const client = await CdpClient.connect(ws);
    const target = await client.call('Target.createTarget', { url: 'about:blank' });
    const attached = await client.call('Target.attachToTarget', { targetId: target.result.targetId, flatten: true });
    const sessionId = attached.result.sessionId;
    await client.call('Page.enable', {}, sessionId);
    await client.call('Runtime.enable', {}, sessionId);
    const bundle = fs.readFileSync(bundlePath, 'utf8');
    const bootstrap = [
      'var module = undefined; var exports = undefined; var define = undefined;',
      bundle,
      'globalThis.__elchatReplay = globalThis.rrwebReplay || globalThis.rrweb || globalThis.__elchatReplay;',
      'globalThis.__elchatEvents = ' + JSON.stringify(events) + ';',
      'void 0;',
    ].join('\\n');
    await client.evaluate(bootstrap, sessionId);
    const setup = [
      '(function () {',
      '  var Replayer = globalThis.__elchatReplay && globalThis.__elchatReplay.Replayer;',
      '  if (!Replayer) return "replayer_constructor_missing";',
      '  globalThis.__elchatClient = undefined;',
      '  globalThis.__elchatReplayer = new Replayer(globalThis.__elchatEvents, { root: document.body, useVirtualDom: true, skipInactive: false, showWarning: false, showDebug: false, mouseTail: false, triggerFocus: false, pauseAnimation: false });',
      '  globalThis.__elchatReplayer.pause(0);',
      '  return "ok";',
      '})()',
    ].join('\\n');
    const setupResult = await client.evaluate(setup, sessionId);
    if (setupResult.result?.result?.value !== 'ok') {
      return { available: false, error: setupResult.result?.result?.value || 'replayer_setup_failed', moments: [] };
    }
    const moments = [];
    const maxCaptures = Math.max(0, Number(payload.max_visual_captures || 0));
    let captures = 0;
    for (const moment of Array.isArray(payload.moments) ? payload.moments : []) {
      const replayTimestamp = Number(moment.replay_timestamp);
      if (!Number.isFinite(replayTimestamp)) continue;
      const relative = Math.max(0, replayTimestamp - Number(events[0].timestamp));
      const wantsCapture = Boolean(moment.capture_candidate) && captures < maxCaptures;
      const extraction = await client.evaluate(buildExtractionScript(relative, wantsCapture), sessionId, true);
      const value = extraction.result?.result?.value;
      if (!value || value.error) continue;
      if (wantsCapture) {
        const shot = await client.call('Page.captureScreenshot', { format: 'jpeg', quality: 55 }, sessionId);
        if (shot.result?.data) {
          value.capture = 'data:image/jpeg;base64,' + shot.result.data;
          captures++;
        }
      }
      moments.push({ ...moment, ...value, replay_timestamp: replayTimestamp, relative_timestamp: relative });
    }
    return { available: true, moments };
  } finally {
    try { ws?.close(); } catch {}
    try { child.kill(); } catch {}
    await rm(profile, { recursive: true, force: true }).catch(() => {});
  }
}

class CdpClient {
  constructor(ws) {
    this.ws = ws;
    this.nextId = 0;
    this.pending = new Map();
    this.referenceName = '__elchatCdp';
    ws.onmessage = event => {
      let message;
      try { message = JSON.parse(String(event.data)); } catch { return; }
      if (!message.id || !this.pending.has(message.id)) return;
      const pending = this.pending.get(message.id);
      this.pending.delete(message.id);
      if (message.error) pending.reject(new Error(message.error.message || 'cdp_error'));
      else pending.resolve(message);
    };
  }

  static connect(ws) {
    return new Promise((resolve, reject) => {
      const fail = error => reject(error instanceof Error ? error : new Error('cdp_connect_failed'));
      ws.onerror = fail;
      ws.onopen = () => resolve(new CdpClient(ws));
    });
  }

  call(method, params = {}, sessionId = undefined) {
    const id = ++this.nextId;
    return new Promise((resolve, reject) => {
      this.pending.set(id, { resolve, reject });
      const message = { id, method, params };
      if (sessionId) message.sessionId = sessionId;
      this.ws.send(JSON.stringify(message));
    });
  }

  evaluate(expression, sessionId, awaitPromise = false) {
    return this.call('Runtime.evaluate', {
      expression,
      awaitPromise,
      returnByValue: true,
      userGesture: true,
    }, sessionId);
  }
}

let result;
try {
  result = await main();
} catch (error) {
  result = { available: false, error: String(error?.message || error), moments: [] };
}
process.stdout.write(JSON.stringify(result || { available: false, moments: [] }));
