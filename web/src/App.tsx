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
import { ComingSoonPage } from './pages/ComingSoonPage';
import { NotFoundPage } from './pages/NotFoundPage';
import { PLANNED_SECTIONS } from './components/nav';

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
        {PLANNED_SECTIONS.map((s) => (
          <Route key={s.path} path={s.path} element={<ComingSoonPage section={s} />} />
        ))}
        <Route path="*" element={<NotFoundPage />} />
      </Routes>
    </AppLayout>
  );
}
