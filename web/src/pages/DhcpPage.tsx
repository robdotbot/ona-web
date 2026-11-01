import { useMemo } from 'react';
import { Anchor, Button, Group, Tabs, Text } from '@mantine/core';
import { IconPlus } from '@tabler/icons-react';
import { createColumnHelper } from '@tanstack/react-table';
import { Link, useNavigate } from 'react-router-dom';
import {
  useAddDhcpServerSubnet,
  useCan,
  useCreateDhcpFailoverGroup,
  useDeleteDhcpFailoverGroup,
  useDeleteDhcpPool,
  useDhcpFailoverGroups,
  useDhcpPools,
  useDhcpServers,
  useUpdateDhcpFailoverGroup,
} from '../api/hooks';
import type { DhcpFailoverGroup, DhcpPool, DhcpServer } from '../api/types';
import { DataTable } from '../components/DataTable';
import { EntityForm, idOr, num, openForm, pick, type Field } from '../components/EntityForm';
import { PageHeader } from '../components/PageHeader';
import { rowActions } from '../components/columns';
import { confirmDelete, notifySuccess } from '../components/feedback';
import { DhcpOptionsPanel, DhcpPoolForm, dhcpPoolColumns } from '../components/panels';
import { useListParams } from '../lib/useListParams';

export function ServerSubnetForm({ hostId, hostLabel, subnetId, subnetLabel }: { hostId?: number; hostLabel?: string; subnetId?: number; subnetLabel?: string }) {
  const add = useAddDhcpServerSubnet();
  const navigate = useNavigate();
  return (
    <EntityForm
      fields={[
        { key: 'hostId', label: 'Server (host)', kind: 'host', required: true, initialLabel: hostLabel, disabled: !!hostId },
        { key: 'subnetId', label: 'Subnet it serves', kind: 'subnet', required: true, initialLabel: subnetLabel, disabled: !!subnetId },
      ]}
      initial={{ hostId: hostId ? String(hostId) : null, subnetId: subnetId ? String(subnetId) : null }}
      submitLabel="Assign"
      onSubmit={async (v) => {
        const server = await add.mutateAsync({ hostId: Number(v.hostId), subnetId: Number(v.subnetId) });
        notifySuccess(`${server.host.fqdn} now serves the subnet`);
        if (!hostId && !subnetId) navigate(`/dhcp/servers/${server.id}`);
      }}
    />
  );
}

const SETTINGS: Field[] = [
  { key: 'responseDelay', label: 'Max response delay', kind: 'number', min: 1, row: 2 },
  { key: 'unackedUpdates', label: 'Max unacked updates', kind: 'number', min: 1, row: 2 },
  { key: 'maxBalance', label: 'Max load balance', kind: 'number', min: 1, row: 3 },
  { key: 'mclt', label: 'MCLT (s)', kind: 'number', min: 1, row: 3 },
  { key: 'primaryPort', label: 'Primary port', kind: 'number', min: 1, max: 65535, row: 4 },
  { key: 'peerPort', label: 'Peer port', kind: 'number', min: 1, max: 65535, row: 4 },
  { key: 'split', label: 'Split', kind: 'number', min: 1, max: 255, description: 'Load balance split (0-255)' },
];

function FailoverGroupForm({ group }: { group?: DhcpFailoverGroup }) {
  const create = useCreateDhcpFailoverGroup();
  const update = useUpdateDhcpFailoverGroup();
  const fields: Field[] = [
    { key: 'primaryServerId', label: 'Primary server', kind: 'host', required: true, row: 1, initialLabel: group?.primaryServer?.fqdn, disabled: !!group },
    { key: 'secondaryServerId', label: 'Secondary server', kind: 'host', required: true, row: 1, initialLabel: group?.secondaryServer?.fqdn, disabled: !!group },
    ...SETTINGS,
  ];
  const initial = Object.fromEntries(SETTINGS.map((f) => [f.key, group ? (group[f.key as keyof DhcpFailoverGroup] as number) : '']));
  return (
    <EntityForm
      fields={fields}
      initial={{ primaryServerId: group?.primaryServer ? String(group.primaryServer.id) : null, secondaryServerId: group?.secondaryServer ? String(group.secondaryServer.id) : null, ...initial }}
      submitLabel={group ? 'Save' : 'Add failover group'}
      footer={<Text size="xs" c="dimmed">{group ? "ONA can't change the servers of a group; delete it and add a new one instead." : 'Empty settings use the ONA defaults.'}</Text>}
      onSubmit={async (v, changed) => {
        const settings = Object.fromEntries(SETTINGS.map((f) => [f.key, num(v[f.key])]));
        if (group) {
          const body = pick(settings, changed);
          if (Object.keys(body).length) await update.mutateAsync({ id: group.id, ...body });
        } else {
          await create.mutateAsync({ primaryServerId: idOr(v.primaryServerId), secondaryServerId: idOr(v.secondaryServerId), ...settings });
        }
        notifySuccess('Failover group saved');
      }}
    />
  );
}

const sc = createColumnHelper<DhcpServer>();
function Servers() {
  const servers = useDhcpServers();
  const navigate = useNavigate();
  const columns = [
    sc.accessor((s) => s.host.fqdn, { id: 'host', header: 'Server', cell: (c) => <Anchor component={Link} to={`/dhcp/servers/${c.row.original.id}`} fw={500} onClick={(e) => e.stopPropagation()}>{c.getValue()}</Anchor> }),
    sc.accessor((s) => s.host.primaryIp ?? '', { id: 'ip', header: 'IP address', cell: (c) => <Text ff="monospace" size="sm">{c.getValue()}</Text> }),
    sc.accessor('subnetCount', { header: 'Subnets served' }),
  ];
  return <DataTable columns={columns} data={servers.data} loading={servers.isFetching} error={servers.error} onRowClick={(s) => navigate(`/dhcp/servers/${s.id}`)} empty="No DHCP servers yet. Assign a subnet to a host to make it one." />;
}

const gc = createColumnHelper<DhcpFailoverGroup>();
function FailoverGroups() {
  const groups = useDhcpFailoverGroups();
  const del = useDeleteDhcpFailoverGroup();
  const canEdit = useCan('advanced');
  const columns = useMemo(
    () => [
      gc.accessor('id', { header: 'ID', size: 60 }),
      gc.accessor((g) => g.primaryServer?.fqdn ?? '', { id: 'primary', header: 'Primary', cell: (c) => c.row.original.primaryServer && <Anchor component={Link} to={`/dhcp/servers/${c.row.original.primaryServer.id}`}>{c.getValue()}</Anchor> }),
      gc.accessor((g) => g.secondaryServer?.fqdn ?? '', { id: 'secondary', header: 'Secondary', cell: (c) => c.row.original.secondaryServer && <Anchor component={Link} to={`/dhcp/servers/${c.row.original.secondaryServer.id}`}>{c.getValue()}</Anchor> }),
      gc.accessor((g) => `${g.primaryPort} / ${g.peerPort}`, { id: 'ports', header: 'Ports' }),
      gc.accessor('mclt', { header: 'MCLT' }),
      gc.accessor('split', { header: 'Split' }),
      gc.display({
        id: 'actions',
        header: '',
        cell: (c) => {
          const g = c.row.original;
          return rowActions({
            label: `group ${g.id}`,
            onEdit: canEdit ? () => openForm('Edit failover group', <FailoverGroupForm group={g} />, 'lg') : undefined,
            onDelete: canEdit
              ? () => confirmDelete({ title: `Delete failover group ${g.id}?`, message: 'Pools in the group are kept, without a failover group.', onConfirm: async () => { await del.mutateAsync(g.id); notifySuccess('Failover group deleted'); } })
              : undefined,
          });
        },
      }),
    ],
    [canEdit],
  );
  return <DataTable columns={columns} data={groups.data} loading={groups.isFetching} error={groups.error} empty="No failover groups." />;
}

function Pools() {
  const { params, update } = useListParams({ sort: 'start', perPage: 50 });
  const pools = useDhcpPools(params);
  const del = useDeleteDhcpPool();
  const canEdit = useCan('advanced');
  const navigate = useNavigate();
  const onDelete = (p: DhcpPool) =>
    confirmDelete({ title: `Delete pool ${p.start} – ${p.end}?`, message: 'This cannot be undone.', onConfirm: async () => { await del.mutateAsync(p.id); notifySuccess('DHCP pool deleted'); } });
  return (
    <DataTable
      columns={dhcpPoolColumns({ showSubnet: true, canEdit, onDelete })}
      data={pools.data?.data}
      meta={pools.data?.meta}
      loading={pools.isFetching}
      error={pools.error}
      sort={params.sort}
      onSortChange={(sort) => update({ sort })}
      onPageChange={(page) => update({ page }, false)}
      onPerPageChange={(perPage) => update({ perPage })}
      onRowClick={(p) => p.subnet && navigate(`/subnets/${p.subnet.id}`)}
      empty="No DHCP pools."
    />
  );
}

export function DhcpPage() {
  const { params, update } = useListParams({ sort: 'start' }, ['tab']);
  const tab = (params.tab as string) || 'servers';
  const canEdit = useCan('advanced');
  const action = canEdit && {
    servers: <Button leftSection={<IconPlus size={16} />} onClick={() => openForm('Assign a subnet to a DHCP server', <ServerSubnetForm />)}>Assign subnet to server</Button>,
    failover: <Button leftSection={<IconPlus size={16} />} onClick={() => openForm('Add failover group', <FailoverGroupForm />, 'lg')}>Add failover group</Button>,
    pools: <Button leftSection={<IconPlus size={16} />} onClick={() => openForm('Add DHCP pool', <DhcpPoolForm />, 'lg')}>Add pool</Button>,
    global: null,
  }[tab];
  return (
    <>
      <PageHeader title="DHCP" subtitle="Servers and the subnets they serve, failover pairs, address pools and options" actions={action} />
      <Tabs value={tab} onChange={(t) => update({ tab: t === 'servers' ? undefined : (t ?? undefined), sort: undefined, page: undefined })} keepMounted={false}>
        <Tabs.List mb="md">
          <Tabs.Tab value="servers">Servers</Tabs.Tab>
          <Tabs.Tab value="failover">Failover groups</Tabs.Tab>
          <Tabs.Tab value="pools">Pools</Tabs.Tab>
          <Tabs.Tab value="global">Global options</Tabs.Tab>
        </Tabs.List>
        <Tabs.Panel value="servers"><Servers /></Tabs.Panel>
        <Tabs.Panel value="failover"><FailoverGroups /></Tabs.Panel>
        <Tabs.Panel value="pools"><Pools /></Tabs.Panel>
        <Tabs.Panel value="global">
          <Group mb="xs"><Text size="sm" c="dimmed">Options here apply to every subnet and host unless set more specifically.</Text></Group>
          <DhcpOptionsPanel scope={{ global: true }} title="Global DHCP options" />
        </Tabs.Panel>
      </Tabs>
    </>
  );
}
