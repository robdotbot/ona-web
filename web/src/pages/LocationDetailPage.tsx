// One location: its address, a map link, and the hosts there (the classic
// location detail window).
import { Alert, Anchor, Button, Card, Center, Loader, SimpleGrid, Stack } from '@mantine/core';
import { IconExternalLink, IconPencil } from '@tabler/icons-react';
import { useNavigate, useParams } from 'react-router-dom';
import { errorMessage } from '../api/client';
import { useCan, useHosts, useLocationRecord } from '../api/hooks';
import { DataTable } from '../components/DataTable';
import { PageHeader } from '../components/PageHeader';
import { hostColumns } from '../components/columns';
import { Fact, SectionHeader } from '../components/panels';
import { useListParams } from '../lib/useListParams';
import { NotFoundPage } from './NotFoundPage';
import { openLocationForm } from './LocationsPage';

/** An OpenStreetMap link for valid decimal coordinates, else null. */
export function mapUrl(lat: string, lon: string): string | null {
  const a = Number(lat), o = Number(lon);
  if (lat.trim() === '' || lon.trim() === '' || !Number.isFinite(a) || !Number.isFinite(o) || Math.abs(a) > 90 || Math.abs(o) > 180) return null;
  return `https://www.openstreetmap.org/?mlat=${a}&mlon=${o}#map=16/${a}/${o}`;
}

export function LocationDetailPage() {
  const id = Number(useParams().id);
  const loc = useLocationRecord(id);
  const { params, update } = useListParams({ sort: 'name' });
  const hosts = useHosts({ ...params, locationId: id });
  const navigate = useNavigate();
  const canEdit = useCan('location_add');

  if (loc.isPending) return <Center py="xl"><Loader /></Center>;
  if (loc.error) {
    if ((loc.error as { status?: number }).status === 404) return <NotFoundPage what="Location" />;
    return <Alert color="red">{errorMessage(loc.error)}</Alert>;
  }
  const l = loc.data;
  const map = mapUrl(l.latitude, l.longitude);
  return (
    <Stack>
      <PageHeader
        title={`${l.reference} – ${l.name}`}
        subtitle={[l.city, l.state].filter(Boolean).join(', ') || undefined}
        actions={canEdit && <Button variant="default" leftSection={<IconPencil size={16} />} onClick={() => openLocationForm(l)}>Edit</Button>}
      />
      <Card withBorder>
        <SimpleGrid cols={{ base: 2, sm: 4 }}>
          <Fact label="Address">{l.address}</Fact>
          <Fact label="City">{l.city}</Fact>
          <Fact label="State">{l.state}</Fact>
          <Fact label="ZIP code">{l.zipCode ? String(l.zipCode) : ''}</Fact>
          <Fact label="Coordinates">
            {l.latitude || l.longitude ? (
              map
                ? <Anchor href={map} target="_blank" rel="noopener noreferrer">{l.latitude}, {l.longitude} <IconExternalLink size={12} /></Anchor>
                : `${l.latitude}, ${l.longitude}`
            ) : ''}
          </Fact>
          <Fact label="Hosts">{String(l.deviceCount ?? hosts.data?.meta.total ?? '')}</Fact>
        </SimpleGrid>
        {l.misc && <Stack mt="md" gap={0}><Fact label="Notes">{l.misc}</Fact></Stack>}
      </Card>
      <SectionHeader title="Hosts at this location" />
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
        empty="No hosts at this location. Set a host's location when editing it."
      />
    </Stack>
  );
}
