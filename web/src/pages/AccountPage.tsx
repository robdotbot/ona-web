// Your own account (the classic "User info" window): username, groups and
// effective permissions, from the session, plus changing your password.
import { Badge, Button, Card, Group, SimpleGrid, Stack, Text } from '@mantine/core';
import { IconKey } from '@tabler/icons-react';
import { useSession } from '../api/hooks';
import { openChangePassword } from '../components/ChangePasswordForm';
import { PageHeader } from '../components/PageHeader';
import { Fact, SectionHeader } from '../components/panels';

export function AccountPage() {
  const { data: s } = useSession();
  if (!s) return null;
  return (
    <Stack>
      <PageHeader
        title="My account"
        subtitle="Who you are logged in as and what you may change"
        actions={<Button variant="default" leftSection={<IconKey size={16} />} onClick={openChangePassword}>Change password</Button>}
      />
      <Card withBorder>
        <SimpleGrid cols={{ base: 1, sm: 2 }}>
          <Fact label="Username">{s.user.username}</Fact>
          {/* Not a Fact: badges can't sit inside its <p>. */}
          <div>
            <Text size="xs" c="dimmed" tt="uppercase" fw={600}>Groups</Text>
            {s.user.groups.length ? (
              <Group gap={6} mt={4}>{s.user.groups.map((g) => <Badge key={g} variant="light" tt="none">{g}</Badge>)}</Group>
            ) : <Text>None</Text>}
          </div>
        </SimpleGrid>
      </Card>
      <SectionHeader title={`Permissions (${s.permissions.length})`} />
      {s.permissions.length ? (
        <Group gap={6}>
          {s.permissions.map((p) => <Badge key={p} variant="outline" tt="none" ff="monospace">{p}</Badge>)}
        </Group>
      ) : (
        <Text size="sm" c="dimmed">No permissions: you can look at everything but not change anything.</Text>
      )}
      <Text size="xs" c="dimmed">
        These come from your groups and any permissions given to you directly. An administrator changes them under Admin → Users;
        changes take effect the next time you log in.
      </Text>
    </Stack>
  );
}
