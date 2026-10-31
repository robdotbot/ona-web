import { Alert, Button, Card, Center, Loader, SimpleGrid, Stack } from '@mantine/core';
import { IconPencil, IconTrash } from '@tabler/icons-react';
import { useNavigate, useParams } from 'react-router-dom';
import { errorMessage } from '../api/client';
import { useBlock, useCan, useDeleteBlock } from '../api/hooks';
import { DataTable } from '../components/DataTable';
import { PageHeader } from '../components/PageHeader';
import { Fact, SectionHeader } from '../components/panels';
import { subnetColumns } from '../components/columns';
import { confirmDelete, notifySuccess } from '../components/feedback';
import { NotFoundPage } from './NotFoundPage';
import { openBlockForm } from './BlocksPage';

export function BlockDetailPage() {
  const id = Number(useParams().id);
  const block = useBlock(id);
  const del = useDeleteBlock();
  const navigate = useNavigate();
  const canEdit = useCan('advanced');

  if (block.isPending) return <Center py="xl"><Loader /></Center>;
  if (block.error) {
    if ((block.error as { status?: number }).status === 404) return <NotFoundPage what="Block" />;
    return <Alert color="red">{errorMessage(block.error)}</Alert>;
  }
  const b = block.data;
  const onDelete = () =>
    confirmDelete({
      title: `Delete block ${b.name}?`,
      message: 'Only the block is deleted; subnets inside it are not affected.',
      onConfirm: async () => { await del.mutateAsync(b.id); notifySuccess(`Block ${b.name} deleted`); navigate('/blocks'); },
    });

  return (
    <Stack>
      <PageHeader
        title={b.name}
        subtitle={`${b.start} – ${b.end}`}
        actions={
          canEdit && (
            <>
              <Button variant="default" leftSection={<IconPencil size={16} />} onClick={() => openBlockForm(b)}>Edit</Button>
              <Button variant="light" color="red" leftSection={<IconTrash size={16} />} onClick={onDelete}>Delete</Button>
            </>
          )
        }
      />
      <Card withBorder>
        <SimpleGrid cols={{ base: 2, sm: 4 }}>
          <Fact label="First address">{b.start}</Fact>
          <Fact label="Last address">{b.end}</Fact>
          <Fact label="Addresses">{b.size}</Fact>
          <Fact label="IP version">IPv{b.ipVersion}</Fact>
        </SimpleGrid>
        {b.notes && <Stack mt="md" gap={0}><Fact label="Notes">{b.notes}</Fact></Stack>}
      </Card>
      <SectionHeader title="Subnets in this block" />
      <DataTable columns={subnetColumns} data={b.subnets} onRowClick={(s) => navigate(`/subnets/${s.id}`)} empty="No subnets start inside this block." />
    </Stack>
  );
}
