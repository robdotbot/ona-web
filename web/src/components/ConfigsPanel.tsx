// Config archives on the host page: list, view the text, diff two versions,
// archive a new one (pasted text, never a file name) and delete.
import { ActionIcon, Badge, Button, Center, Checkbox, Code, Group, Loader, ScrollArea, Stack, Text, Tooltip } from '@mantine/core';
import { IconEye, IconGitCompare, IconTrash } from '@tabler/icons-react';
import { createColumnHelper } from '@tanstack/react-table';
import { useState } from 'react';
import { errorMessage } from '../api/client';
import { useAddConfig, useCan, useConfig, useConfigDiff, useConfigTypes, useConfigs, useDeleteConfig } from '../api/hooks';
import type { Config } from '../api/types';
import { DataTable } from './DataTable';
import { EntityForm, openForm, options } from './EntityForm';
import { confirmDelete, notifySuccess } from './feedback';
import { AddButton, SectionHeader } from './panels';

function ConfigText({ id }: { id: number }) {
  const c = useConfig(id);
  if (c.isPending) return <Center py="md"><Loader size="sm" /></Center>;
  if (c.error) return <Text c="red">{errorMessage(c.error)}</Text>;
  return (
    <Stack gap="xs">
      <Text size="xs" c="dimmed">{c.data.type.name} · {c.data.createdAt} · md5 {c.data.md5} · {c.data.size} bytes</Text>
      <ScrollArea.Autosize mah="65vh">
        <Code block>{c.data.text}</Code>
      </ScrollArea.Autosize>
    </Stack>
  );
}

/** A unified diff with added/removed lines coloured. */
export function DiffView({ diff }: { diff: string }) {
  if (!diff.trim()) return <Text size="sm" c="dimmed">The two configs are identical.</Text>;
  return (
    <ScrollArea.Autosize mah="65vh">
      <Code block>
        {diff.split('\n').map((line, i) => (
          <div
            key={i}
            style={{
              color: line.startsWith('+') ? 'var(--mantine-color-green-text)' : line.startsWith('-') ? 'var(--mantine-color-red-text)' : line.startsWith('@@') ? 'var(--mantine-color-blue-text)' : undefined,
              whiteSpace: 'pre',
            }}
          >
            {line || ' '}
          </div>
        ))}
      </Code>
    </ScrollArea.Autosize>
  );
}

function ConfigDiff({ from, to }: { from: number; to: number }) {
  const d = useConfigDiff(from, to);
  if (d.isPending) return <Center py="md"><Loader size="sm" /></Center>;
  if (d.error) return <Text c="red">{errorMessage(d.error)}</Text>;
  return (
    <Stack gap="xs">
      <Text size="xs" c="dimmed">From {d.data.from.createdAt} (#{d.data.from.id}) to {d.data.to.createdAt} (#{d.data.to.id})</Text>
      <DiffView diff={d.data.diff} />
    </Stack>
  );
}

function AddConfigForm({ hostId }: { hostId: number }) {
  const types = useConfigTypes();
  const add = useAddConfig();
  return (
    <EntityForm
      fields={[
        { key: 'typeId', label: 'Type', kind: 'select', required: true, data: options(types.data, (t) => t.id, (t) => t.name) },
        { key: 'text', label: 'Config text', kind: 'textarea', required: true, description: 'Paste the configuration itself' },
      ]}
      initial={{ typeId: null, text: '' }}
      submitLabel="Archive config"
      onSubmit={async (v) => {
        await add.mutateAsync({ hostId, typeId: Number(v.typeId), text: String(v.text) });
        notifySuccess('Config archived');
      }}
    />
  );
}

const col = createColumnHelper<Config>();

export function ConfigsPanel({ hostId }: { hostId: number }) {
  const canUse = useCan('host_config_admin');
  const configs = useConfigs({ hostId, perPage: 100 }, canUse);
  const del = useDeleteConfig();
  const [selected, setSelected] = useState<number[]>([]);
  if (!canUse) return null;

  const toggle = (id: number) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id].slice(-2)));
  const columns = [
    col.display({
      id: 'select',
      header: '',
      size: 30,
      cell: (c) => <Checkbox aria-label={`Select config ${c.row.original.id} to compare`} checked={selected.includes(c.row.original.id)} onChange={() => toggle(c.row.original.id)} />,
    }),
    col.accessor('createdAt', { header: 'Archived' }),
    col.accessor((c) => c.type.name, { id: 'type', header: 'Type', cell: (c) => <Badge variant="light">{c.getValue()}</Badge> }),
    col.accessor('size', { header: 'Size', cell: (c) => `${c.getValue()} B` }),
    col.accessor('md5', { header: 'MD5', cell: (c) => <Text ff="monospace" size="xs">{c.getValue()}</Text> }),
    col.display({
      id: 'actions',
      header: '',
      cell: (c) => {
        const cfg = c.row.original;
        return (
          <Group gap={4} justify="flex-end" wrap="nowrap">
            <Tooltip label="View">
              <ActionIcon variant="subtle" aria-label={`View config ${cfg.id}`} onClick={() => openForm(`${cfg.type.name} ${cfg.createdAt}`, <ConfigText id={cfg.id} />, 'xl')}>
                <IconEye size={16} />
              </ActionIcon>
            </Tooltip>
            <Tooltip label="Delete">
              <ActionIcon
                variant="subtle"
                color="red"
                aria-label={`Delete config ${cfg.id}`}
                onClick={() => confirmDelete({ title: 'Delete this config?', message: `${cfg.type.name} archived ${cfg.createdAt}. This cannot be undone.`, onConfirm: async () => { await del.mutateAsync(cfg.id); setSelected((s) => s.filter((x) => x !== cfg.id)); notifySuccess('Config deleted'); } })}
              >
                <IconTrash size={16} />
              </ActionIcon>
            </Tooltip>
          </Group>
        );
      },
    }),
  ];

  return (
    <>
      <SectionHeader
        title="Config archive"
        action={
          <Group gap="xs">
            <Button
              size="xs"
              variant="default"
              leftSection={<IconGitCompare size={14} />}
              disabled={selected.length !== 2}
              onClick={() => openForm('Config diff', <ConfigDiff from={selected[0]} to={selected[1]} />, 'xl')}
            >
              Compare selected
            </Button>
            <AddButton onClick={() => openForm('Archive a config', <AddConfigForm hostId={hostId} />, 'lg')}>Add config</AddButton>
          </Group>
        }
      />
      <DataTable columns={columns} data={configs.data?.data} loading={configs.isFetching} error={configs.error} empty="No archived configs." />
      {(configs.data?.data.length ?? 0) > 1 && <Text size="xs" c="dimmed">Tick two configs to compare them.</Text>}
    </>
  );
}
