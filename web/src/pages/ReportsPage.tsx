// Reports from ONA plugins, run as text or CSV (never the plugins' HTML).
import { ActionIcon, Alert, Anchor, Button, Card, Center, Code, Group, Loader, ScrollArea, SegmentedControl, Select, Stack, Switch, Text, TextInput, Title, Tooltip } from '@mantine/core';
import { IconDownload, IconExternalLink, IconPlayerPlay, IconPlus, IconTrash } from '@tabler/icons-react';
import { useState } from 'react';
import { errorMessage } from '../api/client';
import { useReportUsage, useReports, useRunReport } from '../api/hooks';
import type { ReportOutput } from '../api/types';
import { PageHeader } from '../components/PageHeader';
import { notifyError } from '../components/feedback';
import { LEGACY_UI_URL } from '../lib/legacy';
import { useListParams } from '../lib/useListParams';

interface Param { key: string; value: string; flag: boolean }

function download(out: ReportOutput) {
  const blob = new Blob([out.output], { type: out.format === 'csv' ? 'text/csv' : 'text/plain' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `${out.name}.${out.format === 'csv' ? 'csv' : 'txt'}`;
  a.click();
  URL.revokeObjectURL(a.href);
}

function ReportRunner({ name }: { name: string }) {
  const usage = useReportUsage(name);
  const run = useRunReport();
  const [format, setFormat] = useState<'text' | 'csv'>('text');
  const [params, setParams] = useState<Param[]>([{ key: '', value: '', flag: false }]);
  const [out, setOut] = useState<ReportOutput | null>(null);
  const [error, setError] = useState<unknown>(null);

  const set = (i: number, p: Partial<Param>) => setParams((ps) => ps.map((x, j) => (j === i ? { ...x, ...p } : x)));
  const submit = async () => {
    const body: Record<string, string | boolean> = {};
    for (const p of params) if (p.key.trim()) body[p.key.trim()] = p.flag ? true : p.value;
    setError(null);
    try {
      setOut(await run.mutateAsync({ name, format, params: body }));
    } catch (err) {
      setOut(null);
      setError(err);
      if (!(err as { details?: unknown }).details) notifyError(err);
    }
  };

  return (
    <Stack>
      <Card withBorder>
        <Title order={5} mb="xs">Usage</Title>
        {usage.isPending ? <Loader size="sm" /> : usage.error ? <Text c="red">{errorMessage(usage.error)}</Text> : (
          <ScrollArea.Autosize mah={260}><Code block>{usage.data.usage}</Code></ScrollArea.Autosize>
        )}
      </Card>
      <Card withBorder>
        <Title order={5} mb="xs">Parameters</Title>
        <Text size="xs" c="dimmed" mb="sm">The report's own options, from its usage text (e.g. subnet = 10.1.0.0). Tick "flag" for options without a value.</Text>
        <Stack gap="xs">
          {params.map((p, i) => (
            <Group key={i} gap="xs" align="flex-end">
              <TextInput label={i === 0 ? 'Name' : undefined} placeholder="subnet" value={p.key} onChange={(e) => set(i, { key: e.currentTarget.value })} w={180} aria-label={`Parameter ${i + 1} name`} />
              <TextInput label={i === 0 ? 'Value' : undefined} placeholder={p.flag ? '(flag)' : 'value'} disabled={p.flag} value={p.value} onChange={(e) => set(i, { value: e.currentTarget.value })} w={260} aria-label={`Parameter ${i + 1} value`} />
              <Switch label="flag" checked={p.flag} onChange={(e) => set(i, { flag: e.currentTarget.checked })} mb={8} />
              <Tooltip label="Remove">
                <ActionIcon variant="subtle" color="red" mb={4} aria-label={`Remove parameter ${i + 1}`} onClick={() => setParams((ps) => (ps.length > 1 ? ps.filter((_, j) => j !== i) : [{ key: '', value: '', flag: false }]))}>
                  <IconTrash size={16} />
                </ActionIcon>
              </Tooltip>
            </Group>
          ))}
          <Group>
            <Button size="xs" variant="subtle" leftSection={<IconPlus size={14} />} onClick={() => setParams((ps) => [...ps, { key: '', value: '', flag: false }])}>Add parameter</Button>
          </Group>
          <Group mt="xs">
            <SegmentedControl value={format} onChange={(v) => setFormat(v as 'text' | 'csv')} data={[{ value: 'text', label: 'Text' }, { value: 'csv', label: 'CSV' }]} />
            <Button leftSection={<IconPlayerPlay size={16} />} onClick={submit} loading={run.isPending}>Run report</Button>
          </Group>
        </Stack>
      </Card>
      {error !== null && (
        <Alert color="red" title={errorMessage(error)}>
          {((error as { details?: { output?: string[] } }).details?.output ?? []).length > 1 && (
            <Code block>{(error as { details: { output: string[] } }).details.output.join('\n')}</Code>
          )}
        </Alert>
      )}
      {out && (
        <Card withBorder>
          <Group justify="space-between" mb="xs">
            <Title order={5}>Output</Title>
            <Button size="xs" variant="default" leftSection={<IconDownload size={14} />} onClick={() => download(out)}>Download</Button>
          </Group>
          <ScrollArea.Autosize mah="60vh"><Code block>{out.output || '(no output)'}</Code></ScrollArea.Autosize>
        </Card>
      )}
    </Stack>
  );
}

export function ReportsPage() {
  const reports = useReports();
  const { params, update } = useListParams({ sort: '' }, ['report']);
  const selected = (params.report as string | undefined) ?? reports.data?.[0]?.name;
  return (
    <>
      <PageHeader title="Reports" subtitle="Reports provided by ONA plugins, as text or CSV" />
      {reports.isPending && <Center py="xl"><Loader /></Center>}
      {reports.error && <Alert color="red">{errorMessage(reports.error)}</Alert>}
      {reports.data && !reports.data.length && (
        <Alert color="blue">No enabled plugin provides a report. Plugins are managed under Admin → Plugins.</Alert>
      )}
      {reports.data && reports.data.length > 0 && selected && (
        <Stack>
          <Select
            label="Report"
            data={reports.data.map((r) => ({ value: r.name, label: r.description ? `${r.name} – ${r.description}` : r.name }))}
            value={selected}
            onChange={(v) => v && update({ report: v })}
            allowDeselect={false}
            maw={520}
          />
          <ReportRunner key={selected} name={selected} />
          <Text size="xs" c="dimmed">
            Plugin windows with their own HTML (for example the nmap scan views) are still in the{' '}
            <Anchor href={LEGACY_UI_URL} target="_blank" size="xs">classic interface <IconExternalLink size={10} /></Anchor>.
          </Text>
        </Stack>
      )}
    </>
  );
}
