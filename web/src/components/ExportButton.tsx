// "Export CSV" for the paged lists: fetches every row matching the current
// filters and sort (up to EXPORT_LIMIT) and saves them as a CSV file.
import { Button, Tooltip } from '@mantine/core';
import { notifications } from '@mantine/notifications';
import { IconDownload } from '@tabler/icons-react';
import { useState } from 'react';
import type { ListParams } from '../api/types';
import { EXPORT_LIMIT, fetchAll, saveFile, toCsv } from '../lib/csv';
import { notifyError } from './feedback';

interface Props<T> {
  path: string;
  params: ListParams;
  filename: string;
  header: string[];
  row: (item: T) => unknown[];
}

export function ExportButton<T>({ path, params, filename, header, row }: Props<T>) {
  const [busy, setBusy] = useState(false);
  const run = async () => {
    setBusy(true);
    try {
      const { rows, total } = await fetchAll<T>(path, params);
      saveFile(`${filename}.csv`, toCsv(header, rows.map(row)));
      if (total > rows.length) {
        notifications.show({ color: 'yellow', message: `Exported the first ${rows.length.toLocaleString()} of ${total.toLocaleString()} rows; filter the list to export the rest.` });
      }
    } catch (err) {
      notifyError(err, 'Export failed');
    } finally {
      setBusy(false);
    }
  };
  return (
    <Tooltip label={`Download the rows matching the current filters as CSV (up to ${EXPORT_LIMIT.toLocaleString()})`}>
      <Button variant="default" leftSection={<IconDownload size={16} />} loading={busy} onClick={run}>
        Export CSV
      </Button>
    </Tooltip>
  );
}
