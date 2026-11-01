import { useMemo } from 'react';
import { ActionIcon, Anchor, Badge, Button, Group, Text, Tooltip } from '@mantine/core';
import { IconPlus, IconServer2, IconX } from '@tabler/icons-react';
import { createColumnHelper } from '@tanstack/react-table';
import { Link } from 'react-router-dom';
import { useAddDomainServer, useCan, useCreateDomain, useDeleteDomain, useDomains, useRemoveDomainServer, useUpdateDomain } from '../api/hooks';
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

const ROLE_COLOR = { master: 'blue', slave: 'teal', forward: 'gray' } as const;
const ROLES = [
  { value: 'master', label: 'Master' },
  { value: 'slave', label: 'Slave' },
  { value: 'forward', label: 'Forward' },
];

function AddServerForm({ domain }: { domain: Domain }) {
  const add = useAddDomainServer();
  return (
    <EntityForm
      fields={[
        { key: 'hostId', label: 'DNS server (host)', kind: 'host', required: true },
        { key: 'role', label: 'Role', kind: 'select', required: true, data: ROLES },
      ]}
      initial={{ hostId: null, role: 'master' }}
      submitLabel="Assign server"
      footer={<Text size="xs" c="dimmed">ONA also adds an NS record for the server in {domain.name} if there isn't one.</Text>}
      onSubmit={async (v) => {
        await add.mutateAsync({ domainId: domain.id, hostId: Number(v.hostId), role: String(v.role) });
        notifySuccess(`Server assigned to ${domain.name}`);
      }}
    />
  );
}

/** The DNS servers of one domain, with remove buttons (writes need `advanced`). */
function DomainServers({ domain, canEdit }: { domain: Domain; canEdit: boolean }) {
  const remove = useRemoveDomainServer();
  return (
    <Group gap={4} wrap="wrap">
      {domain.servers.map((a) => (
        <Badge
          key={a.id}
          variant="light"
          color={ROLE_COLOR[a.role]}
          tt="none"
          rightSection={canEdit && (
            <ActionIcon
              size="xs"
              variant="transparent"
              color={ROLE_COLOR[a.role]}
              aria-label={`Remove ${a.server.fqdn} from ${domain.name}`}
              onClick={(e) => {
                e.stopPropagation();
                confirmDelete({
                  title: `Remove ${a.server.fqdn} from ${domain.name}?`,
                  message: 'Its NS record in the domain is deleted too.',
                  onConfirm: async () => { await remove.mutateAsync(a.id); notifySuccess('Server removed'); },
                });
              }}
            >
              <IconX size={10} />
            </ActionIcon>
          )}
        >
          <Anchor component={Link} to={`/hosts/${a.server.id}`} size="xs" c="inherit" onClick={(e) => e.stopPropagation()}>{a.server.fqdn}</Anchor> ({a.role})
        </Badge>
      ))}
      {canEdit && (
        <Tooltip label="Assign a DNS server">
          <ActionIcon size="sm" variant="subtle" aria-label={`Assign a DNS server to ${domain.name}`} onClick={() => openForm(`DNS server for ${domain.name}`, <AddServerForm domain={domain} />)}>
            <IconServer2 size={14} />
          </ActionIcon>
        </Tooltip>
      )}
    </Group>
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
      col.display({ id: 'servers', header: 'DNS servers', cell: (c) => <DomainServers domain={c.row.original} canEdit={canEdit} /> }),
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
        subtitle="DNS domains (forward and reverse) and the servers that serve them; click one to see its records"
        actions={canEdit && <Button leftSection={<IconPlus size={16} />} onClick={() => openForm('Add domain', <DomainForm />, 'lg')}>Add domain</Button>}
      />
      <DataTable columns={columns} data={domains.data} loading={domains.isFetching} error={domains.error} empty="No domains yet." />
    </>
  );
}
