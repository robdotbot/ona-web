import { describe, expect, it } from 'vitest';
import { calculate, formatCount, formatIp, layoutRanges, parseIp, parseIpv6, parsePrefix, rangeToCidrs, reverseZone } from './ip';

describe('parseIp / formatIp', () => {
  it('round-trips IPv4', () => {
    const ip = parseIp('192.168.1.10')!;
    expect(ip.version).toBe(4);
    expect(ip.value).toBe(3232235786n);
    expect(formatIp(ip.value, 4)).toBe('192.168.1.10');
  });
  it('rejects bad IPv4', () => {
    expect(parseIp('256.1.1.1')).toBeNull();
    expect(parseIp('1.2.3')).toBeNull();
    expect(parseIp('01.2.3.4')).toBeNull();
  });
  it('parses compressed and embedded-IPv4 IPv6', () => {
    expect(parseIpv6('::1')).toBe(1n);
    expect(parseIpv6('2001:db8::')).toBe(0x20010db8n << 96n);
    expect(parseIpv6('::ffff:10.0.0.1')).toBe((0xffffn << 32n) | 0x0a000001n);
    expect(parseIpv6('1:2:3:4:5:6:7:8')).not.toBeNull();
    expect(parseIpv6('1::2::3')).toBeNull();
    expect(parseIpv6('1:2:3:4:5:6:7:8:9')).toBeNull();
    expect(parseIpv6('12345::')).toBeNull();
  });
  it('formats IPv6 per RFC 5952', () => {
    expect(formatIp(parseIpv6('2001:0db8:0000:0000:0000:ff00:0042:8329')!, 6)).toBe('2001:db8::ff00:42:8329');
    expect(formatIp(0n, 6)).toBe('::');
    expect(formatIp(parseIpv6('2001:db8:0:1:0:0:0:1')!, 6)).toBe('2001:db8:0:1::1');
    // A single zero group is not compressed.
    expect(formatIp(parseIpv6('2001:db8:0:1:1:1:1:1')!, 6)).toBe('2001:db8:0:1:1:1:1:1');
  });
});

describe('parsePrefix', () => {
  it('accepts lengths and dotted masks', () => {
    expect(parsePrefix('24', 4)).toBe(24);
    expect(parsePrefix('/26', 4)).toBe(26);
    expect(parsePrefix('255.255.255.0', 4)).toBe(24);
    expect(parsePrefix('255.255.255.255', 4)).toBe(32);
    expect(parsePrefix('0.0.0.0', 4)).toBe(0);
    expect(parsePrefix('64', 6)).toBe(64);
  });
  it('rejects bad masks', () => {
    expect(parsePrefix('255.0.255.0', 4)).toBeNull();
    expect(parsePrefix('33', 4)).toBeNull();
    expect(parsePrefix('129', 6)).toBeNull();
  });
});

describe('calculate', () => {
  it('computes an IPv4 /24', () => {
    const r = calculate('10.1.2.77/24')!;
    expect(r).toMatchObject({
      network: '10.1.2.0', broadcast: '10.1.2.255', netmask: '255.255.255.0', wildcard: '0.0.0.255',
      firstHost: '10.1.2.1', lastHost: '10.1.2.254', reverseZone: '2.1.10.in-addr.arpa', ipv4Class: 'A', isPrivate: true,
    });
    expect(r.addresses).toBe(256n);
    expect(r.usable).toBe(254n);
    expect(r.addressHex).toBe('0x0a01024d');
  });
  it('treats /31 and /32 as all usable', () => {
    expect(calculate('10.0.0.0', '31')!.usable).toBe(2n);
    expect(calculate('10.0.0.5')!).toMatchObject({ prefix: 32, firstHost: '10.0.0.5', lastHost: '10.0.0.5', broadcast: null });
  });
  it('computes an IPv6 /64', () => {
    const r = calculate('2001:db8:abcd:12::99/64')!;
    expect(r.network).toBe('2001:db8:abcd:12::');
    expect(r.lastHost).toBe('2001:db8:abcd:12:ffff:ffff:ffff:ffff');
    expect(r.addresses).toBe(1n << 64n);
    expect(r.broadcast).toBeNull();
    expect(r.reverseZone).toBe('2.1.0.0.d.c.b.a.8.b.d.0.1.0.0.2.ip6.arpa');
  });
  it('returns null for garbage', () => {
    expect(calculate('nope')).toBeNull();
    expect(calculate('10.0.0.1', '40')).toBeNull();
  });
});

describe('helpers', () => {
  it('reverseZone rounds down to octets', () => {
    expect(reverseZone(parseIp('10.20.0.0')!.value, 12, 4)).toBe('10.in-addr.arpa');
  });
  it('formatCount abbreviates huge counts', () => {
    expect(formatCount(256n)).toBe('256');
    expect(formatCount(1n << 64n)).toBe('2^64');
  });
  it('layoutRanges fills gaps and clips', () => {
    const segs = layoutRanges({ start: 0n, end: 99n }, [
      { start: 10n, end: 19n, id: 'a' },
      { start: 50n, end: 120n, id: 'b' },
      { start: 15n, end: 18n, id: 'c' }, // overlapped by a
    ]);
    expect(segs.map((s) => [s.start, s.end, s.item?.id])).toEqual([
      [0n, 9n, undefined], [10n, 19n, 'a'], [20n, 49n, undefined], [50n, 99n, 'b'],
    ]);
  });
  it('rangeToCidrs covers a range with aligned blocks', () => {
    const base = parseIp('10.0.0.0')!.value;
    expect(rangeToCidrs(base, base + 255n, 4)).toEqual(['10.0.0.0/24']);
    expect(rangeToCidrs(base + 128n, base + 511n, 4)).toEqual(['10.0.0.128/25', '10.0.1.0/24']);
  });
});
