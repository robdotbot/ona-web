import { useEffect, useState } from 'react';
import { useDebouncedValue } from '@mantine/hooks';

/** Search box that writes to the URL after a short pause. */
export function useFilterInput(value: string | undefined, onChange: (v: string) => void) {
  const [text, setText] = useState(value ?? '');
  const [debounced] = useDebouncedValue(text, 300);
  useEffect(() => {
    if (debounced !== (value ?? '')) onChange(debounced);
  }, [debounced]);
  return [text, setText] as const;
}

