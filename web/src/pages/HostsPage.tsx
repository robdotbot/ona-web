import { Button, Group, TextInput } from '@mantine/core';
import { IconPlus, IconSearch } from '@tabler/icons-react';
import { useNavigate } from 'react-router-dom';
import { useCan, useHosts } from '../api/hooks';
import { DataTable } from '../components/DataTable';
import { PageHeader } from '../components/PageHeader';
import { hostColumns } from '../components/columns';
import { openHostForm } from '../components/HostForm';
import { useListParams } from '../lib/useListParams';
import { useFilterInput } from '../lib/useFilterInput';
import { HOST_FILTERS, useHostFilters } from '../components/ListFilters';

export function HostsPage() {
  const { params, update } = useListParams({ sort: 'name' }, HOST_FILTERS);
  const hosts = useHosts(params);
  const canAdd = useCan('host_add');
  const navigate = useNavigate();
  const [q, setQ] = useFilterInput(params.q, (v) => update({ q: v }));
  const filters = useHostFilters(params, update);
  const filtered = !!params.q || HOST_FILTERS.some((f) => params[f]);

  return (
    <>
      <PageHeader
        title="Hosts"
        subtitle="Devices and their addresses"
        actions={canAdd && <Button leftSection={<IconPlus size={16} />} onClick={() => openHostForm()}>Add host</Button>}
      />
      <Group mb="md">
        <TextInput
          placeholder="Filter by name, notes, IP or MAC"
          leftSection={<IconSearch size={16} />}
          value={q}
          onChange={(e) => setQ(e.currentTarget.value)}
          w={340}
          aria-label="Filter hosts"
          data-shortcut="list-filter"
        />
        {filters.button}
      </Group>
      {filters.panel}
      <DataTable
        columns={hostColumns}
        data={hosts.data?.data}
        meta={hosts.data?.meta}
        loading={hosts.isFetching}
        error={hosts.error}
        sort={params.sort}
        onSortChange={(sort) => update({ sort })}
        onPageChange={(page) => update({ page }, false)}
        onPerPageChange={(perPage) => update({ perPage })}
        onRowClick={(h) => navigate(`/hosts/${h.id}`)}
        empty={filtered ? 'No hosts match.' : 'No hosts yet.'}
      />
    </>
  );
}
