import { Alert, Anchor, Card, Center, Loader, SimpleGrid, Stack } from '@mantine/core';
import { createColumnHelper } from '@tanstack/react-table';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { errorMessage } from '../api/client';
import { useCan, useDhcpServer, useRemoveDhcpServerSubnet } from '../api/hooks';
import type { DhcpFailoverGroup, Subnet } from '../api/types';
import { DataTable } from '../components/DataTable';
import { openForm } from '../components/EntityForm';
import { PageHeader } from '../components/PageHeader';
import { rowActions, subnetColumns } from '../components/columns';
import { confirmDelete, notifySuccess } from '../components/feedback';
import { AddButton, DhcpOptionsPanel, Fact, SectionHeader } from '../components/panels';
import { NotFoundPage } from './NotFoundPage';
import { ServerSubnetForm } from './DhcpPage';

const sc = createColumnHelper<Subnet>();
const gc = createColumnHelper<DhcpFailoverGroup>();

export function DhcpServerPage() {
  const id = Number(useParams().id);
  const server = useDhcpServer(id);
  const remove = useRemoveDhcpServerSubnet();
  const canEdit = useCan('advanced');
  const navigate = useNavigate();

  if (server.isPending) return <Center py="xl"><Loader /></Center>;
  if (server.error) {
    if ((server.error as { status?: number }).status === 404) return <NotFoundPage what="DHCP server" />;
    return <Alert color="red">{errorMessage(server.error)}</Alert>;
  }
  const s = server.data;
  const columns = [
    ...subnetColumns,
    sc.display({
      id: 'actions',
      header: '',
      cell: (c) => {
        const sub = c.row.original;
        return rowActions({
          label: sub.name,
          onDelete: canEdit
            ? () => confirmDelete({
                title: `Stop serving ${sub.name}?`,
                message: `${s.host.fqdn} will no longer serve DHCP for ${sub.network}.`,
                onConfirm: async () => {
                  await remove.mutateAsync({ hostId: s.id, subnetId: sub.id });
                  notifySuccess(`${sub.name} removed from ${s.host.fqdn}`);
                  if (s.subnets.length === 1 && !s.failoverGroups.length) navigate('/dhcp');
                },
              })
            : undefined,
        });
      },
    }),
  ];
  const groupColumns = [
    gc.accessor('id', { header: 'Group', size: 70 }),
    gc.accessor((g) => g.primaryServer?.fqdn ?? '', { id: 'primary', header: 'Primary' }),
    gc.accessor((g) => g.secondaryServer?.fqdn ?? '', { id: 'secondary', header: 'Secondary' }),
    gc.accessor('split', { header: 'Split' }),
  ];

  return (
    <Stack>
      <PageHeader title={s.host.fqdn} subtitle="DHCP server" />
      <Card withBorder>
        <SimpleGrid cols={{ base: 2, sm: 4 }}>
          <Fact label="Host"><Anchor component={Link} to={`/hosts/${s.host.id}`}>{s.host.fqdn}</Anchor></Fact>
          <Fact label="IP address">{s.host.primaryIp}</Fact>
          <Fact label="Subnets served">{s.subnets.length}</Fact>
          <Fact label="Failover groups">{s.failoverGroups.length}</Fact>
        </SimpleGrid>
      </Card>
      <SectionHeader
        title="Subnets served"
        action={canEdit && <AddButton onClick={() => openForm(`Assign a subnet to ${s.host.fqdn}`, <ServerSubnetForm hostId={s.id} hostLabel={s.host.fqdn} />)}>Assign subnet</AddButton>}
      />
      <DataTable columns={columns} data={s.subnets} onRowClick={(sub) => navigate(`/subnets/${sub.id}`)} empty="This server serves no subnets directly." />
      <SectionHeader title="Failover groups" />
      <DataTable columns={groupColumns} data={s.failoverGroups} onRowClick={() => navigate('/dhcp?tab=failover')} empty="Not in a failover group." />
      <DhcpOptionsPanel scope={{ serverId: s.id }} title="Server DHCP options" />
    </Stack>
  );
}
