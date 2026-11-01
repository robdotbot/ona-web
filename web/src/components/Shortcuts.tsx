// Keyboard shortcuts: "/" focuses the global search, "f" the page's list
// filter, "?" shows help, and "g" followed by a letter goes to a page.
// They are ignored while typing in a field or with Ctrl/Alt/Cmd held.
import { Group, Kbd, Stack, Table, Text } from '@mantine/core';
import { modals } from '@mantine/modals';
import { useEffect, useRef } from 'react';
import { useNavigate } from 'react-router-dom';

export const GO_TO: { key: string; path: string; label: string }[] = [
  { key: 's', path: '/subnets', label: 'Subnets' },
  { key: 'h', path: '/hosts', label: 'Hosts' },
  { key: 'd', path: '/dns', label: 'DNS records' },
  { key: 'v', path: '/vlans', label: 'VLANs' },
  { key: 'b', path: '/blocks', label: 'Blocks' },
  { key: 'p', path: '/dhcp', label: 'DHCP' },
  { key: 'o', path: '/domains', label: 'Domains' },
  { key: 'l', path: '/locations', label: 'Locations' },
  { key: 'r', path: '/reports', label: 'Reports' },
  { key: 'c', path: '/tools/ipcalc', label: 'IP calculator' },
  { key: 'a', path: '/admin', label: 'Admin' },
];

export type ShortcutAction =
  | { type: 'focus'; target: 'search' | 'filter' }
  | { type: 'help' }
  | { type: 'go'; path: string }
  | { type: 'pending' }
  | null;

/** Pure key → action mapping (pending is true right after "g"). */
export function shortcutFor(key: string, pending: boolean): ShortcutAction {
  if (pending) {
    const go = GO_TO.find((g) => g.key === key.toLowerCase());
    return go ? { type: 'go', path: go.path } : null;
  }
  if (key === '/') return { type: 'focus', target: 'search' };
  if (key === 'f') return { type: 'focus', target: 'filter' };
  if (key === '?') return { type: 'help' };
  if (key === 'g') return { type: 'pending' };
  return null;
}

function isTyping(e: KeyboardEvent): boolean {
  const t = e.target as HTMLElement | null;
  if (!t) return false;
  const tag = t.tagName;
  return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || t.isContentEditable;
}

function focus(selector: string): boolean {
  const el = document.querySelector<HTMLInputElement>(selector);
  if (!el) return false;
  el.focus();
  el.select?.();
  return true;
}

export function openShortcutHelp() {
  modals.open({
    title: 'Keyboard shortcuts',
    children: (
      <Stack gap="sm">
        <Table withRowBorders={false} verticalSpacing={4}>
          <Table.Tbody>
            <Table.Tr><Table.Td><Kbd>/</Kbd></Table.Td><Table.Td>Search everything</Table.Td></Table.Tr>
            <Table.Tr><Table.Td><Kbd>f</Kbd></Table.Td><Table.Td>Filter the list on this page</Table.Td></Table.Tr>
            <Table.Tr><Table.Td><Kbd>?</Kbd></Table.Td><Table.Td>Show this help</Table.Td></Table.Tr>
            <Table.Tr><Table.Td><Kbd>Esc</Kbd></Table.Td><Table.Td>Close a dialog, or leave a field</Table.Td></Table.Tr>
            {GO_TO.map((g) => (
              <Table.Tr key={g.key}>
                <Table.Td><Group gap={4} wrap="nowrap"><Kbd>g</Kbd><Text span size="xs">then</Text><Kbd>{g.key}</Kbd></Group></Table.Td>
                <Table.Td>Go to {g.label}</Table.Td>
              </Table.Tr>
            ))}
          </Table.Tbody>
        </Table>
        <Text size="xs" c="dimmed">Shortcuts are off while you type in a field.</Text>
      </Stack>
    ),
  });
}

export function useKeyboardShortcuts() {
  const navigate = useNavigate();
  const pending = useRef<number | null>(null);

  useEffect(() => {
    const clearPending = () => {
      if (pending.current !== null) window.clearTimeout(pending.current);
      pending.current = null;
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && isTyping(e)) {
        (e.target as HTMLElement).blur();
        return;
      }
      if (e.ctrlKey || e.metaKey || e.altKey || isTyping(e) || e.defaultPrevented) return;
      // Leave keys alone while a dialog is open, apart from closing it.
      if (document.querySelector('.mantine-Modal-content')) return;
      const action = shortcutFor(e.key, pending.current !== null);
      clearPending();
      if (!action) return;
      if (action.type === 'pending') {
        pending.current = window.setTimeout(clearPending, 1200);
        return;
      }
      e.preventDefault();
      if (action.type === 'focus') {
        if (action.target === 'filter' && focus('[data-shortcut="list-filter"]')) return;
        focus('#global-search');
      } else if (action.type === 'help') {
        openShortcutHelp();
      } else if (action.type === 'go') {
        navigate(action.path);
      }
    };
    window.addEventListener('keydown', onKey);
    return () => {
      window.removeEventListener('keydown', onKey);
      clearPending();
    };
  }, [navigate]);
}
