import { Button, Stack, Text, Title } from '@mantine/core';
import { Link } from 'react-router-dom';

export function NotFoundPage({ what = 'Page' }: { what?: string }) {
  return (
    <Stack align="flex-start">
      <Title order={2}>{what} not found</Title>
      <Text c="dimmed">It may have been deleted, or the link is wrong.</Text>
      <Button component={Link} to="/" variant="light">Go to subnets</Button>
    </Stack>
  );
}
