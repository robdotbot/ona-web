import { useMemo, type ReactNode } from 'react';
import { Alert, Anchor, Badge, Button, Card, Center, Group, Loader, SimpleGrid, Stack, Text, Title } from '@mantine/core';
import { IconArrowsExchange, IconPencil, IconPlus, IconTrash } from '@tabler/icons-react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { fetchNextIp, useCan, useDeleteSubnet, useInterfaces, useSubnet } from '../api/hooks';
import { errorMessage } from '../api/client';
import { DataTable } from '../components/DataTable';
import { PageHeader } from '../components/PageHeader';
import { UsageBar } from '../components/UsageBar';
import { openSubnetForm } from '../components/SubnetForm';
import { openHostForm } from '../components/HostForm';
import { interfaceColumns } from '../components/columns';
import { confirmDelete, notifyError, notifySuccess } from '../components/feedback';
import { useListParams } from '../lib/useListParams';
import { CustomAttributesPanel, DhcpOptionsPanel, DhcpPoolsPanel } from '../components/panels';
import { MessagesPanel, TagsBar } from '../components/TagsMessages';
import { SubnetAddressMap } from '../components/AddressMaps';
import { openBulkMove } from '../components/InterfaceActions';
import { NotFoundPage } from './NotFoundPage';

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <Text size="xs" c="dimmed" tt="uppercase" fw={600}>{label}</Text>
      <Text>{children}</Text>
    </div>
  );
}

export function SubnetDetailPage() {
  const id = Number(useParams().id);
  const subnet = useSubnet(id);
  const { params, update } = useListParams({ sort: 'ip', perPage: 50 });
  const ifaces = useInterfaces({ ...params, subnetId: id }, subnet.isSuccess);
  const del = useDeleteSubnet();
  const navigate = useNavigate();
  const canEdit = useCan('subnet_modify');
  const canDelete = useCan('subnet_del');
  const canAddHost = useCan('host_add');
  const canMove = useCan('interface_modify');
  const columns = useMemo(() => interfaceColumns({ showHost: true }), []);

  if (subnet.isPending) return <Center py="xl"><Loader /></Center>;
  if (subnet.error) {
    if ((subnet.error as { status?: number }).status === 404) return <NotFoundPage what="Subnet" />;
    return <Alert color="red">{errorMessage(subnet.error)}</Alert>;
  }
  const s = subnet.data;

  const addHost = async () => {
    let ip: string | undefined;
    try {
      ip = await fetchNextIp(s.id);
    } catch {
      // A full subnet has no next IP; let the user type one.
    }
    openHostForm(undefined, { ip });
  };

  const onDelete = () =>
    confirmDelete({
      title: `Delete subnet ${s.name}?`,
      message: s.interfaceCount
        ? `This also deletes its ${s.interfaceCount} interface(s), and any of its ${s.hostCount} host(s) that have no interfaces elsewhere. This cannot be undone.`
        : 'This cannot be undone.',
      onConfirm: async () => {
        await del.mutateAsync(s.id);
        notifySuccess(`Subnet ${s.name} deleted`);
        navigate('/subnets');
      },
    });

  return (
    <Stack>
      <PageHeader
        title={<Group gap="sm">{s.name}{s.type && <Badge variant="light">{s.type.name}</Badge>}</Group>}
        subtitle={s.network}
        actions={
          <>
            {canAddHost && <Button leftSection={<IconPlus size={16} />} onClick={() => addHost().catch(notifyError)}>Add host</Button>}
            {canMove && s.ipVersion === 4 && s.interfaceCount > 0 && (
              <Button variant="default" leftSection={<IconArrowsExchange size={16} />} onClick={() => openBulkMove({ start: s.firstIp, end: s.lastIp })}>Move addresses</Button>
            )}
            {canEdit && <Button variant="default" leftSection={<IconPencil size={16} />} onClick={() => openSubnetForm(s)}>Edit</Button>}
            {canDelete && <Button variant="light" color="red" leftSection={<IconTrash size={16} />} onClick={onDelete}>Delete</Button>}
          </>
        }
      />
      <Card withBorder>
        <SimpleGrid cols={{ base: 2, sm: 4 }}>
          <Fact label="Network">{s.network}</Fact>
          <Fact label="Netmask">{s.netmask}</Fact>
          {s.firstIp && <Fact label="Range">{s.firstIp} – {s.lastIp}</Fact>}
          <Fact label="VLAN">{s.vlan ? <Anchor component={Link} to={`/vlans/${s.vlan.id}`}>{s.vlan.number} {s.vlan.name} ({s.vlan.campus})</Anchor> : '—'}</Fact>
          <Fact label="Hosts">{s.hostCount}</Fact>
          <Fact label="Interfaces">{s.interfaceCount}</Fact>
          <div>
            <Text size="xs" c="dimmed" tt="uppercase" fw={600}>IP usage</Text>
            <UsageBar usage={s.usage} width={160} />
            <Text size="xs" c="dimmed">{s.usage.used} of {s.usage.total} used</Text>
          </div>
        </SimpleGrid>
        <Stack mt="md" gap={4}>
          <Text size="xs" c="dimmed" tt="uppercase" fw={600}>Tags</Text>
          <TagsBar target={{ subnetId: s.id }} />
        </Stack>
      </Card>
      <MessagesPanel target={{ subnetId: s.id }} />
      <SubnetAddressMap subnet={s} />
      <Title order={4} mt="md">Addresses in use</Title>
      <DataTable
        columns={columns}
        data={ifaces.data?.data}
        meta={ifaces.data?.meta}
        loading={ifaces.isFetching}
        error={ifaces.error}
        sort={params.sort}
        onSortChange={(sort) => update({ sort })}
        onPageChange={(page) => update({ page }, false)}
        onPerPageChange={(perPage) => update({ perPage })}
        onRowClick={(r) => r.host && navigate(`/hosts/${r.host.id}`)}
        empty="No addresses are in use on this subnet."
      />
      {s.ipVersion === 4 && <DhcpPoolsPanel subnetId={s.id} />}
      <DhcpOptionsPanel scope={{ subnetId: s.id }} />
      <CustomAttributesPanel target={{ subnetId: s.id }} />
    </Stack>
  );
}
