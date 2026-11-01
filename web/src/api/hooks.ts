// TanStack Query hooks, one per API endpoint used by the UI.
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, setCsrfToken } from './client';
import type {
  AttributeTarget,
  Block,
  BlockDetail,
  CustomAttribute,
  CustomAttributeType,
  DhcpEntry,
  DhcpFailoverGroup,
  DhcpOption,
  DhcpPool,
  DhcpScope,
  DhcpServer,
  DhcpServerDetail,
  Domain,
  Group,
  Permission,
  Plugin,
  SysConfigEntry,
  User,
  Vlan,
  VlanCampus,
  VlanDetail,
  DeviceType,
  DnsRecord,
  Host,
  HostDetail,
  Interface,
  ListParams,
  ListResponse,
  Location,
  Ref,
  SearchResult,
  Session,
  Subnet,
  SubnetDetail,
} from './types';

// ---- Session --------------------------------------------------------------

export function useSession() {
  return useQuery({
    queryKey: ['session'],
    queryFn: async () => {
      const s = await api<Session>('GET', '/auth/session');
      setCsrfToken(s.csrfToken);
      return s;
    },
    retry: false,
    staleTime: 5 * 60_000,
  });
}

export function useLogin() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: { username: string; password: string }) => api<Session>('POST', '/auth/login', { body }),
    onSuccess: (s) => {
      setCsrfToken(s.csrfToken);
      // Drop data cached for any previous user, but keep the session query
      // itself so components watching it re-render.
      qc.removeQueries({ predicate: (q) => q.queryKey[0] !== 'session' });
      qc.setQueryData(['session'], s);
    },
  });
}

export function useLogout() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => api<void>('POST', '/auth/logout'),
    onSettled: () => {
      setCsrfToken(null);
      qc.removeQueries({ predicate: (q) => q.queryKey[0] !== 'session' });
      qc.setQueryData(['session'], null);
    },
  });
}

/** True if the logged-in user has an ONA permission (the API still enforces it). */
export function useCan(permission: string): boolean {
  const { data } = useSession();
  return !!data?.permissions.includes(permission);
}

// ---- Lookups --------------------------------------------------------------

const lookup = <T,>(path: string) => () => api<{ data: T[] }>('GET', path).then((r) => r.data);

export const useSubnetTypes = () =>
  useQuery({ queryKey: ['subnet-types'], queryFn: lookup<Ref & { shortName: string }>('/subnet-types'), staleTime: Infinity });
export const useDeviceTypes = () =>
  useQuery({ queryKey: ['device-types'], queryFn: lookup<DeviceType>('/device-types'), staleTime: Infinity });
export const useDomains = () => useQuery({ queryKey: ['domains'], queryFn: lookup<Domain>('/domains'), staleTime: 60_000 });
export const useLocations = () =>
  useQuery({ queryKey: ['locations'], queryFn: lookup<Location>('/locations'), staleTime: 60_000 });

// ---- Generic list / detail -----------------------------------------------

function useList<T>(resource: string, params: ListParams, enabled = true) {
  return useQuery({
    queryKey: [resource, 'list', params],
    queryFn: () => api<ListResponse<T>>('GET', `/${resource}`, { query: params }),
    placeholderData: keepPreviousData,
    enabled,
  });
}

function useDetail<T>(resource: string, id: number | undefined) {
  return useQuery({
    queryKey: [resource, 'detail', id],
    queryFn: () => api<T>('GET', `/${resource}/${id}`),
    enabled: id !== undefined && !Number.isNaN(id),
  });
}

/**
 * Create/update/delete for a resource. Any write can change usage counts,
 * host summaries and DNS lists elsewhere, so invalidate all cached data.
 */
function useWrite<TBody, TResult>(fn: (body: TBody) => Promise<TResult>) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: fn,
    onSuccess: () => qc.invalidateQueries({ predicate: (q) => q.queryKey[0] !== 'session' }),
  });
}

// ---- Subnets --------------------------------------------------------------

export interface SubnetInput {
  name?: string;
  ip?: string;
  netmask?: string;
  typeId?: number;
  /** 0 detaches the subnet from its VLAN. */
  vlanId?: number;
}

export const useSubnets = (p: ListParams) => useList<Subnet>('subnets', p);
export const useSubnet = (id?: number) => useDetail<SubnetDetail>('subnets', id);
export const useCreateSubnet = () => useWrite((b: SubnetInput) => api<SubnetDetail>('POST', '/subnets', { body: b }));
export const useUpdateSubnet = (id: number) =>
  useWrite((b: SubnetInput) => api<SubnetDetail>('PATCH', `/subnets/${id}`, { body: b }));
export const useDeleteSubnet = () => useWrite((id: number) => api<void>('DELETE', `/subnets/${id}`));
export const fetchNextIp = (subnetId: number) =>
  api<{ ip: string }>('GET', `/subnets/${subnetId}/next-ip`).then((r) => r.ip);

// ---- Hosts ----------------------------------------------------------------

export interface HostInput {
  name?: string;
  deviceTypeId?: number;
  ip?: string;
  notes?: string;
  locationId?: number;
  mac?: string;
  interfaceName?: string;
  interfaceDescription?: string;
  addPtr?: boolean;
}

export const useHosts = (p: ListParams) => useList<Host>('hosts', p);
export const useHost = (id?: number) => useDetail<HostDetail>('hosts', id);
export const useCreateHost = () => useWrite((b: HostInput) => api<HostDetail>('POST', '/hosts', { body: b }));
export const useUpdateHost = (id: number) =>
  useWrite((b: HostInput) => api<HostDetail>('PATCH', `/hosts/${id}`, { body: b }));
export const useDeleteHost = () => useWrite((id: number) => api<void>('DELETE', `/hosts/${id}`));

// ---- Interfaces -----------------------------------------------------------

export interface InterfaceInput {
  hostId?: number;
  ip?: string;
  mac?: string;
  name?: string;
  description?: string;
  addPtr?: boolean;
}

export const useInterfaces = (p: ListParams, enabled = true) => useList<Interface>('interfaces', p, enabled);
export const useCreateInterface = () =>
  useWrite((b: InterfaceInput) => api<Interface>('POST', '/interfaces', { body: b }));
export const useUpdateInterface = () =>
  useWrite(({ id, ...b }: InterfaceInput & { id: number }) => api<Interface>('PATCH', `/interfaces/${id}`, { body: b }));
export const useDeleteInterface = () => useWrite((id: number) => api<void>('DELETE', `/interfaces/${id}`));

// ---- DNS records ----------------------------------------------------------

export interface DnsRecordInput {
  name?: string;
  type?: string;
  ip?: string;
  pointsTo?: string;
  ttl?: number;
  notes?: string;
  mxPreference?: number;
  txt?: string;
  srvPriority?: number;
  srvWeight?: number;
  srvPort?: number;
  addPtr?: boolean;
}

export const useDnsRecords = (p: ListParams) => useList<DnsRecord>('dns-records', p);
export const useCreateDnsRecord = () =>
  useWrite((b: DnsRecordInput) => api<DnsRecord>('POST', '/dns-records', { body: b }));
export const useUpdateDnsRecord = () =>
  useWrite(({ id, ...b }: DnsRecordInput & { id: number }) => api<DnsRecord>('PATCH', `/dns-records/${id}`, { body: b }));
export const useDeleteDnsRecord = () => useWrite((id: number) => api<void>('DELETE', `/dns-records/${id}`));

// ---- Search ---------------------------------------------------------------

export const useSearch = (q: string) =>
  useQuery({
    queryKey: ['search', q],
    queryFn: () => api<SearchResult>('GET', '/search', { query: { q } }),
    enabled: q.trim() !== '',
  });

export const quickSearch = (q: string) => api<SearchResult>('GET', '/search', { query: { q } });

// ---- Shared CRUD helpers for the remaining resources ------------------------

type Body = Record<string, unknown>;
const all = <T,>(path: string) => () => api<{ data: T[] }>('GET', path).then((r) => r.data);
const crud = <T,>(path: string) => ({
  create: () => useWrite((b: Body) => api<T>('POST', path, { body: b })),
  update: () => useWrite(({ id, ...b }: Body & { id: number | string }) => api<T>('PATCH', `${path}/${id}`, { body: b })),
  remove: () => useWrite((id: number | string) => api<void>('DELETE', `${path}/${id}`)),
});

// ---- VLANs ----------------------------------------------------------------

export const useVlanCampuses = (p: ListParams) => useList<VlanCampus>('vlan-campuses', p);
export const useAllVlanCampuses = () =>
  useQuery({ queryKey: ['vlan-campuses', 'all'], queryFn: () => api<ListResponse<VlanCampus>>('GET', '/vlan-campuses', { query: { perPage: 200 } }).then((r) => r.data) });
const campuses = crud<VlanCampus>('/vlan-campuses');
export const useCreateVlanCampus = campuses.create;
export const useUpdateVlanCampus = campuses.update;
export const useDeleteVlanCampus = campuses.remove;

export const useVlans = (p: ListParams) => useList<Vlan>('vlans', p);
export const useAllVlans = () =>
  useQuery({ queryKey: ['vlans', 'all'], queryFn: () => api<ListResponse<Vlan>>('GET', '/vlans', { query: { perPage: 200 } }).then((r) => r.data) });
export const useVlan = (id?: number) => useDetail<VlanDetail>('vlans', id);
const vlans = crud<VlanDetail>('/vlans');
export const useCreateVlan = vlans.create;
export const useUpdateVlan = vlans.update;
export const useDeleteVlan = vlans.remove;

// ---- Blocks ---------------------------------------------------------------

export const useBlocks = (p: ListParams) => useList<Block>('blocks', p);
export const useBlock = (id?: number) => useDetail<BlockDetail>('blocks', id);
const blocks = crud<BlockDetail>('/blocks');
export const useCreateBlock = blocks.create;
export const useUpdateBlock = blocks.update;
export const useDeleteBlock = blocks.remove;

// ---- DHCP -----------------------------------------------------------------

export const useDhcpServers = () => useQuery({ queryKey: ['dhcp-servers', 'all'], queryFn: all<DhcpServer>('/dhcp-servers') });
export const useDhcpServer = (id?: number) => useDetail<DhcpServerDetail>('dhcp-servers', id);
export const useAddDhcpServerSubnet = () =>
  useWrite((b: { hostId: number; subnetId: number }) => api<DhcpServerDetail>('POST', '/dhcp-servers', { body: b }));
export const useRemoveDhcpServerSubnet = () =>
  useWrite(({ hostId, subnetId }: { hostId: number; subnetId: number }) => api<void>('DELETE', `/dhcp-servers/${hostId}/subnets/${subnetId}`));

export const useDhcpFailoverGroups = () =>
  useQuery({ queryKey: ['dhcp-failover-groups', 'all'], queryFn: all<DhcpFailoverGroup>('/dhcp-failover-groups') });
const groupsCrud = crud<DhcpFailoverGroup>('/dhcp-failover-groups');
export const useCreateDhcpFailoverGroup = groupsCrud.create;
export const useUpdateDhcpFailoverGroup = groupsCrud.update;
export const useDeleteDhcpFailoverGroup = groupsCrud.remove;

export const useDhcpPools = (p: ListParams, enabled = true) => useList<DhcpPool>('dhcp-pools', p, enabled);
const pools = crud<DhcpPool>('/dhcp-pools');
export const useCreateDhcpPool = pools.create;
export const useUpdateDhcpPool = pools.update;
export const useDeleteDhcpPool = pools.remove;

export const useDhcpOptions = () =>
  useQuery({ queryKey: ['dhcp-options'], queryFn: all<DhcpOption>('/dhcp-options'), staleTime: Infinity });

const scopeQuery = (scope: DhcpScope) =>
  'global' in scope ? { global: 'true' } : (scope as Record<string, number>);
export const useDhcpEntries = (scope: DhcpScope, enabled = true) =>
  useQuery({
    queryKey: ['dhcp-entries', 'list', scope],
    queryFn: () => api<{ data: DhcpEntry[] }>('GET', '/dhcp-entries', { query: scopeQuery(scope) }).then((r) => r.data),
    enabled,
  });
const entries = crud<DhcpEntry>('/dhcp-entries');
export const useCreateDhcpEntry = entries.create;
export const useUpdateDhcpEntry = entries.update;
export const useDeleteDhcpEntry = entries.remove;

// ---- Custom attributes ----------------------------------------------------

export const useCustomAttributeTypes = () =>
  useQuery({ queryKey: ['custom-attribute-types', 'all'], queryFn: all<CustomAttributeType>('/custom-attribute-types') });
const caTypes = crud<CustomAttributeType>('/custom-attribute-types');
export const useCreateCustomAttributeType = caTypes.create;
export const useUpdateCustomAttributeType = caTypes.update;
export const useDeleteCustomAttributeType = caTypes.remove;

export const useCustomAttributes = (p: ListParams | AttributeTarget, enabled = true) =>
  useList<CustomAttribute>('custom-attributes', { perPage: 200, ...(p as ListParams) }, enabled);
const attrs = crud<CustomAttribute>('/custom-attributes');
export const useCreateCustomAttribute = attrs.create;
export const useUpdateCustomAttribute = attrs.update;
export const useDeleteCustomAttribute = attrs.remove;

// ---- Locations & domains --------------------------------------------------

const locations = crud<Location>('/locations');
export const useCreateLocation = locations.create;
export const useUpdateLocation = locations.update;
export const useDeleteLocation = locations.remove;
const domains = crud<Domain>('/domains');
export const useCreateDomain = domains.create;
export const useUpdateDomain = domains.update;
export const useDeleteDomain = domains.remove;

// ---- Admin ----------------------------------------------------------------

export const useUsers = (enabled = true) => useQuery({ queryKey: ['users', 'all'], queryFn: all<User>('/users'), enabled });
const users = crud<User>('/users');
export const useCreateUser = users.create;
export const useUpdateUser = users.update;
export const useDeleteUser = users.remove;

export const useGroups = (enabled = true) => useQuery({ queryKey: ['groups', 'all'], queryFn: all<Group>('/groups'), enabled });
export const useGroup = (id?: number) => useDetail<Group>('groups', id);
const groups = crud<Group>('/groups');
export const useCreateGroup = groups.create;
export const useUpdateGroup = groups.update;
export const useDeleteGroup = groups.remove;

export const usePermissions = (enabled = true) =>
  useQuery({ queryKey: ['permissions'], queryFn: all<Permission>('/permissions'), enabled, staleTime: Infinity });

export const useSysConfig = (enabled = true) => useQuery({ queryKey: ['sys-config', 'all'], queryFn: all<SysConfigEntry>('/sys-config'), enabled });
const sysConfig = crud<SysConfigEntry>('/sys-config');
export const useCreateSysConfig = sysConfig.create;
export const useUpdateSysConfig = sysConfig.update;
export const useDeleteSysConfig = sysConfig.remove;

export const usePlugins = (enabled = true) => useQuery({ queryKey: ['plugins', 'all'], queryFn: all<Plugin>('/plugins'), enabled });
export const useSetPluginEnabled = () =>
  useWrite(({ name, enabled }: { name: string; enabled: boolean }) => api<Plugin>('PATCH', `/plugins/${encodeURIComponent(name)}`, { body: { enabled } }));

// ---- Your own account -----------------------------------------------------

export const useChangePassword = () =>
  useMutation({
    mutationFn: (b: { currentPassword: string; newPassword: string }) => api<void>('POST', '/auth/password', { body: b }),
  });
