// Native date picker (Android dialog / iOS inline sheet). Not used on web.
import React, { useState } from 'react';
import { Modal, Platform, Pressable, Text, View } from 'react-native';
import DateTimePicker from '@react-native-community/datetimepicker';
import { C } from '../theme';

const toISO = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const fromISO = (s?: string) => { const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || ''); return m ? new Date(+m[1], +m[2] - 1, +m[3]) : new Date(); };

export function DatePicker({ value, min, onChange, onClose }: { value: string; min?: string; onChange: (v: string | null) => void; onClose: () => void }) {
  const [d, setD] = useState(fromISO(value));
  if (Platform.OS === 'android') {
    return <DateTimePicker value={d} mode="date" minimumDate={min ? fromISO(min) : undefined} onChange={(e, v) => onChange(e.type === 'set' && v ? toISO(v) : null)} />;
  }
  return (
    <Modal transparent animationType="slide" onRequestClose={onClose}>
      <Pressable style={{ flex: 1, backgroundColor: 'rgba(0,0,0,.3)' }} onPress={onClose} />
      <View style={{ backgroundColor: '#fff', paddingBottom: 30 }}>
        <View style={{ flexDirection: 'row', justifyContent: 'space-between', padding: 12 }}>
          <Pressable onPress={onClose}><Text style={{ color: C.muted, fontSize: 16 }}>Cancel</Text></Pressable>
          <Pressable onPress={() => onChange(toISO(d))}><Text style={{ color: C.primary, fontSize: 16, fontWeight: '700' }}>Done</Text></Pressable>
        </View>
        <DateTimePicker value={d} mode="date" display="inline" minimumDate={min ? fromISO(min) : undefined} onChange={(_, v) => v && setD(v)} />
      </View>
    </Modal>
  );
}
