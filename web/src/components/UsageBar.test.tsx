import { render, screen } from '@testing-library/react';
import { MantineProvider } from '@mantine/core';
import { describe, expect, it } from 'vitest';
import { UsageBar } from './UsageBar';

const renderBar = (used: number, total: string) =>
  render(<MantineProvider><UsageBar usage={{ used, total, percent: 0 }} /></MantineProvider>);

describe('UsageBar', () => {
  it('shows the rounded percentage', () => {
    renderBar(127, '254');
    expect(screen.getByText('50%')).toBeInTheDocument();
  });
  it('shows <1% for small non-zero usage instead of 0%', () => {
    renderBar(2, '254');
    expect(screen.getByText('<1%')).toBeInTheDocument();
  });
});
