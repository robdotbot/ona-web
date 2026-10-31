import { Group, Progress, Text, Tooltip } from '@mantine/core';
import type { Subnet } from '../api/types';

/** IP usage as ONA computes it: interfaces plus DHCP pool addresses. */
export function UsageBar({ usage, width = 140 }: { usage: Subnet['usage']; width?: number }) {
  const total = Number(usage.total);
  const pct = total > 0 ? Math.min(100, (usage.used / total) * 100) : 0;
  const color = pct >= 90 ? 'red' : pct >= 70 ? 'yellow' : 'teal';
  return (
    <Tooltip label={`${usage.used} of ${usage.total} addresses used`}>
      <Group gap="xs" wrap="nowrap">
        <Progress value={pct} color={color} w={width} size="md" aria-label="IP usage" />
        <Text size="xs" c="dimmed" w={36}>
          {pct > 0 && pct < 1 ? '<1' : Math.round(pct)}%
        </Text>
      </Group>
    </Tooltip>
  );
}
