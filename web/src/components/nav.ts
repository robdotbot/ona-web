import {
  IconAdjustments,
  IconBoxMultiple,
  IconDevices,
  IconMapPin,
  IconNetwork,
  IconRouter,
  IconServer,
  IconSitemap,
  IconTags,
  IconWorld,
  type Icon,
} from '@tabler/icons-react';

export interface NavSection {
  path: string;
  label: string;
  icon: Icon;
  /** Shown only when the session has at least one of these permissions. */
  anyPermission?: string[];
}

export const NAV_GROUPS: { label?: string; sections: NavSection[] }[] = [
  {
    sections: [
      { path: '/subnets', label: 'Subnets', icon: IconNetwork },
      { path: '/hosts', label: 'Hosts', icon: IconServer },
      { path: '/dns', label: 'DNS records', icon: IconWorld },
    ],
  },
  {
    label: 'Network',
    sections: [
      { path: '/vlans', label: 'VLANs', icon: IconRouter },
      { path: '/blocks', label: 'Blocks', icon: IconBoxMultiple },
      { path: '/dhcp', label: 'DHCP', icon: IconDevices },
      { path: '/domains', label: 'Domains', icon: IconSitemap },
      { path: '/locations', label: 'Locations', icon: IconMapPin },
      { path: '/custom-attributes', label: 'Custom attributes', icon: IconTags },
    ],
  },
  {
    label: 'Administration',
    sections: [{ path: '/admin', label: 'Admin', icon: IconAdjustments, anyPermission: ['user_admin', 'advanced'] }],
  },
];
