// ONA's change log (the ona_logs table). ONA only fills it when the
// log_to_db system setting is 1; the page says so and links to the setting.
import { Alert, Anchor, Group, Text, TextInput } from '@mantine/core';
import { IconSearch } from '@tabler/icons-react';
import { createColumnHelper } from '@tanstack/react-table';
import { Link } from 'react-router-dom';
import { useCan, useChangeLog } from '../api/hooks';
import type { LogEntry } from '../api/types';
import { DataTable } from '../components/DataTable';
import { PageHeader } from '../components/PageHeader';
import { useFilterInput } from '../lib/useFilterInput';
import { useListParams } from '../lib/useListParams';

const col = createColumnHelper<LogEntry>();
const columns = [
  col.accessor('timestamp', { id: 'timestamp', header: 'When', enableSorting: true, cell: (c) => <Text size="sm" ff="monospace" style={{ whiteSpace: 'nowrap' }}>{c.getValue()}</Text> }),
  col.accessor('username', { id: 'username', header: 'User', enableSorting: true }),
  col.accessor('remoteAddr', { header: 'From' }),
  col.accessor('message', { header: 'Change', cell: (c) => <Text size="sm" style={{ overflowWrap: 'anywhere' }}>{c.getValue().replace(/^INFO => /, '')}</Text> }),
];

export function ChangeLogPage() {
  const canView = useCan('advanced');
  const { params, update } = useListParams({ sort: '-timestamp', perPage: 50 }, ['username']);
  const log = useChangeLog(params, canView);
  const [q, setQ] = useFilterInput(params.q, (v) => update({ q: v }));
  const [user, setUser] = useFilterInput(params.username as string | undefined, (v) => update({ username: v }));

  if (!canView) {
    return (
      <>
        <PageHeader title="Change log" />
        <Alert color="yellow">You need the advanced permission to see the change log.</Alert>
      </>
    );
  }
  return (
    <>
      <PageHeader title="Change log" subtitle="Changes recorded by ONA, newest first" />
      {log.data && !log.data.meta.logToDb && (
        <Alert color="yellow" mb="md" title="ONA is not writing the change log">
          Changes are only recorded while the <Text span ff="monospace">log_to_db</Text> system setting is 1. Turn it on under{' '}
          <Anchor component={Link} to="/admin?tab=config">Admin → System configuration</Anchor>.
        </Alert>
      )}
      <Group mb="md">
        <TextInput placeholder="Filter by text" leftSection={<IconSearch size={16} />} value={q} onChange={(e) => setQ(e.currentTarget.value)} w={300} aria-label="Filter log" data-shortcut="list-filter" />
        <TextInput placeholder="Username" value={user} onChange={(e) => setUser(e.currentTarget.value)} w={180} aria-label="Filter by user" />
      </Group>
      <DataTable
        columns={columns}
        data={log.data?.data}
        meta={log.data?.meta}
        loading={log.isFetching}
        error={log.error}
        sort={params.sort}
        onSortChange={(sort) => update({ sort })}
        onPageChange={(page) => update({ page }, false)}
        onPerPageChange={(perPage) => update({ perPage })}
        empty="No log entries."
      />
    </>
  );
}
