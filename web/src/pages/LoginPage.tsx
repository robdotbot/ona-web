import { Alert, Button, Center, Paper, PasswordInput, Stack, Text, TextInput, Title } from '@mantine/core';
import { useForm } from '@mantine/form';
import { IconAlertCircle } from '@tabler/icons-react';
import { useLogin } from '../api/hooks';
import { errorMessage } from '../api/client';

export function LoginPage() {
  const login = useLogin();
  const form = useForm({
    initialValues: { username: '', password: '' },
    validate: {
      username: (v) => (v.trim() ? null : 'Enter your username'),
      password: (v) => (v ? null : 'Enter your password'),
    },
  });

  return (
    <Center mih="100vh" bg="var(--mantine-color-body)">
      <Paper withBorder shadow="md" p="xl" radius="md" w={380}>
        <form onSubmit={form.onSubmit((v) => login.mutate({ username: v.username.trim(), password: v.password }))}>
          <Stack>
            <div>
              <Title order={2}>OpenNetAdmin</Title>
              <Text c="dimmed" size="sm">Sign in with your ONA account</Text>
            </div>
            {login.error && (
              <Alert color="red" icon={<IconAlertCircle size={16} />}>
                {errorMessage(login.error)}
              </Alert>
            )}
            <TextInput label="Username" autoComplete="username" autoFocus {...form.getInputProps('username')} />
            <PasswordInput label="Password" autoComplete="current-password" {...form.getInputProps('password')} />
            <Button type="submit" loading={login.isPending} fullWidth>
              Sign in
            </Button>
          </Stack>
        </form>
      </Paper>
    </Center>
  );
}
