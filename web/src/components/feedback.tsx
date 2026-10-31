import { Code, Stack, Text } from '@mantine/core';
import { modals } from '@mantine/modals';
import { notifications } from '@mantine/notifications';
import type { UseFormReturnType } from '@mantine/form';
import type { ReactNode } from 'react';
import { ApiError, errorMessage } from '../api/client';

export function notifySuccess(message: string) {
  notifications.show({ color: 'teal', message });
}

export function notifyError(err: unknown, title = 'Request failed') {
  const output = err instanceof ApiError ? err.details?.output : undefined;
  notifications.show({
    color: 'red',
    title,
    autoClose: 8000,
    message: (
      <Stack gap={4}>
        <Text size="sm">{errorMessage(err)}</Text>
        {output && output.length > 1 && <Code block>{output.join('\n')}</Code>}
      </Stack>
    ),
  });
}

/**
 * Show API errors on a form: per-field messages from details.fields, and
 * everything else as a notification.
 */
export function applyServerErrors(form: UseFormReturnType<any>, err: unknown, fieldMap: Record<string, string> = {}) {
  const fields = err instanceof ApiError ? err.details?.fields : undefined;
  if (fields) {
    form.setErrors(Object.fromEntries(Object.entries(fields).map(([k, v]) => [fieldMap[k] ?? k, v])));
    return;
  }
  notifyError(err);
}

export function confirmDelete(opts: { title: string; message: ReactNode; onConfirm: () => Promise<unknown> }) {
  modals.openConfirmModal({
    title: opts.title,
    children: <Text size="sm">{opts.message}</Text>,
    labels: { confirm: 'Delete', cancel: 'Cancel' },
    confirmProps: { color: 'red' },
    onConfirm: () => {
      opts.onConfirm().catch((err) => notifyError(err, 'Delete failed'));
    },
  });
}
