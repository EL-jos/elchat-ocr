const isRecord = value => value !== null && typeof value === 'object' && !Array.isArray(value);

function normalizeRrwebNode(node) {
  if (Array.isArray(node)) return node.map(normalizeRrwebNode);
  if (!isRecord(node)) return node;

  const normalized = { ...node };
  if (Number(normalized.type) === 3 && (normalized.textContent === null || normalized.textContent === undefined)) {
    normalized.textContent = '';
  }
  if (Array.isArray(normalized.childNodes)) {
    normalized.childNodes = normalized.childNodes.map(normalizeRrwebNode);
  }
  return normalized;
}

/** Keep export replay normalization aligned with the dashboard's rrweb player. */
export function normalizeRrwebEvent(event) {
  if (!isRecord(event) || !isRecord(event.data)) return event;

  const normalized = { ...event };
  const data = { ...event.data };
  const type = Number(event.type);

  if (type === 2 && data.node) data.node = normalizeRrwebNode(data.node);

  if (type === 3 && Array.isArray(data.texts)) {
    data.texts = data.texts.map(mutation => {
      if (!isRecord(mutation)) return mutation;
      const item = { ...mutation };
      if (item.value === null || item.value === undefined) item.value = '';
      return item;
    });
  }

  if (type === 3 && Array.isArray(data.adds)) {
    data.adds = data.adds.map(mutation => {
      if (!isRecord(mutation)) return mutation;
      const item = { ...mutation };
      if (item.node) item.node = normalizeRrwebNode(item.node);
      return item;
    });
  }

  normalized.data = data;
  return normalized;
}

/** Return the recorded viewport without upscaling, downscaling, or aspect changes. */
export function createReplayVideoViewport(width, height, options = {}) {
  const sourceWidth = Math.round(Number(width));
  const sourceHeight = Math.round(Number(height));
  const requestedLimit = Number(options.maxOutputDimension);
  const maxOutputDimension = Number.isFinite(requestedLimit) && requestedLimit >= 2
    ? Math.min(7680, Math.round(requestedLimit))
    : 4320;

  if (!Number.isFinite(sourceWidth) || !Number.isFinite(sourceHeight) || sourceWidth < 2 || sourceHeight < 2) {
    throw new Error('replay_video_viewport_missing');
  }
  if (sourceWidth > maxOutputDimension || sourceHeight > maxOutputDimension) {
    throw new Error('replay_video_viewport_exceeds_limit');
  }

  return {
    sourceWidth,
    sourceHeight,
    deviceScaleFactor: 1,
    width: sourceWidth,
    height: sourceHeight,
  };
}

/** Prefer the initial rrweb Meta dimensions; use a recorded resize only for legacy captures. */
export function findRecordedReplayViewport(events, options = {}) {
  const validSize = data => Number(data?.width) >= 2 && Number(data?.height) >= 2;
  const meta = (Array.isArray(events) ? events : []).find(event => Number(event?.type) === 4 && validSize(event?.data));
  const resize = meta ? null : (Array.isArray(events) ? events : []).find(event =>
    Number(event?.type) === 3
    && Number(event?.data?.source) === 4
    && validSize(event?.data),
  );
  const recorded = meta?.data || resize?.data;
  if (!recorded) throw new Error('replay_video_viewport_missing');
  return createReplayVideoViewport(recorded.width, recorded.height, options);
}
