import { useMemo } from 'react';
import { Button, Group, Select, TextInput } from '@mantine/core';
import { IconPlus, IconSearch } from '@tabler/icons-react';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { useNavigate } from 'react-router-dom';
import { useCan, useDeleteDnsRecord, useDnsRecords, useDomains } from '../api/hooks';
import { DNS_TYPES, type DnsRecord } from '../api/types';
import { DataTable } from '../components/DataTable';
import { PageHeader } from '../components/PageHeader';
import { dnsColumns, rowActions } from '../components/columns';
import { openDnsRecordForm } from '../components/DnsRecordForm';
import { confirmDelete, notifySuccess } from '../components/feedback';
import { useListParams } from '../lib/useListParams';
import { useFilterInput } from '../lib/useFilterInput';

export function DnsRecordsPage() {
  const { params, update } = useListParams({ sort: 'name' }, ['type', 'domainId', 'hostId']);
  const records = useDnsRecords(params);
  const domains = useDomains();
  const del = useDeleteDnsRecord();
  const navigate = useNavigate();
  const canAdd = useCan('host_add');
  const canEdit = useCan('host_modify');
  const canDelete = useCan('host_del');
  const [q, setQ] = useFilterInput(params.q, (v) => update({ q: v }));

  const columns = useMemo<ColumnDef<DnsRecord, any>[]>(() => {
    const c = createColumnHelper<DnsRecord>();
    return [
      ...dnsColumns,
      c.display({
        id: 'actions',
        header: '',
        cell: (cell) => {
          const r = cell.row.original;
          return rowActions({
            label: `${r.type} ${r.fqdn}`,
            onEdit: canEdit ? () => openDnsRecordForm(r) : undefined,
            onDelete: canDelete
              ? () => confirmDelete({
                  title: `Delete ${r.type} record ${r.fqdn}?`,
                  message: r.type === 'A' && r.hostId
                    ? 'If this is a host\'s primary name, ONA will refuse; delete the host instead.'
                    : 'This cannot be undone.',
                  onConfirm: async () => { await del.mutateAsync(r.id); notifySuccess('Record deleted'); },
                })
              : undefined,
          });
        },
      }),
    ];
  }, [canEdit, canDelete]);

  return (
    <>
      <PageHeader
        title="DNS records"
        subtitle="A, AAAA, CNAME, MX, NS, PTR, SRV and TXT records"
        actions={canAdd && <Button leftSection={<IconPlus size={16} />} onClick={() => openDnsRecordForm()}>Add record</Button>}
      />
      <Group mb="md">
        <TextInput placeholder="Filter by name, text, notes or IP" leftSection={<IconSearch size={16} />} value={q} onChange={(e) => setQ(e.currentTarget.value)} w={300} aria-label="Filter records" />
        <Select placeholder="All types" clearable data={[...DNS_TYPES]} value={(params.type as string) ?? null} onChange={(v) => update({ type: v ?? undefined })} w={130} aria-label="Filter by type" />
        <Select
          placeholder="All domains"
          clearable
          searchable
          data={(domains.data ?? []).map((d) => ({ value: String(d.id), label: d.name }))}
          value={params.domainId ? String(params.domainId) : null}
          onChange={(v) => update({ domainId: v ?? undefined })}
          w={220}
          aria-label="Filter by domain"
        />
        {params.hostId && <Button variant="subtle" size="xs" onClick={() => update({ hostId: undefined })}>Clear host filter</Button>}
      </Group>
      <DataTable
        columns={columns}
        data={records.data?.data}
        meta={records.data?.meta}
        loading={records.isFetching}
        error={records.error}
        sort={params.sort}
        onSortChange={(sort) => update({ sort })}
        onPageChange={(page) => update({ page }, false)}
        onPerPageChange={(perPage) => update({ perPage })}
        onRowClick={(r) => r.hostId && navigate(`/hosts/${r.hostId}`)}
        empty="No records match."
      />
    </>
  );
}
