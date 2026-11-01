import { useMemo } from 'react';
import { Anchor, Badge, Button, Group, Select, TextInput } from '@mantine/core';
import { IconPlus, IconSearch } from '@tabler/icons-react';
import { createColumnHelper } from '@tanstack/react-table';
import { Link, useNavigate } from 'react-router-dom';
import { useCan, useSubnets, useSubnetTypes } from '../api/hooks';
import type { Subnet } from '../api/types';
import { DataTable } from '../components/DataTable';
import { PageHeader } from '../components/PageHeader';
import { UsageBar } from '../components/UsageBar';
import { openSubnetForm } from '../components/SubnetForm';
import { useListParams } from '../lib/useListParams';
import { useFilterInput } from '../lib/useFilterInput';
import { SUBNET_FILTERS, useSubnetFilters } from '../components/ListFilters';
import { TagBadges } from '../components/columns';

const col = createColumnHelper<Subnet>();

export function SubnetsPage() {
  const { params, update } = useListParams({ sort: 'ip' }, SUBNET_FILTERS);
  const subnets = useSubnets(params);
  const types = useSubnetTypes();
  const canAdd = useCan('subnet_add');
  const navigate = useNavigate();
  const [q, setQ] = useFilterInput(params.q, (v) => update({ q: v }));
  const filters = useSubnetFilters(params, update);
  const filtered = !!params.q || SUBNET_FILTERS.some((f) => params[f]);

  const columns = useMemo(
    () => [
      col.accessor('name', {
        id: 'name',
        header: 'Name',
        enableSorting: true,
        cell: (c) => <Anchor component={Link} to={`/subnets/${c.row.original.id}`} fw={500} onClick={(e) => e.stopPropagation()}>{c.getValue()}</Anchor>,
      }),
      col.accessor('network', { id: 'ip', header: 'Network', enableSorting: true }),
      col.accessor((s) => s.type?.name ?? '', { id: 'type', header: 'Type', enableSorting: true, cell: (c) => c.getValue() && <Badge variant="light">{c.getValue()}</Badge> }),
      col.accessor('usage', { header: 'IP usage', cell: (c) => <UsageBar usage={c.getValue()} /> }),
      col.accessor((s) => `${s.usage.used} / ${s.usage.total}`, { id: 'used', header: 'Used / total' }),
      col.accessor('tags', { header: 'Tags', cell: (c) => <TagBadges tags={c.getValue()} /> }),
    ],
    [],
  );

  return (
    <>
      <PageHeader
        title="Subnets"
        subtitle="IP networks and how full they are"
        actions={canAdd && <Button leftSection={<IconPlus size={16} />} onClick={() => openSubnetForm()}>Add subnet</Button>}
      />
      <Group mb="md">
        <TextInput
          placeholder="Filter by name or an IP inside the subnet"
          leftSection={<IconSearch size={16} />}
          value={q}
          onChange={(e) => setQ(e.currentTarget.value)}
          w={340}
          aria-label="Filter subnets"
          data-shortcut="list-filter"
        />
        <Select
          placeholder="All types"
          clearable
          data={(types.data ?? []).map((t) => ({ value: String(t.id), label: t.name }))}
          value={params.typeId ? String(params.typeId) : null}
          onChange={(v) => update({ typeId: v ?? undefined })}
          w={200}
          aria-label="Filter by type"
        />
        {filters.button}
      </Group>
      {filters.panel}
      <DataTable
        columns={columns}
        data={subnets.data?.data}
        meta={subnets.data?.meta}
        loading={subnets.isFetching}
        error={subnets.error}
        sort={params.sort}
        onSortChange={(sort) => update({ sort })}
        onPageChange={(page) => update({ page }, false)}
        onPerPageChange={(perPage) => update({ perPage })}
        onRowClick={(s) => navigate(`/subnets/${s.id}`)}
        empty={filtered ? 'No subnets match.' : 'No subnets yet. Add one to get started.'}
      />
    </>
  );
}
