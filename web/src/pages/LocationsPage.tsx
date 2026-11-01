import { useMemo } from 'react';
import { Anchor, Button } from '@mantine/core';
import { IconPlus } from '@tabler/icons-react';
import { createColumnHelper } from '@tanstack/react-table';
import { Link, useNavigate } from 'react-router-dom';
import { useCan, useCreateLocation, useDeleteLocation, useLocations, useUpdateLocation } from '../api/hooks';
import type { Location } from '../api/types';
import { DataTable } from '../components/DataTable';
import { EntityForm, num, openForm, pick } from '../components/EntityForm';
import { PageHeader } from '../components/PageHeader';
import { rowActions } from '../components/columns';
import { confirmDelete, notifySuccess } from '../components/feedback';

const TEXT_FIELDS = ['name', 'address', 'city', 'state', 'latitude', 'longitude', 'misc'] as const;

function LocationForm({ location }: { location?: Location }) {
  const create = useCreateLocation();
  const update = useUpdateLocation();
  return (
    <EntityForm
      fields={[
        { key: 'reference', label: 'Reference', required: true, row: 1, description: 'Short code (up to 10), stored upper case', placeholder: 'HQ' },
        { key: 'name', label: 'Name', required: true, row: 1, placeholder: 'Head office' },
        { key: 'address', label: 'Address' },
        { key: 'city', label: 'City', row: 2 },
        { key: 'state', label: 'State', row: 2 },
        { key: 'zipCode', label: 'ZIP code', kind: 'number', min: 0, max: 99999, row: 2 },
        { key: 'latitude', label: 'Latitude', row: 3 },
        { key: 'longitude', label: 'Longitude', row: 3 },
        { key: 'misc', label: 'Notes', kind: 'textarea' },
      ]}
      initial={{
        reference: location?.reference ?? '',
        zipCode: location?.zipCode ?? '',
        ...Object.fromEntries(TEXT_FIELDS.map((k) => [k, location?.[k] ?? ''])),
      }}
      submitLabel={location ? 'Save' : 'Add location'}
      onSubmit={async (v, changed) => {
        const body = { reference: String(v.reference), zipCode: num(v.zipCode) ?? 0, ...Object.fromEntries(TEXT_FIELDS.map((k) => [k, String(v[k])])) };
        if (location) {
          if (Object.keys(changed).length) await update.mutateAsync({ id: location.id, ...pick(body, changed) });
        } else {
          await create.mutateAsync(body);
        }
        notifySuccess(`Location ${body.reference.toUpperCase()} saved`);
      }}
    />
  );
}

export const openLocationForm = (location?: Location) =>
  openForm(location ? `Edit ${location.reference}` : 'Add location', <LocationForm location={location} />, 'lg');

const col = createColumnHelper<Location>();

export function LocationsPage() {
  const locations = useLocations();
  const del = useDeleteLocation();
  const can = { add: useCan('location_add'), del: useCan('location_del') };
  const navigate = useNavigate();
  const columns = useMemo(
    () => [
      col.accessor('reference', {
        header: 'Reference',
        cell: (c) => <Anchor component={Link} to={`/locations/${c.row.original.id}`} fw={500} size="sm" onClick={(e) => e.stopPropagation()}>{c.getValue()}</Anchor>,
      }),
      col.accessor('name', { header: 'Name' }),
      col.accessor((l) => [l.address, l.city, l.state, l.zipCode].filter(Boolean).join(', '), { id: 'address', header: 'Address' }),
      col.accessor('deviceCount', { header: 'Hosts' }),
      col.display({
        id: 'actions',
        header: '',
        cell: (c) => {
          const l = c.row.original;
          return rowActions({
            label: l.reference,
            onEdit: can.add ? () => openForm(`Edit ${l.reference}`, <LocationForm location={l} />, 'lg') : undefined,
            onDelete: can.del
              ? () => confirmDelete({
                  title: `Delete location ${l.reference}?`,
                  message: l.deviceCount ? `${l.deviceCount} host(s) are at this location; move them first.` : 'This cannot be undone.',
                  onConfirm: async () => { await del.mutateAsync(l.id); notifySuccess(`Location ${l.reference} deleted`); },
                })
              : undefined,
          });
        },
      }),
    ],
    [can.add, can.del],
  );
  return (
    <>
      <PageHeader
        title="Locations"
        subtitle="Sites where hosts live; set a host's location when editing it"
        actions={can.add && <Button leftSection={<IconPlus size={16} />} onClick={() => openForm('Add location', <LocationForm />, 'lg')}>Add location</Button>}
      />
      <DataTable columns={columns} data={locations.data} loading={locations.isFetching} error={locations.error} onRowClick={(l) => navigate(`/locations/${l.id}`)} empty="No locations yet." />
    </>
  );
}
