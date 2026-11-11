import React from 'react';
import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { AppProvider } from '../src/store';
import { C } from '../src/theme';

export default function RootLayout() {
  return (
    <SafeAreaProvider>
      <AppProvider>
        <StatusBar style="dark" />
        <Stack screenOptions={{ headerTintColor: C.ink, headerTitleStyle: { fontWeight: '700' }, contentStyle: { backgroundColor: C.bg }, headerBackButtonDisplayMode: 'minimal' }}>
          <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
          <Stack.Screen name="results" options={{ title: 'Results' }} />
          <Stack.Screen name="details" options={{ title: 'Details' }} />
          <Stack.Screen name="travellers" options={{ title: 'Travellers' }} />
          <Stack.Screen name="payment" options={{ title: 'Payment' }} />
          <Stack.Screen name="done" options={{ title: 'Booking', headerBackVisible: false, gestureEnabled: false }} />
          <Stack.Screen name="trip" options={{ title: 'Trip' }} />
          <Stack.Screen name="login" options={{ title: 'Sign in', presentation: 'modal' }} />
        </Stack>
      </AppProvider>
    </SafeAreaProvider>
  );
}
