// Home page: record counts (the classic desktop's counts box), active
// messages from every host and subnet, and first steps on an empty install.
import { Alert, Anchor, Card, Center, Group, List, Loader, SimpleGrid, Stack, Text, ThemeIcon, Title, UnstyledButton } from '@mantine/core';
import {
  IconBoxMultiple,
  IconDevices,
  IconMapPin,
  IconMessage,
  IconNetwork,
  IconPlugConnected,
  IconRouter,
  IconServer,
  IconSitemap,
  IconWorld,
  type Icon,
} from '@tabler/icons-react';
import { Link, useNavigate } from 'react-router-dom';
import { errorMessage } from '../api/client';
import { useCan, useMessages, useStats } from '../api/hooks';
import type { Stats } from '../api/types';
import { PageHeader } from '../components/PageHeader';

type CountKey = keyof Stats['counts'];
const TILES: { key: CountKey; label: string; path: string; icon: Icon }[] = [
  { key: 'subnets', label: 'Subnets', path: '/subnets', icon: IconNetwork },
  { key: 'hosts', label: 'Hosts', path: '/hosts', icon: IconServer },
  { key: 'interfaces', label: 'Interfaces', path: '/hosts', icon: IconPlugConnected },
  { key: 'dnsRecords', label: 'DNS records', path: '/dns', icon: IconWorld },
  { key: 'domains', label: 'Domains', path: '/domains', icon: IconSitemap },
  { key: 'vlans', label: 'VLANs', path: '/vlans', icon: IconRouter },
  { key: 'blocks', label: 'Blocks', path: '/blocks', icon: IconBoxMultiple },
  { key: 'dhcpServers', label: 'DHCP servers', path: '/dhcp', icon: IconDevices },
  { key: 'dnsServers', label: 'DNS servers', path: '/domains', icon: IconSitemap },
  { key: 'locations', label: 'Locations', path: '/locations', icon: IconMapPin },
];

const PRIORITY_COLOR: Record<number, string> = { 0: 'blue', 1: 'red', 2: 'yellow', 3: 'green' };

function FirstSteps() {
  const canSubnet = useCan('subnet_add');
  return (
    <Alert color="blue" title="Getting started">
      <List size="sm" spacing={4}>
        <List.Item>Add a <Anchor component={Link} to="/domains" size="sm">domain</Anchor> for host names (example.com exists already), and a reverse domain such as 10.in-addr.arpa if you want PTR records.</List.Item>
        <List.Item>{canSubnet ? <>Add a <Anchor component={Link} to="/subnets" size="sm">subnet</Anchor></> : 'Add a subnet'}, then add hosts to it from the subnet's page.</List.Item>
        <List.Item>Optionally set up <Anchor component={Link} to="/locations" size="sm">locations</Anchor>, <Anchor component={Link} to="/vlans" size="sm">VLANs</Anchor> and <Anchor component={Link} to="/reference" size="sm">device types</Anchor>.</List.Item>
      </List>
    </Alert>
  );
}

export function HomePage() {
  const stats = useStats();
  const messages = useMessages({});
  const navigate = useNavigate();

  if (stats.isPending) return <Center py="xl"><Loader /></Center>;
  if (stats.error) return <Alert color="red">{errorMessage(stats.error)}</Alert>;
  const c = stats.data.counts;

  return (
    <Stack>
      <PageHeader title="Overview" subtitle={stats.data.version ? `OpenNetAdmin ${stats.data.version}` : undefined} />
      {c.subnets === 0 && <FirstSteps />}
      <SimpleGrid cols={{ base: 2, sm: 3, md: 5 }}>
        {TILES.map((t) => (
          <UnstyledButton key={t.key} onClick={() => navigate(t.path)} aria-label={`${c[t.key]} ${t.label}`}>
            <Card withBorder padding="md">
              <Group justify="space-between" wrap="nowrap">
                <div>
                  <Text size="xs" c="dimmed" tt="uppercase" fw={600}>{t.label}</Text>
                  <Text fw={700} size="xl">{c[t.key].toLocaleString()}</Text>
                </div>
                <ThemeIcon variant="light" size="lg"><t.icon size={20} /></ThemeIcon>
              </Group>
            </Card>
          </UnstyledButton>
        ))}
      </SimpleGrid>

      <Title order={4} mt="md">Messages</Title>
      {messages.isPending && <Loader size="sm" />}
      {messages.data && !messages.data.length && <Text size="sm" c="dimmed">No active messages on any host or subnet.</Text>}
      <Stack gap="xs">
        {(messages.data ?? []).map((m) => (
          <Alert key={m.id} color={PRIORITY_COLOR[m.priority]} icon={<IconMessage size={18} />}>
            <Group justify="space-between" wrap="nowrap" align="flex-start">
              <Text size="sm" style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>{m.message}</Text>
              <Anchor component={Link} to={`/${m.target.kind === 'host' ? 'hosts' : 'subnets'}/${m.target.id}`} size="sm" style={{ whiteSpace: 'nowrap' }}>
                {m.target.name ?? `${m.target.kind} #${m.target.id}`}
              </Anchor>
            </Group>
            <Text size="xs" c="dimmed" mt={4}>{m.username} · {m.createdAt}{m.expiration ? ` · expires ${m.expiration}` : ''}</Text>
          </Alert>
        ))}
      </Stack>
    </Stack>
  );
}
