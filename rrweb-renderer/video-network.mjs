import { lookup } from 'node:dns/promises';
import { isIP } from 'node:net';

const nonPublicIpv4Ranges = [
  ['0.0.0.0', 8],
  ['10.0.0.0', 8],
  ['100.64.0.0', 10],
  ['127.0.0.0', 8],
  ['169.254.0.0', 16],
  ['172.16.0.0', 12],
  ['192.0.0.0', 24],
  ['192.0.2.0', 24],
  ['192.88.99.0', 24],
  ['192.168.0.0', 16],
  ['198.18.0.0', 15],
  ['198.51.100.0', 24],
  ['203.0.113.0', 24],
  ['224.0.0.0', 4],
  ['240.0.0.0', 4],
];

function ipv4AsBigInt(address) {
  if (isIP(address) !== 4) return null;
  return address.split('.').reduce((value, octet) => (value << 8n) | BigInt(Number(octet)), 0n);
}

function inIpv4Range(address, network, prefix) {
  const value = ipv4AsBigInt(address);
  const base = ipv4AsBigInt(network);
  if (value === null || base === null) return true;
  const shift = BigInt(32 - prefix);
  return (value >> shift) === (base >> shift);
}

export function isPublicReplayImageIpv4(address) {
  return isIP(address) === 4 && !nonPublicIpv4Ranges.some(([network, prefix]) => inIpv4Range(address, network, prefix));
}

/** Resolve and pin a trusted site's exact origin to a public IPv4 address. */
export async function resolveReplayImageOrigin(siteOrigin) {
  let parsed;
  try {
    parsed = new URL(String(siteOrigin || ''));
  } catch {
    return null;
  }

  if (!['http:', 'https:'].includes(parsed.protocol)
    || parsed.username || parsed.password
    || parsed.port
    || isIP(parsed.hostname)
    || !/^[a-z0-9.-]+$/i.test(parsed.hostname)
    || parsed.hostname.startsWith('.') || parsed.hostname.endsWith('.')) {
    return null;
  }

  let addresses;
  try {
    addresses = await lookup(parsed.hostname, { all: true, verbatim: true });
  } catch {
    return null;
  }

  const pinnedAddress = addresses.find(item => isPublicReplayImageIpv4(item.address))?.address;
  if (!pinnedAddress) return null;

  return {
    origin: parsed.origin,
    hostname: parsed.hostname.toLowerCase(),
    address: pinnedAddress,
  };
}

/** Permit only GET image resources from the pinned, exact site origin. */
export function isAllowedReplayImageRequest(request, policy) {
  if (!policy || request?.resourceType !== 'Image' || request?.request?.method !== 'GET') return false;
  try {
    const url = new URL(String(request.request.url || ''));
    return url.origin === policy.origin && url.hostname.toLowerCase() === policy.hostname;
  } catch {
    return false;
  }
}

