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

export function HostsPage() {
  const { params, update } = useListParams({ sort: 'name' });
  const hosts = useHosts(params);
  const canAdd = useCan('host_add');
  const navigate = useNavigate();
  const [q, setQ] = useFilterInput(params.q, (v) => update({ q: v }));

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
        />
      </Group>
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
        empty={params.q ? 'No hosts match.' : 'No hosts yet.'}
      />
    </>
  );
}
