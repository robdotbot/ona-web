import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MantineProvider } from '@mantine/core';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { EntityForm, idOr, num, pick } from './EntityForm';

describe('EntityForm helpers', () => {
  it('num turns empty values into undefined', () => {
    expect(num('')).toBeUndefined();
    expect(num(null)).toBeUndefined();
    expect(num('42')).toBe(42);
    expect(num(7)).toBe(7);
  });
  it('idOr maps a cleared select to the fallback', () => {
    expect(idOr('5')).toBe(5);
    expect(idOr(null, 0)).toBe(0);
    expect(idOr('', undefined)).toBeUndefined();
  });
  it('pick keeps only changed keys', () => {
    expect(pick({ a: 1, b: 2, c: 3 }, { a: 9, c: 9 })).toEqual({ a: 1, c: 3 });
  });
});

describe('EntityForm', () => {
  afterEach(cleanup);
  const renderForm = (onSubmit: (v: unknown, changed: unknown) => Promise<void>) =>
    render(
      <MantineProvider>
        <EntityForm
          fields={[
            { key: 'name', label: 'Name', required: true },
            { key: 'notes', label: 'Notes' },
            { key: 'groups', label: 'Groups', kind: 'multiselect', data: [{ value: '1', label: 'one' }] },
          ]}
          initial={{ name: 'web', notes: 'old', groups: ['1'] }}
          submitLabel="Save"
          onSubmit={onSubmit}
        />
      </MantineProvider>,
    );

  it('passes only the changed (trimmed) fields', async () => {
    const onSubmit = vi.fn().mockResolvedValue(undefined);
    renderForm(onSubmit);
    fireEvent.change(screen.getByLabelText('Notes'), { target: { value: '  new  ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(onSubmit).toHaveBeenCalled());
    const [values, changed] = onSubmit.mock.calls[0];
    expect(changed).toEqual({ notes: 'new' });
    expect(values).toMatchObject({ name: 'web', notes: 'new', groups: ['1'] });
  });

  it('blocks submit when a required field is empty', async () => {
    const onSubmit = vi.fn().mockResolvedValue(undefined);
    renderForm(onSubmit);
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: '' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    expect(await screen.findByText('Required')).toBeInTheDocument();
    expect(onSubmit).not.toHaveBeenCalled();
  });
});
