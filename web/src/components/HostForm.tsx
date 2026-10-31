import { Button, Checkbox, Divider, Group, Select, Stack, TextInput, Textarea } from '@mantine/core';
import { useForm } from '@mantine/form';
import { modals } from '@mantine/modals';
import { useNavigate } from 'react-router-dom';
import { useCreateHost, useDeviceTypes, useLocations, useUpdateHost, type HostInput } from '../api/hooks';
import type { HostDetail } from '../api/types';
import { applyServerErrors, notifySuccess } from './feedback';

const FIELD_MAP = { deviceTypeId: 'deviceTypeId', locationId: 'locationId' };

function HostForm({ host, defaults }: { host?: HostDetail; defaults?: { ip?: string } }) {
  const deviceTypes = useDeviceTypes();
  const locations = useLocations();
  const create = useCreateHost();
  const update = useUpdateHost(host?.id ?? 0);
  const navigate = useNavigate();
  const form = useForm({
    initialValues: {
      name: host?.fqdn ?? '',
      deviceTypeId: host?.deviceType ? String(host.deviceType.id) : '',
      locationId: host?.location ? String(host.location.id) : '',
      notes: host?.notes ?? '',
      ip: defaults?.ip ?? '',
      mac: '',
      interfaceName: '',
      addPtr: false,
    },
    validate: {
      name: (v) => (v.trim() ? null : 'Required'),
      deviceTypeId: (v) => (v ? null : 'Required'),
      ip: (v) => (host || v.trim() ? null : 'Required'),
    },
  });

  const submit = form.onSubmit(async (v) => {
    try {
      if (host) {
        const body: HostInput = {};
        if (v.name.trim() !== host.fqdn) body.name = v.name.trim();
        if (Number(v.deviceTypeId) !== host.deviceType?.id) body.deviceTypeId = Number(v.deviceTypeId);
        if (v.notes !== host.notes) body.notes = v.notes;
        const loc = v.locationId ? Number(v.locationId) : 0;
        if (loc !== (host.location?.id ?? 0)) body.locationId = loc;
        if (Object.keys(body).length) await update.mutateAsync(body);
        notifySuccess('Host saved');
      } else {
        const created = await create.mutateAsync({
          name: v.name.trim(),
          deviceTypeId: Number(v.deviceTypeId),
          ip: v.ip.trim(),
          notes: v.notes || undefined,
          locationId: v.locationId ? Number(v.locationId) : undefined,
          mac: v.mac.trim() || undefined,
          interfaceName: v.interfaceName.trim() || undefined,
          addPtr: v.addPtr,
        });
        notifySuccess(`Host ${created.fqdn} added`);
        navigate(`/hosts/${created.id}`);
      }
      modals.closeAll();
    } catch (err) {
      applyServerErrors(form, err, FIELD_MAP);
    }
  });

  return (
    <form onSubmit={submit}>
      <Stack>
        <TextInput label="Host name" description="Name or FQDN; without a domain the default domain is used" placeholder="web01.example.com" data-autofocus {...form.getInputProps('name')} />
        <Select label="Device type" searchable data={(deviceTypes.data ?? []).map((d) => ({ value: String(d.id), label: d.name }))} {...form.getInputProps('deviceTypeId')} />
        <Select label="Location" clearable searchable placeholder="None" data={(locations.data ?? []).map((l) => ({ value: String(l.id), label: `${l.reference} – ${l.name}` }))} {...form.getInputProps('locationId')} />
        <Textarea label="Notes" autosize minRows={2} {...form.getInputProps('notes')} />
        {!host && (
          <>
            <Divider label="First interface" labelPosition="left" />
            <Group grow align="flex-start">
              <TextInput label="IP address" placeholder="10.1.0.10" {...form.getInputProps('ip')} />
              <TextInput label="MAC address" placeholder="optional" {...form.getInputProps('mac')} />
            </Group>
            <TextInput label="Interface name" placeholder="eth0 (optional)" {...form.getInputProps('interfaceName')} />
            <Checkbox label="Also add a PTR record (needs a matching in-addr.arpa domain)" {...form.getInputProps('addPtr', { type: 'checkbox' })} />
          </>
        )}
        <Group justify="flex-end">
          <Button variant="default" onClick={() => modals.closeAll()}>Cancel</Button>
          <Button type="submit" loading={create.isPending || update.isPending}>{host ? 'Save' : 'Add host'}</Button>
        </Group>
      </Stack>
    </form>
  );
}

export function openHostForm(host?: HostDetail, defaults?: { ip?: string }) {
  modals.open({ title: host ? `Edit ${host.fqdn}` : 'Add host', size: 'lg', children: <HostForm host={host} defaults={defaults} /> });
}
