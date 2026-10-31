import { useMemo, type ReactNode } from 'react';
import { Alert, Anchor, Button, Card, Center, Group, Loader, SimpleGrid, Stack, Text, Title } from '@mantine/core';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { IconPencil, IconPlus, IconTrash } from '@tabler/icons-react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useCan, useDeleteDnsRecord, useDeleteHost, useDeleteInterface, useDhcpServers, useHost } from '../api/hooks';
import { errorMessage } from '../api/client';
import type { DnsRecord, Interface } from '../api/types';
import { DataTable } from '../components/DataTable';
import { PageHeader } from '../components/PageHeader';
import { openHostForm } from '../components/HostForm';
import { openInterfaceForm } from '../components/InterfaceForm';
import { openDnsRecordForm } from '../components/DnsRecordForm';
import { dnsColumns, interfaceColumns, rowActions } from '../components/columns';
import { confirmDelete, notifySuccess } from '../components/feedback';
import { CustomAttributesPanel, DhcpOptionsPanel } from '../components/panels';
import { NotFoundPage } from './NotFoundPage';

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <Text size="xs" c="dimmed" tt="uppercase" fw={600}>{label}</Text>
      <Text style={{ whiteSpace: 'pre-wrap' }}>{children || '—'}</Text>
    </div>
  );
}

export function HostDetailPage() {
  const id = Number(useParams().id);
  const host = useHost(id);
  const dhcpServers = useDhcpServers();
  const navigate = useNavigate();
  const delHost = useDeleteHost();
  const delIface = useDeleteInterface();
  const delDns = useDeleteDnsRecord();
  const can = {
    hostAdd: useCan('host_add'),
    hostModify: useCan('host_modify'),
    hostDel: useCan('host_del'),
    ifaceModify: useCan('interface_modify'),
    ifaceDel: useCan('interface_del') && useCan('host_del'),
  };

  const ifaceCols = useMemo<ColumnDef<Interface, any>[]>(() => {
    const ic = createColumnHelper<Interface>();
    return [
      ...interfaceColumns({ showSubnet: true }),
      ic.display({
        id: 'actions',
        header: '',
        cell: (c) => {
          const r = c.row.original;
          const primary = r.id === host.data?.primaryInterfaceId;
          return rowActions({
            label: r.ip,
            onEdit: can.ifaceModify ? () => openInterfaceForm(id, r) : undefined,
            onDelete: can.ifaceDel && !primary
              ? () => confirmDelete({
                  title: `Delete interface ${r.ip}?`,
                  message: 'DNS records on this address are deleted too.',
                  onConfirm: async () => { await delIface.mutateAsync(r.id); notifySuccess(`Interface ${r.ip} deleted`); },
                })
              : undefined,
          });
        },
      }),
    ];
  }, [host.data?.primaryInterfaceId, can.ifaceModify, can.ifaceDel, id]);

  const dnsCols = useMemo<ColumnDef<DnsRecord, any>[]>(() => {
    const dc = createColumnHelper<DnsRecord>();
    return [
      ...dnsColumns,
      dc.display({
        id: 'actions',
        header: '',
        cell: (c) => {
          const r = c.row.original;
          const primary = r.id === host.data?.primaryDnsId;
          return rowActions({
            label: `${r.type} ${r.fqdn}`,
            onEdit: can.hostModify ? () => openDnsRecordForm(r) : undefined,
            onDelete: can.hostDel && !primary
              ? () => confirmDelete({
                  title: `Delete ${r.type} record ${r.fqdn}?`,
                  message: 'This cannot be undone.',
                  onConfirm: async () => { await delDns.mutateAsync(r.id); notifySuccess('Record deleted'); },
                })
              : undefined,
          });
        },
      }),
    ];
  }, [host.data?.primaryDnsId, can.hostModify, can.hostDel]);

  if (host.isPending) return <Center py="xl"><Loader /></Center>;
  if (host.error) {
    if ((host.error as { status?: number }).status === 404) return <NotFoundPage what="Host" />;
    return <Alert color="red">{errorMessage(host.error)}</Alert>;
  }
  const h = host.data;

  const onDelete = () =>
    confirmDelete({
      title: `Delete host ${h.fqdn}?`,
      message: `This deletes the host, its ${h.interfaceCount} interface(s) and their DNS records. This cannot be undone.`,
      onConfirm: async () => {
        await delHost.mutateAsync(h.id);
        notifySuccess(`Host ${h.fqdn} deleted`);
        navigate('/hosts');
      },
    });

  return (
    <Stack>
      <PageHeader
        title={h.fqdn}
        subtitle={h.deviceType?.name}
        actions={
          <>
            {can.hostModify && <Button variant="default" leftSection={<IconPencil size={16} />} onClick={() => openHostForm(h)}>Edit</Button>}
            {can.hostDel && <Button variant="light" color="red" leftSection={<IconTrash size={16} />} onClick={onDelete}>Delete</Button>}
          </>
        }
      />
      <Card withBorder>
        <SimpleGrid cols={{ base: 2, sm: 4 }}>
          <Fact label="Primary IP">{h.primaryIp}</Fact>
          <Fact label="Domain">{h.domain.name}</Fact>
          <Fact label="Device type">{h.deviceType?.name}</Fact>
          <Fact label="Location">{h.location ? `${h.location.reference} – ${h.location.name}` : ''}</Fact>
        </SimpleGrid>
        {h.notes && <Stack mt="md" gap={0}><Fact label="Notes">{h.notes}</Fact></Stack>}
      </Card>

      <Group justify="space-between" mt="md">
        <Title order={4}>Interfaces</Title>
        {can.hostAdd && <Button size="xs" variant="light" leftSection={<IconPlus size={14} />} onClick={() => openInterfaceForm(h.id)}>Add interface</Button>}
      </Group>
      <DataTable columns={ifaceCols} data={h.interfaces} empty="No interfaces." />

      <Group justify="space-between" mt="md">
        <Title order={4}>DNS records</Title>
        {can.hostAdd && (
          <Button size="xs" variant="light" leftSection={<IconPlus size={14} />} onClick={() => openDnsRecordForm(undefined, { type: 'CNAME', pointsTo: h.fqdn, name: '' })}>
            Add record
          </Button>
        )}
      </Group>
      <DataTable columns={dnsCols} data={h.dnsRecords} empty="No DNS records." />
      <Text size="xs" c="dimmed">
        The primary interface and primary DNS record can't be deleted here; delete the host instead. See all records in{' '}
        <Anchor component={Link} to={`/dns?hostId=${h.id}`} size="xs">DNS records</Anchor>.
      </Text>
      <DhcpOptionsPanel scope={{ hostId: h.id }} />
      <CustomAttributesPanel target={{ hostId: h.id }} />
      {dhcpServers.data?.some((d) => d.id === h.id) && (
        <Text size="xs" c="dimmed">
          This host is a DHCP server: see <Anchor component={Link} to={`/dhcp/servers/${h.id}`} size="xs">the subnets it serves</Anchor>.
        </Text>
      )}
    </Stack>
  );
}
