import { useMemo } from 'react';
import { Anchor, Button, Text } from '@mantine/core';
import { IconPlus } from '@tabler/icons-react';
import { createColumnHelper } from '@tanstack/react-table';
import { Link } from 'react-router-dom';
import { useCan, useCreateDomain, useDeleteDomain, useDomains, useUpdateDomain } from '../api/hooks';
import type { Domain } from '../api/types';
import { DataTable } from '../components/DataTable';
import { EntityForm, idOr, num, openForm, options, pick, type Field } from '../components/EntityForm';
import { PageHeader } from '../components/PageHeader';
import { rowActions } from '../components/columns';
import { confirmDelete, notifySuccess } from '../components/feedback';

const SOA: Field[] = [
  { key: 'primaryMaster', label: 'Primary master', row: 2, placeholder: 'ns1.example.com' },
  { key: 'adminEmail', label: 'Admin email', row: 2, placeholder: 'hostmaster' },
  { key: 'defaultTtl', label: 'Default TTL', kind: 'number', min: 1, row: 3 },
  { key: 'refresh', label: 'Refresh', kind: 'number', min: 1, row: 3 },
  { key: 'retry', label: 'Retry', kind: 'number', min: 1, row: 4 },
  { key: 'expiry', label: 'Expiry', kind: 'number', min: 1, row: 4 },
  { key: 'minimum', label: 'Minimum', kind: 'number', min: 1, row: 4 },
];

function DomainForm({ domain }: { domain?: Domain }) {
  const domains = useDomains();
  const create = useCreateDomain();
  const update = useUpdateDomain();
  const parents = (domains.data ?? []).filter((d) => d.id !== domain?.id);
  return (
    <EntityForm
      fields={[
        { key: 'name', label: 'Name', required: true, row: 1, description: 'With a parent, just the new label (e.g. "lab")', placeholder: 'lab' },
        { key: 'parentId', label: 'Parent domain', kind: 'select', row: 1, placeholder: 'None (top level)', data: options(parents, (d) => d.id, (d) => d.name) },
        ...SOA,
      ]}
      initial={{
        name: domain?.shortName ?? '',
        parentId: domain?.parent ? String(domain.parent.id) : null,
        ...Object.fromEntries(SOA.map((f) => [f.key, domain ? (domain[f.key as keyof Domain] as string | number) : ''])),
      }}
      submitLabel={domain ? 'Save' : 'Add domain'}
      footer={!domain && <Text size="xs" c="dimmed">Empty SOA values use the ONA defaults (system configuration dns_*).</Text>}
      onSubmit={async (v, changed) => {
        const body = {
          name: String(v.name),
          parentId: idOr(v.parentId, domain ? 0 : undefined),
          primaryMaster: String(v.primaryMaster) || undefined,
          adminEmail: String(v.adminEmail) || undefined,
          defaultTtl: num(v.defaultTtl), refresh: num(v.refresh), retry: num(v.retry), expiry: num(v.expiry), minimum: num(v.minimum),
        };
        if (domain) {
          if (Object.keys(changed).length) await update.mutateAsync({ id: domain.id, ...pick(body, changed) });
        } else {
          await create.mutateAsync(body);
        }
        notifySuccess('Domain saved');
      }}
    />
  );
}

const col = createColumnHelper<Domain>();

export function DomainsPage() {
  const domains = useDomains();
  const del = useDeleteDomain();
  const canEdit = useCan('advanced');
  const columns = useMemo(
    () => [
      col.accessor('name', { header: 'Domain', cell: (c) => <Anchor component={Link} to={`/dns?domainId=${c.row.original.id}`} fw={500}>{c.getValue()}</Anchor> }),
      col.accessor((d) => d.parent?.name ?? '', { id: 'parent', header: 'Parent' }),
      col.accessor('recordCount', { header: 'Records' }),
      col.accessor('defaultTtl', { header: 'Default TTL' }),
      col.accessor('primaryMaster', { header: 'Primary master' }),
      col.display({
        id: 'actions',
        header: '',
        cell: (c) => {
          const d = c.row.original;
          return rowActions({
            label: d.name,
            onEdit: canEdit ? () => openForm(`Edit ${d.name}`, <DomainForm domain={d} />, 'lg') : undefined,
            onDelete: canEdit
              ? () => confirmDelete({
                  title: `Delete domain ${d.name}?`,
                  message: d.recordCount ? `${d.recordCount} DNS record(s) use it; ONA only deletes unused domains without children.` : 'This cannot be undone.',
                  onConfirm: async () => { await del.mutateAsync(d.id); notifySuccess(`Domain ${d.name} deleted`); },
                })
              : undefined,
          });
        },
      }),
    ],
    [canEdit],
  );
  return (
    <>
      <PageHeader
        title="Domains"
        subtitle="DNS domains (forward and reverse); click one to see its records"
        actions={canEdit && <Button leftSection={<IconPlus size={16} />} onClick={() => openForm('Add domain', <DomainForm />, 'lg')}>Add domain</Button>}
      />
      <DataTable columns={columns} data={domains.data} loading={domains.isFetching} error={domains.error} empty="No domains yet." />
    </>
  );
}
