// A small declarative form for the simpler resources (VLANs, blocks, DHCP,
// locations, domains, admin...). Field keys are the API's body keys, so
// server-side field errors (details.fields) land on the right input.
import { Button, Checkbox, Group, MultiSelect, NumberInput, PasswordInput, Select, Stack, TextInput, Textarea } from '@mantine/core';
import { useForm } from '@mantine/form';
import { modals } from '@mantine/modals';
import type { ReactNode } from 'react';
import { applyServerErrors } from './feedback';
import { HostPicker, SubnetPicker } from './pickers';

export type FieldValue = string | number | boolean | string[] | null;
export type Values = Record<string, FieldValue>;

export interface Field {
  key: string;
  label: string;
  kind?: 'text' | 'number' | 'textarea' | 'select' | 'multiselect' | 'password' | 'checkbox' | 'host' | 'subnet';
  required?: boolean;
  description?: string;
  placeholder?: string;
  data?: { value: string; label: string }[];
  /** Fields with the same row number are laid out side by side. */
  row?: number;
  min?: number;
  max?: number;
  disabled?: boolean;
  /** For host / subnet pickers: the label of the initially selected record. */
  initialLabel?: string;
}

interface Props {
  fields: Field[];
  initial: Values;
  submitLabel: string;
  /** `changed` holds only the fields that differ from `initial`. */
  onSubmit: (values: Values, changed: Values) => Promise<unknown>;
  footer?: ReactNode;
}

const same = (a: FieldValue, b: FieldValue) =>
  Array.isArray(a) && Array.isArray(b) ? a.length === b.length && [...a].sort().join('\n') === [...b].sort().join('\n') : a === b;

export function EntityForm({ fields, initial, submitLabel, onSubmit, footer }: Props) {
  const form = useForm<Values>({
    initialValues: initial,
    validate: Object.fromEntries(
      fields
        .filter((f) => f.required)
        .map((f) => [f.key, (v: FieldValue) => (v === '' || v === null || (Array.isArray(v) && !v.length) ? 'Required' : null)]),
    ),
  });

  const submit = form.onSubmit(async (values) => {
    const changed: Values = {};
    for (const [k, v] of Object.entries(values)) {
      const trimmed = typeof v === 'string' ? v.trim() : v;
      values[k] = trimmed;
      if (!same(trimmed, initial[k] ?? null)) changed[k] = trimmed;
    }
    try {
      await onSubmit(values, changed);
      modals.closeAll();
    } catch (err) {
      applyServerErrors(form, err);
    }
  });

  const input = (f: Field, autofocus: boolean) => {
    const common = { label: f.label, description: f.description, placeholder: f.placeholder, disabled: f.disabled, 'data-autofocus': autofocus || undefined };
    switch (f.kind) {
      case 'number':
        return <NumberInput key={f.key} {...common} min={f.min} max={f.max} allowDecimal={false} {...form.getInputProps(f.key)} />;
      case 'textarea':
        return <Textarea key={f.key} {...common} autosize minRows={2} {...form.getInputProps(f.key)} />;
      case 'password':
        return <PasswordInput key={f.key} {...common} autoComplete="new-password" {...form.getInputProps(f.key)} />;
      case 'select':
        return <Select key={f.key} {...common} data={f.data ?? []} searchable clearable={!f.required} {...form.getInputProps(f.key)} />;
      case 'multiselect':
        return <MultiSelect key={f.key} {...common} data={f.data ?? []} searchable clearable {...form.getInputProps(f.key)} />;
      case 'host':
        return <HostPicker key={f.key} {...common} initialLabel={f.initialLabel} clearable={!f.required} {...form.getInputProps(f.key)} />;
      case 'subnet':
        return <SubnetPicker key={f.key} {...common} initialLabel={f.initialLabel} clearable={!f.required} {...form.getInputProps(f.key)} />;
      case 'checkbox':
        return <Checkbox key={f.key} label={f.label} description={f.description} disabled={f.disabled} mt="xs" {...form.getInputProps(f.key, { type: 'checkbox' })} />;
      default:
        return <TextInput key={f.key} {...common} {...form.getInputProps(f.key)} />;
    }
  };

  // Group consecutive fields sharing a row number.
  const rows: Field[][] = [];
  for (const f of fields) {
    const last = rows[rows.length - 1];
    if (f.row !== undefined && last && last[0].row === f.row) last.push(f);
    else rows.push([f]);
  }

  return (
    <form onSubmit={submit}>
      <Stack>
        {rows.map((r, i) =>
          r.length === 1 ? input(r[0], i === 0) : (
            <Group key={r[0].key} grow align="flex-start">
              {r.map((f, j) => input(f, i === 0 && j === 0))}
            </Group>
          ),
        )}
        {footer}
        <Group justify="flex-end">
          <Button variant="default" onClick={() => modals.closeAll()}>Cancel</Button>
          <Button type="submit" loading={form.submitting}>{submitLabel}</Button>
        </Group>
      </Stack>
    </form>
  );
}

export function openForm(title: string, children: ReactNode, size: string = 'md') {
  modals.open({ title, size, children });
}

// ---- Value helpers for building request bodies --------------------------

/** '' / null → undefined, otherwise a number. */
export const num = (v: FieldValue | undefined) => (v === '' || v === null || v === undefined ? undefined : Number(v));
/** A select's string ID → number; cleared → `cleared` (e.g. 0 to detach). */
export const idOr = (v: FieldValue | undefined, cleared?: number) => (v === '' || v === null || v === undefined ? cleared : Number(v));
export const str = (v: FieldValue | undefined) => (v === null || v === undefined ? undefined : String(v));
/** Keep only the keys present in `changed`. */
export function pick<T extends Record<string, unknown>>(body: T, changed: Values): Partial<T> {
  return Object.fromEntries(Object.entries(body).filter(([k]) => k in changed)) as Partial<T>;
}
export const options = <T,>(items: T[] | undefined, value: (t: T) => number | string, label: (t: T) => string) =>
  (items ?? []).map((t) => ({ value: String(value(t)), label: label(t) }));
