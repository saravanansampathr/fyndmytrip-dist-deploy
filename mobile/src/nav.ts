import { router } from 'expo-router';
import { flow } from './store';

export function startSearch(svcKey: string, q: Record<string, any>) {
  flow.query[svcKey] = q;
  router.push({ pathname: '/results', params: { svc: svcKey } });
}
