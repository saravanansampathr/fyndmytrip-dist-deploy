import React from 'react';
import { Tabs } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { C } from '../../src/theme';

export default function TabsLayout() {
  return (
    <Tabs screenOptions={{ tabBarActiveTintColor: C.primary, headerTitleStyle: { fontWeight: '700' }, sceneStyle: { backgroundColor: C.bg } }}>
      <Tabs.Screen name="index" options={{ title: 'Book', headerShown: false, tabBarIcon: ({ color, size }) => <Ionicons name="search" color={color as string} size={size} /> }} />
      <Tabs.Screen name="trips" options={{ title: 'My Trips', tabBarIcon: ({ color, size }) => <Ionicons name="briefcase" color={color as string} size={size} /> }} />
      <Tabs.Screen name="account" options={{ title: 'Account', tabBarIcon: ({ color, size }) => <Ionicons name="person-circle" color={color as string} size={size} /> }} />
    </Tabs>
  );
}
