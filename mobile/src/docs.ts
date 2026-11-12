// Download a ticket, voucher or invoice PDF with the sign-in token and open
// the phone's share / preview sheet so it can be saved or sent.
import * as Sharing from 'expo-sharing';
import { File, Paths } from 'expo-file-system';
import { apiHeaders, apiUrl } from './api';

export async function openDocument(path: string, name: string) {
  const dest = new File(Paths.cache, name);
  try { if (dest.exists) dest.delete(); } catch { /* fresh file */ }
  const file = await File.downloadFileAsync(apiUrl(path), dest, { headers: apiHeaders(), idempotent: true } as any);
  if (await Sharing.isAvailableAsync()) await Sharing.shareAsync(file.uri, { mimeType: 'application/pdf', UTI: 'com.adobe.pdf', dialogTitle: name });
}
