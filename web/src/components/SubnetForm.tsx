import { Button, Group, Select, Stack, TextInput } from '@mantine/core';
import { useForm } from '@mantine/form';
import { modals } from '@mantine/modals';
import { useNavigate } from 'react-router-dom';
import { useAllVlans, useCreateSubnet, useSubnetTypes, useUpdateSubnet, type SubnetInput } from '../api/hooks';
import type { SubnetDetail } from '../api/types';
import { applyServerErrors, notifySuccess } from './feedback';

function SubnetForm({ subnet }: { subnet?: SubnetDetail }) {
  const types = useSubnetTypes();
  const vlans = useAllVlans();
  const create = useCreateSubnet();
  const update = useUpdateSubnet(subnet?.id ?? 0);
  const navigate = useNavigate();
  const form = useForm({
    initialValues: {
      name: subnet?.name ?? '',
      ip: subnet?.ip ?? '',
      netmask: subnet ? String(subnet.cidr) : '24',
      typeId: subnet?.type ? String(subnet.type.id) : '',
      vlanId: subnet?.vlan ? String(subnet.vlan.id) : '',
    },
    validate: {
      name: (v) => (v.trim() ? null : 'Required'),
      ip: (v) => (v.trim() ? null : 'Required'),
      netmask: (v) => (v.trim() ? null : 'Required'),
      typeId: (v) => (v ? null : 'Required'),
    },
  });

  const submit = form.onSubmit(async (v) => {
    const body: SubnetInput = { name: v.name.trim(), ip: v.ip.trim(), netmask: v.netmask.trim(), typeId: Number(v.typeId) };
    const vlanId = v.vlanId ? Number(v.vlanId) : 0;
    try {
      if (subnet) {
        // Only send what changed; ONA refuses some no-op changes.
        const changed: SubnetInput = {};
        if (body.name !== subnet.name) changed.name = body.name;
        if (body.ip !== subnet.ip) changed.ip = body.ip;
        if (body.netmask !== String(subnet.cidr) && body.netmask !== subnet.netmask) changed.netmask = body.netmask;
        if (body.typeId !== subnet.type?.id) changed.typeId = body.typeId;
        if (vlanId !== (subnet.vlan?.id ?? 0)) changed.vlanId = vlanId;
        if (Object.keys(changed).length) await update.mutateAsync(changed);
        notifySuccess('Subnet saved');
      } else {
        const created = await create.mutateAsync(vlanId ? { ...body, vlanId } : body);
        notifySuccess(`Subnet ${created.name} added`);
        navigate(`/subnets/${created.id}`);
      }
      modals.closeAll();
    } catch (err) {
      applyServerErrors(form, err, { typeId: 'typeId', vlanId: 'vlanId' });
    }
  });

  return (
    <form onSubmit={submit}>
      <Stack>
        <TextInput label="Name" description="Stored in upper case, e.g. LAN-OFFICE" data-autofocus {...form.getInputProps('name')} />
        <Group grow align="flex-start">
          <TextInput label="Network address" placeholder="10.1.0.0" {...form.getInputProps('ip')} />
          <TextInput label="Netmask" description="Prefix length or dotted mask" placeholder="24" {...form.getInputProps('netmask')} />
        </Group>
        <Select
          label="Type"
          placeholder="Choose a subnet type"
          data={(types.data ?? []).map((t) => ({ value: String(t.id), label: t.name }))}
          searchable
          {...form.getInputProps('typeId')}
        />
        <Select
          label="VLAN"
          placeholder="None"
          clearable
          searchable
          data={(vlans.data ?? []).map((x) => ({ value: String(x.id), label: `${x.number} ${x.name} (${x.campus.name})` }))}
          {...form.getInputProps('vlanId')}
        />
        <Group justify="flex-end">
          <Button variant="default" onClick={() => modals.closeAll()}>Cancel</Button>
          <Button type="submit" loading={create.isPending || update.isPending}>{subnet ? 'Save' : 'Add subnet'}</Button>
        </Group>
      </Stack>
    </form>
  );
}

export function openSubnetForm(subnet?: SubnetDetail) {
  modals.open({ title: subnet ? `Edit ${subnet.name}` : 'Add subnet', children: <SubnetForm subnet={subnet} /> });
}
