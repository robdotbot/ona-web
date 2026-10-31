import { Alert, Button, Card, Center, Loader, SimpleGrid, Stack } from '@mantine/core';
import { IconPencil, IconTrash } from '@tabler/icons-react';
import { useNavigate, useParams } from 'react-router-dom';
import { errorMessage } from '../api/client';
import { useCan, useDeleteVlan, useVlan } from '../api/hooks';
import { DataTable } from '../components/DataTable';
import { PageHeader } from '../components/PageHeader';
import { CustomAttributesPanel, Fact, SectionHeader } from '../components/panels';
import { subnetColumns } from '../components/columns';
import { confirmDelete, notifySuccess } from '../components/feedback';
import { NotFoundPage } from './NotFoundPage';
import { openVlanForm } from './VlansPage';

export function VlanDetailPage() {
  const id = Number(useParams().id);
  const vlan = useVlan(id);
  const del = useDeleteVlan();
  const navigate = useNavigate();
  const canEdit = useCan('vlan_modify');
  const canDelete = useCan('vlan_del');

  if (vlan.isPending) return <Center py="xl"><Loader /></Center>;
  if (vlan.error) {
    if ((vlan.error as { status?: number }).status === 404) return <NotFoundPage what="VLAN" />;
    return <Alert color="red">{errorMessage(vlan.error)}</Alert>;
  }
  const v = vlan.data;
  const onDelete = () =>
    confirmDelete({
      title: `Delete VLAN ${v.name}?`,
      message: v.subnets.length ? `${v.subnets.length} subnet(s) still use it; ONA only deletes unused VLANs. Detach them first (edit each subnet).` : 'This cannot be undone.',
      onConfirm: async () => { await del.mutateAsync(v.id); notifySuccess(`VLAN ${v.name} deleted`); navigate('/vlans'); },
    });

  return (
    <Stack>
      <PageHeader
        title={`VLAN ${v.number} · ${v.name}`}
        subtitle={`Campus ${v.campus.name}`}
        actions={
          <>
            {canEdit && <Button variant="default" leftSection={<IconPencil size={16} />} onClick={() => openVlanForm(v)}>Edit</Button>}
            {canDelete && <Button variant="light" color="red" leftSection={<IconTrash size={16} />} onClick={onDelete}>Delete</Button>}
          </>
        }
      />
      <Card withBorder>
        <SimpleGrid cols={{ base: 2, sm: 4 }}>
          <Fact label="Number">{v.number}</Fact>
          <Fact label="Name">{v.name}</Fact>
          <Fact label="Campus">{v.campus.name}</Fact>
          <Fact label="Subnets">{v.subnets.length}</Fact>
        </SimpleGrid>
      </Card>
      <SectionHeader title="Subnets" />
      <DataTable columns={subnetColumns} data={v.subnets} onRowClick={(s) => navigate(`/subnets/${s.id}`)} empty="No subnets use this VLAN. Assign one by editing the subnet." />
      <CustomAttributesPanel target={{ vlanId: v.id }} />
    </Stack>
  );
}
