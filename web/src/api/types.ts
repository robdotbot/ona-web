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
  address: string;
  city: string;
  state: string;
  zipCode: number | null;
  latitude: string;
  longitude: string;
  misc: string;
  deviceCount?: number;
}

export interface Domain {
  id: number;
  name: string;
  shortName: string;
  parent: Ref | null;
  primaryMaster: string;
  adminEmail: string;
  serial: number;
  refresh: number;
  retry: number;
  expiry: number;
  minimum: number;
  defaultTtl: number;
  recordCount?: number;
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

export interface VlanCampus {
  id: number;
  name: string;
  vlanCount: number;
}

export interface Vlan {
  id: number;
  name: string;
  number: number;
  campus: Ref;
  subnetCount: number;
}

export interface VlanDetail extends Vlan {
  subnets: Subnet[];
}

export interface Block {
  id: number;
  name: string;
  start: string;
  end: string;
  ipVersion: 4 | 6;
  size: string;
  notes: string;
}

export interface BlockDetail extends Block {
  subnets: Subnet[];
}

export interface HostRef {
  id: number;
  fqdn: string;
}

export interface DhcpServer {
  id: number;
  host: { id: number; fqdn: string; primaryIp: string | null };
  subnetCount?: number;
}

export interface DhcpServerDetail extends DhcpServer {
  subnets: Subnet[];
  failoverGroups: DhcpFailoverGroup[];
  options: DhcpEntry[];
}

export interface DhcpFailoverGroup {
  id: number;
  primaryServer: HostRef | null;
  secondaryServer: HostRef | null;
  responseDelay: number;
  unackedUpdates: number;
  maxBalance: number;
  primaryPort: number;
  peerPort: number;
  mclt: number;
  split: number;
}

export interface DhcpPool {
  id: number;
  subnet: Ref | null;
  failoverGroupId: number | null;
  start: string;
  end: string;
  size: number;
  leaseLength: number;
  leaseGrace: number;
  leaseRenewal: number;
  leaseRebind: number;
}

export interface DhcpOption {
  id: number;
  name: string;
  displayName: string;
  number: number;
  type: string;
  typeName: string;
  system: boolean;
}

export interface DhcpEntry {
  id: number;
  option: { id: number; name: string; displayName: string; number: number; type: string };
  value: string;
  scope: 'global' | 'subnet' | 'host' | 'server';
  target: Ref | null;
}

/** Where a DHCP option or custom attribute lives (exactly one key). */
export type DhcpScope = { hostId: number } | { subnetId: number } | { serverId: number } | { global: true };
export type AttributeTarget = { hostId: number } | { subnetId: number } | { vlanId: number };

export interface CustomAttributeType {
  id: number;
  name: string;
  notes: string;
  validationRule: string;
  failedRuleText: string;
  useCount: number;
}

export interface CustomAttribute {
  id: number;
  type: Ref;
  value: string;
  target: { kind: 'host' | 'subnet' | 'vlan'; id: number };
}

export interface User {
  id: number;
  username: string;
  level: number;
  createdAt: string | null;
  lastLoginAt: string | null;
  groups: Ref[];
  permissions: string[];
}

export interface Group {
  id: number;
  name: string;
  description: string;
  memberCount: number;
  permissions: string[];
  members?: { id: number; username: string }[];
}

export interface Permission {
  id: number;
  name: string;
  description: string;
}

export interface SysConfigEntry {
  name: string;
  value: string;
  description: string;
  validationRule: string;
  failedRuleText: string;
  editable: boolean;
  deleteable: boolean;
}

export interface Plugin {
  name: string;
  version: string | null;
  description: string | null;
  helpUrl: string | null;
  enabled: boolean;
  hasInstaller: boolean;
  local: boolean;
}

export interface ListParams {
  page?: number;
  perPage?: number;
  sort?: string;
  q?: string;
  [filter: string]: string | number | undefined;
}
