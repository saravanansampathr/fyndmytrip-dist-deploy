import React from 'react';
import { ActivityIndicator, View } from 'react-native';
import { Tabs } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { C } from '../../src/theme';
import { useApp } from '../../src/store';

export default function TabsLayout() {
  const { partner, ready } = useApp();
  // Wait until the saved sign-in is restored, so a partner's tab exists
  // before the first screen is chosen.
  if (!ready) return <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: C.bg }}><ActivityIndicator color={C.primary} /></View>;
  return (
    <Tabs screenOptions={{ tabBarActiveTintColor: C.primary, headerTitleStyle: { fontWeight: '700' }, sceneStyle: { backgroundColor: C.bg } }}>
      <Tabs.Screen name="index" options={{ title: 'Book', headerShown: false, tabBarIcon: ({ color, size }) => <Ionicons name="search" color={color as string} size={size} /> }} />
      <Tabs.Screen name="partner" options={{ title: 'Partner', href: partner ? undefined : null, tabBarIcon: ({ color, size }) => <Ionicons name="stats-chart" color={color as string} size={size} /> }} />
      <Tabs.Screen name="trips" options={{ title: partner ? 'Bookings' : 'My Trips', tabBarIcon: ({ color, size }) => <Ionicons name="briefcase" color={color as string} size={size} /> }} />
      <Tabs.Screen name="account" options={{ title: 'Account', tabBarIcon: ({ color, size }) => <Ionicons name="person-circle" color={color as string} size={size} /> }} />
    </Tabs>
  );
}
