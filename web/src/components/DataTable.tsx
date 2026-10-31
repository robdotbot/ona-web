import {
  flexRender,
  getCoreRowModel,
  useReactTable,
  type ColumnDef,
  type SortingState,
} from '@tanstack/react-table';
import { Alert, Center, Group, Loader, Pagination, Select, Table, Text, UnstyledButton } from '@mantine/core';
import { IconChevronDown, IconChevronUp, IconSelector } from '@tabler/icons-react';
import type { ListMeta } from '../api/types';
import { errorMessage } from '../api/client';

interface Props<T> {
  columns: ColumnDef<T, any>[];
  data: T[] | undefined;
  meta?: ListMeta;
  loading?: boolean;
  error?: unknown;
  /** "field" or "-field", as the API expects. */
  sort?: string;
  onSortChange?: (sort: string) => void;
  onPageChange?: (page: number) => void;
  onPerPageChange?: (perPage: number) => void;
  onRowClick?: (row: T) => void;
  empty?: string;
}

/**
 * Table with server-side sorting and pagination. Columns opt in to sorting
 * with `enableSorting: true` and an `id` matching the API's sort field.
 */
export function DataTable<T>({
  columns,
  data,
  meta,
  loading,
  error,
  sort,
  onSortChange,
  onPageChange,
  onPerPageChange,
  onRowClick,
  empty = 'Nothing here yet.',
}: Props<T>) {
  const sorting: SortingState = sort ? [{ id: sort.replace(/^-/, ''), desc: sort.startsWith('-') }] : [];
  const table = useReactTable({
    data: data ?? [],
    columns,
    getCoreRowModel: getCoreRowModel(),
    manualSorting: true,
    manualPagination: true,
    enableSortingRemoval: false,
    state: { sorting },
    onSortingChange: (updater) => {
      const next = typeof updater === 'function' ? updater(sorting) : updater;
      if (next[0] && onSortChange) onSortChange((next[0].desc ? '-' : '') + next[0].id);
    },
    defaultColumn: { enableSorting: false },
  });

  if (error) return <Alert color="red" title="Could not load data">{errorMessage(error)}</Alert>;

  return (
    <>
      <Table.ScrollContainer minWidth={600}>
        <Table striped highlightOnHover={!!onRowClick} verticalSpacing="xs">
          <Table.Thead>
            {table.getHeaderGroups().map((hg) => (
              <Table.Tr key={hg.id}>
                {hg.headers.map((header) => {
                  const canSort = header.column.getCanSort();
                  const dir = header.column.getIsSorted();
                  const label = flexRender(header.column.columnDef.header, header.getContext());
                  return (
                    <Table.Th key={header.id} style={{ width: header.column.columnDef.size }}>
                      {canSort ? (
                        <UnstyledButton onClick={header.column.getToggleSortingHandler()} aria-label={`Sort by ${String(header.column.columnDef.header)}`}>
                          <Group gap={4} wrap="nowrap">
                            <Text fw={600} size="sm">{label}</Text>
                            {dir === 'asc' ? <IconChevronUp size={14} /> : dir === 'desc' ? <IconChevronDown size={14} /> : <IconSelector size={14} opacity={0.5} />}
                          </Group>
                        </UnstyledButton>
                      ) : (
                        label
                      )}
                    </Table.Th>
                  );
                })}
              </Table.Tr>
            ))}
          </Table.Thead>
          <Table.Tbody style={{ opacity: loading && data ? 0.6 : 1 }}>
            {table.getRowModel().rows.map((row) => (
              <Table.Tr
                key={row.id}
                onClick={onRowClick ? () => onRowClick(row.original) : undefined}
                style={onRowClick ? { cursor: 'pointer' } : undefined}
              >
                {row.getVisibleCells().map((cell) => (
                  <Table.Td key={cell.id}>{flexRender(cell.column.columnDef.cell, cell.getContext())}</Table.Td>
                ))}
              </Table.Tr>
            ))}
          </Table.Tbody>
        </Table>
      </Table.ScrollContainer>
      {loading && !data && (
        <Center py="xl">
          <Loader size="sm" />
        </Center>
      )}
      {data && data.length === 0 && (
        <Text c="dimmed" ta="center" py="xl">
          {empty}
        </Text>
      )}
      {meta && meta.total > 0 && (onPageChange || onPerPageChange) && (
        <Group justify="space-between" mt="md">
          <Text size="sm" c="dimmed">
            {(meta.page - 1) * meta.perPage + 1}–{Math.min(meta.page * meta.perPage, meta.total)} of {meta.total}
          </Text>
          <Group gap="sm">
            {onPerPageChange && (
              <Select
                size="xs"
                w={90}
                aria-label="Rows per page"
                data={['10', '25', '50', '100']}
                value={String(meta.perPage)}
                onChange={(v) => v && onPerPageChange(Number(v))}
                allowDeselect={false}
              />
            )}
            {onPageChange && meta.totalPages > 1 && (
              <Pagination size="sm" total={meta.totalPages} value={meta.page} onChange={onPageChange} />
            )}
          </Group>
        </Group>
      )}
    </>
  );
}
