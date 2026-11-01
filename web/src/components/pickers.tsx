// Searchable selects for records too numerous to list in full.
import { Select, type SelectProps } from '@mantine/core';
import { useDebouncedValue } from '@mantine/hooks';
import { useState } from 'react';
import { useHosts, useSubnets } from '../api/hooks';

type PickerProps = Omit<SelectProps, 'data' | 'searchValue' | 'onSearchChange'> & { initialLabel?: string };

function usePicker(initialLabel?: string) {
  const [search, setSearch] = useState(initialLabel ?? '');
  const [debounced] = useDebouncedValue(search, 250);
  // Don't search with the label of the current selection.
  return { search, setSearch, q: debounced === initialLabel ? '' : debounced };
}

export function HostPicker({ initialLabel, ...props }: PickerProps) {
  const p = usePicker(initialLabel);
  const hosts = useHosts({ q: p.q || undefined, perPage: 20, sort: 'name' });
  const data = (hosts.data?.data ?? []).map((h) => ({ value: String(h.id), label: h.primaryIp ? `${h.fqdn} (${h.primaryIp})` : h.fqdn }));
  if (props.value && initialLabel && !data.some((d) => d.value === props.value)) data.unshift({ value: String(props.value), label: initialLabel });
  return <Select searchable placeholder="Search hosts by name or IP" nothingFoundMessage="No hosts match" filter={({ options }) => options} searchValue={p.search} onSearchChange={p.setSearch} data={data} {...props} />;
}

export function SubnetPicker({ initialLabel, ...props }: PickerProps) {
  const p = usePicker(initialLabel);
  const subnets = useSubnets({ q: p.q || undefined, perPage: 20, sort: 'name' });
  const data = (subnets.data?.data ?? []).map((s) => ({ value: String(s.id), label: `${s.name} (${s.network})` }));
  if (props.value && initialLabel && !data.some((d) => d.value === props.value)) data.unshift({ value: String(props.value), label: initialLabel });
  return <Select searchable placeholder="Search subnets by name or an IP inside" nothingFoundMessage="No subnets match" filter={({ options }) => options} searchValue={p.search} onSearchChange={p.setSearch} data={data} {...props} />;
}
