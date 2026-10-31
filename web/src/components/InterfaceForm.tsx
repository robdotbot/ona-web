import { Button, Checkbox, Group, Stack, TextInput } from '@mantine/core';
import { useForm } from '@mantine/form';
import { modals } from '@mantine/modals';
import { useCreateInterface, useUpdateInterface, type InterfaceInput } from '../api/hooks';
import type { Interface } from '../api/types';
import { applyServerErrors, notifySuccess } from './feedback';

function InterfaceForm({ hostId, iface }: { hostId: number; iface?: Interface }) {
  const create = useCreateInterface();
  const update = useUpdateInterface();
  const form = useForm({
    initialValues: {
      ip: iface?.ip ?? '',
      mac: iface?.mac ?? '',
      name: iface?.name ?? '',
      description: iface?.description ?? '',
      addPtr: false,
    },
    validate: { ip: (v) => (v.trim() ? null : 'Required') },
  });

  const submit = form.onSubmit(async (v) => {
    try {
      if (iface) {
        const body: InterfaceInput = {};
        if (v.ip.trim() !== iface.ip) body.ip = v.ip.trim();
        if (v.mac.trim() !== (iface.mac ?? '')) body.mac = v.mac.trim();
        if (v.name !== iface.name) body.name = v.name;
        if (v.description !== (iface.description ?? '')) body.description = v.description;
        if (Object.keys(body).length) await update.mutateAsync({ id: iface.id, ...body });
        notifySuccess('Interface saved');
      } else {
        await create.mutateAsync({
          hostId,
          ip: v.ip.trim(),
          mac: v.mac.trim() || undefined,
          name: v.name.trim() || undefined,
          description: v.description.trim() || undefined,
          addPtr: v.addPtr,
        });
        notifySuccess(`Interface ${v.ip.trim()} added`);
      }
      modals.closeAll();
    } catch (err) {
      applyServerErrors(form, err);
    }
  });

  return (
    <form onSubmit={submit}>
      <Stack>
        <Group grow align="flex-start">
          <TextInput label="IP address" data-autofocus {...form.getInputProps('ip')} />
          <TextInput label="MAC address" {...form.getInputProps('mac')} />
        </Group>
        <TextInput label="Name" placeholder="eth0" {...form.getInputProps('name')} />
        <TextInput label="Description" {...form.getInputProps('description')} />
        {!iface && <Checkbox label="Also add a PTR record" {...form.getInputProps('addPtr', { type: 'checkbox' })} />}
        <Group justify="flex-end">
          <Button variant="default" onClick={() => modals.closeAll()}>Cancel</Button>
          <Button type="submit" loading={create.isPending || update.isPending}>{iface ? 'Save' : 'Add interface'}</Button>
        </Group>
      </Stack>
    </form>
  );
}

export function openInterfaceForm(hostId: number, iface?: Interface) {
  modals.open({ title: iface ? `Edit interface ${iface.ip}` : 'Add interface', children: <InterfaceForm hostId={hostId} iface={iface} /> });
}
