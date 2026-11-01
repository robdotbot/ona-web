// Tags (badges with add/remove) and messages, shown on host and subnet pages.
import { ActionIcon, Alert, Autocomplete, Badge, Button, Group, Stack, Switch, Text, Tooltip } from '@mantine/core';
import { IconClockOff, IconMessage, IconPencil, IconPlus, IconTrash, IconX } from '@tabler/icons-react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import {
  useAddMessage,
  useAddTag,
  useCan,
  useDeleteMessage,
  useDeleteTag,
  useMessages,
  useTagNames,
  useTags,
  useUpdateMessage,
} from '../api/hooks';
import type { Message, TagTarget } from '../api/types';
import { EntityForm, openForm } from './EntityForm';
import { confirmDelete, notifyError, notifySuccess } from './feedback';
import { AddButton, SectionHeader } from './panels';

const kindOf = (t: TagTarget) => ('hostId' in t ? 'host' : 'subnet');

/** Tag badges linking to the filtered list, with remove buttons and an add box. */
export function TagsBar({ target }: { target: TagTarget }) {
  const kind = kindOf(target);
  const tags = useTags(target);
  const names = useTagNames();
  const add = useAddTag();
  const del = useDeleteTag();
  const canAdd = useCan(kind === 'host' ? 'host_add' : 'subnet_add');
  const canDel = useCan(kind === 'host' ? 'host_del' : 'subnet_del');
  const [adding, setAdding] = useState(false);
  const [name, setName] = useState('');
  const listPath = kind === 'host' ? '/hosts' : '/subnets';

  const submit = async () => {
    const n = name.trim();
    if (!n) return;
    try {
      await add.mutateAsync({ name: n, ...target });
      setName('');
      setAdding(false);
      notifySuccess(`Tag ${n.replace(/\s+/g, '-')} added`);
    } catch (err) {
      notifyError(err, 'Could not add the tag');
    }
  };

  const have = new Set((tags.data ?? []).map((t) => t.name));
  return (
    <Group gap={6} wrap="wrap" aria-label="Tags">
      {(tags.data ?? []).map((t) => (
        <Badge
          key={t.id}
          variant="light"
          color="grape"
          component={Link}
          to={`${listPath}?tag=${encodeURIComponent(t.name)}`}
          style={{ cursor: 'pointer', textTransform: 'none' }}
          rightSection={canDel && (
            <ActionIcon
              size="xs"
              variant="transparent"
              color="grape"
              aria-label={`Remove tag ${t.name}`}
              onClick={(e) => {
                e.preventDefault();
                e.stopPropagation();
                del.mutateAsync(t.id).then(() => notifySuccess(`Tag ${t.name} removed`), (err) => notifyError(err));
              }}
            >
              <IconX size={10} />
            </ActionIcon>
          )}
        >
          {t.name}
        </Badge>
      ))}
      {!tags.data?.length && !adding && <Text size="sm" c="dimmed">No tags</Text>}
      {canAdd && (adding ? (
        <form onSubmit={(e) => { e.preventDefault(); void submit(); }}>
          <Group gap={4}>
            <Autocomplete
              size="xs"
              placeholder="tag name"
              aria-label="New tag"
              autoFocus
              value={name}
              onChange={setName}
              data={(names.data ?? []).map((n) => n.name).filter((n) => !have.has(n))}
              onKeyDown={(e) => { if (e.key === 'Escape') setAdding(false); }}
              w={180}
            />
            <Button size="xs" type="submit" loading={add.isPending}>Add</Button>
            <Button size="xs" variant="subtle" onClick={() => setAdding(false)}>Cancel</Button>
          </Group>
        </form>
      ) : (
        <Tooltip label="Add a tag">
          <ActionIcon size="sm" variant="subtle" aria-label="Add tag" onClick={() => setAdding(true)}>
            <IconPlus size={14} />
          </ActionIcon>
        </Tooltip>
      ))}
    </Group>
  );
}

const PRIORITY_COLOR: Record<number, string> = { 0: 'blue', 1: 'red', 2: 'yellow', 3: 'green' };
const PRIORITY_OPTIONS = [
  { value: '1', label: 'High (red)' },
  { value: '2', label: 'Medium (yellow)' },
  { value: '3', label: 'Low (green)' },
  { value: '0', label: 'Information (blue)' },
];

function MessageForm({ target, message }: { target: TagTarget; message?: Message }) {
  const add = useAddMessage();
  const update = useUpdateMessage();
  return (
    <EntityForm
      fields={[
        { key: 'message', label: 'Message', kind: 'textarea', required: true },
        { key: 'priority', label: 'Priority', kind: 'select', required: true, data: PRIORITY_OPTIONS, row: 1 },
        { key: 'expiration', label: 'Expires', row: 1, placeholder: 'YYYY-MM-DD', description: message ? undefined : 'Empty: in six weeks' },
      ]}
      initial={{
        message: message?.message ?? '',
        priority: String(message?.priority ?? 3),
        expiration: message?.expiration?.slice(0, 16) ?? '',
      }}
      submitLabel={message ? 'Save' : 'Add message'}
      onSubmit={async (v, changed) => {
        const body: Record<string, unknown> = { message: String(v.message), priority: Number(v.priority) };
        if (v.expiration) body.expiration = String(v.expiration);
        if (message) {
          const patch = Object.fromEntries(Object.entries(body).filter(([k]) => k in changed));
          if (Object.keys(patch).length) await update.mutateAsync({ id: message.id, ...patch });
        } else {
          await add.mutateAsync({ ...body, ...target });
        }
        notifySuccess('Message saved');
      }}
    />
  );
}

/** Messages on a host or subnet (writes need `advanced`). */
export function MessagesPanel({ target }: { target: TagTarget }) {
  const [showExpired, setShowExpired] = useState(false);
  const msgs = useMessages(target, showExpired);
  const update = useUpdateMessage();
  const del = useDeleteMessage();
  const canEdit = useCan('advanced');
  const list = msgs.data ?? [];
  if (!canEdit && !list.length) return null;
  return (
    <>
      <SectionHeader
        title="Messages"
        action={
          <Group gap="sm">
            {canEdit && <Switch size="xs" label="Show expired" checked={showExpired} onChange={(e) => setShowExpired(e.currentTarget.checked)} />}
            {canEdit && <AddButton onClick={() => openForm('Add message', <MessageForm target={target} />)}>Add message</AddButton>}
          </Group>
        }
      />
      {!list.length && <Text size="sm" c="dimmed">No messages.</Text>}
      <Stack gap="xs">
        {list.map((m) => (
          <Alert
            key={m.id}
            color={m.expired ? 'gray' : PRIORITY_COLOR[m.priority]}
            icon={<IconMessage size={18} />}
            title={
              <Group gap="xs">
                <Text size="xs" c="dimmed" fw={400}>
                  {m.username} · {m.createdAt}{m.expiration ? ` · ${m.expired ? 'expired' : 'expires'} ${m.expiration}` : ''}
                </Text>
              </Group>
            }
            withCloseButton={false}
          >
            <Group justify="space-between" align="flex-start" wrap="nowrap">
              <Text size="sm" style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>{m.message}</Text>
              {canEdit && (
                <Group gap={2} wrap="nowrap">
                  <Tooltip label="Edit">
                    <ActionIcon variant="subtle" aria-label="Edit message" onClick={() => openForm('Edit message', <MessageForm target={target} message={m} />)}>
                      <IconPencil size={16} />
                    </ActionIcon>
                  </Tooltip>
                  {!m.expired && (
                    <Tooltip label="Expire now">
                      <ActionIcon variant="subtle" aria-label="Expire message" onClick={() => update.mutateAsync({ id: m.id, expiration: 'now' }).then(() => notifySuccess('Message expired'), notifyError)}>
                        <IconClockOff size={16} />
                      </ActionIcon>
                    </Tooltip>
                  )}
                  <Tooltip label="Delete">
                    <ActionIcon
                      variant="subtle"
                      color="red"
                      aria-label="Delete message"
                      onClick={() => confirmDelete({ title: 'Delete this message?', message: 'This cannot be undone.', onConfirm: async () => { await del.mutateAsync(m.id); notifySuccess('Message deleted'); } })}
                    >
                      <IconTrash size={16} />
                    </ActionIcon>
                  </Tooltip>
                </Group>
              )}
            </Group>
          </Alert>
        ))}
      </Stack>
    </>
  );
}
