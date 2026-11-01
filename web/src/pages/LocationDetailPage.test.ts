import { describe, expect, it } from 'vitest';
import { mapUrl } from './LocationDetailPage';

describe('mapUrl', () => {
  it('links valid coordinates to OpenStreetMap', () => {
    expect(mapUrl('51.5', '-0.12')).toBe('https://www.openstreetmap.org/?mlat=51.5&mlon=-0.12#map=16/51.5/-0.12');
  });
  it('rejects missing, non-numeric and out-of-range values', () => {
    expect(mapUrl('', '1')).toBeNull();
    expect(mapUrl('N51', '1')).toBeNull();
    expect(mapUrl('91', '0')).toBeNull();
    expect(mapUrl('0', '181')).toBeNull();
  });
});
