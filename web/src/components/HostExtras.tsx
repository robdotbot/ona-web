// Host page extras: what the host serves (DNS domains, DHCP subnets) and
// links to outside tools (the classic "Host actions", configured in ONA).
import { Anchor, Badge, Card, Group, Stack, Text } from '@mantine/core';
import { IconExternalLink } from '@tabler/icons-react';
import { Link } from 'react-router-dom';
import { useHostActions } from '../api/hooks';
import type { HostDetail } from '../api/types';

const ROLE_COLOR = { master: 'blue', slave: 'teal', forward: 'gray' } as const;

export function HostServices({ host }: { host: HostDetail }) {
  const { dnsDomains, dhcpSubnets, dhcpFailoverGroupIds } = host.services;
  if (!dnsDomains.length && !dhcpSubnets.length && !dhcpFailoverGroupIds.length) return null;
  return (
    <Card withBorder>
      <Text size="xs" c="dimmed" tt="uppercase" fw={600} mb="xs">Services on this host</Text>
      <Stack gap="xs">
        {dnsDomains.length > 0 && (
          <Group gap={6} align="flex-start" wrap="nowrap">
            <Text size="sm" fw={500} w={110} style={{ flexShrink: 0 }}>DNS server for</Text>
            <Group gap={6} wrap="wrap">
              {dnsDomains.map((d) => (
                <Badge key={d.id} variant="light" color={ROLE_COLOR[d.role]} tt="none" component={Link} to={`/dns?domainId=${d.domain.id}`} style={{ cursor: 'pointer' }}>
                  {d.domain.name} ({d.role})
                </Badge>
              ))}
            </Group>
          </Group>
        )}
        {(dhcpSubnets.length > 0 || dhcpFailoverGroupIds.length > 0) && (
          <Group gap={6} align="flex-start" wrap="nowrap">
            <Text size="sm" fw={500} w={110} style={{ flexShrink: 0 }}>DHCP server for</Text>
            <Group gap={6} wrap="wrap">
              {dhcpSubnets.map((s) => (
                <Badge key={s.id} variant="light" tt="none" component={Link} to={`/subnets/${s.id}`} style={{ cursor: 'pointer' }}>
                  {s.name} {s.network}
                </Badge>
              ))}
              {dhcpFailoverGroupIds.length > 0 && <Text size="sm" c="dimmed">{dhcpFailoverGroupIds.length} failover group(s)</Text>}
              <Anchor component={Link} to={`/dhcp/servers/${host.id}`} size="sm">DHCP server details</Anchor>
            </Group>
          </Group>
        )}
      </Stack>
    </Card>
  );
}

/** Links built from ONA's host_actions config; hidden when none apply. */
export function HostActionLinks({ hostId }: { hostId: number }) {
  const actions = useHostActions(hostId);
  if (!actions.data?.length) return null;
  return (
    <Stack gap={4}>
      <Text size="xs" c="dimmed" tt="uppercase" fw={600}>Links</Text>
      <Group gap="md" wrap="wrap" aria-label="Host actions">
        {actions.data.map((a) => (
          <Anchor key={a.name} href={a.url} target="_blank" rel="noopener noreferrer" size="sm" title={a.title}>
            {a.name} <IconExternalLink size={12} />
          </Anchor>
        ))}
      </Group>
    </Stack>
  );
}
