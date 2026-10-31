import { useMemo } from 'react';
import { Anchor, Badge, Button, Code, Group, Select, Text, TextInput, Title } from '@mantine/core';
import { IconPlus, IconSearch } from '@tabler/icons-react';
import { createColumnHelper } from '@tanstack/react-table';
import { Link } from 'react-router-dom';
import {
  useCan,
  useCreateCustomAttributeType,
  useCustomAttributeTypes,
  useCustomAttributes,
  useDeleteCustomAttributeType,
  useUpdateCustomAttributeType,
} from '../api/hooks';
import type { CustomAttribute, CustomAttributeType } from '../api/types';
import { DataTable } from '../components/DataTable';
import { EntityForm, openForm, options, pick } from '../components/EntityForm';
import { PageHeader } from '../components/PageHeader';
import { rowActions } from '../components/columns';
import { confirmDelete, notifySuccess } from '../components/feedback';
import { useFilterInput } from '../lib/useFilterInput';
import { useListParams } from '../lib/useListParams';

function TypeForm({ type }: { type?: CustomAttributeType }) {
  const create = useCreateCustomAttributeType();
  const update = useUpdateCustomAttributeType();
  return (
    <EntityForm
      fields={[
        { key: 'name', label: 'Name', required: true, placeholder: 'nmap_scan' },
        { key: 'notes', label: 'Description', kind: 'textarea' },
        { key: 'validationRule', label: 'Validation rule', placeholder: '/^[YN]$/', description: 'Optional PCRE pattern, with delimiters, that values must match' },
        { key: 'failedRuleText', label: 'Message when a value fails the rule', placeholder: 'Must be either Y or N' },
      ]}
      initial={{ name: type?.name ?? '', notes: type?.notes ?? '', validationRule: type?.validationRule ?? '', failedRuleText: type?.failedRuleText ?? '' }}
      submitLabel={type ? 'Save' : 'Add type'}
      onSubmit={async (v, changed) => {
        const body = { name: String(v.name), notes: String(v.notes), validationRule: String(v.validationRule), failedRuleText: String(v.failedRuleText) };
        if (type) {
          if (Object.keys(changed).length) await update.mutateAsync({ id: type.id, ...pick(body, changed) });
        } else {
          await create.mutateAsync(body);
        }
        notifySuccess(`Attribute type ${body.name} saved`);
      }}
    />
  );
}

const tc = createColumnHelper<CustomAttributeType>();
const vc = createColumnHelper<CustomAttribute>();
const TARGET_PATH = { host: '/hosts', subnet: '/subnets', vlan: '/vlans' } as const;

export function CustomAttributesPage() {
  const types = useCustomAttributeTypes();
  const del = useDeleteCustomAttributeType();
  const canEdit = useCan('advanced');
  const { params, update } = useListParams({ sort: 'type', perPage: 50 }, ['typeId']);
  const values = useCustomAttributes(params);
  const [q, setQ] = useFilterInput(params.q, (v) => update({ q: v }));

  const typeColumns = useMemo(
    () => [
      tc.accessor('name', { header: 'Type', cell: (c) => <Text fw={500} size="sm">{c.getValue()}</Text> }),
      tc.accessor('notes', { header: 'Description', cell: (c) => <Text size="sm" lineClamp={2}>{c.getValue()}</Text> }),
      tc.accessor('validationRule', { header: 'Rule', cell: (c) => c.getValue() && <Code>{c.getValue()}</Code> }),
      tc.accessor('useCount', { header: 'In use', cell: (c) => <Anchor component="button" size="sm" onClick={() => update({ typeId: c.row.original.id })}>{c.getValue()}</Anchor> }),
      tc.display({
        id: 'actions',
        header: '',
        cell: (c) => {
          const t = c.row.original;
          return rowActions({
            label: t.name,
            onEdit: canEdit ? () => openForm(`Edit ${t.name}`, <TypeForm type={t} />) : undefined,
            onDelete: canEdit
              ? () => confirmDelete({
                  title: `Delete attribute type ${t.name}?`,
                  message: t.useCount ? `${t.useCount} record(s) still have this attribute; remove those first.` : 'This cannot be undone.',
                  onConfirm: async () => { await del.mutateAsync(t.id); notifySuccess(`Attribute type ${t.name} deleted`); },
                })
              : undefined,
          });
        },
      }),
    ],
    [canEdit],
  );

  const valueColumns = useMemo(
    () => [
      vc.accessor((a) => a.type.name, { id: 'type', header: 'Type', enableSorting: true }),
      vc.accessor('value', { id: 'value', header: 'Value', enableSorting: true, cell: (c) => <Text size="sm" lineClamp={2}>{c.getValue()}</Text> }),
      vc.accessor((a) => a.target.kind, {
        id: 'target',
        header: 'On',
        cell: (c) => {
          const t = c.row.original.target;
          return <Anchor component={Link} to={`${TARGET_PATH[t.kind]}/${t.id}`}><Badge component="span" variant="light" mr={6}>{t.kind}</Badge>#{t.id}</Anchor>;
        },
      }),
    ],
    [],
  );

  return (
    <>
      <PageHeader
        title="Custom attributes"
        subtitle="Extra typed fields on hosts, subnets and VLANs. Set values on each record's page."
        actions={canEdit && <Button leftSection={<IconPlus size={16} />} onClick={() => openForm('Add attribute type', <TypeForm />)}>Add type</Button>}
      />
      <Title order={4} mb="xs">Types</Title>
      <DataTable columns={typeColumns} data={types.data} loading={types.isFetching} error={types.error} empty="No attribute types." />
      <Title order={4} mt="xl" mb="xs">Values</Title>
      <Group mb="md">
        <TextInput placeholder="Filter by value" leftSection={<IconSearch size={16} />} value={q} onChange={(e) => setQ(e.currentTarget.value)} w={280} aria-label="Filter values" />
        <Select
          placeholder="All types"
          clearable
          data={options(types.data, (t) => t.id, (t) => t.name)}
          value={params.typeId ? String(params.typeId) : null}
          onChange={(v) => update({ typeId: v ?? undefined })}
          w={220}
          aria-label="Filter by type"
        />
      </Group>
      <DataTable
        columns={valueColumns}
        data={values.data?.data}
        meta={values.data?.meta}
        loading={values.isFetching}
        error={values.error}
        sort={params.sort}
        onSortChange={(sort) => update({ sort })}
        onPageChange={(page) => update({ page }, false)}
        onPerPageChange={(perPage) => update({ perPage })}
        empty="No values match."
      />
    </>
  );
}
