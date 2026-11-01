import { useMemo } from 'react';
import { Anchor, Button, Group, Select, Tabs, TextInput } from '@mantine/core';
import { IconPlus, IconSearch } from '@tabler/icons-react';
import { createColumnHelper } from '@tanstack/react-table';
import { Link, useNavigate } from 'react-router-dom';
import {
  useAllVlanCampuses,
  useCan,
  useCreateVlan,
  useCreateVlanCampus,
  useDeleteVlanCampus,
  useUpdateVlan,
  useUpdateVlanCampus,
  useVlanCampuses,
  useVlans,
} from '../api/hooks';
import type { Vlan, VlanCampus, VlanDetail } from '../api/types';
import { DataTable } from '../components/DataTable';
import { EntityForm, idOr, num, openForm, options, pick } from '../components/EntityForm';
import { PageHeader } from '../components/PageHeader';
import { rowActions } from '../components/columns';
import { confirmDelete, notifySuccess } from '../components/feedback';
import { useFilterInput } from '../lib/useFilterInput';
import { useListParams } from '../lib/useListParams';

const NAME_HELP = 'Stored in upper case. Letters, digits, spaces and . _ : / # ( ) + -';

function VlanForm({ vlan, campusId }: { vlan?: VlanDetail; campusId?: number }) {
  const campuses = useAllVlanCampuses();
  const create = useCreateVlan();
  const update = useUpdateVlan();
  const navigate = useNavigate();
  return (
    <EntityForm
      fields={[
        { key: 'campusId', label: 'Campus', kind: 'select', required: true, data: options(campuses.data, (c) => c.id, (c) => c.name) },
        { key: 'name', label: 'Name', required: true, description: NAME_HELP, row: 1 },
        { key: 'number', label: 'VLAN number', kind: 'number', required: true, min: 1, max: 4094, row: 1 },
      ]}
      initial={{ campusId: vlan ? String(vlan.campus.id) : campusId ? String(campusId) : null, name: vlan?.name ?? '', number: vlan?.number ?? '' }}
      submitLabel={vlan ? 'Save' : 'Add VLAN'}
      onSubmit={async (v, changed) => {
        const body = { campusId: idOr(v.campusId), name: String(v.name), number: num(v.number) };
        if (vlan) {
          if (Object.keys(changed).length) await update.mutateAsync({ id: vlan.id, ...pick(body, changed) });
          notifySuccess('VLAN saved');
        } else {
          const created = await create.mutateAsync(body);
          notifySuccess(`VLAN ${created.name} added`);
          navigate(`/vlans/${created.id}`);
        }
      }}
    />
  );
}

export const openVlanForm = (vlan?: VlanDetail, campusId?: number) =>
  openForm(vlan ? `Edit VLAN ${vlan.name}` : 'Add VLAN', <VlanForm vlan={vlan} campusId={campusId} />);

function CampusForm({ campus }: { campus?: VlanCampus }) {
  const create = useCreateVlanCampus();
  const update = useUpdateVlanCampus();
  return (
    <EntityForm
      fields={[{ key: 'name', label: 'Name', required: true, description: NAME_HELP }]}
      initial={{ name: campus?.name ?? '' }}
      submitLabel={campus ? 'Save' : 'Add campus'}
      onSubmit={async (v) => {
        if (campus) await update.mutateAsync({ id: campus.id, name: String(v.name) });
        else await create.mutateAsync({ name: String(v.name) });
        notifySuccess('Campus saved');
      }}
    />
  );
}

const vc = createColumnHelper<Vlan>();
const cc = createColumnHelper<VlanCampus>();

function VlanList() {
  const { params, update } = useListParams({ sort: 'number', perPage: 50 }, ['campusId']);
  const vlans = useVlans(params);
  const campuses = useAllVlanCampuses();
  const navigate = useNavigate();
  const [q, setQ] = useFilterInput(params.q, (v) => update({ q: v }));
  const columns = useMemo(
    () => [
      vc.accessor('number', { id: 'number', header: 'Number', enableSorting: true, size: 100 }),
      vc.accessor('name', {
        id: 'name',
        header: 'Name',
        enableSorting: true,
        cell: (c) => <Anchor component={Link} to={`/vlans/${c.row.original.id}`} fw={500} onClick={(e) => e.stopPropagation()}>{c.getValue()}</Anchor>,
      }),
      vc.accessor((v) => v.campus.name, { id: 'campus', header: 'Campus', enableSorting: true }),
      vc.accessor('subnetCount', { header: 'Subnets' }),
    ],
    [],
  );
  return (
    <>
      <Group mb="md">
        <TextInput placeholder="Filter by name or number" leftSection={<IconSearch size={16} />} value={q} onChange={(e) => setQ(e.currentTarget.value)} w={300} aria-label="Filter VLANs" />
        <Select
          placeholder="All campuses"
          clearable
          data={options(campuses.data, (c) => c.id, (c) => c.name)}
          value={params.campusId ? String(params.campusId) : null}
          onChange={(v) => update({ campusId: v ?? undefined })}
          w={220}
          aria-label="Filter by campus"
        />
      </Group>
      <DataTable
        columns={columns}
        data={vlans.data?.data}
        meta={vlans.data?.meta}
        loading={vlans.isFetching}
        error={vlans.error}
        sort={params.sort}
        onSortChange={(sort) => update({ sort })}
        onPageChange={(page) => update({ page }, false)}
        onPerPageChange={(perPage) => update({ perPage })}
        onRowClick={(v) => navigate(`/vlans/${v.id}`)}
        empty={params.q || params.campusId ? 'No VLANs match.' : 'No VLANs yet. Add a campus, then a VLAN in it.'}
      />
    </>
  );
}

function CampusList() {
  const { params, update } = useListParams({ sort: 'name', perPage: 50 });
  const campuses = useVlanCampuses(params);
  const del = useDeleteVlanCampus();
  const can = { add: useCan('vlan_add'), modify: useCan('vlan_modify'), del: useCan('vlan_del') };
  const columns = useMemo(
    () => [
      cc.accessor('name', { id: 'name', header: 'Campus', enableSorting: true, cell: (c) => <Anchor component={Link} to={`/vlans?tab=vlans&campusId=${c.row.original.id}`} fw={500}>{c.getValue()}</Anchor> }),
      cc.accessor('vlanCount', { header: 'VLANs' }),
      cc.display({
        id: 'actions',
        header: '',
        cell: (c) => {
          const r = c.row.original;
          return (
            <Group gap="xs" justify="flex-end" wrap="nowrap">
              {can.add && <Button size="compact-xs" variant="subtle" onClick={() => openVlanForm(undefined, r.id)}>Add VLAN</Button>}
              {rowActions({
                label: r.name,
                onEdit: can.modify ? () => openForm(`Edit campus ${r.name}`, <CampusForm campus={r} />) : undefined,
                onDelete: can.del
                  ? () => confirmDelete({
                      title: `Delete campus ${r.name}?`,
                      message: r.vlanCount ? `It still has ${r.vlanCount} VLAN(s); ONA only deletes empty campuses.` : 'This cannot be undone.',
                      onConfirm: async () => { await del.mutateAsync(r.id); notifySuccess(`Campus ${r.name} deleted`); },
                    })
                  : undefined,
              })}
            </Group>
          );
        },
      }),
    ],
    [can.add, can.modify, can.del],
  );
  return (
    <DataTable
      columns={columns}
      data={campuses.data?.data}
      meta={campuses.data?.meta}
      loading={campuses.isFetching}
      error={campuses.error}
      sort={params.sort}
      onSortChange={(sort) => update({ sort })}
      onPageChange={(page) => update({ page }, false)}
      onPerPageChange={(perPage) => update({ perPage })}
      empty="No campuses yet. A campus groups VLANs, e.g. per site."
    />
  );
}

export function VlansPage() {
  const { params, update } = useListParams({ sort: 'number' }, ['tab']);
  const tab = (params.tab as string) || 'vlans';
  const canAdd = useCan('vlan_add');
  return (
    <>
      <PageHeader
        title="VLANs"
        subtitle="VLANs grouped into campuses; subnets can be assigned to a VLAN"
        actions={
          canAdd && (
            <>
              <Button variant="default" leftSection={<IconPlus size={16} />} onClick={() => openForm('Add VLAN campus', <CampusForm />)}>Add campus</Button>
              <Button leftSection={<IconPlus size={16} />} onClick={() => openVlanForm()}>Add VLAN</Button>
            </>
          )
        }
      />
      <Tabs value={tab} onChange={(t) => update({ tab: t === 'vlans' ? undefined : (t ?? undefined), q: undefined, sort: undefined, campusId: undefined })} keepMounted={false}>
        <Tabs.List mb="md">
          <Tabs.Tab value="vlans">VLANs</Tabs.Tab>
          <Tabs.Tab value="campuses">Campuses</Tabs.Tab>
        </Tabs.List>
        <Tabs.Panel value="vlans"><VlanList /></Tabs.Panel>
        <Tabs.Panel value="campuses"><CampusList /></Tabs.Panel>
      </Tabs>
    </>
  );
}
