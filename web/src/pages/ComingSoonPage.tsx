import { Anchor, Paper, Stack, Text, ThemeIcon, Title } from '@mantine/core';
import type { NavSection } from '../components/nav';

const LEGACY_UI_URL = import.meta.env.VITE_LEGACY_UI_URL ?? 'http://localhost:8080/';

export function ComingSoonPage({ section }: { section: NavSection }) {
  return (
    <Paper withBorder p="xl" maw={640}>
      <Stack align="flex-start">
        <ThemeIcon size="xl" variant="light">
          <section.icon size={24} />
        </ThemeIcon>
        <Title order={2}>{section.label}</Title>
        <Text c="dimmed">
          {section.description} {section.description ? 'are' : 'is'} not available in the new interface yet. Until then, use the{' '}
          <Anchor href={LEGACY_UI_URL} target="_blank">classic ONA interface</Anchor>, which works on the same data.
        </Text>
      </Stack>
    </Paper>
  );
}
