import { describe, expect, it } from 'vitest';
import { GO_TO, shortcutFor } from './Shortcuts';

describe('shortcutFor', () => {
  it('maps single keys', () => {
    expect(shortcutFor('/', false)).toEqual({ type: 'focus', target: 'search' });
    expect(shortcutFor('f', false)).toEqual({ type: 'focus', target: 'filter' });
    expect(shortcutFor('?', false)).toEqual({ type: 'help' });
    expect(shortcutFor('g', false)).toEqual({ type: 'pending' });
    expect(shortcutFor('x', false)).toBeNull();
  });
  it('maps "g" sequences to pages', () => {
    expect(shortcutFor('h', true)).toEqual({ type: 'go', path: '/hosts' });
    expect(shortcutFor('S', true)).toEqual({ type: 'go', path: '/subnets' });
    expect(shortcutFor('z', true)).toBeNull();
  });
  it('has no duplicate "g" keys', () => {
    expect(new Set(GO_TO.map((g) => g.key)).size).toBe(GO_TO.length);
  });
});
