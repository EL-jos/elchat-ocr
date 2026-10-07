import test from 'node:test';
import assert from 'node:assert/strict';
import { createReplayVideoViewport, findRecordedReplayViewport, normalizeRrwebEvent } from './video-helpers.mjs';
import { isAllowedReplayImageRequest, isPublicReplayImageIpv4, resolveReplayImageOrigin } from './video-network.mjs';

test('normalizes null text in snapshots and incremental rrweb mutations without mutating input', () => {
  const input = {
    type: 2,
    timestamp: 100,
    data: {
      node: {
        type: 0,
        childNodes: [{ type: 3, textContent: null }, { type: 3 }],
      },
    },
  };
  const normalized = normalizeRrwebEvent(input);
  assert.deepEqual(normalized.data.node.childNodes, [
    { type: 3, textContent: '' },
    { type: 3, textContent: '' },
  ]);
  assert.equal(input.data.node.childNodes[0].textContent, null);

  const mutation = normalizeRrwebEvent({
    type: 3,
    timestamp: 101,
    data: {
      texts: [{ id: 1, value: null }, { id: 2 }],
      adds: [{ node: { type: 3, textContent: null } }],
    },
  });
  assert.deepEqual(mutation.data.texts, [{ id: 1, value: '' }, { id: 2, value: '' }]);
  assert.deepEqual(mutation.data.adds, [{ node: { type: 3, textContent: '' } }]);
});

test('exports the exact recorded mobile viewport without changing its pixel dimensions', () => {
  assert.deepEqual(createReplayVideoViewport(393, 852), {
    sourceWidth: 393,
    sourceHeight: 852,
    deviceScaleFactor: 1,
    width: 393,
    height: 852,
  });
});

test('uses the exact desktop or television viewport instead of scaling to a standard canvas', () => {
  assert.deepEqual(createReplayVideoViewport(3840, 2160), {
    sourceWidth: 3840,
    sourceHeight: 2160,
    deviceScaleFactor: 1,
    width: 3840,
    height: 2160,
  });
});

test('recovers dimensions from rrweb viewport resize data if old metadata is missing', () => {
  assert.deepEqual(findRecordedReplayViewport([
    { type: 3, data: { source: 3, width: 1, height: 1 } },
    { type: 3, data: { source: 4, width: 800, height: 1280 } },
  ]), {
    sourceWidth: 800,
    sourceHeight: 1280,
    deviceScaleFactor: 1,
    width: 800,
    height: 1280,
  });
});

test('keeps the session-start viewport when the replay later records a resize', () => {
  assert.deepEqual(findRecordedReplayViewport([
    { type: 4, data: { width: 393, height: 852 } },
    { type: 3, data: { source: 4, width: 852, height: 393 } },
  ]), {
    sourceWidth: 393,
    sourceHeight: 852,
    deviceScaleFactor: 1,
    width: 393,
    height: 852,
  });
});

test('does not invent a device viewport or silently distort dimensions outside export limits', () => {
  assert.throws(() => createReplayVideoViewport(null, null), /replay_video_viewport_missing/);
  assert.throws(() => createReplayVideoViewport(3840, 2160, { maxOutputDimension: 1920 }), /replay_video_viewport_exceeds_limit/);
});

test('allows only image GETs from the exact pinned site origin', () => {
  const policy = { origin: 'https://shop.example.com', hostname: 'shop.example.com', address: '93.184.216.34' };
  assert.equal(isAllowedReplayImageRequest({ resourceType: 'Image', request: { method: 'GET', url: 'https://shop.example.com/media/hero.webp' } }, policy), true);
  assert.equal(isAllowedReplayImageRequest({ resourceType: 'Document', request: { method: 'GET', url: 'https://shop.example.com/' } }, policy), false);
  assert.equal(isAllowedReplayImageRequest({ resourceType: 'Image', request: { method: 'POST', url: 'https://shop.example.com/media/hero.webp' } }, policy), false);
  assert.equal(isAllowedReplayImageRequest({ resourceType: 'Image', request: { method: 'GET', url: 'https://cdn.example.com/media/hero.webp' } }, policy), false);
  assert.equal(isAllowedReplayImageRequest({ resourceType: 'Image', request: { method: 'GET', url: 'http://shop.example.com/media/hero.webp' } }, policy), false);
});

test('rejects private, loopback, link-local and reserved IPv4 destinations for replay assets', async () => {
  for (const address of ['0.1.2.3', '10.1.2.3', '100.64.0.1', '127.0.0.1', '169.254.1.2', '172.20.0.1', '192.168.1.1', '198.18.0.1', '203.0.113.8', '224.0.0.1']) {
    assert.equal(isPublicReplayImageIpv4(address), false, `${address} must not be fetched by the renderer`);
  }
  assert.equal(isPublicReplayImageIpv4('93.184.216.34'), true);
  assert.equal(isPublicReplayImageIpv4('::1'), false);
  assert.equal(await resolveReplayImageOrigin('http://127.0.0.1'), null);
  assert.equal(await resolveReplayImageOrigin('https://shop.example.com:8443'), null);
});
