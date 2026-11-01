import { Button, Checkbox, Group, NumberInput, Select, Stack, TextInput } from '@mantine/core';
import { useForm } from '@mantine/form';
import { modals } from '@mantine/modals';
import { useCreateDnsRecord, useDnsViews, useUpdateDnsRecord, type DnsRecordInput } from '../api/hooks';
import { DNS_TYPES, type DnsRecord } from '../api/types';
import { applyServerErrors, notifySuccess } from './feedback';

// Which inputs each record type uses.
const USES: Record<string, { ip?: boolean; pointsTo?: boolean; mx?: boolean; txt?: boolean; srv?: boolean }> = {
  A: { ip: true },
  AAAA: { ip: true },
  PTR: { ip: true },
  CNAME: { pointsTo: true },
  NS: { pointsTo: true },
  MX: { pointsTo: true, mx: true },
  SRV: { pointsTo: true, srv: true },
  TXT: { txt: true },
};

const FIELD_MAP = { pointsTo: 'pointsTo', mxPreference: 'mxPreference', srvPriority: 'srvPriority', srvWeight: 'srvWeight', srvPort: 'srvPort' };

function DnsRecordForm({ record, defaults }: { record?: DnsRecord; defaults?: Partial<DnsRecordInput> }) {
  const create = useCreateDnsRecord();
  const update = useUpdateDnsRecord();
  const views = useDnsViews();
  const scheduled = !!record && !record.enabled && record.activeFrom !== null;
  const form = useForm({
    initialValues: {
      type: record?.type ?? defaults?.type ?? 'A',
      name: record?.fqdn ?? defaults?.name ?? '',
      ip: record?.ip ?? defaults?.ip ?? '',
      pointsTo: record?.pointsTo?.fqdn ?? defaults?.pointsTo ?? '',
      ttl: (record?.ttl ?? 0) as number | string,
      mxPreference: (record?.mxPreference ?? 10) as number | string,
      txt: record?.txt ?? '',
      srvPriority: (record?.srv?.priority ?? 0) as number | string,
      srvWeight: (record?.srv?.weight ?? 0) as number | string,
      srvPort: (record?.srv?.port ?? '') as number | string,
      notes: record?.notes ?? '',
      addPtr: false,
      enabled: record ? record.enabled || scheduled : true,
      activeFrom: scheduled ? record!.activeFrom!.slice(0, 16) : '',
      viewId: String(record?.view.id ?? 0),
    },
    validate: { name: (v) => (v.trim() ? null : 'Required') },
  });
  const uses = USES[form.values.type] ?? {};
  const num = (v: number | string) => (v === '' ? undefined : Number(v));

  const submit = form.onSubmit(async (v) => {
    const all: DnsRecordInput = {
      name: v.name.trim(),
      ttl: num(v.ttl),
      notes: v.notes,
      ip: uses.ip ? v.ip.trim() : undefined,
      pointsTo: uses.pointsTo ? v.pointsTo.trim() : undefined,
      mxPreference: uses.mx ? num(v.mxPreference) : undefined,
      txt: uses.txt ? v.txt : undefined,
      srvPriority: uses.srv ? num(v.srvPriority) : undefined,
      srvWeight: uses.srv ? num(v.srvWeight) : undefined,
      srvPort: uses.srv ? num(v.srvPort) : undefined,
      viewId: Number(v.viewId),
    };
    // Disabled wins; otherwise a start date schedules the record.
    const state: DnsRecordInput = !v.enabled ? { enabled: false } : v.activeFrom.trim() ? { activeFrom: v.activeFrom.trim() } : { enabled: true };
    try {
      if (record) {
        const before: DnsRecordInput = {
          name: record.fqdn, ttl: record.ttl, notes: record.notes, ip: record.ip ?? undefined,
          pointsTo: record.pointsTo?.fqdn, mxPreference: record.mxPreference ?? undefined, txt: record.txt ?? undefined,
          srvPriority: record.srv?.priority, srvWeight: record.srv?.weight, srvPort: record.srv?.port,
          viewId: record.view.id,
        };
        const changed = Object.fromEntries(
          Object.entries(all).filter(([k, val]) => val !== undefined && val !== before[k as keyof DnsRecordInput]),
        );
        const stateBefore = !record.enabled && !scheduled ? 'off' : scheduled ? `from ${record.activeFrom!.slice(0, 16)}` : 'on';
        const stateAfter = state.enabled === false ? 'off' : state.activeFrom ? `from ${state.activeFrom}` : 'on';
        if (stateAfter !== stateBefore) Object.assign(changed, state);
        if (Object.keys(changed).length) await update.mutateAsync({ id: record.id, ...changed });
        notifySuccess('Record saved');
      } else {
        const body = Object.fromEntries(Object.entries(all).filter(([, val]) => val !== undefined && val !== ''));
        if (!body.viewId) delete body.viewId;
        const created = await create.mutateAsync({ ...body, ...(state.enabled === true ? {} : state), type: v.type, addPtr: uses.ip && v.type !== 'PTR' ? v.addPtr : undefined });
        notifySuccess(`${created.type} record ${created.fqdn} added`);
      }
      modals.closeAll();
    } catch (err) {
      applyServerErrors(form, err, FIELD_MAP);
    }
  });

  return (
    <form onSubmit={submit}>
      <Stack>
        <Group grow align="flex-start">
          <Select label="Type" data={[...DNS_TYPES]} disabled={!!record} allowDeselect={false} {...form.getInputProps('type')} description={record ? 'Cannot be changed' : undefined} />
          <NumberInput label="TTL" description="0 = domain default" min={0} {...form.getInputProps('ttl')} />
        </Group>
        <TextInput label="Name" description="FQDN, e.g. www.example.com" data-autofocus {...form.getInputProps('name')} />
        {uses.ip && <TextInput label="IP address" description="Must belong to an existing interface" {...form.getInputProps('ip')} />}
        {uses.pointsTo && <TextInput label="Points to" description="FQDN of an existing A record" {...form.getInputProps('pointsTo')} />}
        {uses.mx && <NumberInput label="MX preference" min={0} max={65535} {...form.getInputProps('mxPreference')} />}
        {uses.txt && <TextInput label="Text" {...form.getInputProps('txt')} />}
        {uses.srv && (
          <Group grow>
            <NumberInput label="Priority" min={0} {...form.getInputProps('srvPriority')} />
            <NumberInput label="Weight" min={0} {...form.getInputProps('srvWeight')} />
            <NumberInput label="Port" min={0} max={65535} {...form.getInputProps('srvPort')} />
          </Group>
        )}
        <TextInput label="Notes" {...form.getInputProps('notes')} />
        <Group grow align="flex-end">
          <Checkbox label="Enabled" description="Disabled records stay in ONA but are left out of DNS" mb={4} {...form.getInputProps('enabled', { type: 'checkbox' })} />
          <TextInput
            label="Active from"
            placeholder="YYYY-MM-DD HH:MM"
            description="Optional: schedule the record"
            disabled={!form.values.enabled}
            {...form.getInputProps('activeFrom')}
          />
        </Group>
        {(views.data?.length ?? 0) > 1 && (
          <Select
            label="DNS view"
            description={record?.pointsTo ? 'Records pointing at another record follow its view' : 'Split horizon: which view serves this record'}
            data={(views.data ?? []).map((v) => ({ value: String(v.id), label: v.description ? `${v.name} – ${v.description}` : v.name }))}
            allowDeselect={false}
            {...form.getInputProps('viewId')}
          />
        )}
        {!record && uses.ip && form.values.type !== 'PTR' && (
          <Checkbox label="Also add a PTR record" {...form.getInputProps('addPtr', { type: 'checkbox' })} />
        )}
        <Group justify="flex-end">
          <Button variant="default" onClick={() => modals.closeAll()}>Cancel</Button>
          <Button type="submit" loading={create.isPending || update.isPending}>{record ? 'Save' : 'Add record'}</Button>
        </Group>
      </Stack>
    </form>
  );
}

export function openDnsRecordForm(record?: DnsRecord, defaults?: Partial<DnsRecordInput>) {
  modals.open({ title: record ? `Edit ${record.type} ${record.fqdn}` : 'Add DNS record', size: 'lg', children: <DnsRecordForm record={record} defaults={defaults} /> });
}
