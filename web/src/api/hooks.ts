// TanStack Query hooks, one per API endpoint used by the UI.
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, setCsrfToken } from './client';
import type {
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
export const useDomains = () => useQuery({ queryKey: ['domains'], queryFn: lookup<Ref>('/domains'), staleTime: 60_000 });
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
