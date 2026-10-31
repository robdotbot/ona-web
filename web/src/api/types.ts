// Types for the ONA REST API (see api/rest/openapi.yaml).

export interface Ref {
  id: number;
  name: string;
}

export interface ListMeta {
  page: number;
  perPage: number;
  total: number;
  totalPages: number;
}

export interface ListResponse<T> {
  data: T[];
  meta: ListMeta;
}

export interface Session {
  user: { username: string; groups: string[] };
  permissions: string[];
  csrfToken: string;
}

export interface Subnet {
  id: number;
  name: string;
  ip: string;
  cidr: number;
  netmask: string;
  network: string;
  ipVersion: 4 | 6;
  type: Ref | null;
  usage: { used: number; total: string; percent: number };
}

export interface SubnetDetail extends Subnet {
  vlan: { id: number; name: string; number: number; campus: string } | null;
  firstIp?: string;
  lastIp?: string;
  interfaceCount: number;
  hostCount: number;
}

export interface DeviceType {
  id: number;
  name: string;
  manufacturer: string;
  model: string;
  role: string;
}

export interface Location {
  id: number;
  reference: string;
  name: string;
  city: string | null;
}

export interface Host {
  id: number;
  name: string;
  fqdn: string;
  domain: Ref;
  primaryIp: string | null;
  primaryInterfaceId: number | null;
  interfaceCount: number;
  notes: string;
  deviceType: DeviceType | null;
  location: Location | null;
}

export interface HostDetail extends Host {
  primaryDnsId: number;
  interfaces: Interface[];
  dnsRecords: DnsRecord[];
}

export interface Interface {
  id: number;
  ip: string;
  ipVersion: 4 | 6;
  mac: string | null;
  name: string;
  description: string | null;
  lastResponse: string | null;
  natInterfaceId: number | null;
  host: { id: number; fqdn: string } | null;
  subnet: { id: number; name: string; network: string } | null;
}

export const DNS_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'PTR', 'SRV', 'TXT'] as const;
export type DnsType = (typeof DNS_TYPES)[number];

export interface DnsRecord {
  id: number;
  type: DnsType;
  name: string;
  fqdn: string;
  domain: Ref;
  ttl: number;
  ip: string | null;
  interfaceId: number | null;
  hostId: number | null;
  pointsTo: { id: number; fqdn: string } | null;
  notes: string;
  enabled: boolean;
  mxPreference: number | null;
  txt: string | null;
  srv: { priority: number; weight: number; port: number } | null;
}

export interface SearchResult {
  query: string;
  bestMatch: { type: 'host' | 'subnet'; id: number } | null;
  hosts: Host[];
  subnets: Subnet[];
  interfaces: Interface[];
  dnsRecords: DnsRecord[];
}

export interface ListParams {
  page?: number;
  perPage?: number;
  sort?: string;
  q?: string;
  [filter: string]: string | number | undefined;
}
