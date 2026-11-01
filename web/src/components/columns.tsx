// Column sets shared by several pages.
import { ActionIcon, Anchor, Badge, Group, Text, Tooltip } from '@mantine/core';
import { IconPencil, IconTrash } from '@tabler/icons-react';
import { createColumnHelper } from '@tanstack/react-table';
import { Link } from 'react-router-dom';
import type { DnsRecord, Host, Interface, Subnet } from '../api/types';
import { UsageBar } from './UsageBar';
import type { MouseEvent } from 'react';

const stop = (e: MouseEvent) => e.stopPropagation();

export function rowActions(opts: { label: string; onEdit?: () => void; onDelete?: () => void }) {
  return (
    <Group gap={4} justify="flex-end" wrap="nowrap" onClick={stop}>
      {opts.onEdit && (
        <Tooltip label="Edit">
          <ActionIcon variant="subtle" aria-label={`Edit ${opts.label}`} onClick={opts.onEdit}>
            <IconPencil size={16} />
          </ActionIcon>
        </Tooltip>
      )}
      {opts.onDelete && (
        <Tooltip label="Delete">
          <ActionIcon variant="subtle" color="red" aria-label={`Delete ${opts.label}`} onClick={opts.onDelete}>
            <IconTrash size={16} />
          </ActionIcon>
        </Tooltip>
      )}
    </Group>
  );
}

const h = createColumnHelper<Host>();
export const hostColumns = [
  h.accessor('fqdn', {
    id: 'name',
    header: 'Host',
    enableSorting: true,
    cell: (c) => <Anchor component={Link} to={`/hosts/${c.row.original.id}`} fw={500} onClick={stop}>{c.getValue()}</Anchor>,
  }),
  h.accessor('primaryIp', { id: 'ip', header: 'Primary IP', enableSorting: true }),
  h.accessor((r) => r.deviceType?.name ?? '', { id: 'deviceType', header: 'Device type' }),
  h.accessor('interfaceCount', { header: 'Interfaces' }),
  h.accessor('notes', { header: 'Notes', cell: (c) => <Text size="sm" lineClamp={1}>{c.getValue()}</Text> }),
];

const i = createColumnHelper<Interface>();
export const interfaceColumns = (opts: { showHost?: boolean; showSubnet?: boolean } = {}) => [
  i.accessor('ip', { id: 'ip', header: 'IP address', enableSorting: true, cell: (c) => <Text ff="monospace" size="sm">{c.getValue()}</Text> }),
  ...(opts.showHost
    ? [i.accessor((r) => r.host?.fqdn ?? '', {
        id: 'host',
        header: 'Host',
        enableSorting: true,
        cell: (c) => c.row.original.host && <Anchor component={Link} to={`/hosts/${c.row.original.host.id}`} onClick={stop}>{c.getValue()}</Anchor>,
      })]
    : []),
  ...(opts.showSubnet
    ? [i.accessor((r) => r.subnet?.name ?? '', {
        id: 'subnet',
        header: 'Subnet',
        cell: (c) => c.row.original.subnet && <Anchor component={Link} to={`/subnets/${c.row.original.subnet.id}`} onClick={stop}>{c.getValue()} <Text span c="dimmed" size="xs">{c.row.original.subnet.network}</Text></Anchor>,
      })]
    : []),
  i.accessor('mac', { header: 'MAC', cell: (c) => <Text ff="monospace" size="sm">{c.getValue()}</Text> }),
  i.accessor('name', { id: 'name', header: 'Name', enableSorting: true }),
  i.accessor('description', { header: 'Description' }),
];

const d = createColumnHelper<DnsRecord>();
export function dnsValue(r: DnsRecord): string {
  if (r.type === 'TXT') return r.txt ?? '';
  if (r.type === 'MX') return `${r.mxPreference} ${r.pointsTo?.fqdn ?? ''}`;
  if (r.type === 'SRV') return `${r.srv?.priority} ${r.srv?.weight} ${r.srv?.port} ${r.pointsTo?.fqdn ?? ''}`;
  if (r.pointsTo) return r.pointsTo.fqdn;
  return r.ip ?? '';
}
export const dnsColumns = [
  d.accessor('type', { id: 'type', header: 'Type', enableSorting: true, size: 80, cell: (c) => <Badge variant="light" color={c.getValue() === 'A' || c.getValue() === 'AAAA' ? 'blue' : 'grape'}>{c.getValue()}</Badge> }),
  d.accessor('fqdn', { id: 'name', header: 'Name', enableSorting: true }),
  d.accessor((r) => dnsValue(r), { id: 'value', header: 'Value', cell: (c) => <Text ff="monospace" size="sm" lineClamp={1}>{c.getValue()}</Text> }),
  d.accessor('ttl', { header: 'TTL', cell: (c) => c.getValue() || <Text span c="dimmed" size="sm">default</Text> }),
  d.accessor('notes', { header: 'Notes', cell: (c) => <Text size="sm" lineClamp={1}>{c.getValue()}</Text> }),
];

const sc = createColumnHelper<Subnet>();
/** Unsorted subnet columns for subnets embedded in another record (VLAN, block, DHCP server). */
export const subnetColumns = [
  sc.accessor('name', { header: 'Subnet', cell: (c) => <Anchor component={Link} to={`/subnets/${c.row.original.id}`} fw={500} onClick={stop}>{c.getValue()}</Anchor> }),
  sc.accessor('network', { header: 'Network', cell: (c) => <Text ff="monospace" size="sm">{c.getValue()}</Text> }),
  sc.accessor((s) => s.type?.name ?? '', { id: 'type', header: 'Type' }),
  sc.accessor('usage', { header: 'IP usage', cell: (c) => <UsageBar usage={c.getValue()} /> }),
];
