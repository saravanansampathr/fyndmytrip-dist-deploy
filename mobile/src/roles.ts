// Who is a "partner" in the app: anyone who sells or manages travel rather
// than a direct traveller. Partners get the Partner tab, see bookings of
// their whole account, can pay from wallet + credit, and see B2B fare lines.
import type { User } from './api';

export const PARTNER_ROLES = ['agent', 'sub_agent', 'partner_admin', 'staff_user', 'api_user', 'staff', 'super_admin'];
export const isPartner = (u?: User | null) => !!u && !u.is_guest && PARTNER_ROLES.includes(String(u.role));

export const ROLE_LABEL: Record<string, string> = {
  agent: 'Travel agent', sub_agent: 'Sub-agent', partner_admin: 'White-label partner', staff_user: 'Partner staff',
  api_user: 'API partner', staff: 'FyndMyTrip staff', super_admin: 'FyndMyTrip admin', customer: 'Customer',
};
