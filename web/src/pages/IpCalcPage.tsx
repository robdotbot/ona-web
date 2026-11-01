// IP calculator (IPv4 and IPv6), computed in the browser, with links to the
// subnet that contains the address when ONA has one.
import { Alert, Anchor, Badge, Card, Group, Stack, Table, Text, TextInput } from '@mantine/core';
import { useDebouncedValue } from '@mantine/hooks';
import { IconCalculator } from '@tabler/icons-react';
import { useMemo, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { useSubnets } from '../api/hooks';
import { PageHeader } from '../components/PageHeader';
import { calculate, formatCount } from '../lib/ip';
import { useListParams } from '../lib/useListParams';
import { useFilterInput } from '../lib/useFilterInput';

export function IpCalcPage() {
  const { params, update } = useListParams({ sort: '' }, ['ip', 'mask']);
  const [ip, setIp] = useFilterInput(params.ip as string | undefined, (v) => update({ ip: v }));
  const [mask, setMask] = useFilterInput(params.mask as string | undefined, (v) => update({ mask: v }));
  const r = useMemo(() => (ip.trim() ? calculate(ip, mask) : null), [ip, mask]);
  const [lookup] = useDebouncedValue(r?.address ?? '', 400);
  // The subnet containing the address, if ONA has one.
  const containing = useSubnets({ q: lookup, perPage: 5 }, !!lookup);

  const rows: [string, ReactNode][] = r
    ? [
        ['Address', r.address],
        ['Network', `${r.network}/${r.prefix}`],
        ['Netmask', r.netmask],
        ['Wildcard', r.wildcard],
        ...(r.broadcast ? [['Broadcast', r.broadcast] as [string, string]] : []),
        ['Usable range', `${r.firstHost} – ${r.lastHost}`],
        ['Addresses', `${formatCount(r.addresses)} (${formatCount(r.usable)} usable)`],
        ['Reverse zone', r.reverseZone],
        ['Hex', r.addressHex],
        ['Decimal (as ONA stores it)', r.addressDecimal],
        ['Binary', r.addressBinary],
        ...(r.ipv4Class ? [['Class', `${r.ipv4Class}${r.isPrivate ? ' · private / shared range' : ''}`] as [string, string]] : []),
      ]
    : [];
  // ?q= also matches names, so check the subnet really contains the address.
  const hit = r && lookup ? containing.data?.data.find((s) => s.ipVersion === r.version && calculate(r.address, String(s.cidr))?.network === s.ip) : undefined;

  return (
    <>
      <PageHeader title="IP calculator" subtitle="Network, mask and range for IPv4 and IPv6 addresses" />
      <Stack maw={760}>
        <Group align="flex-start">
          <TextInput
            label="Address"
            placeholder="10.1.2.3/24 or 2001:db8::1/64"
            leftSection={<IconCalculator size={16} />}
            value={ip}
            onChange={(e) => setIp(e.currentTarget.value)}
            w={340}
            data-autofocus
            autoFocus
          />
          <TextInput label="Mask or prefix" placeholder="24 or 255.255.255.0" value={mask} onChange={(e) => setMask(e.currentTarget.value)} w={220} description="Optional if the address has /prefix" />
        </Group>
        {ip.trim() && !r && <Alert color="red">Not a valid IPv4 or IPv6 address and mask.</Alert>}
        {r && (
          <Card withBorder>
            <Group mb="sm">
              <Badge variant="light">IPv{r.version}</Badge>
              {hit && (
                <Text size="sm">
                  In ONA subnet <Anchor component={Link} to={`/subnets/${hit.id}`}>{hit.name}</Anchor> ({hit.network})
                </Text>
              )}
            </Group>
            <Table withRowBorders={false} verticalSpacing={4}>
              <Table.Tbody>
                {rows.map(([k, v]) => (
                  <Table.Tr key={k}>
                    <Table.Td c="dimmed" w={220}>{k}</Table.Td>
                    <Table.Td ff="monospace" style={{ overflowWrap: 'anywhere' }}>{v}</Table.Td>
                  </Table.Tr>
                ))}
              </Table.Tbody>
            </Table>
          </Card>
        )}
      </Stack>
    </>
  );
}
