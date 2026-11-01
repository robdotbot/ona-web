// IPv4 / IPv6 address arithmetic with BigInt, for the IP calculator and the
// address maps. No network access; everything is computed in the browser.

export type IpVersion = 4 | 6;

export interface ParsedIp {
  version: IpVersion;
  value: bigint;
}

const V4_BITS = 32;
const V6_BITS = 128;
export const bits = (v: IpVersion) => (v === 4 ? V4_BITS : V6_BITS);
const maxValue = (v: IpVersion) => (1n << BigInt(bits(v))) - 1n;

export function parseIpv4(text: string): bigint | null {
  const parts = text.trim().split('.');
  if (parts.length !== 4) return null;
  let n = 0n;
  for (const p of parts) {
    if (!/^\d{1,3}$/.test(p) || Number(p) > 255 || (p.length > 1 && p.startsWith('0'))) return null;
    n = (n << 8n) | BigInt(p);
  }
  return n;
}

export function parseIpv6(text: string): bigint | null {
  let t = text.trim().toLowerCase();
  if (t.startsWith('[') && t.endsWith(']')) t = t.slice(1, -1);
  if (!/^[0-9a-f:.]+$/.test(t) || t.includes(':::')) return null;
  // An embedded IPv4 tail (::ffff:10.0.0.1) counts as two groups.
  let tail: bigint | null = null;
  const lastColon = t.lastIndexOf(':');
  if (t.slice(lastColon + 1).includes('.')) {
    tail = parseIpv4(t.slice(lastColon + 1));
    if (tail === null) return null;
    t = t.slice(0, lastColon + 1) + '0:0';
  }
  const halves = t.split('::');
  if (halves.length > 2) return null;
  const head = halves[0] ? halves[0].split(':') : [];
  const rest = halves.length === 2 && halves[1] ? halves[1].split(':') : [];
  const missing = 8 - head.length - rest.length;
  if (halves.length === 1 ? head.length !== 8 : missing < 1) return null;
  const groups = [...head, ...Array(halves.length === 2 ? missing : 0).fill('0'), ...rest];
  let n = 0n;
  for (const g of groups) {
    if (!/^[0-9a-f]{1,4}$/.test(g)) return null;
    n = (n << 16n) | BigInt(parseInt(g, 16));
  }
  if (tail !== null) n = (n & ~0xffffffffn) | tail;
  return n;
}

export function parseIp(text: string): ParsedIp | null {
  const v4 = parseIpv4(text);
  if (v4 !== null) return { version: 4, value: v4 };
  const v6 = parseIpv6(text);
  if (v6 !== null) return { version: 6, value: v6 };
  return null;
}

export function formatIp(value: bigint, version: IpVersion): string {
  if (version === 4) return [24n, 16n, 8n, 0n].map((s) => String((value >> s) & 255n)).join('.');
  const groups = Array.from({ length: 8 }, (_, i) => ((value >> BigInt(112 - 16 * i)) & 0xffffn).toString(16));
  // RFC 5952: compress the longest run (2+) of zero groups.
  let best = -1, bestLen = 0;
  for (let i = 0; i < 8; ) {
    if (groups[i] !== '0') { i++; continue; }
    let j = i;
    while (j < 8 && groups[j] === '0') j++;
    if (j - i > bestLen && j - i > 1) { best = i; bestLen = j - i; }
    i = j;
  }
  if (best < 0) return groups.join(':');
  return `${groups.slice(0, best).join(':')}::${groups.slice(best + bestLen).join(':')}`;
}

/** Mask for a prefix length. */
export function prefixMask(prefix: number, version: IpVersion): bigint {
  const b = BigInt(bits(version));
  return prefix === 0 ? 0n : (maxValue(version) >> (b - BigInt(prefix))) << (b - BigInt(prefix));
}

/** Prefix length from "24", "/24" or a dotted IPv4 mask; null if invalid. */
export function parsePrefix(text: string, version: IpVersion): number | null {
  const t = text.trim().replace(/^\//, '');
  if (/^\d{1,3}$/.test(t)) {
    const n = Number(t);
    return n <= bits(version) ? n : null;
  }
  if (version !== 4) return null;
  const m = parseIpv4(t);
  if (m === null) return null;
  const inv = ~m & 0xffffffffn;
  if ((inv & (inv + 1n)) !== 0n) return null; // not contiguous
  return 32 - inv.toString(2).replace(/^0+/, '').length;
}

export interface CalcResult {
  version: IpVersion;
  address: string;
  prefix: number;
  netmask: string;
  wildcard: string;
  network: string;
  broadcast: string | null;
  firstHost: string;
  lastHost: string;
  addresses: bigint;
  usable: bigint;
  /** ip6.arpa / in-addr.arpa zone of the network (on nibble/octet boundary). */
  reverseZone: string;
  addressHex: string;
  addressDecimal: string;
  addressBinary: string;
  ipv4Class?: string;
  isPrivate?: boolean;
}

/** Everything the classic ipcalc window shows, for "address/prefix" input. */
export function calculate(addressText: string, prefixText?: string): CalcResult | null {
  let addr = addressText.trim();
  let pfx = prefixText?.trim() ?? '';
  if (addr.includes('/')) [addr, pfx] = [addr.slice(0, addr.indexOf('/')), addr.slice(addr.indexOf('/') + 1)];
  const ip = parseIp(addr);
  if (!ip) return null;
  const { version, value } = ip;
  const prefix = pfx === '' ? bits(version) : parsePrefix(pfx, version);
  if (prefix === null) return null;
  const mask = prefixMask(prefix, version);
  const network = value & mask;
  const last = network | (~mask & maxValue(version));
  const addresses = last - network + 1n;
  // IPv4 /31 and /32 (RFC 3021) and IPv6 have no broadcast to subtract.
  const reserveEnds = version === 4 && prefix < 31;
  const usable = reserveEnds ? addresses - 2n : addresses;
  const width = bits(version);
  const result: CalcResult = {
    version,
    address: formatIp(value, version),
    prefix,
    netmask: version === 4 ? formatIp(mask, 4) : `/${prefix}`,
    wildcard: version === 4 ? formatIp(~mask & 0xffffffffn, 4) : formatIp(~mask & maxValue(6), 6),
    network: formatIp(network, version),
    broadcast: version === 4 && prefix < 31 ? formatIp(last, 4) : null,
    firstHost: formatIp(reserveEnds ? network + 1n : network, version),
    lastHost: formatIp(reserveEnds ? last - 1n : last, version),
    addresses,
    usable,
    reverseZone: reverseZone(network, prefix, version),
    addressHex: '0x' + value.toString(16).padStart(width / 4, '0'),
    addressDecimal: value.toString(),
    addressBinary: version === 4
      ? value.toString(2).padStart(32, '0').replace(/(.{8})(?!$)/g, '$1.')
      : value.toString(2).padStart(128, '0').replace(/(.{16})(?!$)/g, '$1:'),
  };
  if (version === 4) {
    const first = Number(value >> 24n);
    result.ipv4Class = first < 128 ? 'A' : first < 192 ? 'B' : first < 224 ? 'C' : first < 240 ? 'D (multicast)' : 'E (reserved)';
    result.isPrivate = isPrivateV4(value);
  }
  return result;
}

function isPrivateV4(v: bigint): boolean {
  const inNet = (net: string, p: number) => (v & prefixMask(p, 4)) === parseIpv4(net)!;
  return inNet('10.0.0.0', 8) || inNet('172.16.0.0', 12) || inNet('192.168.0.0', 16) || inNet('100.64.0.0', 10);
}

/** The reverse zone covering a network, rounded down to whole octets / nibbles. */
export function reverseZone(network: bigint, prefix: number, version: IpVersion): string {
  if (version === 4) {
    const octets = Math.floor(prefix / 8);
    const parts = [24n, 16n, 8n, 0n].slice(0, octets).map((s) => String((network >> s) & 255n));
    return [...parts.reverse(), 'in-addr.arpa'].join('.');
  }
  const nibbles = Math.floor(prefix / 4);
  const hex = network.toString(16).padStart(32, '0').slice(0, nibbles);
  return [...hex.split('').reverse(), 'ip6.arpa'].join('.');
}

/** Human-friendly count, e.g. 2^64 for very large IPv6 ranges. */
export function formatCount(n: bigint): string {
  if (n < 1_000_000_000_000n) return n.toLocaleString('en-US');
  const exp = n.toString(2).length - 1;
  return (1n << BigInt(exp)) === n ? `2^${exp}` : `~2^${exp}`;
}

// ---- Ranges for the block map ------------------------------------------------

export interface Range {
  start: bigint;
  end: bigint;
}

export interface Segment<T> extends Range {
  item?: T;
}

/**
 * Split [outer.start, outer.end] into used segments (the given ranges,
 * clipped and sorted; overlaps keep the first) and free gaps between them.
 */
export function layoutRanges<T extends Range>(outer: Range, ranges: T[]): Segment<T>[] {
  const sorted = ranges
    .map((r) => ({ ...r, start: r.start < outer.start ? outer.start : r.start, end: r.end > outer.end ? outer.end : r.end }))
    .filter((r) => r.start <= r.end)
    .sort((a, b) => (a.start < b.start ? -1 : a.start > b.start ? 1 : 0));
  const out: Segment<T>[] = [];
  let cursor = outer.start;
  for (const r of sorted) {
    if (r.end < cursor) continue; // fully overlapped by an earlier range
    const start = r.start < cursor ? cursor : r.start;
    if (start > cursor) out.push({ start: cursor, end: start - 1n });
    out.push({ start, end: r.end, item: r as T });
    cursor = r.end + 1n;
  }
  if (cursor <= outer.end) out.push({ start: cursor, end: outer.end });
  return out;
}

/** Largest CIDR blocks that exactly cover [start, end] (for "free" labels). */
export function rangeToCidrs(start: bigint, end: bigint, version: IpVersion, limit = 8): string[] {
  const out: string[] = [];
  const width = bits(version);
  let cur = start;
  while (cur <= end && out.length < limit) {
    let size = width;
    // Grow the block while it stays aligned and inside the range.
    while (size > 0) {
      const next = size - 1;
      const blockMask = prefixMask(next, version);
      const blockEnd = cur | (~blockMask & maxValue(version));
      if ((cur & blockMask) !== cur || blockEnd > end) break;
      size = next;
    }
    out.push(`${formatIp(cur, version)}/${size}`);
    cur = (cur | (~prefixMask(size, version) & maxValue(version))) + 1n;
  }
  if (cur <= end) out.push('…');
  return out;
}
