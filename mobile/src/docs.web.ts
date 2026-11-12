// Web build (tests): fetch the PDF and save it through the browser.
import { apiHeaders, apiUrl } from './api';

export async function openDocument(path: string, name: string) {
  const res = await fetch(apiUrl(path), { headers: apiHeaders() });
  if (!res.ok) throw new Error(`Download failed (${res.status})`);
  const blob = await res.blob();
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob); a.download = name; a.click();
}
