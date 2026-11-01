// Interface operations beyond add/edit/delete: move to another host, share
// with other hosts (VRRP/HSRP/CARP), external NAT addresses, and moving a
// range of addresses to another subnet.
import { Alert, Button, Group, Stack, Table, Text, TextInput } from '@mantine/core';
import { useForm } from '@mantine/form';
import { modals } from '@mantine/modals';
import { useState } from 'react';
import { useAddNat, useBulkMoveInterfaces, useMoveInterfaceToHost, useShareInterface, type InterfaceMoveInput } from '../api/hooks';
import type { Interface, InterfaceMoveResult } from '../api/types';
import { EntityForm, openForm } from './EntityForm';
import { applyServerErrors, notifySuccess } from './feedback';

function MoveToHostForm({ iface }: { iface: Interface }) {
  const move = useMoveInterfaceToHost();
  return (
    <EntityForm
      fields={[{ key: 'hostId', label: 'New host', kind: 'host', required: true }]}
      initial={{ hostId: null }}
      submitLabel="Move interface"
      footer={<Text size="xs" c="dimmed">The address keeps its DNS records. A host's primary interface can't be moved; change its primary DNS name first.</Text>}
      onSubmit={async (v) => {
        const r = await move.mutateAsync({ id: iface.id, hostId: Number(v.hostId) });
        notifySuccess(`${iface.ip} moved to ${r.host?.fqdn ?? 'the new host'}`);
      }}
    />
  );
}

function ShareForm({ iface }: { iface: Interface }) {
  const share = useShareInterface();
  return (
    <EntityForm
      fields={[
        { key: 'hostId', label: 'Share with host', kind: 'host', required: true },
        { key: 'name', label: 'Interface name on that host', placeholder: 'e.g. carp0', description: 'Only if it differs' },
      ]}
      initial={{ hostId: null, name: '' }}
      submitLabel="Share"
      footer={<Text size="xs" c="dimmed">For addresses several hosts answer on (VRRP, HSRP, CARP…). The address stays on {iface.host?.fqdn}.</Text>}
      onSubmit={async (v) => {
        await share.mutateAsync({ id: iface.id, hostId: Number(v.hostId), name: String(v.name) || undefined });
        notifySuccess(`${iface.ip} shared`);
      }}
    />
  );
}

function NatForm({ iface }: { iface: Interface }) {
  const add = useAddNat();
  return (
    <EntityForm
      fields={[{ key: 'ip', label: 'External (NAT) address', required: true, placeholder: '203.0.113.10' }]}
      initial={{ ip: '' }}
      submitLabel="Add NAT address"
      footer={<Text size="xs" c="dimmed">ONA adds it as an "EXT NAT" interface on the same host, with a PTR record, so its subnet and reverse zone must exist.</Text>}
      onSubmit={async (v) => {
        await add.mutateAsync({ id: iface.id, ip: String(v.ip) });
        notifySuccess(`NAT address ${v.ip} added to ${iface.ip}`);
      }}
    />
  );
}

export const openMoveToHost = (iface: Interface) => openForm(`Move ${iface.ip} to another host`, <MoveToHostForm iface={iface} />);
export const openShare = (iface: Interface) => openForm(`Share ${iface.ip}`, <ShareForm iface={iface} />);
export const openNat = (iface: Interface) => openForm(`NAT address for ${iface.ip}`, <NatForm iface={iface} />);

/** Move the interfaces in a range of one subnet to free addresses of another: preview first, then commit. */
function BulkMoveForm({ defaults }: { defaults?: Partial<InterfaceMoveInput> }) {
  const move = useBulkMoveInterfaces();
  const [preview, setPreview] = useState<{ input: InterfaceMoveInput; result: InterfaceMoveResult } | null>(null);
  const form = useForm({
    initialValues: { start: defaults?.start ?? '', end: defaults?.end ?? '', newStart: defaults?.newStart ?? '', newEnd: defaults?.newEnd ?? '' },
    validate: { start: (v) => (v.trim() ? null : 'Required'), newStart: (v) => (v.trim() ? null : 'Required') },
    // Any edit invalidates the preview.
    onValuesChange: () => setPreview(null),
  });
  const input = (): InterfaceMoveInput => {
    const v = form.getValues();
    return { start: v.start.trim(), end: v.end.trim() || undefined, newStart: v.newStart.trim(), newEnd: v.newEnd.trim() || undefined };
  };

  const onPreview = form.onSubmit(async () => {
    try {
      const i = input();
      setPreview({ input: i, result: await move.mutateAsync({ ...i, commit: false }) });
    } catch (err) {
      setPreview(null);
      applyServerErrors(form, err);
    }
  });
  const onCommit = async () => {
    if (!preview) return;
    try {
      const r = await move.mutateAsync({ ...preview.input, commit: true });
      notifySuccess(`${r.moves.length} interface(s) moved`);
      modals.closeAll();
    } catch (err) {
      applyServerErrors(form, err);
    }
  };

  return (
    <form onSubmit={onPreview}>
      <Stack>
        <Text size="sm" c="dimmed">
          Moves every interface between the first and last address (one subnet) to the free addresses of the new range
          (another subnet), in order, skipping DHCP pools. IPv4 only. DNS records follow their interfaces.
        </Text>
        <Group grow align="flex-start">
          <TextInput label="First address to move" required data-autofocus {...form.getInputProps('start')} />
          <TextInput label="Last address to move" placeholder="Same as first" {...form.getInputProps('end')} />
        </Group>
        <Group grow align="flex-start">
          <TextInput label="First new address" required {...form.getInputProps('newStart')} />
          <TextInput label="Last new address" placeholder="Same as first" {...form.getInputProps('newEnd')} />
        </Group>
        {preview && (
          <Alert color="blue" title={`${preview.result.moves.length} interface(s) would move`}>
            <Table withRowBorders={false} verticalSpacing={2} fz="sm">
              <Table.Tbody>
                {preview.result.moves.map((m) => (
                  <Table.Tr key={m.from}>
                    <Table.Td ff="monospace">{m.from}</Table.Td>
                    <Table.Td>→</Table.Td>
                    <Table.Td ff="monospace">{m.to}</Table.Td>
                    <Table.Td c="dimmed">{m.host}</Table.Td>
                  </Table.Tr>
                ))}
              </Table.Tbody>
            </Table>
          </Alert>
        )}
        <Group justify="flex-end">
          <Button variant="default" onClick={() => modals.closeAll()}>Cancel</Button>
          {preview ? (
            <Button color="orange" onClick={onCommit} loading={move.isPending}>Move {preview.result.moves.length} interface(s)</Button>
          ) : (
            <Button type="submit" loading={move.isPending}>Preview</Button>
          )}
        </Group>
      </Stack>
    </form>
  );
}

export const openBulkMove = (defaults?: Partial<InterfaceMoveInput>) =>
  modals.open({ title: 'Move addresses to another subnet', size: 'lg', children: <BulkMoveForm defaults={defaults} /> });
