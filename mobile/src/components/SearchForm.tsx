// The search form for one service, built from its field list.
import React, { useState } from 'react';
import { View } from 'react-native';
import { Service, defaults } from '../services';
import { AirportField, Button, Choice, Counter, DateField, Input } from './ui';

export function SearchForm({ svc, initial, onSearch, compact }: { svc: Service; initial?: Record<string, any>; onSearch: (q: Record<string, any>) => void; compact?: boolean }) {
  const [q, setQ] = useState<Record<string, any>>(() => ({ ...defaults(svc), ...(initial || {}) }));
  const set = (k: string, v: any) => setQ((p) => ({ ...p, [k]: v }));
  const swap = () => {
    const [a, b] = svc.fields.filter((f) => f.kind).map((f) => f.name);
    if (a && b) setQ((p) => ({ ...p, [a]: p[b], [b]: p[a] }));
  };
  const places = svc.fields.filter((f) => f.kind).length;
  return (
    <View>
      {svc.fields.map((f, i) => {
        const id = `f-${f.name}`;
        if (f.kind === 'airport') return <AirportField key={f.name} label={f.label} value={q[f.name]} onChange={(v) => set(f.name, v)} testID={id} />;
        if (f.type === 'date') return <DateField key={f.name} label={f.label} value={q[f.name]} onChange={(v) => set(f.name, v)} testID={id} />;
        if (f.type === 'number') return <Counter key={f.name} label={f.label} value={+q[f.name] || 1} min={f.min} max={f.max} onChange={(v) => set(f.name, v)} testID={id} />;
        if (f.type === 'select') return <Choice key={f.name} label={f.label} value={q[f.name]} options={f.options || []} onChange={(v) => set(f.name, v)} testID={id} />;
        return (
          <View key={f.name}>
            <Input label={f.label} value={String(q[f.name] ?? '')} onChangeText={(v) => set(f.name, v)} placeholder={f.placeholder} testID={id} autoCorrect={false} />
            {places === 2 && i === 0 && !compact ? <Button kind="link" title="⇅ Swap" onPress={swap} style={{ alignSelf: 'flex-end', marginTop: -8 }} /> : null}
          </View>
        );
      })}
      {places === 2 && svc.fields[0].kind === 'airport' ? <Button kind="link" title="⇅ Swap From and To" onPress={swap} style={{ alignSelf: 'flex-start', marginTop: -6, marginBottom: 6 }} /> : null}
      <Button title={`Search ${svc.label.toLowerCase()}`} icon="search" onPress={() => onSearch(q)} testID="search-btn" />
    </View>
  );
}
