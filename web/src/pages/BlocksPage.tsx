import { useMemo } from 'react';
import { Anchor, Button, Group, Text, TextInput } from '@mantine/core';
import { IconPlus, IconSearch } from '@tabler/icons-react';
import { createColumnHelper } from '@tanstack/react-table';
import { Link, useNavigate } from 'react-router-dom';
import { useBlocks, useCan, useCreateBlock, useUpdateBlock } from '../api/hooks';
import type { Block, BlockDetail } from '../api/types';
import { DataTable } from '../components/DataTable';
import { EntityForm, openForm, pick } from '../components/EntityForm';
import { PageHeader } from '../components/PageHeader';
import { notifySuccess } from '../components/feedback';
import { useFilterInput } from '../lib/useFilterInput';
import { useListParams } from '../lib/useListParams';

function BlockForm({ block }: { block?: BlockDetail }) {
  const create = useCreateBlock();
  const update = useUpdateBlock();
  const navigate = useNavigate();
  return (
    <EntityForm
      fields={[
        { key: 'name', label: 'Name', required: true, description: 'Stored in upper case; spaces become -' },
        { key: 'start', label: 'First address', required: true, placeholder: '10.0.0.0', row: 1 },
        { key: 'end', label: 'Last address', required: true, placeholder: '10.0.255.255', row: 1 },
        { key: 'notes', label: 'Notes', kind: 'textarea' },
      ]}
      initial={{ name: block?.name ?? '', start: block?.start ?? '', end: block?.end ?? '', notes: block?.notes ?? '' }}
      submitLabel={block ? 'Save' : 'Add block'}
      onSubmit={async (v, changed) => {
        const body = { name: String(v.name), start: String(v.start), end: String(v.end), notes: String(v.notes) };
        if (block) {
          if (Object.keys(changed).length) await update.mutateAsync({ id: block.id, ...pick(body, changed) });
          notifySuccess('Block saved');
        } else {
          const created = await create.mutateAsync(body);
          notifySuccess(`Block ${created.name} added`);
          navigate(`/blocks/${created.id}`);
        }
      }}
    />
  );
}

export const openBlockForm = (block?: BlockDetail) => openForm(block ? `Edit block ${block.name}` : 'Add block', <BlockForm block={block} />);

const col = createColumnHelper<Block>();

export function BlocksPage() {
  const { params, update } = useListParams({ sort: 'start' });
  const blocks = useBlocks(params);
  const canAdd = useCan('advanced');
  const navigate = useNavigate();
  const [q, setQ] = useFilterInput(params.q, (v) => update({ q: v }));
  const columns = useMemo(
    () => [
      col.accessor('name', {
        id: 'name',
        header: 'Name',
        enableSorting: true,
        cell: (c) => <Anchor component={Link} to={`/blocks/${c.row.original.id}`} fw={500} onClick={(e) => e.stopPropagation()}>{c.getValue()}</Anchor>,
      }),
      col.accessor('start', { id: 'start', header: 'First address', enableSorting: true, cell: (c) => <Text ff="monospace" size="sm">{c.getValue()}</Text> }),
      col.accessor('end', { id: 'end', header: 'Last address', enableSorting: true, cell: (c) => <Text ff="monospace" size="sm">{c.getValue()}</Text> }),
      col.accessor('size', { header: 'Addresses' }),
      col.accessor('notes', { header: 'Notes', cell: (c) => <Text size="sm" lineClamp={1}>{c.getValue()}</Text> }),
    ],
    [],
  );
  return (
    <>
      <PageHeader
        title="Blocks"
        subtitle="Named address ranges for planning; subnets inside a block are listed on it"
        actions={canAdd && <Button leftSection={<IconPlus size={16} />} onClick={() => openBlockForm()}>Add block</Button>}
      />
      <Group mb="md">
        <TextInput placeholder="Filter by name, notes or an address inside" leftSection={<IconSearch size={16} />} value={q} onChange={(e) => setQ(e.currentTarget.value)} w={340} aria-label="Filter blocks" />
      </Group>
      <DataTable
        columns={columns}
        data={blocks.data?.data}
        meta={blocks.data?.meta}
        loading={blocks.isFetching}
        error={blocks.error}
        sort={params.sort}
        onSortChange={(sort) => update({ sort })}
        onPageChange={(page) => update({ page }, false)}
        onPerPageChange={(perPage) => update({ perPage })}
        onRowClick={(b) => navigate(`/blocks/${b.id}`)}
        empty={params.q ? 'No blocks match.' : 'No blocks yet.'}
      />
    </>
  );
}
