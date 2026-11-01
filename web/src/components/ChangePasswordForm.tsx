import { Button, Group, PasswordInput, Stack, Text } from '@mantine/core';
import { useForm } from '@mantine/form';
import { modals } from '@mantine/modals';
import { useChangePassword } from '../api/hooks';
import { applyServerErrors, notifySuccess } from './feedback';

function ChangePasswordForm() {
  const change = useChangePassword();
  const form = useForm({
    initialValues: { currentPassword: '', newPassword: '', confirm: '' },
    validate: {
      currentPassword: (v) => (v ? null : 'Required'),
      newPassword: (v) => (v ? null : 'Required'),
      confirm: (v, all) => (v === all.newPassword ? null : "Doesn't match the new password"),
    },
  });
  const submit = form.onSubmit(async (v) => {
    try {
      await change.mutateAsync({ currentPassword: v.currentPassword, newPassword: v.newPassword });
      notifySuccess('Password changed');
      modals.closeAll();
    } catch (err) {
      applyServerErrors(form, err);
    }
  });
  return (
    <form onSubmit={submit}>
      <Stack>
        <PasswordInput label="Current password" autoComplete="current-password" data-autofocus {...form.getInputProps('currentPassword')} />
        <PasswordInput label="New password" autoComplete="new-password" {...form.getInputProps('newPassword')} />
        <PasswordInput label="Repeat new password" autoComplete="new-password" {...form.getInputProps('confirm')} />
        <Text size="xs" c="dimmed">You stay logged in. The classic UI uses the same password.</Text>
        <Group justify="flex-end">
          <Button variant="default" onClick={() => modals.closeAll()}>Cancel</Button>
          <Button type="submit" loading={change.isPending}>Change password</Button>
        </Group>
      </Stack>
    </form>
  );
}

export const openChangePassword = () => modals.open({ title: 'Change your password', children: <ChangePasswordForm /> });
