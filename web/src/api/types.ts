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
  tags: string[];
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
  servers: { id: number; server: HostRef; role: DnsServerRole }[];
}

export type DnsServerRole = 'master' | 'slave' | 'forward';

export interface DomainServer {
  id: number;
  domain: Ref;
  server: HostRef;
  role: DnsServerRole;
  rebuildPending: boolean;
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
  tags: string[];
}

export interface InterfaceShare {
  hostId: number;
  fqdn: string;
  name: string;
}

export interface HostDetail extends Host {
  primaryDnsId: number;
  interfaces: (Interface & { sharedWith: InterfaceShare[] })[];
  /** Other hosts' interfaces shared with this host. */
  sharedInterfaces: Interface[];
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
  /** Only on single-interface and host detail responses. */
  sharedWith?: InterfaceShare[];
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
  /** Active now (its begin date is not in the future). */
  enabled: boolean;
  /** Begin date; null when disabled. A future date means scheduled. */
  activeFrom: string | null;
  view: Ref;
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

// ---- Reference data ---------------------------------------------------------

interface ReferenceCommon {
  id: number;
  /** Records using this row; it can only be deleted at 0. */
  inUse: number;
  /** Built in: can't be changed or deleted. */
  system?: boolean;
}
export interface Manufacturer extends ReferenceCommon { name: string }
export interface Model extends ReferenceCommon { name: string; manufacturerId: number; snmpSysObjectId: string }
export interface Role extends ReferenceCommon { name: string }
export interface DeviceTypeRecord extends ReferenceCommon, Omit<DeviceType, 'id'> { modelId: number; roleId: number }
export interface SubnetType extends ReferenceCommon { name: string; shortName: string; notes: string }
export interface DhcpOptionRecord extends ReferenceCommon, Omit<DhcpOption, 'id' | 'system'> {}
export interface ConfigType extends ReferenceCommon { name: string }
export interface DnsView extends ReferenceCommon { name: string; description: string }

// ---- Tags, messages ---------------------------------------------------------

export type TagTarget = { hostId: number } | { subnetId: number };

export interface Tag {
  id: number;
  name: string;
  target: { kind: 'host' | 'subnet'; id: number };
}

export interface TagName {
  name: string;
  hostCount: number;
  subnetCount: number;
}

export interface Message {
  id: number;
  target: { kind: 'host' | 'subnet'; id: number };
  priority: 0 | 1 | 2 | 3;
  priorityName: 'info' | 'high' | 'medium' | 'low';
  message: string;
  username: string;
  createdAt: string;
  expiration: string | null;
  expired: boolean;
}

// ---- Config archives, reports, change log -----------------------------------

export interface Config {
  id: number;
  host: HostRef;
  type: Ref;
  md5: string;
  size: number;
  createdAt: string;
}

export interface ConfigDetail extends Config {
  text: string;
}

export interface ConfigDiff {
  from: Config;
  to: Config;
  diff: string;
}

export interface Report {
  name: string;
  description: string;
}

export interface ReportUsage extends Report {
  usage: string;
}

export interface ReportOutput {
  name: string;
  format: 'text' | 'csv';
  output: string;
}

export interface LogEntry {
  id: number;
  timestamp: string;
  username: string;
  remoteAddr: string;
  context: string;
  message: string;
}

export interface SubnetMap {
  subnetId: number;
  network: string;
  size: number;
  reserved: number[];
  used: { offset: number; ip: string; interfaceId: number; host: HostRef | null }[];
  pools: { id: number; start: string; end: string; startOffset: number; endOffset: number }[];
}

export interface InterfaceMoveResult {
  committed: boolean;
  moves: { from: string; to: string; host: string }[];
}
