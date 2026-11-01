// Visual maps: every address of an IPv4 subnet (used / free / DHCP pool),
// and the subnets and free space inside a block.
import { Box, Center, Group, Loader, Paper, Stack, Table, Text, Tooltip, UnstyledButton } from '@mantine/core';
import { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { errorMessage } from '../api/client';
import { useCan, useSubnetMap } from '../api/hooks';
import type { BlockDetail, SubnetDetail } from '../api/types';
import { formatCount, formatIp, layoutRanges, parseIp, rangeToCidrs, type IpVersion } from '../lib/ip';
import { openHostForm } from './HostForm';
import { SectionHeader } from './panels';

type Cell = 'free' | 'used' | 'pool' | 'reserved';
const CELL_COLORS: Record<Cell, string> = {
  free: 'var(--mantine-color-default-border)',
  used: 'var(--mantine-color-blue-filled)',
  pool: 'var(--mantine-color-orange-filled)',
  reserved: 'var(--mantine-color-dark-3)',
};
const LABELS: Record<Cell, string> = { free: 'Free', used: 'In use', pool: 'DHCP pool', reserved: 'Network / broadcast' };

function Legend({ counts }: { counts: Record<Cell, number> }) {
  return (
    <Group gap="md">
      {(Object.keys(LABELS) as Cell[]).map((k) => (
        <Group key={k} gap={6}>
          <Box w={12} h={12} style={{ background: CELL_COLORS[k], borderRadius: 2 }} />
          <Text size="xs">{LABELS[k]} ({counts[k]})</Text>
        </Group>
      ))}
    </Group>
  );
}

/** Address grid for IPv4 subnets up to /20 (the API limit). */
export function SubnetAddressMap({ subnet }: { subnet: SubnetDetail }) {
  const supported = subnet.ipVersion === 4 && subnet.cidr >= 20;
  const map = useSubnetMap(subnet.id, supported);
  const navigate = useNavigate();
  const canAdd = useCan('host_add');

  const cells = useMemo(() => {
    if (!map.data) return null;
    const kinds: Cell[] = Array(map.data.size).fill('free');
    const info: (string | undefined)[] = Array(map.data.size);
    const hosts: (number | undefined)[] = Array(map.data.size);
    for (const p of map.data.pools) for (let o = p.startOffset; o <= p.endOffset; o++) kinds[o] = 'pool';
    for (const o of map.data.reserved) kinds[o] = 'reserved';
    for (const u of map.data.used) {
      kinds[u.offset] = 'used';
      info[u.offset] = u.host?.fqdn;
      hosts[u.offset] = u.host?.id;
    }
    const counts = { free: 0, used: 0, pool: 0, reserved: 0 } as Record<Cell, number>;
    for (const k of kinds) counts[k]++;
    return { kinds, info, hosts, counts };
  }, [map.data]);

  if (!supported) return null;
  const base = parseIp(subnet.ip)?.value ?? 0n;
  const ipAt = (o: number) => formatIp(base + BigInt(o), 4);
  const perRow = map.data && map.data.size >= 256 ? 32 : 16;

  return (
    <>
      <SectionHeader title="Address map" />
      {map.isPending && <Center py="md"><Loader size="sm" /></Center>}
      {map.error && <Text c="red" size="sm">{errorMessage(map.error)}</Text>}
      {map.data && cells && (
        <Paper withBorder p="sm">
          <Stack gap="sm">
            <Legend counts={cells.counts} />
            <Box
              role="grid"
              aria-label={`Address map of ${map.data.network}`}
              style={{ display: 'grid', gridTemplateColumns: `max-content repeat(${perRow}, 14px)`, justifyContent: 'start', gap: 2, alignItems: 'center', overflowX: 'auto' }}
            >
              {cells.kinds.map((kind, o) => {
                const ip = ipAt(o);
                const label = `${ip} – ${cells.info[o] ?? LABELS[kind]}`;
                const clickable = (kind === 'used' && cells.hosts[o]) || (kind === 'free' && canAdd);
                const cell = (
                  <UnstyledButton
                    key={o}
                    title={label}
                    aria-label={label}
                    disabled={!clickable}
                    onClick={() => {
                      if (kind === 'used' && cells.hosts[o]) navigate(`/hosts/${cells.hosts[o]}`);
                      else if (kind === 'free' && canAdd) openHostForm(undefined, { ip });
                    }}
                    style={{ width: 14, height: 14, borderRadius: 2, background: CELL_COLORS[kind], cursor: clickable ? 'pointer' : 'default' }}
                  />
                );
                return o % perRow === 0
                  ? [<Text key={`l${o}`} size="10px" c="dimmed" ff="monospace" pr={4} ta="right">.{ip.split('.').slice(-2).join('.')}</Text>, cell]
                  : cell;
              })}
            </Box>
            <Text size="xs" c="dimmed">Click a used address to open its host{canAdd ? ', or a free one to add a host there' : ''}.</Text>
          </Stack>
        </Paper>
      )}
    </>
  );
}

const pct = (part: bigint, total: bigint) => Number((part * 100000n) / total) / 1000;

/** Bar of the subnets inside a block, with the free space between them. */
export function BlockMap({ block }: { block: BlockDetail }) {
  const navigate = useNavigate();
  const layout = useMemo(() => {
    const start = parseIp(block.start);
    const end = parseIp(block.end);
    if (!start || !end || start.version !== end.version) return null;
    const version: IpVersion = start.version;
    const subnets = block.subnets.flatMap((s) => {
      const ip = parseIp(s.ip);
      if (!ip || ip.version !== version) return [];
      const width = version === 4 ? 32 : 128;
      const last = ip.value + (1n << BigInt(width - s.cidr)) - 1n;
      return [{ start: ip.value, end: last, subnet: s }];
    });
    const segments = layoutRanges({ start: start.value, end: end.value }, subnets);
    const total = end.value - start.value + 1n;
    const free = segments.filter((g) => !g.item);
    const freeCount = free.reduce((n, g) => n + (g.end - g.start + 1n), 0n);
    return { version, segments, total, free, freeCount };
  }, [block]);

  if (!layout) return null;
  const { version, segments, total, free, freeCount } = layout;
  return (
    <>
      <SectionHeader title="Block map" />
      <Paper withBorder p="sm">
        <Stack gap="sm">
          <Box style={{ display: 'flex', height: 34, borderRadius: 4, overflow: 'hidden', border: '1px solid var(--mantine-color-default-border)' }} aria-label="Block map">
            {segments.map((g) => {
              const w = Math.max(pct(g.end - g.start + 1n, total), 0.4);
              const label = g.item
                ? `${g.item.subnet.name} ${g.item.subnet.network} (${g.item.subnet.usage.percent}% used)`
                : `Free: ${formatIp(g.start, version)} – ${formatIp(g.end, version)}`;
              return (
                <Tooltip key={String(g.start)} label={label} withArrow>
                  <UnstyledButton
                    aria-label={label}
                    onClick={() => g.item && navigate(`/subnets/${g.item.subnet.id}`)}
                    style={{
                      width: `${w}%`,
                      minWidth: 3,
                      background: g.item ? 'var(--mantine-color-blue-light)' : 'transparent',
                      borderRight: '1px solid var(--mantine-color-default-border)',
                      cursor: g.item ? 'pointer' : 'default',
                      overflow: 'hidden',
                      whiteSpace: 'nowrap',
                      textOverflow: 'ellipsis',
                      fontSize: 11,
                      padding: '0 4px',
                      color: g.item ? 'var(--mantine-color-blue-light-color)' : 'var(--mantine-color-dimmed)',
                    }}
                  >
                    {g.item ? g.item.subnet.name : ''}
                  </UnstyledButton>
                </Tooltip>
              );
            })}
          </Box>
          <Text size="sm">
            {formatCount(freeCount)} of {formatCount(total)} addresses ({pct(freeCount, total).toFixed(1)}%) are not in any subnet.
          </Text>
          {free.length > 0 && (
            <Table withRowBorders={false} verticalSpacing={2} fz="sm">
              <Table.Thead>
                <Table.Tr><Table.Th>Free range</Table.Th><Table.Th>Addresses</Table.Th><Table.Th>As CIDR blocks</Table.Th></Table.Tr>
              </Table.Thead>
              <Table.Tbody>
                {free.slice(0, 20).map((g) => (
                  <Table.Tr key={String(g.start)}>
                    <Table.Td ff="monospace">{formatIp(g.start, version)} – {formatIp(g.end, version)}</Table.Td>
                    <Table.Td>{formatCount(g.end - g.start + 1n)}</Table.Td>
                    <Table.Td ff="monospace">{rangeToCidrs(g.start, g.end, version, 4).join(', ')}</Table.Td>
                  </Table.Tr>
                ))}
              </Table.Tbody>
            </Table>
          )}
          {free.length > 20 && <Text size="xs" c="dimmed">…and {free.length - 20} more free ranges.</Text>}
        </Stack>
      </Paper>
    </>
  );
}
