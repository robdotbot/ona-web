import { useMemo, type ReactNode } from 'react';
import { ActionIcon, Alert, Anchor, Badge, Button, Card, Center, Group, Loader, Menu, SimpleGrid, Stack, Text, Title } from '@mantine/core';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { IconArrowsExchange, IconDots, IconLink, IconLinkOff, IconPencil, IconPlus, IconTrash, IconWorldShare } from '@tabler/icons-react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useCan, useDeleteDnsRecord, useDeleteHost, useDeleteInterface, useDeleteNat, useDhcpServers, useHost, useUnshareInterface } from '../api/hooks';
import { errorMessage } from '../api/client';
import type { DnsRecord, HostDetail } from '../api/types';
import { DataTable } from '../components/DataTable';
import { PageHeader } from '../components/PageHeader';
import { openHostForm } from '../components/HostForm';
import { openInterfaceForm } from '../components/InterfaceForm';
import { openDnsRecordForm } from '../components/DnsRecordForm';
import { dnsColumns, interfaceColumns, rowActions } from '../components/columns';
import { confirmDelete, notifyError, notifySuccess } from '../components/feedback';
import { CustomAttributesPanel, DhcpOptionsPanel, SectionHeader } from '../components/panels';
import { openMoveToHost, openNat, openShare } from '../components/InterfaceActions';
import { MessagesPanel, TagsBar } from '../components/TagsMessages';
import { ConfigsPanel } from '../components/ConfigsPanel';
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
  const delNat = useDeleteNat();
  const unshare = useUnshareInterface();
  const ifaceModify = useCan('interface_modify');
  const ifaceDelOnly = useCan('interface_del');
  const hostAdd = useCan('host_add');
  const hostDel = useCan('host_del');
  const can = {
    advanced: useCan('advanced'),
    ifaceDelOnly,
    natAdd: ifaceModify && hostAdd,
    natDel: ifaceModify && ifaceDelOnly && hostDel,
    hostAdd,
    hostModify: useCan('host_modify'),
    hostDel,
    ifaceModify,
    // (Not `useCan(a) && useCan(b)`: hooks must not be called conditionally.)
    ifaceDel: ifaceDelOnly && hostDel,
  };

  const ifaceCols = useMemo<ColumnDef<HostDetail['interfaces'][number], any>[]>(() => {
    const ic = createColumnHelper<HostDetail['interfaces'][number]>();
    const byId = new Map((host.data?.interfaces ?? []).map((i) => [i.id, i]));
    return [
      ...(interfaceColumns({ showSubnet: true }) as ColumnDef<HostDetail['interfaces'][number], any>[]),
      ic.display({
        id: 'links',
        header: 'NAT / shared with',
        cell: (c) => {
          const r = c.row.original;
          const nat = r.natInterfaceId ? byId.get(r.natInterfaceId) : undefined;
          return (
            <Group gap={4} wrap="wrap">
              {r.natInterfaceId && <Badge size="sm" variant="light" color="orange" tt="none">NAT {nat?.ip ?? `#${r.natInterfaceId}`}</Badge>}
              {r.sharedWith.map((sh) => (
                <Badge key={sh.hostId} size="sm" variant="light" color="teal" tt="none" component={Link} to={`/hosts/${sh.hostId}`} style={{ cursor: 'pointer' }} onClick={(e) => e.stopPropagation()}>
                  {sh.fqdn}{sh.name ? ` (${sh.name})` : ''}
                </Badge>
              ))}
            </Group>
          );
        },
      }),
      ic.display({
        id: 'actions',
        header: '',
        cell: (c) => {
          const r = c.row.original;
          const primary = r.id === host.data?.primaryInterfaceId;
          const extra = [
            can.advanced && !primary && { label: 'Move to another host…', icon: IconArrowsExchange, onClick: () => openMoveToHost(r) },
            can.ifaceDelOnly && { label: 'Share with another host…', icon: IconLink, onClick: () => openShare(r) },
            ...(can.ifaceDelOnly ? r.sharedWith.map((sh) => ({
              label: `Stop sharing with ${sh.fqdn}`,
              icon: IconLinkOff,
              onClick: () => unshare.mutateAsync({ id: r.id, hostId: sh.hostId }).then(() => notifySuccess(`No longer shared with ${sh.fqdn}`), notifyError),
            })) : []),
            can.natAdd && !r.natInterfaceId && { label: 'Add NAT address…', icon: IconWorldShare, onClick: () => openNat(r) },
            can.natDel && r.natInterfaceId && {
              label: 'Remove NAT address',
              icon: IconTrash,
              color: 'red',
              onClick: () => confirmDelete({
                title: `Remove the NAT address of ${r.ip}?`,
                message: 'The NAT interface and its DNS records are deleted.',
                onConfirm: async () => { await delNat.mutateAsync(r.id); notifySuccess('NAT address removed'); },
              }),
            },
          ].filter(Boolean) as { label: string; icon: typeof IconLink; color?: string; onClick: () => void }[];
          return (
            <Group gap={4} justify="flex-end" wrap="nowrap" onClick={(e) => e.stopPropagation()}>
              {rowActions({
                label: r.ip,
                onEdit: can.ifaceModify ? () => openInterfaceForm(id, r) : undefined,
                onDelete: can.ifaceDel && !primary
                  ? () => confirmDelete({
                      title: `Delete interface ${r.ip}?`,
                      message: 'DNS records on this address are deleted too.',
                      onConfirm: async () => { await delIface.mutateAsync(r.id); notifySuccess(`Interface ${r.ip} deleted`); },
                    })
                  : undefined,
              })}
              {extra.length > 0 && (
                <Menu position="bottom-end" withinPortal>
                  <Menu.Target>
                    <ActionIcon variant="subtle" aria-label={`More actions for ${r.ip}`}><IconDots size={16} /></ActionIcon>
                  </Menu.Target>
                  <Menu.Dropdown>
                    {extra.map((a) => (
                      <Menu.Item key={a.label} color={a.color} leftSection={<a.icon size={14} />} onClick={a.onClick}>{a.label}</Menu.Item>
                    ))}
                  </Menu.Dropdown>
                </Menu>
              )}
            </Group>
          );
        },
      }),
    ];
  }, [host.data, can.ifaceModify, can.ifaceDel, can.advanced, can.ifaceDelOnly, can.natAdd, can.natDel, id]);

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

  const sharedCols = useMemo(() => interfaceColumns({ showHost: true, showSubnet: true }), []);

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
        <Stack mt="md" gap={4}>
          <Text size="xs" c="dimmed" tt="uppercase" fw={600}>Tags</Text>
          <TagsBar target={{ hostId: h.id }} />
        </Stack>
      </Card>
      <MessagesPanel target={{ hostId: h.id }} />

      <Group justify="space-between" mt="md">
        <Title order={4}>Interfaces</Title>
        {can.hostAdd && <Button size="xs" variant="light" leftSection={<IconPlus size={14} />} onClick={() => openInterfaceForm(h.id)}>Add interface</Button>}
      </Group>
      <DataTable columns={ifaceCols} data={h.interfaces} empty="No interfaces." />
      {h.sharedInterfaces.length > 0 && (
        <>
          <SectionHeader title="Shared from other hosts" />
          <DataTable columns={sharedCols} data={h.sharedInterfaces} empty="" />
        </>
      )}

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
      <ConfigsPanel hostId={h.id} />
      {dhcpServers.data?.some((d) => d.id === h.id) && (
        <Text size="xs" c="dimmed">
          This host is a DHCP server: see <Anchor component={Link} to={`/dhcp/servers/${h.id}`} size="xs">the subnets it serves</Anchor>.
        </Text>
      )}
    </Stack>
  );
}
