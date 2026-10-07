import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { spawnSync } from 'node:child_process';
import { gzipSync } from 'node:zlib';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

const rendererDir = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(rendererDir, '..', '..');
const dashboardNodeModules = path.join(repoRoot, 'frontend', 'dashboard', 'node_modules');
const replayBundle = path.join(dashboardNodeModules, '@rrweb', 'replay', 'dist', 'replay.umd.cjs');
const enabled = process.env.ELCHAT_REPLAY_VIDEO_E2E === '1';

test('renders image assets at the exact odd-sized rrweb viewport in MP4', { skip: !enabled }, async () => {
  const chromiumPath = process.env.ELCHAT_TEST_CHROMIUM
    || [
      path.join(process.env.ProgramFiles || '', 'Google', 'Chrome', 'Application', 'chrome.exe'),
      path.join(process.env['ProgramFiles(x86)'] || '', 'Google', 'Chrome', 'Application', 'chrome.exe'),
      path.join(process.env.LOCALAPPDATA || '', 'Google', 'Chrome', 'Application', 'chrome.exe'),
    ].find(candidate => candidate && fs.existsSync(candidate));
  const ffmpegBinary = process.env.ELCHAT_TEST_FFMPEG || 'ffmpeg';
  const stylePath = path.join(path.dirname(replayBundle), 'style.css');
  assert.ok(chromiumPath && fs.existsSync(chromiumPath), 'Chrome/Chromium binary is required for the opt-in renderer test');
  assert.ok(fs.existsSync(replayBundle) && fs.existsSync(stylePath), 'rrweb replay bundle and styles are required');

  const workspace = await mkdtemp(path.join(os.tmpdir(), 'elchat-video-e2e-'));
  try {
    const start = Date.now() - 1500;
    const viewportWidth = 421;
    const viewportHeight = 801;
    const inlineImage = `data:image/svg+xml;base64,${Buffer.from('<svg xmlns="http://www.w3.org/2000/svg" width="60" height="60"><rect width="60" height="60" fill="#ff00ff"/></svg>').toString('base64')}`;
    const chunkPath = path.join(workspace, 'chunk-000000.gz');
    const manifestPath = path.join(workspace, 'manifest.json');
    const outputPath = path.join(workspace, 'replay.mp4');
    const events = [
      { type: 4, timestamp: start, data: { href: 'https://example.invalid/', width: viewportWidth, height: viewportHeight } },
      {
        type: 2,
        timestamp: start + 10,
        data: {
          node: {
            type: 0,
            id: 1,
            childNodes: [{
              type: 2,
              id: 2,
              tagName: 'html',
              attributes: {},
              childNodes: [
                { type: 2, id: 3, tagName: 'head', attributes: {}, childNodes: [] },
                {
                  type: 2,
                  id: 4,
                  tagName: 'body',
                  attributes: { style: `margin:0;width:${viewportWidth}px;height:${viewportHeight}px;background:#111;color:#fff;font:28px sans-serif;overflow:hidden` },
                  childNodes: [{
                    type: 2,
                    id: 5,
                    tagName: 'h1',
                    attributes: {},
                    childNodes: [{ type: 3, id: 6, textContent: null }],
                  }, {
                    type: 2,
                    id: 7,
                    tagName: 'img',
                    attributes: {
                      src: 'https://example.invalid/image.png',
                      rr_dataURL: inlineImage,
                      style: 'position:absolute;left:20px;top:20px;width:60px;height:60px',
                    },
                    childNodes: [],
                  }],
                },
              ],
            }],
          },
          initialOffset: { left: 0, top: 0 },
        },
      },
      { type: 3, timestamp: start + 700, data: { source: 0, texts: [{ id: 6, value: null }] } },
      { type: 3, timestamp: start + 1200, data: { source: 0, texts: [{ id: 6, value: 'Replay text remains readable' }] } },
    ];
    await writeFile(chunkPath, gzipSync(JSON.stringify(events)));
    await writeFile(manifestPath, JSON.stringify({
      output_path: outputPath,
      ffmpeg_binary: ffmpegBinary,
      replay_umd_path: replayBundle,
      chromium_path: chromiumPath,
      chunks: [{ path: chunkPath, index: 0 }],
      max_decoded_bytes: 1024 * 1024,
      max_events: 100,
      max_duration_seconds: 30,
      max_output_dimension: 4320,
      fps: 6,
      speed: 1,
      skip_inactive: false,
      widget_events: [],
    }));

    const worker = spawnSync(process.execPath, [path.join(rendererDir, 'video.mjs'), manifestPath], {
      encoding: 'utf8',
      timeout: 120000,
      maxBuffer: 2 * 1024 * 1024,
      windowsHide: true,
    });
    assert.equal(worker.status, 0, worker.stderr || worker.error?.message || 'renderer process failed');
    const result = JSON.parse(worker.stdout);
    assert.equal(result.status, 'ready');
    assert.equal(result.width, viewportWidth);
    assert.equal(result.height, viewportHeight);
    assert.ok(result.frame_count >= 2);
    assert.ok(fs.statSync(outputPath).size > 1024);

    const probe = spawnSync(ffmpegBinary, ['-hide_banner', '-i', outputPath, '-f', 'null', '-'], {
      encoding: 'utf8',
      timeout: 30000,
      maxBuffer: 1024 * 1024,
      windowsHide: true,
    });
    assert.equal(probe.status, 0, probe.stderr || 'generated MP4 could not be decoded');
    assert.match(probe.stderr, /421x801/, 'encoded MP4 dimensions must exactly match the recorded rrweb viewport');

    const firstFrame = spawnSync(ffmpegBinary, [
      '-hide_banner', '-loglevel', 'error', '-ss', '0.5', '-i', outputPath,
      '-frames:v', '1', '-f', 'rawvideo', '-pix_fmt', 'rgb24', 'pipe:1',
    ], { encoding: 'buffer', timeout: 30000, maxBuffer: 4 * 1024 * 1024, windowsHide: true });
    assert.equal(firstFrame.status, 0, firstFrame.stderr?.toString() || 'could not decode the first exported frame');
    const pixelOffset = (40 * viewportWidth + 40) * 3;
    const [red, green, blue] = firstFrame.stdout.subarray(pixelOffset, pixelOffset + 3);
    assert.ok(red > 150 && green < 110 && blue > 150, `rrweb image data URL should be visible at the expected pixel (got ${red},${green},${blue})`);
  } finally {
    await rm(workspace, { recursive: true, force: true });
  }
});
