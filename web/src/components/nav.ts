import {
  IconAdjustments,
  IconBoxMultiple,
  IconDevices,
  IconNetwork,
  IconRouter,
  IconServer,
  IconTags,
  IconWorld,
  type Icon,
} from '@tabler/icons-react';

export interface NavSection {
  path: string;
  label: string;
  icon: Icon;
  description?: string;
}

export const NAV_SECTIONS: NavSection[] = [
  { path: '/subnets', label: 'Subnets', icon: IconNetwork },
  { path: '/hosts', label: 'Hosts', icon: IconServer },
  { path: '/dns', label: 'DNS records', icon: IconWorld },
];

/**
 * Areas the legacy UI has that this UI doesn't yet. Each gets a placeholder
 * page; to build one, add API endpoints (api/rest) and swap the route.
 */
export const PLANNED_SECTIONS: NavSection[] = [
  { path: '/vlans', label: 'VLANs', icon: IconRouter, description: 'VLANs and VLAN campuses' },
  { path: '/blocks', label: 'Blocks', icon: IconBoxMultiple, description: 'IP address blocks' },
  { path: '/dhcp', label: 'DHCP', icon: IconDevices, description: 'DHCP servers, pools, failover groups and options' },
  { path: '/custom-attributes', label: 'Custom attributes', icon: IconTags, description: 'Custom attribute types and values' },
  { path: '/admin', label: 'Admin', icon: IconAdjustments, description: 'Users, groups, permissions, system configuration and plugins' },
];
