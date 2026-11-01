// Every address of a subnet in order with its status (the classic "List by
// IP"): used / free / DHCP pool / reserved. Works for any size, IPv6 too;
// CSV download for subnets up to 65,536 addresses.
import { ActionIcon, Anchor, Badge, Button, Group, SegmentedControl, Text, Tooltip } from '@mantine/core';
import { IconDownload, IconPlus } from '@tabler/icons-react';
import { createColumnHelper } from '@tanstack/react-table';
import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { fetchSubnetAddresses, useCan, useSubnetAddresses } from '../api/hooks';
import type { AddressStatus, SubnetAddress, SubnetDetail } from '../api/types';
import { DataTable } from './DataTable';
import { openHostForm } from './HostForm';
import { notifyError } from './feedback';
import { SectionHeader } from './panels';

const STATUS: Record<AddressStatus, { label: string; color: string }> = {
  used: { label: 'in use', color: 'blue' },
  free: { label: 'free', color: 'gray' },
  pool: { label: 'DHCP pool', color: 'orange' },
  reserved: { label: 'network / broadcast', color: 'dark' },
};
const CSV_LIMIT = 65536;

const csvCell = (v: string) => (/[",\n]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v);

async function downloadCsv(subnet: SubnetDetail, status: string) {
  const rows: SubnetAddress[] = [];
  for (let page = 1; ; page++) {
    const r = await fetchSubnetAddresses(subnet.id, { page, perPage: 1000, status });
    rows.push(...r.data);
    if (page >= Number(r.meta.totalPages)) break;
  }
  const csv = ['ip,status,host', ...rows.map((r) => [r.ip, r.status, r.host?.fqdn ?? ''].map(csvCell).join(','))].join('\n') + '\n';
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
  a.download = `${subnet.name}-${status}.csv`;
  a.click();
  URL.revokeObjectURL(a.href);
}

const col = createColumnHelper<SubnetAddress>();

export function SubnetAddressesPanel({ subnet }: { subnet: SubnetDetail }) {
  const [status, setStatus] = useState('all');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [downloading, setDownloading] = useState(false);
  const list = useSubnetAddresses(subnet.id, { page, perPage, status });
  const canAdd = useCan('host_add');
  const total = list.data ? Number(list.data.meta.total) : undefined;
  const columns = useMemo(
    () => [
      col.accessor('ip', { header: 'Address', cell: (c) => <Text ff="monospace" size="sm">{c.getValue()}</Text> }),
      col.accessor('status', { header: 'Status', cell: (c) => <Badge size="sm" variant="light" color={STATUS[c.getValue()].color} tt="none">{STATUS[c.getValue()].label}</Badge> }),
      col.accessor((r) => r.host?.fqdn ?? '', {
        id: 'host',
        header: 'Host',
        cell: (c) => c.row.original.host && <Anchor component={Link} to={`/hosts/${c.row.original.host.id}`} size="sm">{c.getValue()}</Anchor>,
      }),
      col.display({
        id: 'actions',
        header: '',
        cell: (c) =>
          canAdd && c.row.original.status === 'free' && (
            <Group justify="flex-end">
              <Tooltip label="Add a host here">
                <ActionIcon variant="subtle" aria-label={`Add a host at ${c.row.original.ip}`} onClick={() => openHostForm(undefined, { ip: c.row.original.ip })}>
                  <IconPlus size={16} />
                </ActionIcon>
              </Tooltip>
            </Group>
          ),
      }),
    ],
    [canAdd],
  );

  return (
    <>
      <SectionHeader
        title="All addresses"
        action={
          <Group gap="sm">
            <SegmentedControl
              size="xs"
              value={status}
              onChange={(v) => { setStatus(v); setPage(1); }}
              data={[{ value: 'all', label: 'All' }, { value: 'used', label: 'In use' }, { value: 'free', label: 'Free' }]}
              aria-label="Address status"
            />
            <Tooltip label={total !== undefined && total > CSV_LIMIT ? `Only for up to ${CSV_LIMIT.toLocaleString()} addresses` : 'Download as CSV'}>
              <Button
                size="xs"
                variant="default"
                leftSection={<IconDownload size={14} />}
                disabled={total === undefined || total > CSV_LIMIT || total === 0}
                loading={downloading}
                onClick={() => { setDownloading(true); downloadCsv(subnet, status).catch(notifyError).finally(() => setDownloading(false)); }}
              >
                CSV
              </Button>
            </Tooltip>
          </Group>
        }
      />
      <DataTable
        columns={columns}
        data={list.data?.data}
        meta={list.data ? { page: list.data.meta.page, perPage: list.data.meta.perPage, total: total!, totalPages: Number(list.data.meta.totalPages) } : undefined}
        loading={list.isFetching}
        error={list.error}
        totalText={list.data && typeof list.data.meta.total === 'string' ? BigInt(list.data.meta.total).toLocaleString('en-US') : undefined}
        onPageChange={setPage}
        onPerPageChange={(n) => { setPerPage(n); setPage(1); }}
        empty={status === 'free' ? 'No free addresses.' : 'No addresses.'}
      />
    </>
  );
}
