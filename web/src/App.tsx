import { Center, Loader } from '@mantine/core';
import { Navigate, Route, Routes } from 'react-router-dom';
import { useSession } from './api/hooks';
import { AppLayout } from './components/AppLayout';
import { LoginPage } from './pages/LoginPage';
import { SubnetsPage } from './pages/SubnetsPage';
import { SubnetDetailPage } from './pages/SubnetDetailPage';
import { HostsPage } from './pages/HostsPage';
import { HostDetailPage } from './pages/HostDetailPage';
import { DnsRecordsPage } from './pages/DnsRecordsPage';
import { SearchPage } from './pages/SearchPage';
import { NotFoundPage } from './pages/NotFoundPage';
import { VlansPage } from './pages/VlansPage';
import { VlanDetailPage } from './pages/VlanDetailPage';
import { BlocksPage } from './pages/BlocksPage';
import { BlockDetailPage } from './pages/BlockDetailPage';
import { DhcpPage } from './pages/DhcpPage';
import { DhcpServerPage } from './pages/DhcpServerPage';
import { CustomAttributesPage } from './pages/CustomAttributesPage';
import { LocationsPage } from './pages/LocationsPage';
import { DomainsPage } from './pages/DomainsPage';
import { AdminPage } from './pages/AdminPage';

export function App() {
  const session = useSession();

  if (session.isPending) {
    return (
      <Center h="100vh">
        <Loader />
      </Center>
    );
  }
  if (!session.data) return <LoginPage />;

  return (
    <AppLayout>
      <Routes>
        <Route path="/" element={<Navigate to="/subnets" replace />} />
        <Route path="/subnets" element={<SubnetsPage />} />
        <Route path="/subnets/:id" element={<SubnetDetailPage />} />
        <Route path="/hosts" element={<HostsPage />} />
        <Route path="/hosts/:id" element={<HostDetailPage />} />
        <Route path="/dns" element={<DnsRecordsPage />} />
        <Route path="/search" element={<SearchPage />} />
        <Route path="/vlans" element={<VlansPage />} />
        <Route path="/vlans/:id" element={<VlanDetailPage />} />
        <Route path="/blocks" element={<BlocksPage />} />
        <Route path="/blocks/:id" element={<BlockDetailPage />} />
        <Route path="/dhcp" element={<DhcpPage />} />
        <Route path="/dhcp/servers/:id" element={<DhcpServerPage />} />
        <Route path="/custom-attributes" element={<CustomAttributesPage />} />
        <Route path="/locations" element={<LocationsPage />} />
        <Route path="/domains" element={<DomainsPage />} />
        <Route path="/admin" element={<AdminPage />} />
        <Route path="*" element={<NotFoundPage />} />
      </Routes>
    </AppLayout>
  );
}
