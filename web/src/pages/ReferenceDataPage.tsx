// The small lookup tables ONA's classic admin windows edit: manufacturers,
// models, roles, device types, subnet types, DHCP option definitions, config
// types and DNS views. Anyone can read them; writes need `advanced`.
import { Badge, Button, Group, Tabs, Text, Tooltip } from '@mantine/core';
import { IconPlus } from '@tabler/icons-react';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { useMemo } from 'react';
import { useCan, useReference, useReferenceWrite, type ReferenceResource } from '../api/hooks';
import { DataTable } from '../components/DataTable';
import { EntityForm, num, openForm, options, pick, type Field, type Values } from '../components/EntityForm';
import { PageHeader } from '../components/PageHeader';
import { rowActions } from '../components/columns';
import { confirmDelete, notifySuccess } from '../components/feedback';
import { useListParams } from '../lib/useListParams';

type Row = { id: number; inUse: number; system?: boolean } & Record<string, unknown>;

interface Spec {
  label: string;
  singular: string;
  description: string;
  /** Form fields; number inputs and selects whose key ends in "Id" are sent as numbers. */
  fields: (lookups: Lookups) => Field[];
  /** Table columns (key → header), shown before "In use". */
  columns: { key: string; header: string; render?: (r: Row, l: Lookups) => string }[];
  inUseLabel: string;
}

interface Lookups {
  manufacturers: Row[];
  models: Row[];
  roles: Row[];
}

const DHCP_TYPES = [
  { value: 'L', label: 'IP address list (L)' },
  { value: 'S', label: 'String (S)' },
  { value: 'N', label: 'Number (N)' },
  { value: 'I', label: 'IP address (I)' },
  { value: 'B', label: 'Boolean (B)' },
];

const nameOf = (rows: Row[], id: unknown) => String(rows.find((r) => r.id === id)?.name ?? `#${id}`);
const modelLabel = (l: Lookups, m: Row) => `${nameOf(l.manufacturers, m.manufacturerId)} ${m.name}`;

const SPECS: Record<ReferenceResource, Spec> = {
  manufacturers: {
    label: 'Manufacturers', singular: 'manufacturer', description: 'Device makers; models belong to one.', inUseLabel: 'models',
    fields: () => [{ key: 'name', label: 'Name', required: true }],
    columns: [{ key: 'name', header: 'Name' }],
  },
  models: {
    label: 'Models', singular: 'model', description: 'Device models, combined with a role to make a device type.', inUseLabel: 'device types',
    fields: (l) => [
      { key: 'manufacturerId', label: 'Manufacturer', kind: 'select', required: true, data: options(l.manufacturers, (m) => m.id, (m) => String(m.name)) },
      { key: 'name', label: 'Model', required: true },
      { key: 'snmpSysObjectId', label: 'SNMP sysObjectID', placeholder: '1.3.6.1.4.1.9.1.1' },
    ],
    columns: [
      { key: 'manufacturerId', header: 'Manufacturer', render: (r, l) => nameOf(l.manufacturers, r.manufacturerId) },
      { key: 'name', header: 'Model' },
      { key: 'snmpSysObjectId', header: 'SNMP sysObjectID' },
    ],
  },
  roles: {
    label: 'Roles', singular: 'role', description: 'What a device does (router, switch, server…).', inUseLabel: 'device types',
    fields: () => [{ key: 'name', label: 'Name', required: true }],
    columns: [{ key: 'name', header: 'Name' }],
  },
  'device-types': {
    label: 'Device types', singular: 'device type', description: 'A model in a role; every host has one.', inUseLabel: 'hosts',
    fields: (l) => [
      { key: 'modelId', label: 'Model', kind: 'select', required: true, data: options(l.models, (m) => m.id, (m) => modelLabel(l, m)) },
      { key: 'roleId', label: 'Role', kind: 'select', required: true, data: options(l.roles, (r) => r.id, (r) => String(r.name)) },
    ],
    columns: [
      { key: 'manufacturer', header: 'Manufacturer' },
      { key: 'model', header: 'Model' },
      { key: 'role', header: 'Role' },
    ],
  },
  'subnet-types': {
    label: 'Subnet types', singular: 'subnet type', description: 'Categories for subnets (LAN, loopback, VLAN…).', inUseLabel: 'subnets',
    fields: () => [
      { key: 'name', label: 'Name', required: true, row: 1 },
      { key: 'shortName', label: 'Short name', row: 1, description: 'Letters, digits, - and _' },
      { key: 'notes', label: 'Notes' },
    ],
    columns: [{ key: 'name', header: 'Name' }, { key: 'shortName', header: 'Short name' }, { key: 'notes', header: 'Notes' }],
  },
  'dhcp-options': {
    label: 'DHCP options', singular: 'DHCP option', description: 'Option definitions used by DHCP entries. Built-in ones are read-only.', inUseLabel: 'entries',
    fields: () => [
      { key: 'displayName', label: 'Display name', required: true, row: 1 },
      { key: 'name', label: 'Name', required: true, row: 1, description: 'As in dhcpd.conf, e.g. domain-name' },
      { key: 'number', label: 'Option number', kind: 'number', required: true, min: 0, max: 254, row: 2 },
      { key: 'type', label: 'Type', kind: 'select', required: true, data: DHCP_TYPES, row: 2 },
    ],
    columns: [
      { key: 'displayName', header: 'Display name' },
      { key: 'name', header: 'Name' },
      { key: 'number', header: 'Number' },
      { key: 'typeName', header: 'Type' },
    ],
  },
  'config-types': {
    label: 'Config types', singular: 'config type', description: 'Kinds of archived configuration text (IOS_CONFIG…).', inUseLabel: 'configs',
    fields: () => [{ key: 'name', label: 'Name', required: true }],
    columns: [{ key: 'name', header: 'Name' }],
  },
  'dns-views': {
    label: 'DNS views', singular: 'DNS view', description: 'Split-horizon views; records belong to one. The DEFAULT view is built in.', inUseLabel: 'records',
    fields: () => [
      { key: 'name', label: 'Name', required: true, description: 'Stored upper case; spaces become -' },
      { key: 'description', label: 'Description' },
    ],
    columns: [{ key: 'name', header: 'Name' }, { key: 'description', header: 'Description' }],
  },
};

const RESOURCES = Object.keys(SPECS) as ReferenceResource[];

function ReferenceForm({ resource, row, lookups }: { resource: ReferenceResource; row?: Row; lookups: Lookups }) {
  const spec = SPECS[resource];
  const w = useReferenceWrite(resource);
  const fields = spec.fields(lookups);
  const initial: Values = Object.fromEntries(
    fields.map((f) => {
      const v = row?.[f.key];
      if (f.kind === 'select') return [f.key, v === undefined || v === null ? null : String(v)];
      return [f.key, v === undefined || v === null ? '' : (v as string | number)];
    }),
  );
  return (
    <EntityForm
      fields={fields}
      initial={initial}
      submitLabel={row ? 'Save' : `Add ${spec.singular}`}
      onSubmit={async (v, changed) => {
        const body: Record<string, unknown> = {};
        for (const f of fields) {
          const val = v[f.key];
          const isId = f.kind === 'select' && f.key.endsWith('Id');
          body[f.key] = f.kind === 'number' || isId ? num(val) : val === null ? '' : String(val);
        }
        if (row) {
          const patch = pick(body, changed);
          if (Object.keys(patch).length) await w.update.mutateAsync({ id: row.id, ...patch });
        } else {
          await w.create.mutateAsync(body);
        }
        notifySuccess(`${spec.singular[0].toUpperCase()}${spec.singular.slice(1)} saved`);
      }}
    />
  );
}

function ReferenceTable({ resource }: { resource: ReferenceResource }) {
  const spec = SPECS[resource];
  const canEdit = useCan('advanced');
  const rows = useReference(resource);
  const w = useReferenceWrite(resource);
  // Selects in the model / device type forms need these lists.
  const manufacturers = useReference('manufacturers', resource === 'models' || resource === 'device-types');
  const models = useReference('models', resource === 'models' || resource === 'device-types');
  const roles = useReference('roles', resource === 'device-types');
  const lookups: Lookups = {
    manufacturers: (manufacturers.data ?? []) as unknown as Row[],
    models: (models.data ?? []) as unknown as Row[],
    roles: (roles.data ?? []) as unknown as Row[],
  };
  const label = (r: Row) => String(r.name ?? r.displayName ?? `${r.model ?? ''} ${r.role ?? ''}`.trim());

  const columns = useMemo<ColumnDef<Row, any>[]>(() => {
    const c = createColumnHelper<Row>();
    return [
      ...spec.columns.map((col, i) =>
        c.accessor((r) => (col.render ? col.render(r, lookups) : String(r[col.key] ?? '')), {
          id: col.key,
          header: col.header,
          cell: (cell) => (
            <Group gap={6} wrap="nowrap">
              <Text size="sm" fw={i === 0 ? 500 : undefined}>{cell.getValue()}</Text>
              {i === 0 && cell.row.original.system && <Badge size="xs" variant="light" color="gray">built in</Badge>}
            </Group>
          ),
        }),
      ),
      c.accessor('inUse', { header: `Used by ${spec.inUseLabel}` }),
      c.display({
        id: 'actions',
        header: '',
        cell: (cell) => {
          const r = cell.row.original;
          if (!canEdit || r.system) return null;
          return rowActions({
            label: label(r),
            onEdit: () => openForm(`Edit ${spec.singular}`, <ReferenceForm resource={resource} row={r} lookups={lookups} />),
            onDelete: r.inUse
              ? undefined
              : () => confirmDelete({
                  title: `Delete ${spec.singular} ${label(r)}?`,
                  message: 'This cannot be undone.',
                  onConfirm: async () => { await w.remove.mutateAsync(r.id); notifySuccess(`${label(r)} deleted`); },
                }),
          });
        },
      }),
    ];
  }, [resource, canEdit, manufacturers.data, models.data, roles.data]);

  return (
    <>
      <Group justify="space-between" mb="sm">
        <Text size="sm" c="dimmed">{spec.description} Rows still in use can't be deleted.</Text>
        {canEdit && (
          <Button size="xs" leftSection={<IconPlus size={14} />} onClick={() => openForm(`Add ${spec.singular}`, <ReferenceForm resource={resource} lookups={lookups} />)}>
            Add {spec.singular}
          </Button>
        )}
      </Group>
      <DataTable columns={columns} data={rows.data as unknown as Row[] | undefined} loading={rows.isFetching} error={rows.error} empty={`No ${spec.label.toLowerCase()} yet.`} />
    </>
  );
}

export function ReferenceDataPage() {
  const canEdit = useCan('advanced');
  const { params, update } = useListParams({ sort: '' }, ['tab']);
  const tab = RESOURCES.includes(params.tab as ReferenceResource) ? (params.tab as ReferenceResource) : RESOURCES[0];
  return (
    <>
      <PageHeader
        title="Reference data"
        subtitle={canEdit ? 'Lookup tables used across ONA' : 'Lookup tables used across ONA (changing them needs the advanced permission)'}
      />
      <Tabs value={tab} onChange={(t) => update({ tab: t ?? undefined })} keepMounted={false}>
        <Tabs.List mb="md">
          {RESOURCES.map((r) => (
            <Tooltip key={r} label={SPECS[r].description} openDelay={600}>
              <Tabs.Tab value={r}>{SPECS[r].label}</Tabs.Tab>
            </Tooltip>
          ))}
        </Tabs.List>
        {RESOURCES.map((r) => <Tabs.Panel key={r} value={r}><ReferenceTable resource={r} /></Tabs.Panel>)}
      </Tabs>
    </>
  );
}
