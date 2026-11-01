import { useState, type ReactNode } from 'react';
import {
  ActionIcon,
  AppShell,
  Burger,
  Group,
  Menu,
  NavLink,
  ScrollArea,
  Text,
  TextInput,
  Tooltip,
  useComputedColorScheme,
  useMantineColorScheme,
} from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { IconExternalLink, IconLogout, IconMoon, IconSearch, IconSun, IconUser } from '@tabler/icons-react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useLogout, useSession } from '../api/hooks';
import { NAV_GROUPS } from './nav';
import { LEGACY_UI_URL } from '../lib/legacy';


function GlobalSearch() {
  const navigate = useNavigate();
  const [q, setQ] = useState('');
  return (
    <form
      role="search"
      onSubmit={(e) => {
        e.preventDefault();
        if (q.trim()) navigate(`/search?q=${encodeURIComponent(q.trim())}`);
      }}
    >
      <TextInput
        placeholder="Search hosts, IPs, MACs, subnets, DNS…"
        aria-label="Global search"
        leftSection={<IconSearch size={16} />}
        value={q}
        onChange={(e) => setQ(e.currentTarget.value)}
        w={{ base: 200, sm: 380 }}
      />
    </form>
  );
}

export function AppLayout({ children }: { children: ReactNode }) {
  const [opened, { toggle, close }] = useDisclosure();
  const { pathname } = useLocation();
  const { data: session } = useSession();
  const logout = useLogout();
  const { setColorScheme } = useMantineColorScheme();
  const scheme = useComputedColorScheme('light');

  return (
    <AppShell
      header={{ height: 60 }}
      navbar={{ width: 230, breakpoint: 'sm', collapsed: { mobile: !opened } }}
      padding="lg"
    >
      <AppShell.Header>
        <Group h="100%" px="md" justify="space-between" wrap="nowrap">
          <Group gap="sm" wrap="nowrap">
            <Burger opened={opened} onClick={toggle} hiddenFrom="sm" size="sm" aria-label="Toggle navigation" />
            <Text component={Link} to="/" fw={700} size="lg" visibleFrom="xs">
              OpenNetAdmin
            </Text>
          </Group>
          <GlobalSearch />
          <Group gap="xs" wrap="nowrap">
            <Tooltip label="Toggle dark mode">
              <ActionIcon
                variant="default"
                size="lg"
                aria-label="Toggle color scheme"
                onClick={() => setColorScheme(scheme === 'dark' ? 'light' : 'dark')}
              >
                {scheme === 'dark' ? <IconSun size={18} /> : <IconMoon size={18} />}
              </ActionIcon>
            </Tooltip>
            <Menu position="bottom-end" withArrow>
              <Menu.Target>
                <ActionIcon variant="default" size="lg" aria-label="User menu">
                  <IconUser size={18} />
                </ActionIcon>
              </Menu.Target>
              <Menu.Dropdown>
                <Menu.Label>Signed in as {session?.user.username}</Menu.Label>
                <Menu.Item component="a" href={LEGACY_UI_URL} target="_blank" leftSection={<IconExternalLink size={14} />}>
                  Classic ONA interface
                </Menu.Item>
                <Menu.Divider />
                <Menu.Item color="red" leftSection={<IconLogout size={14} />} onClick={() => logout.mutate()}>
                  Log out
                </Menu.Item>
              </Menu.Dropdown>
            </Menu>
          </Group>
        </Group>
      </AppShell.Header>

      <AppShell.Navbar p="sm">
        <AppShell.Section grow component={ScrollArea}>
          {NAV_GROUPS.map((g, i) => {
            const sections = g.sections.filter(
              (s) => !s.anyPermission || s.anyPermission.some((p) => session?.permissions.includes(p)),
            );
            if (!sections.length) return null;
            return (
              <div key={g.label ?? i}>
                {g.label && (
                  <Text size="xs" fw={600} c="dimmed" tt="uppercase" mt="lg" mb={4} px="sm">
                    {g.label}
                  </Text>
                )}
                {sections.map((s) => (
                  <NavLink
                    key={s.path}
                    component={Link}
                    to={s.path}
                    label={s.label}
                    leftSection={<s.icon size={18} />}
                    active={pathname.startsWith(s.path)}
                    onClick={close}
                  />
                ))}
              </div>
            );
          })}
        </AppShell.Section>
      </AppShell.Navbar>

      <AppShell.Main>{children}</AppShell.Main>
    </AppShell>
  );
}
