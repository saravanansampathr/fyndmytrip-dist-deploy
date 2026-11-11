// Shared building blocks: cards, buttons, form fields.
import React, { useMemo, useState } from 'react';
import { ActivityIndicator, Modal, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, TextStyle, View, ViewStyle } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { C, R } from '../theme';
import { fmtDate } from '../money';
import { DatePicker } from './DatePicker';
import airports from '../data/airports.json';

export function Card({ children, style, testID }: { children: React.ReactNode; style?: ViewStyle | ViewStyle[]; testID?: string }) {
  return <View testID={testID} style={[s.card, style as any]}>{children}</View>;
}

export function H1({ children, style }: { children: React.ReactNode; style?: TextStyle }) { return <Text accessibilityRole="header" style={[s.h1, style]}>{children}</Text>; }
export function H2({ children, style }: { children: React.ReactNode; style?: TextStyle }) { return <Text accessibilityRole="header" style={[s.h2, style]}>{children}</Text>; }
export function Muted({ children, style }: { children: React.ReactNode; style?: TextStyle }) { return <Text style={[s.muted, style]}>{children}</Text>; }

export function Button({ title, onPress, kind = 'primary', busy, disabled, icon, testID, style }: {
  title: string; onPress?: () => void; kind?: 'primary' | 'ghost' | 'link' | 'danger'; busy?: boolean; disabled?: boolean; icon?: any; testID?: string; style?: ViewStyle;
}) {
  const off = disabled || busy;
  const fg = kind === 'primary' || kind === 'danger' ? '#fff' : kind === 'link' ? C.primary : C.ink;
  return (
    <Pressable testID={testID} accessibilityRole="button" accessibilityState={{ disabled: !!off, busy: !!busy }} disabled={off} onPress={onPress}
      style={({ pressed }) => [s.btn, kind === 'primary' && s.btnPrimary, kind === 'ghost' && s.btnGhost, kind === 'link' && s.btnLink, kind === 'danger' && s.btnDanger, off && { opacity: 0.55 }, pressed && { opacity: 0.8 }, style]}>
      {busy ? <ActivityIndicator color={fg} style={{ marginRight: 8 }} /> : icon ? <Ionicons name={icon} size={18} color={fg} style={{ marginRight: 8 }} /> : null}
      <Text style={[s.btnText, { color: fg }]}>{title}</Text>
    </Pressable>
  );
}

export function Label({ children }: { children: React.ReactNode }) { return <Text style={s.label}>{children}</Text>; }

export function Input({ label, value, onChangeText, testID, ...rest }: { label: string; value: string; onChangeText: (v: string) => void; testID?: string } & React.ComponentProps<typeof TextInput>) {
  return (
    <View style={s.field}>
      <Label>{label}</Label>
      <TextInput testID={testID} accessibilityLabel={label} value={value} onChangeText={onChangeText} placeholderTextColor="#94a3b8" style={s.input} {...rest} />
    </View>
  );
}

/** A small set of choices shown as tappable chips. */
export function Choice({ label, value, options, onChange, testID }: { label: string; value: string; options: [string, string][]; onChange: (v: string) => void; testID?: string }) {
  return (
    <View style={s.field} testID={testID}>
      <Label>{label}</Label>
      <View style={s.chips}>
        {options.map(([v, l]) => (
          <Pressable key={v} accessibilityRole="radio" accessibilityState={{ checked: v === value }} testID={testID ? `${testID}-${v}` : undefined} onPress={() => onChange(v)} style={[s.chip, v === value && s.chipOn]}>
            <Text style={[s.chipText, v === value && { color: '#fff' }]}>{l}</Text>
          </Pressable>
        ))}
      </View>
    </View>
  );
}

export function Counter({ label, value, onChange, min = 1, max = 9, testID }: { label: string; value: number; onChange: (v: number) => void; min?: number; max?: number; testID?: string }) {
  return (
    <View style={s.field}>
      <Label>{label}</Label>
      <View style={s.counter}>
        <Pressable accessibilityLabel={`Fewer ${label.toLowerCase()}`} testID={testID ? testID + '-minus' : undefined} onPress={() => onChange(Math.max(min, value - 1))} style={s.cBtn}><Ionicons name="remove" size={20} color={C.ink} /></Pressable>
        <Text style={s.cVal} testID={testID}>{value}</Text>
        <Pressable accessibilityLabel={`More ${label.toLowerCase()}`} testID={testID ? testID + '-plus' : undefined} onPress={() => onChange(Math.min(max, value + 1))} style={s.cBtn}><Ionicons name="add" size={20} color={C.ink} /></Pressable>
      </View>
    </View>
  );
}

export function DateField({ label, value, onChange, testID, min }: { label: string; value: string; onChange: (v: string) => void; testID?: string; min?: string }) {
  const [open, setOpen] = useState(false);
  if (Platform.OS === 'web') {
    return <Input label={label} value={value} onChangeText={onChange} testID={testID} placeholder="YYYY-MM-DD" />;
  }
  return (
    <View style={s.field}>
      <Label>{label}</Label>
      <Pressable testID={testID} accessibilityRole="button" accessibilityLabel={`${label}, ${fmtDate(value)}`} onPress={() => setOpen(true)} style={[s.input, { justifyContent: 'center' }]}>
        <Text style={{ color: C.ink, fontSize: 16 }}>{value ? fmtDate(value) : 'Choose a date'}</Text>
      </Pressable>
      {open ? <DatePicker value={value} min={min} onClose={() => setOpen(false)} onChange={(v) => { setOpen(false); if (v) onChange(v); }} /> : null}
    </View>
  );
}

type Airport = [string, string, string, string];
const AIRPORTS = airports as Airport[];

/** Airport picker: type a city or code, pick from the list. */
export function AirportField({ label, value, onChange, testID }: { label: string; value: string; onChange: (v: string) => void; testID?: string }) {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const current = AIRPORTS.find((a) => a[0] === String(value).toUpperCase());
  const list = useMemo(() => {
    const t = q.trim().toLowerCase();
    if (!t) return AIRPORTS.filter((a) => a[3] === 'India').slice(0, 40);
    const exact = AIRPORTS.filter((a) => a[0].toLowerCase() === t);
    const rest = AIRPORTS.filter((a) => a[0].toLowerCase() !== t && (a[1].toLowerCase().startsWith(t) || a[2].toLowerCase().includes(t) || a[3].toLowerCase().startsWith(t)));
    return [...exact, ...rest].slice(0, 50);
  }, [q]);
  return (
    <View style={s.field}>
      <Label>{label}</Label>
      <Pressable testID={testID} accessibilityRole="button" accessibilityLabel={`${label}: ${current ? current[1] : value}`} onPress={() => { setQ(''); setOpen(true); }} style={[s.input, { justifyContent: 'center' }]}>
        <Text style={{ fontSize: 16, color: C.ink }}><Text style={{ fontWeight: '700' }}>{String(value).toUpperCase()}</Text>{current ? `  ${current[1]}` : ''}</Text>
      </Pressable>
      <Modal visible={open} animationType="slide" onRequestClose={() => setOpen(false)}>
        <View style={{ flex: 1, backgroundColor: '#fff', paddingTop: Platform.OS === 'ios' ? 54 : 16 }}>
          <View style={{ flexDirection: 'row', alignItems: 'center', paddingHorizontal: 16, gap: 8 }}>
            <Pressable accessibilityLabel="Close" onPress={() => setOpen(false)} style={{ padding: 6 }}><Ionicons name="close" size={26} color={C.ink} /></Pressable>
            <TextInput testID={testID ? testID + '-search' : undefined} autoFocus value={q} onChangeText={setQ} placeholder={`${label}: city, airport or code`} placeholderTextColor="#94a3b8" style={[s.input, { flex: 1 }]} />
          </View>
          <ScrollView keyboardShouldPersistTaps="handled" style={{ marginTop: 8 }}>
            {list.map((a) => (
              <Pressable key={a[0]} testID={testID ? `${testID}-opt-${a[0]}` : undefined} onPress={() => { onChange(a[0]); setOpen(false); }} style={s.apRow}>
                <Text style={s.apCode}>{a[0]}</Text>
                <View style={{ flex: 1 }}><Text style={{ fontSize: 15, color: C.ink }}>{a[1]}, {a[3]}</Text><Text style={s.muted} numberOfLines={1}>{a[2]}</Text></View>
              </Pressable>
            ))}
            {!list.length ? <Text style={[s.muted, { padding: 16 }]}>No airport matches “{q}”.</Text> : null}
          </ScrollView>
        </View>
      </Modal>
    </View>
  );
}

export function Row({ label, value, bold, testID }: { label: string; value: string; bold?: boolean; testID?: string }) {
  return (
    <View style={[s.row, bold && { borderTopWidth: 1, borderTopColor: C.line, paddingTop: 10, marginTop: 4 }]}>
      <Text style={[s.rowL, bold && { fontWeight: '700', color: C.ink }]}>{label}</Text>
      <Text testID={testID} style={[s.rowV, bold && { fontWeight: '800', fontSize: 17 }]}>{value}</Text>
    </View>
  );
}

export function Tag({ text, tone = 'ok' }: { text: string; tone?: 'ok' | 'warn' | 'err' | 'plain' }) {
  const bg = tone === 'ok' ? C.okBg : tone === 'warn' ? C.warnBg : tone === 'err' ? C.errBg : '#eef2f7';
  const fg = tone === 'ok' ? C.ok : tone === 'warn' ? C.warn : tone === 'err' ? C.err : C.muted;
  return <View style={[s.tag, { backgroundColor: bg }]}><Text style={{ color: fg, fontSize: 12, fontWeight: '600' }}>{text}</Text></View>;
}

export function Empty({ icon = 'search', title, text, children }: { icon?: any; title: string; text?: string; children?: React.ReactNode }) {
  return (
    <View style={{ alignItems: 'center', padding: 32, gap: 8 }}>
      <Ionicons name={icon} size={40} color="#94a3b8" />
      <Text style={[s.h2, { textAlign: 'center' }]}>{title}</Text>
      {text ? <Text style={[s.muted, { textAlign: 'center' }]}>{text}</Text> : null}
      {children}
    </View>
  );
}

/** Booking progress: Details → Travellers → Payment → Confirmed. */
export function Steps({ at }: { at: number }) {
  const names = ['Details', 'Travellers', 'Payment', 'Confirmed'];
  return (
    <View style={s.steps} accessibilityLabel={`Step ${at + 1} of 4: ${names[at]}`}>
      {names.map((n, i) => (
        <View key={n} style={{ flex: 1, alignItems: 'center' }}>
          <View style={[s.stepDot, i <= at && { backgroundColor: C.primary }]}>{i < at ? <Ionicons name="checkmark" size={12} color="#fff" /> : <Text style={{ color: i <= at ? '#fff' : C.muted, fontSize: 11, fontWeight: '700' }}>{i + 1}</Text>}</View>
          <Text style={{ fontSize: 11, marginTop: 3, color: i <= at ? C.ink : C.muted }}>{n}</Text>
        </View>
      ))}
    </View>
  );
}

export function Trust() {
  return (
    <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 12, marginTop: 8 }}>
      {[['lock-closed', 'Secure payment'], ['flash', 'Instant confirmation'], ['headset', '24×7 support']].map(([i, t]) => (
        <View key={t} style={{ flexDirection: 'row', alignItems: 'center', gap: 4 }}><Ionicons name={i as any} size={14} color={C.ok} /><Text style={{ fontSize: 12, color: C.muted }}>{t}</Text></View>
      ))}
    </View>
  );
}

export const s = StyleSheet.create({
  card: { backgroundColor: C.card, borderRadius: R, padding: 16, marginBottom: 12, borderWidth: 1, borderColor: C.line },
  h1: { fontSize: 24, fontWeight: '800', color: C.ink, marginBottom: 6 },
  h2: { fontSize: 18, fontWeight: '700', color: C.ink, marginBottom: 6 },
  muted: { color: C.muted, fontSize: 13 },
  btn: { minHeight: 48, borderRadius: 10, paddingHorizontal: 16, flexDirection: 'row', alignItems: 'center', justifyContent: 'center' },
  btnPrimary: { backgroundColor: C.primary },
  btnDanger: { backgroundColor: C.err },
  btnGhost: { backgroundColor: '#fff', borderWidth: 1, borderColor: C.line },
  btnLink: { minHeight: 36, paddingHorizontal: 4 },
  btnText: { fontSize: 16, fontWeight: '700' },
  field: { marginBottom: 12 },
  label: { fontSize: 13, color: C.muted, marginBottom: 6, fontWeight: '600' },
  input: { minHeight: 48, borderWidth: 1, borderColor: C.line, borderRadius: 10, paddingHorizontal: 12, fontSize: 16, color: C.ink, backgroundColor: '#fff' },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  chip: { paddingHorizontal: 12, paddingVertical: 9, borderRadius: 20, borderWidth: 1, borderColor: C.line, backgroundColor: '#fff' },
  chipOn: { backgroundColor: C.primary, borderColor: C.primary },
  chipText: { fontSize: 14, color: C.ink, textTransform: 'capitalize' },
  counter: { flexDirection: 'row', alignItems: 'center', borderWidth: 1, borderColor: C.line, borderRadius: 10, alignSelf: 'flex-start', backgroundColor: '#fff' },
  cBtn: { width: 48, height: 46, alignItems: 'center', justifyContent: 'center' },
  cVal: { minWidth: 36, textAlign: 'center', fontSize: 17, fontWeight: '700', color: C.ink },
  apRow: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 16, paddingVertical: 12, borderBottomWidth: 1, borderBottomColor: '#f1f5f9' },
  apCode: { width: 48, fontWeight: '800', fontSize: 15, color: C.primary },
  row: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 5, gap: 12 },
  rowL: { color: C.muted, fontSize: 14, flexShrink: 1 },
  rowV: { color: C.ink, fontSize: 14, fontWeight: '600', textAlign: 'right' },
  tag: { alignSelf: 'flex-start', paddingHorizontal: 8, paddingVertical: 3, borderRadius: 6 },
  steps: { flexDirection: 'row', paddingVertical: 10, backgroundColor: '#fff', borderBottomWidth: 1, borderBottomColor: C.line },
  stepDot: { width: 20, height: 20, borderRadius: 10, backgroundColor: '#e2e8f0', alignItems: 'center', justifyContent: 'center' },
});
