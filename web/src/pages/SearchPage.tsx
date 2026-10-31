import { useEffect, useMemo, type ReactNode } from 'react';
import { Alert, Badge, Center, Group, Loader, Stack, Text, Title } from '@mantine/core';
import { createColumnHelper } from '@tanstack/react-table';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useSearch } from '../api/hooks';
import { errorMessage } from '../api/client';
import type { Subnet } from '../api/types';
import { DataTable } from '../components/DataTable';
import { PageHeader } from '../components/PageHeader';
import { UsageBar } from '../components/UsageBar';
import { dnsColumns, hostColumns, interfaceColumns } from '../components/columns';

const sc = createColumnHelper<Subnet>();
const subnetColumns = [
  sc.accessor('name', { header: 'Name' }),
  sc.accessor('network', { header: 'Network' }),
  sc.accessor('usage', { header: 'IP usage', cell: (c) => <UsageBar usage={c.getValue()} /> }),
];

function Section({ title, count, children }: { title: string; count: number; children: ReactNode }) {
  if (!count) return null;
  return (
    <Stack gap="xs">
      <Group gap="xs">
        <Title order={4}>{title}</Title>
        <Badge variant="light">{count}</Badge>
      </Group>
      {children}
    </Stack>
  );
}

export function SearchPage() {
  const [sp] = useSearchParams();
  const q = sp.get('q') ?? '';
  const search = useSearch(q);
  const navigate = useNavigate();
  const ifaceCols = useMemo(() => interfaceColumns({ showHost: true, showSubnet: true }), []);
  const r = search.data;

  // Like the classic quick search: an exact IP or name match goes straight there.
  useEffect(() => {
    if (r?.bestMatch && sp.get('stay') !== '1') {
      navigate(`/${r.bestMatch.type === 'host' ? 'hosts' : 'subnets'}/${r.bestMatch.id}`, { replace: true });
    }
  }, [r, navigate, sp]);

  const total = r ? r.hosts.length + r.subnets.length + r.interfaces.length + r.dnsRecords.length : 0;

  return (
    <>
      <PageHeader title="Search" subtitle={q ? `Results for “${q}”` : 'Type in the search box above'} />
      {search.isFetching && <Center py="xl"><Loader /></Center>}
      {search.error && <Alert color="red">{errorMessage(search.error)}</Alert>}
      {r && !search.isFetching && total === 0 && <Text c="dimmed">Nothing matches “{q}”.</Text>}
      {r && !search.isFetching && (
        <Stack gap="xl">
          <Section title="Hosts" count={r.hosts.length}>
            <DataTable columns={hostColumns} data={r.hosts} onRowClick={(h) => navigate(`/hosts/${h.id}`)} />
          </Section>
          <Section title="Subnets" count={r.subnets.length}>
            <DataTable columns={subnetColumns} data={r.subnets} onRowClick={(s) => navigate(`/subnets/${s.id}`)} />
          </Section>
          <Section title="Interfaces" count={r.interfaces.length}>
            <DataTable columns={ifaceCols} data={r.interfaces} onRowClick={(i) => i.host && navigate(`/hosts/${i.host.id}`)} />
          </Section>
          <Section title="DNS records" count={r.dnsRecords.length}>
            <DataTable columns={dnsColumns} data={r.dnsRecords} onRowClick={(d) => d.hostId && navigate(`/hosts/${d.hostId}`)} />
          </Section>
          {total >= 10 && <Text size="xs" c="dimmed">Each group shows up to 10 matches; use the list pages' filters for more.</Text>}
        </Stack>
      )}
    </>
  );
}
