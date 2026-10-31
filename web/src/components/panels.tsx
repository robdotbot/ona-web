// Building blocks shared by the detail pages: facts, section headers, and the
// custom attribute / DHCP option / DHCP pool panels shown on several pages.
import { Badge, Button, Group, Text, Title } from '@mantine/core';
import { IconPlus } from '@tabler/icons-react';
import { createColumnHelper } from '@tanstack/react-table';
import type { ReactNode } from 'react';
import {
  useCan,
  useCreateCustomAttribute,
  useCreateDhcpEntry,
  useCreateDhcpPool,
  useCustomAttributeTypes,
  useCustomAttributes,
  useDeleteCustomAttribute,
  useDeleteDhcpEntry,
  useDeleteDhcpPool,
  useDhcpEntries,
  useDhcpFailoverGroups,
  useDhcpOptions,
  useDhcpPools,
  useUpdateCustomAttribute,
  useUpdateDhcpEntry,
  useUpdateDhcpPool,
} from '../api/hooks';
import type { AttributeTarget, CustomAttribute, DhcpEntry, DhcpFailoverGroup, DhcpPool, DhcpScope } from '../api/types';
import { DataTable } from './DataTable';
import { EntityForm, idOr, num, openForm, options, pick, type Field } from './EntityForm';
import { rowActions } from './columns';
import { confirmDelete, notifySuccess } from './feedback';

export function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <Text size="xs" c="dimmed" tt="uppercase" fw={600}>{label}</Text>
      <Text style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>{children === '' || children === null || children === undefined ? '—' : children}</Text>
    </div>
  );
}

export function SectionHeader({ title, action }: { title: string; action?: ReactNode }) {
  return (
    <Group justify="space-between" mt="md">
      <Title order={4}>{title}</Title>
      {action}
    </Group>
  );
}

export function AddButton({ onClick, children }: { onClick: () => void; children: ReactNode }) {
  return <Button size="xs" variant="light" leftSection={<IconPlus size={14} />} onClick={onClick}>{children}</Button>;
}

// ---- Custom attributes ------------------------------------------------------

function CustomAttributeForm({ target, attr }: { target: AttributeTarget; attr?: CustomAttribute }) {
  const types = useCustomAttributeTypes();
  const create = useCreateCustomAttribute();
  const update = useUpdateCustomAttribute();
  const type = (id: unknown) => types.data?.find((t) => String(t.id) === id);
  const fields: Field[] = [
    { key: 'typeId', label: 'Type', kind: 'select', required: true, data: options(types.data, (t) => t.id, (t) => t.name) },
    { key: 'value', label: 'Value', required: true, kind: 'textarea' },
  ];
  return (
    <EntityForm
      fields={fields}
      initial={{ typeId: attr ? String(attr.type.id) : null, value: attr?.value ?? '' }}
      submitLabel={attr ? 'Save' : 'Add attribute'}
      footer={<Text size="xs" c="dimmed">Values must match the type's validation rule, if it has one (see Custom attributes).</Text>}
      onSubmit={async (v, changed) => {
        const body = { typeId: idOr(v.typeId), value: String(v.value) };
        if (attr) {
          if (Object.keys(changed).length) await update.mutateAsync({ id: attr.id, ...pick(body, changed) });
        } else {
          await create.mutateAsync({ ...body, ...target });
        }
        notifySuccess(`Attribute ${type(v.typeId)?.name ?? ''} saved`);
      }}
    />
  );
}

const ca = createColumnHelper<CustomAttribute>();

export function CustomAttributesPanel({ target, enabled = true }: { target: AttributeTarget; enabled?: boolean }) {
  const attrs = useCustomAttributes(target, enabled);
  const del = useDeleteCustomAttribute();
  const can = { add: useCan('custom_attribute_add'), modify: useCan('custom_attribute_modify'), del: useCan('custom_attribute_del') };
  const columns = [
    ca.accessor((a) => a.type.name, { id: 'type', header: 'Attribute', cell: (c) => <Text fw={500} size="sm">{c.getValue()}</Text> }),
    ca.accessor('value', { header: 'Value', cell: (c) => <Text size="sm" style={{ whiteSpace: 'pre-wrap' }}>{c.getValue()}</Text> }),
    ca.display({
      id: 'actions',
      header: '',
      cell: (c) => {
        const a = c.row.original;
        return rowActions({
          label: a.type.name,
          onEdit: can.modify ? () => openForm(`Edit ${a.type.name}`, <CustomAttributeForm target={target} attr={a} />) : undefined,
          onDelete: can.del
            ? () => confirmDelete({ title: `Remove ${a.type.name}?`, message: 'This cannot be undone.', onConfirm: async () => { await del.mutateAsync(a.id); notifySuccess('Attribute removed'); } })
            : undefined,
        });
      },
    }),
  ];
  return (
    <>
      <SectionHeader title="Custom attributes" action={can.add && <AddButton onClick={() => openForm('Add custom attribute', <CustomAttributeForm target={target} />)}>Add attribute</AddButton>} />
      <DataTable columns={columns} data={attrs.data?.data} loading={attrs.isFetching} error={attrs.error} empty="No custom attributes." />
    </>
  );
}

// ---- DHCP options -----------------------------------------------------------

export function DhcpEntryForm({ scope, entry }: { scope: DhcpScope; entry?: DhcpEntry }) {
  const opts = useDhcpOptions();
  const create = useCreateDhcpEntry();
  const update = useUpdateDhcpEntry();
  return (
    <EntityForm
      fields={[
        { key: 'optionId', label: 'Option', kind: 'select', required: true, data: options(opts.data, (o) => o.id, (o) => `${o.displayName} (${o.name}, ${o.number}) – ${o.typeName}`) },
        { key: 'value', label: 'Value', required: true, description: 'IP address lists are comma separated' },
      ]}
      initial={{ optionId: entry ? String(entry.option.id) : null, value: entry?.value ?? '' }}
      submitLabel={entry ? 'Save' : 'Add option'}
      onSubmit={async (v, changed) => {
        const body = { optionId: idOr(v.optionId), value: String(v.value) };
        if (entry) {
          if (Object.keys(changed).length) await update.mutateAsync({ id: entry.id, ...pick(body, changed) });
        } else {
          await create.mutateAsync({ ...body, ...scope });
        }
        notifySuccess('DHCP option saved');
      }}
    />
  );
}

const de = createColumnHelper<DhcpEntry>();

export function DhcpOptionsPanel({ scope, title = 'DHCP options', enabled = true }: { scope: DhcpScope; title?: string; enabled?: boolean }) {
  const entries = useDhcpEntries(scope, enabled);
  const del = useDeleteDhcpEntry();
  const canEdit = useCan('advanced');
  const columns = [
    de.accessor((e) => e.option.displayName, { id: 'option', header: 'Option', cell: (c) => <Text fw={500} size="sm">{c.getValue()} <Text span c="dimmed" size="xs">{c.row.original.option.name} ({c.row.original.option.number})</Text></Text> }),
    de.accessor('value', { header: 'Value', cell: (c) => <Text ff="monospace" size="sm">{c.getValue()}</Text> }),
    de.display({
      id: 'actions',
      header: '',
      cell: (c) => {
        const e = c.row.original;
        return rowActions({
          label: e.option.displayName,
          onEdit: canEdit ? () => openForm(`Edit ${e.option.displayName}`, <DhcpEntryForm scope={scope} entry={e} />) : undefined,
          onDelete: canEdit
            ? () => confirmDelete({ title: `Remove ${e.option.displayName}?`, message: 'This cannot be undone.', onConfirm: async () => { await del.mutateAsync(e.id); notifySuccess('DHCP option removed'); } })
            : undefined,
        });
      },
    }),
  ];
  return (
    <>
      <SectionHeader title={title} action={canEdit && <AddButton onClick={() => openForm('Add DHCP option', <DhcpEntryForm scope={scope} />)}>Add option</AddButton>} />
      <DataTable columns={columns} data={entries.data} loading={entries.isFetching} error={entries.error} empty="No DHCP options set here." />
    </>
  );
}

// ---- DHCP pools -------------------------------------------------------------

export const groupLabel = (g: DhcpFailoverGroup) => `${g.primaryServer?.fqdn ?? '?'} / ${g.secondaryServer?.fqdn ?? '?'}`;

export function DhcpPoolForm({ pool, defaults }: { pool?: DhcpPool; defaults?: { start?: string; end?: string } }) {
  const groups = useDhcpFailoverGroups();
  const create = useCreateDhcpPool();
  const update = useUpdateDhcpPool();
  const fields: Field[] = [
    { key: 'start', label: 'First address', required: true, row: 1, placeholder: '10.1.0.100' },
    { key: 'end', label: 'Last address', required: true, row: 1, placeholder: '10.1.0.200' },
    { key: 'failoverGroupId', label: 'Failover group', kind: 'select', placeholder: 'None', data: options(groups.data, (g) => g.id, groupLabel) },
    { key: 'leaseLength', label: 'Lease length (s)', kind: 'number', min: 1, row: 2 },
    { key: 'leaseGrace', label: 'Grace period (s)', kind: 'number', min: 1, row: 2 },
    { key: 'leaseRenewal', label: 'Renewal (s)', kind: 'number', min: 1, row: 3 },
    { key: 'leaseRebind', label: 'Rebind (s)', kind: 'number', min: 1, row: 3 },
  ];
  return (
    <EntityForm
      fields={fields}
      initial={{
        start: pool?.start ?? defaults?.start ?? '',
        end: pool?.end ?? defaults?.end ?? '',
        failoverGroupId: pool?.failoverGroupId ? String(pool.failoverGroupId) : null,
        leaseLength: pool?.leaseLength ?? '',
        leaseGrace: pool?.leaseGrace ?? '',
        leaseRenewal: pool?.leaseRenewal ?? '',
        leaseRebind: pool?.leaseRebind ?? '',
      }}
      submitLabel={pool ? 'Save' : 'Add pool'}
      footer={!pool && <Text size="xs" c="dimmed">The pool goes on the subnet containing the first address. Empty lease times use the ONA defaults.</Text>}
      onSubmit={async (v, changed) => {
        const body = {
          start: String(v.start), end: String(v.end),
          failoverGroupId: idOr(v.failoverGroupId, pool ? 0 : undefined),
          leaseLength: num(v.leaseLength), leaseGrace: num(v.leaseGrace), leaseRenewal: num(v.leaseRenewal), leaseRebind: num(v.leaseRebind),
        };
        if (pool) {
          if (Object.keys(changed).length) await update.mutateAsync({ id: pool.id, ...pick(body, changed) });
        } else {
          await create.mutateAsync(body);
        }
        notifySuccess('DHCP pool saved');
      }}
    />
  );
}

const dp = createColumnHelper<DhcpPool>();

export function dhcpPoolColumns(opts: { showSubnet?: boolean; canEdit: boolean; onDelete: (p: DhcpPool) => void }) {
  return [
    dp.accessor((p) => `${p.start} – ${p.end}`, { id: 'start', header: 'Range', enableSorting: true, cell: (c) => <Text ff="monospace" size="sm">{c.getValue()}</Text> }),
    dp.accessor('size', { header: 'Addresses' }),
    ...(opts.showSubnet ? [dp.accessor((p) => p.subnet?.name ?? '', { id: 'subnet', header: 'Subnet', enableSorting: true })] : []),
    dp.accessor('failoverGroupId', { header: 'Failover', cell: (c) => (c.getValue() ? <Badge component="span" variant="light">group {c.getValue()}</Badge> : '—') }),
    dp.accessor('leaseLength', { header: 'Lease (s)' }),
    dp.display({
      id: 'actions',
      header: '',
      cell: (c) => {
        const p = c.row.original;
        return rowActions({
          label: `${p.start}-${p.end}`,
          onEdit: opts.canEdit ? () => openForm('Edit DHCP pool', <DhcpPoolForm pool={p} />, 'lg') : undefined,
          onDelete: opts.canEdit ? () => opts.onDelete(p) : undefined,
        });
      },
    }),
  ];
}

export function DhcpPoolsPanel({ subnetId, enabled = true, defaults }: { subnetId: number; enabled?: boolean; defaults?: { start?: string; end?: string } }) {
  const pools = useDhcpPools({ subnetId, perPage: 200 }, enabled);
  const del = useDeleteDhcpPool();
  const canEdit = useCan('advanced');
  const onDelete = (p: DhcpPool) =>
    confirmDelete({ title: `Delete pool ${p.start} – ${p.end}?`, message: 'This cannot be undone.', onConfirm: async () => { await del.mutateAsync(p.id); notifySuccess('DHCP pool deleted'); } });
  return (
    <>
      <SectionHeader title="DHCP pools" action={canEdit && <AddButton onClick={() => openForm('Add DHCP pool', <DhcpPoolForm defaults={defaults} />, 'lg')}>Add pool</AddButton>} />
      <DataTable columns={dhcpPoolColumns({ canEdit, onDelete })} data={pools.data?.data} loading={pools.isFetching} error={pools.error} empty="No DHCP pools on this subnet." />
    </>
  );
}
