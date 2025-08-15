import React, { useState, useEffect } from 'react';
import { Routes, Route, useNavigate, useLocation } from 'react-router-dom';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

// Components
import Sidebar from './components/Sidebar';
import Dashboard from './pages/Dashboard';
import UserRestrictions from './pages/UserRestrictions';
import RoleRestrictions from './pages/RoleRestrictions';
import PluginControl from './pages/PluginControl';
import ActivityLogs from './pages/ActivityLogs';
import ImportExport from './pages/ImportExport';
import Settings from './pages/Settings';
import Loading from './components/Loading';
import ErrorBoundary from './components/ErrorBoundary';

// Context
import { AppProvider } from './context/AppContext';

const App = () => {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [appData, setAppData] = useState(null);
  const navigate = useNavigate();
  const location = useLocation();

  // Initialize app data
  useEffect(() => {
    initializeApp();
  }, []);

  // Handle page navigation based on URL params
  useEffect(() => {
    const urlParams = new URLSearchParams(window.location.search);
    const page = urlParams.get('page');

    if (page && location.pathname === '/') {
      switch (page) {
        case 'access-control-pro':
          navigate('/dashboard');
          break;
        case 'acp-user-restrictions':
          navigate('/user-restrictions');
          break;
        case 'acp-role-restrictions':
          navigate('/role-restrictions');
          break;
        case 'acp-plugin-control':
          navigate('/plugin-control');
          break;
        case 'acp-logs':
          navigate('/logs');
          break;
        case 'acp-import-export':
          navigate('/import-export');
          break;
        case 'acp-settings':
          navigate('/settings');
          break;
        default:
          navigate('/dashboard');
      }
    }
  }, [navigate, location]);

  const initializeApp = async () => {
    try {
      // Set up API fetch defaults
      apiFetch.use(apiFetch.createNonceMiddleware(window.acpAdminData.nonce));
      apiFetch.use(apiFetch.createRootURLMiddleware(window.acpAdminData.restUrl));

      // Get initial data
      const [users, roles, plugins, adminMenu, stats] = await Promise.all([
        apiFetch({ path: '/acp/v1/users' }),
        apiFetch({ path: '/acp/v1/roles' }),
        apiFetch({ path: '/acp/v1/plugins' }),
        apiFetch({ path: '/acp/v1/admin-menu' }),
        apiFetch({ path: '/acp/v1/dashboard/stats' })
      ]);

      setAppData({
        users,
        roles,
        plugins,
        adminMenu,
        stats,
        ...window.acpAdminData
      });

      setLoading(false);
    } catch (err) {
      console.error('Failed to initialize app:', err);
      setError(__('Failed to load application data. Please refresh the page.', 'access-control-pro'));
      setLoading(false);
    }
  };

  if (loading) {
    return <Loading message={__('Loading Access Control Pro...', 'access-control-pro')} />;
  }

  if (error) {
    return (
      <div className="acp-error-container">
        <div className="acp-error-message">
          <h2>{__('Error', 'access-control-pro')}</h2>
          <p>{error}</p>
          <button
            className="button button-primary"
            onClick={() => window.location.reload()}
          >
            {__('Refresh Page', 'access-control-pro')}
          </button>
        </div>
      </div>
    );
  }

  return (
    <ErrorBoundary>
      <AppProvider value={appData}>
        <div className="acp-admin-container">
          <Sidebar />
          <main className="acp-main-content">
            <Routes>
              <Route path="/" element={<Dashboard />} />
              <Route path="/dashboard" element={<Dashboard />} />
              <Route path="/user-restrictions" element={<UserRestrictions />} />
              <Route path="/role-restrictions" element={<RoleRestrictions />} />
              <Route path="/plugin-control" element={<PluginControl />} />
              {appData.isPro && (
                <>
                  <Route path="/logs" element={<ActivityLogs />} />
                  <Route path="/import-export" element={<ImportExport />} />
                </>
              )}
              <Route path="/settings" element={<Settings />} />
            </Routes>
          </main>
        </div>
      </AppProvider>
    </ErrorBoundary>
  );
};

export default App;
