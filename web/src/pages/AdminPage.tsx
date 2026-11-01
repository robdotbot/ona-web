import { useMemo } from 'react';
import { Alert, Anchor, Badge, Button, Code, Group, Switch, Tabs, Text, Tooltip } from '@mantine/core';
import { IconExternalLink, IconPlus } from '@tabler/icons-react';
import { createColumnHelper } from '@tanstack/react-table';
import {
  useCan,
  useCreateGroup,
  useCreateSysConfig,
  useCreateUser,
  useDeleteGroup,
  useDeleteSysConfig,
  useDeleteUser,
  useGroups,
  usePermissions,
  usePlugins,
  useSession,
  useSetPluginEnabled,
  useSysConfig,
  useUpdateGroup,
  useUpdateSysConfig,
  useUpdateUser,
  useUsers,
} from '../api/hooks';
import type { Group as OnaGroup, Plugin, SysConfigEntry, User } from '../api/types';
import { DataTable } from '../components/DataTable';
import { EntityForm, openForm, options, pick } from '../components/EntityForm';
import { PageHeader } from '../components/PageHeader';
import { rowActions } from '../components/columns';
import { confirmDelete, notifyError, notifySuccess } from '../components/feedback';
import { LEGACY_UI_URL } from '../lib/legacy';
import { useListParams } from '../lib/useListParams';

const NEXT_LOGIN = 'Permission changes take effect the next time the user logs in.';

function usePermissionOptions() {
  const perms = usePermissions();
  return options(perms.data, (p) => p.name, (p) => `${p.name} – ${p.description}`);
}

function UserForm({ user }: { user?: User }) {
  const groups = useGroups();
  const permOptions = usePermissionOptions();
  const create = useCreateUser();
  const update = useUpdateUser();
  return (
    <EntityForm
      fields={[
        { key: 'username', label: 'Username', required: true, description: 'Letters, digits and . - _' },
        { key: 'password', label: user ? 'New password' : 'Password', kind: 'password', required: !user, description: user ? 'Leave empty to keep the current password' : undefined },
        { key: 'groupIds', label: 'Groups', kind: 'multiselect', data: options(groups.data, (g) => g.id, (g) => g.name) },
        { key: 'permissions', label: 'Extra permissions', kind: 'multiselect', data: permOptions, description: 'Granted directly, on top of the groups' },
      ]}
      initial={{ username: user?.username ?? '', password: '', groupIds: (user?.groups ?? []).map((g) => String(g.id)), permissions: user?.permissions ?? [] }}
      submitLabel={user ? 'Save' : 'Add user'}
      footer={<Text size="xs" c="dimmed">{NEXT_LOGIN} ONA stores local passwords as unsalted MD5.</Text>}
      onSubmit={async (v, changed) => {
        const body = {
          username: String(v.username),
          password: v.password ? String(v.password) : undefined,
          groupIds: (v.groupIds as string[]).map(Number),
          permissions: v.permissions as string[],
        };
        if (user) {
          const b = pick(body, changed);
          if (!b.password) delete b.password;
          if (Object.keys(b).length) await update.mutateAsync({ id: user.id, ...b });
        } else {
          await create.mutateAsync(body);
        }
        notifySuccess(`User ${body.username} saved`);
      }}
    />
  );
}

function GroupForm({ group }: { group?: OnaGroup }) {
  const permOptions = usePermissionOptions();
  const create = useCreateGroup();
  const update = useUpdateGroup();
  return (
    <EntityForm
      fields={[
        { key: 'name', label: 'Name', required: true },
        { key: 'description', label: 'Description' },
        { key: 'permissions', label: 'Permissions', kind: 'multiselect', data: permOptions },
      ]}
      initial={{ name: group?.name ?? '', description: group?.description ?? '', permissions: group?.permissions ?? [] }}
      submitLabel={group ? 'Save' : 'Add group'}
      footer={<Text size="xs" c="dimmed">{NEXT_LOGIN}</Text>}
      onSubmit={async (v, changed) => {
        const body = { name: String(v.name), description: String(v.description), permissions: v.permissions as string[] };
        if (group) {
          if (Object.keys(changed).length) await update.mutateAsync({ id: group.id, ...pick(body, changed) });
        } else {
          await create.mutateAsync(body);
        }
        notifySuccess(`Group ${body.name} saved`);
      }}
    />
  );
}

function SysConfigForm({ entry }: { entry?: SysConfigEntry }) {
  const create = useCreateSysConfig();
  const update = useUpdateSysConfig();
  return (
    <EntityForm
      fields={[
        { key: 'name', label: 'Name', required: true, disabled: !!entry, description: entry ? undefined : 'Letters, digits and _' },
        { key: 'value', label: 'Value', description: entry?.validationRule ? `Must match ${entry.validationRule}${entry.failedRuleText ? ` (${entry.failedRuleText})` : ''}` : undefined },
        { key: 'description', label: 'Description', kind: 'textarea' },
      ]}
      initial={{ name: entry?.name ?? '', value: entry?.value ?? '', description: entry?.description ?? '' }}
      submitLabel={entry ? 'Save' : 'Add entry'}
      onSubmit={async (v, changed) => {
        const body = { value: String(v.value), description: String(v.description) };
        if (entry) {
          if (Object.keys(changed).length) await update.mutateAsync({ id: entry.name, ...pick(body, changed) });
        } else {
          await create.mutateAsync({ name: String(v.name), ...body });
        }
        notifySuccess(`${entry?.name ?? v.name} saved`);
      }}
    />
  );
}

const uc = createColumnHelper<User>();
function Users() {
  const users = useUsers();
  const del = useDeleteUser();
  const me = useSession().data?.user.username;
  const columns = useMemo(
    () => [
      uc.accessor('username', { header: 'Username', cell: (c) => <Text fw={500} size="sm">{c.getValue()}{c.getValue() === me && <Badge component="span" ml="xs" size="xs" variant="light">you</Badge>}</Text> }),
      uc.accessor((u) => u.groups.map((g) => g.name).join(', '), { id: 'groups', header: 'Groups' }),
      uc.accessor((u) => u.permissions.join(', '), { id: 'permissions', header: 'Extra permissions', cell: (c) => <Text size="sm" lineClamp={1}>{c.getValue()}</Text> }),
      uc.accessor('lastLoginAt', { header: 'Last login', cell: (c) => c.getValue() ?? '—' }),
      uc.display({
        id: 'actions',
        header: '',
        cell: (c) => {
          const u = c.row.original;
          return rowActions({
            label: u.username,
            onEdit: () => openForm(`Edit user ${u.username}`, <UserForm user={u} />, 'lg'),
            onDelete: u.username === me ? undefined : () => confirmDelete({ title: `Delete user ${u.username}?`, message: 'This cannot be undone.', onConfirm: async () => { await del.mutateAsync(u.id); notifySuccess(`User ${u.username} deleted`); } }),
          });
        },
      }),
    ],
    [me],
  );
  return (
    <>
      <Group justify="flex-end" mb="sm"><Button leftSection={<IconPlus size={16} />} onClick={() => openForm('Add user', <UserForm />, 'lg')}>Add user</Button></Group>
      <DataTable columns={columns} data={users.data} loading={users.isFetching} error={users.error} empty="No users." />
    </>
  );
}

const gc = createColumnHelper<OnaGroup>();
function Groups() {
  const groups = useGroups();
  const del = useDeleteGroup();
  const columns = useMemo(
    () => [
      gc.accessor('name', { header: 'Group', cell: (c) => <Text fw={500} size="sm">{c.getValue()}</Text> }),
      gc.accessor('description', { header: 'Description' }),
      gc.accessor('memberCount', { header: 'Members' }),
      gc.accessor((g) => g.permissions.join(', '), { id: 'permissions', header: 'Permissions', cell: (c) => <Text size="sm" lineClamp={2}>{c.getValue()}</Text> }),
      gc.display({
        id: 'actions',
        header: '',
        cell: (c) => {
          const g = c.row.original;
          return rowActions({
            label: g.name,
            onEdit: () => openForm(`Edit group ${g.name}`, <GroupForm group={g} />, 'lg'),
            onDelete: () => confirmDelete({ title: `Delete group ${g.name}?`, message: g.memberCount ? `Its ${g.memberCount} member(s) lose the group's permissions.` : 'This cannot be undone.', onConfirm: async () => { await del.mutateAsync(g.id); notifySuccess(`Group ${g.name} deleted`); } }),
          });
        },
      }),
    ],
    [],
  );
  return (
    <>
      <Group justify="flex-end" mb="sm"><Button leftSection={<IconPlus size={16} />} onClick={() => openForm('Add group', <GroupForm />, 'lg')}>Add group</Button></Group>
      <DataTable columns={columns} data={groups.data} loading={groups.isFetching} error={groups.error} empty="No groups." />
    </>
  );
}

const sc = createColumnHelper<SysConfigEntry>();
function SysConfig() {
  const entries = useSysConfig();
  const del = useDeleteSysConfig();
  const columns = useMemo(
    () => [
      sc.accessor('name', { header: 'Name', cell: (c) => <Code>{c.getValue()}</Code> }),
      sc.accessor('value', { header: 'Value', cell: (c) => <Text ff="monospace" size="sm" style={{ overflowWrap: 'anywhere' }}>{c.getValue()}</Text> }),
      sc.accessor('description', { header: 'Description', cell: (c) => <Text size="sm" lineClamp={2}>{c.getValue()}</Text> }),
      sc.display({
        id: 'actions',
        header: '',
        cell: (c) => {
          const e = c.row.original;
          return rowActions({
            label: e.name,
            onEdit: e.editable ? () => openForm(`Edit ${e.name}`, <SysConfigForm entry={e} />) : undefined,
            onDelete: e.deleteable ? () => confirmDelete({ title: `Delete ${e.name}?`, message: 'This cannot be undone.', onConfirm: async () => { await del.mutateAsync(e.name); notifySuccess(`${e.name} deleted`); } }) : undefined,
          });
        },
      }),
    ],
    [],
  );
  return (
    <>
      <Group justify="space-between" mb="sm">
        <Text size="sm" c="dimmed">Entries without an edit button are managed by ONA itself.</Text>
        <Button leftSection={<IconPlus size={16} />} onClick={() => openForm('Add configuration entry', <SysConfigForm />)}>Add entry</Button>
      </Group>
      <DataTable columns={columns} data={entries.data} loading={entries.isFetching} error={entries.error} empty="No entries." />
    </>
  );
}

const pc = createColumnHelper<Plugin>();
function Plugins() {
  const plugins = usePlugins();
  const toggle = useSetPluginEnabled();
  const columns = useMemo(
    () => [
      pc.accessor('name', { header: 'Plugin', cell: (c) => <Text fw={500} size="sm">{c.getValue()}{c.row.original.local && <Badge component="span" ml="xs" size="xs" variant="light" color="gray">local</Badge>}</Text> }),
      pc.accessor('version', { header: 'Version', cell: (c) => c.getValue() ?? 'unknown' }),
      pc.accessor('description', { header: 'Description' }),
      pc.display({
        id: 'links',
        header: '',
        cell: (c) => {
          const p = c.row.original;
          return (
            <Group gap="sm" wrap="nowrap" justify="flex-end">
              {p.hasInstaller && (
                <Tooltip label="Run the plugin's install steps in the classic UI (Admin → Plugins)">
                  <Anchor href={LEGACY_UI_URL} target="_blank" size="sm">Installer <IconExternalLink size={12} /></Anchor>
                </Tooltip>
              )}
              {p.helpUrl && <Anchor href={p.helpUrl} target="_blank" rel="noopener noreferrer" size="sm">Help <IconExternalLink size={12} /></Anchor>}
              <Switch
                checked={p.enabled}
                label={p.enabled ? 'Enabled' : 'Disabled'}
                aria-label={`${p.enabled ? 'Disable' : 'Enable'} ${p.name}`}
                onChange={(e) => {
                  const enabled = e.currentTarget.checked;
                  toggle.mutateAsync({ name: p.name, enabled }).then(() => notifySuccess(`${p.name} ${enabled ? 'enabled' : 'disabled'}`), notifyError);
                }}
              />
            </Group>
          );
        },
      }),
    ],
    [],
  );
  return <DataTable columns={columns} data={plugins.data} loading={plugins.isFetching} error={plugins.error} empty="No plugins installed." />;
}

export function AdminPage() {
  const canUsers = useCan('user_admin');
  const canAdvanced = useCan('advanced');
  const { params, update } = useListParams({ sort: '' }, ['tab']);
  const tabs = [
    ...(canUsers ? [{ value: 'users', label: 'Users', panel: <Users /> }, { value: 'groups', label: 'Groups', panel: <Groups /> }] : []),
    ...(canAdvanced ? [{ value: 'config', label: 'System configuration', panel: <SysConfig /> }, { value: 'plugins', label: 'Plugins', panel: <Plugins /> }] : []),
  ];
  if (!tabs.length) {
    return (
      <>
        <PageHeader title="Admin" />
        <Alert color="yellow">You need the user_admin or advanced permission to use the admin screens.</Alert>
      </>
    );
  }
  const tab = tabs.some((t) => t.value === params.tab) ? (params.tab as string) : tabs[0].value;
  return (
    <>
      <PageHeader title="Admin" subtitle="Users, groups and permissions, system configuration and plugins" />
      <Tabs value={tab} onChange={(t) => update({ tab: t ?? undefined })} keepMounted={false}>
        <Tabs.List mb="md">
          {tabs.map((t) => <Tabs.Tab key={t.value} value={t.value}>{t.label}</Tabs.Tab>)}
        </Tabs.List>
        {tabs.map((t) => <Tabs.Panel key={t.value} value={t.value}>{t.panel}</Tabs.Panel>)}
      </Tabs>
    </>
  );
}
