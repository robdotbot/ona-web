// "More filters" for the host and subnet lists. Every filter lives in the URL
// (see useListParams), so filtered views can be bookmarked.
import { Badge, Button, Collapse, Group, Paper, Select, SimpleGrid, TextInput } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { IconAdjustmentsHorizontal } from '@tabler/icons-react';
import { useEffect, type ReactNode } from 'react';
import { useAllVlans, useCustomAttributeTypes, useDeviceTypes, useLocations, useTagNames } from '../api/hooks';
import type { ListParams } from '../api/types';
import { useFilterInput } from '../lib/useFilterInput';

type Update = (changes: Partial<Record<string, string | number | undefined>>) => void;

export const HOST_FILTERS = ['tag', 'deviceTypeId', 'locationId', 'ipFrom', 'ipTo', 'caTypeId', 'caValue'];
export const SUBNET_FILTERS = ['typeId', 'tag', 'vlanId', 'ipFrom', 'ipTo'];

const str = (v: unknown) => (v === undefined || v === null || v === '' ? null : String(v));

/** A text filter that writes to the URL after a pause. */
function TextFilter({ name, label, placeholder, params, update }: { name: string; label: string; placeholder?: string; params: ListParams; update: Update }) {
  const current = str(params[name]) ?? '';
  const [v, setV] = useFilterInput(current, (x) => update({ [name]: x.trim() || undefined }));
  // "Clear filters" empties the URL; empty the box too.
  useEffect(() => { if (current === '') setV(''); }, [current]);
  return <TextInput label={label} placeholder={placeholder} value={v} onChange={(e) => setV(e.currentTarget.value)} />;
}

function SelectFilter({ name, label, data, params, update }: { name: string; label: string; data: { value: string; label: string }[]; params: ListParams; update: Update }) {
  return (
    <Select label={label} placeholder="Any" clearable searchable data={data} value={str(params[name])} onChange={(v) => update({ [name]: v ?? undefined })} />
  );
}

function TagFilter({ params, update, kind }: { params: ListParams; update: Update; kind: 'host' | 'subnet' }) {
  const names = useTagNames();
  const data = (names.data ?? [])
    .filter((n) => (kind === 'host' ? n.hostCount : n.subnetCount) > 0 || n.name === params.tag)
    .map((n) => ({ value: n.name, label: `${n.name} (${kind === 'host' ? n.hostCount : n.subnetCount})` }));
  if (params.tag && !data.some((d) => d.value === params.tag)) data.unshift({ value: String(params.tag), label: String(params.tag) });
  return <SelectFilter name="tag" label="Tag" data={data} params={params} update={update} />;
}

/** The toggle button and collapsible panel, returned separately so pages can place them. */
function useFilterPanel(names: string[], params: ListParams, update: Update, children: ReactNode) {
  const active = names.filter((n) => str(params[n]) !== null).length;
  const [opened, { toggle }] = useDisclosure(active > 0);
  const button = (
    <Button
      variant={opened ? 'light' : 'default'}
      leftSection={<IconAdjustmentsHorizontal size={16} />}
      rightSection={active > 0 && <Badge size="sm" circle>{active}</Badge>}
      onClick={toggle}
      aria-expanded={opened}
    >
      More filters
    </Button>
  );
  const panel = (
    <Collapse in={opened}>
      <Paper withBorder p="sm" mb="md">
        <SimpleGrid cols={{ base: 1, sm: 2, md: 4 }}>{children}</SimpleGrid>
        {active > 0 && (
          <Group justify="flex-end" mt="sm">
            <Button size="xs" variant="subtle" onClick={() => update(Object.fromEntries(names.map((n) => [n, undefined])))}>Clear filters</Button>
          </Group>
        )}
      </Paper>
    </Collapse>
  );
  return { button, panel };
}

export function useHostFilters(params: ListParams, update: Update) {
  const types = useDeviceTypes();
  const locations = useLocations();
  const caTypes = useCustomAttributeTypes();
  return useFilterPanel(HOST_FILTERS, params, update, (
    <>
      <TagFilter kind="host" params={params} update={update} />
      <SelectFilter name="deviceTypeId" label="Device type" data={(types.data ?? []).map((t) => ({ value: String(t.id), label: t.name }))} params={params} update={update} />
      <SelectFilter name="locationId" label="Location" data={(locations.data ?? []).map((l) => ({ value: String(l.id), label: `${l.reference} – ${l.name}` }))} params={params} update={update} />
      <Group grow gap="xs" align="flex-start">
        <TextFilter name="ipFrom" label="IP from" placeholder="10.0.0.0" params={params} update={update} />
        <TextFilter name="ipTo" label="IP to" placeholder="10.0.255.255" params={params} update={update} />
      </Group>
      <SelectFilter name="caTypeId" label="Has custom attribute" data={(caTypes.data ?? []).map((t) => ({ value: String(t.id), label: t.name }))} params={params} update={update} />
      {params.caTypeId && <TextFilter name="caValue" label="…with value containing" params={params} update={update} />}
    </>
  ));
}

export function useSubnetFilters(params: ListParams, update: Update) {
  const vlans = useAllVlans();
  return useFilterPanel(SUBNET_FILTERS.filter((f) => f !== 'typeId'), params, update, (
    <>
      <TagFilter kind="subnet" params={params} update={update} />
      <SelectFilter name="vlanId" label="VLAN" data={(vlans.data ?? []).map((v) => ({ value: String(v.id), label: `${v.number} ${v.name} (${v.campus.name})` }))} params={params} update={update} />
      <Group grow gap="xs" align="flex-start">
        <TextFilter name="ipFrom" label="Network from" placeholder="10.0.0.0" params={params} update={update} />
        <TextFilter name="ipTo" label="Network to" placeholder="10.0.255.255" params={params} update={update} />
      </Group>
    </>
  ));
}
