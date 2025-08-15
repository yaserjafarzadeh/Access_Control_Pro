import React, { useState, useEffect } from 'react';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useAppContext } from '../context/AppContext';
import Loading from '../components/Loading';

const ActivityLogs = () => {
  const { users, setError } = useAppContext();
  const [loading, setLoading] = useState(false);
  const [logs, setLogs] = useState([]);
  const [currentPage, setCurrentPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [filters, setFilters] = useState({
    user_id: '',
    action: '',
    object_type: '',
    date_from: '',
    date_to: ''
  });
  const [showFilters, setShowFilters] = useState(false);

  const logsPerPage = 20;

  useEffect(() => {
    loadLogs();
  }, [currentPage, filters]);

  const loadLogs = async () => {
    try {
      setLoading(true);

      const queryParams = new URLSearchParams({
        page: currentPage,
        per_page: logsPerPage,
        ...Object.fromEntries(Object.entries(filters).filter(([, value]) => value))
      });

      const response = await apiFetch({
        path: `/acp/v1/logs?${queryParams.toString()}`
      });

      setLogs(response.logs || []);
      setTotalPages(Math.ceil((response.total || 0) / logsPerPage));
    } catch (error) {
      setError(__('Failed to load activity logs', 'access-control-pro'));
    } finally {
      setLoading(false);
    }
  };

  const handleFilterChange = (key, value) => {
    setFilters(prev => ({
      ...prev,
      [key]: value
    }));
    setCurrentPage(1); // Reset to first page when filtering
  };

  const clearFilters = () => {
    setFilters({
      user_id: '',
      action: '',
      object_type: '',
      date_from: '',
      date_to: ''
    });
    setCurrentPage(1);
  };

  const exportLogs = async () => {
    try {
      setLoading(true);

      const queryParams = new URLSearchParams({
        export: 'csv',
        ...Object.fromEntries(Object.entries(filters).filter(([, value]) => value))
      });

      const response = await apiFetch({
        path: `/acp/v1/logs/export?${queryParams.toString()}`,
        parse: false
      });

      const blob = await response.blob();
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `activity-logs-${new Date().toISOString().split('T')[0]}.csv`;
      document.body.appendChild(a);
      a.click();
      window.URL.revokeObjectURL(url);
      document.body.removeChild(a);
    } catch (error) {
      setError(__('Failed to export logs', 'access-control-pro'));
    } finally {
      setLoading(false);
    }
  };

  const clearOldLogs = async () => {
    if (!confirm(__('This will permanently delete logs older than 30 days. Are you sure?', 'access-control-pro'))) {
      return;
    }

    try {
      await apiFetch({
        path: '/acp/v1/logs/cleanup',
        method: 'POST'
      });

      alert(__('Old logs cleared successfully!', 'access-control-pro'));
      loadLogs();
    } catch (error) {
      setError(__('Failed to clear old logs', 'access-control-pro'));
    }
  };

  const getActionIcon = (action) => {
    const icons = {
      'restriction_added': '➕',
      'restriction_updated': '✏️',
      'restriction_deleted': '❌',
      'user_blocked': '🚫',
      'plugin_restricted': '🔌',
      'role_restricted': '👥',
      'login_blocked': '🔒',
      'access_denied': '⛔',
      'settings_updated': '⚙️'
    };

    return icons[action] || '📝';
  };

  const getActionText = (action) => {
    const actions = {
      'restriction_added': __('Added restriction', 'access-control-pro'),
      'restriction_updated': __('Updated restriction', 'access-control-pro'),
      'restriction_deleted': __('Deleted restriction', 'access-control-pro'),
      'user_blocked': __('Blocked user access', 'access-control-pro'),
      'plugin_restricted': __('Restricted plugin access', 'access-control-pro'),
      'role_restricted': __('Restricted role access', 'access-control-pro'),
      'login_blocked': __('Blocked login attempt', 'access-control-pro'),
      'access_denied': __('Access denied', 'access-control-pro'),
      'settings_updated': __('Updated settings', 'access-control-pro')
    };

    return actions[action] || action.replace(/_/g, ' ');
  };

  const formatDate = (dateString) => {
    const date = new Date(dateString);
    return date.toLocaleString();
  };

  const getUserName = (userId) => {
    const user = users.find(u => u.id == userId);
    return user ? user.name : __('Unknown User', 'access-control-pro');
  };

  return (
    <div className="acp-activity-logs">
      <div className="acp-page-header">
        <h1 className="acp-page-title">
          {__('Activity Logs', 'access-control-pro')}
          <span className="acp-pro-badge">{__('PRO', 'access-control-pro')}</span>
        </h1>
        <p className="acp-page-subtitle">
          {__('Monitor all access control activities and security events', 'access-control-pro')}
        </p>
      </div>

      {/* Controls */}
      <div className="acp-logs-controls">
        <div className="acp-logs-controls__left">
          <button
            className={`acp-btn acp-btn--secondary ${showFilters ? 'acp-btn--active' : ''}`}
            onClick={() => setShowFilters(!showFilters)}
          >
            {__('Filters', 'access-control-pro')} {Object.values(filters).some(v => v) && '●'}
          </button>

          {Object.values(filters).some(v => v) && (
            <button
              className="acp-btn acp-btn--link"
              onClick={clearFilters}
            >
              {__('Clear Filters', 'access-control-pro')}
            </button>
          )}
        </div>

        <div className="acp-logs-controls__right">
          <button
            className="acp-btn acp-btn--secondary"
            onClick={exportLogs}
            disabled={loading}
          >
            {__('Export CSV', 'access-control-pro')}
          </button>

          <button
            className="acp-btn acp-btn--danger"
            onClick={clearOldLogs}
          >
            {__('Clear Old Logs', 'access-control-pro')}
          </button>
        </div>
      </div>

      {/* Filters */}
      {showFilters && (
        <div className="acp-logs-filters">
          <div className="acp-filters-grid">
            <div className="acp-filter-group">
              <label className="acp-filter-label">{__('User', 'access-control-pro')}</label>
              <select
                className="acp-filter-select"
                value={filters.user_id}
                onChange={(e) => handleFilterChange('user_id', e.target.value)}
              >
                <option value="">{__('All Users', 'access-control-pro')}</option>
                {users.map(user => (
                  <option key={user.id} value={user.id}>
                    {user.name} ({user.email})
                  </option>
                ))}
              </select>
            </div>

            <div className="acp-filter-group">
              <label className="acp-filter-label">{__('Action', 'access-control-pro')}</label>
              <select
                className="acp-filter-select"
                value={filters.action}
                onChange={(e) => handleFilterChange('action', e.target.value)}
              >
                <option value="">{__('All Actions', 'access-control-pro')}</option>
                <option value="restriction_added">{__('Restriction Added', 'access-control-pro')}</option>
                <option value="restriction_updated">{__('Restriction Updated', 'access-control-pro')}</option>
                <option value="restriction_deleted">{__('Restriction Deleted', 'access-control-pro')}</option>
                <option value="user_blocked">{__('User Blocked', 'access-control-pro')}</option>
                <option value="plugin_restricted">{__('Plugin Restricted', 'access-control-pro')}</option>
                <option value="access_denied">{__('Access Denied', 'access-control-pro')}</option>
              </select>
            </div>

            <div className="acp-filter-group">
              <label className="acp-filter-label">{__('Object Type', 'access-control-pro')}</label>
              <select
                className="acp-filter-select"
                value={filters.object_type}
                onChange={(e) => handleFilterChange('object_type', e.target.value)}
              >
                <option value="">{__('All Types', 'access-control-pro')}</option>
                <option value="user">{__('User', 'access-control-pro')}</option>
                <option value="role">{__('Role', 'access-control-pro')}</option>
                <option value="plugin">{__('Plugin', 'access-control-pro')}</option>
                <option value="menu">{__('Menu', 'access-control-pro')}</option>
                <option value="content">{__('Content', 'access-control-pro')}</option>
              </select>
            </div>

            <div className="acp-filter-group">
              <label className="acp-filter-label">{__('Date From', 'access-control-pro')}</label>
              <input
                type="date"
                className="acp-filter-input"
                value={filters.date_from}
                onChange={(e) => handleFilterChange('date_from', e.target.value)}
              />
            </div>

            <div className="acp-filter-group">
              <label className="acp-filter-label">{__('Date To', 'access-control-pro')}</label>
              <input
                type="date"
                className="acp-filter-input"
                value={filters.date_to}
                onChange={(e) => handleFilterChange('date_to', e.target.value)}
              />
            </div>
          </div>
        </div>
      )}

      {/* Logs Table */}
      <div className="acp-logs-content">
        {loading ? (
          <Loading message={__('Loading activity logs...', 'access-control-pro')} />
        ) : logs.length > 0 ? (
          <>
            <div className="acp-logs-table">
              <table className="acp-table">
                <thead>
                  <tr>
                    <th>{__('Time', 'access-control-pro')}</th>
                    <th>{__('User', 'access-control-pro')}</th>
                    <th>{__('Action', 'access-control-pro')}</th>
                    <th>{__('Object', 'access-control-pro')}</th>
                    <th>{__('IP Address', 'access-control-pro')}</th>
                    <th>{__('Details', 'access-control-pro')}</th>
                  </tr>
                </thead>
                <tbody>
                  {logs.map(log => (
                    <tr key={log.id}>
                      <td className="acp-log-time">
                        {formatDate(log.created_at)}
                      </td>
                      <td className="acp-log-user">
                        <div className="acp-user-info">
                          <strong>{getUserName(log.user_id)}</strong>
                          <small>ID: {log.user_id}</small>
                        </div>
                      </td>
                      <td className="acp-log-action">
                        <div className="acp-action-info">
                          <span className="acp-action-icon">
                            {getActionIcon(log.action)}
                          </span>
                          <span className="acp-action-text">
                            {getActionText(log.action)}
                          </span>
                        </div>
                      </td>
                      <td className="acp-log-object">
                        <div className="acp-object-info">
                          <span className="acp-object-type">{log.object_type}</span>
                          {log.object_id && (
                            <small className="acp-object-id">#{log.object_id}</small>
                          )}
                        </div>
                      </td>
                      <td className="acp-log-ip">
                        {log.ip_address || __('Unknown', 'access-control-pro')}
                      </td>
                      <td className="acp-log-details">
                        {log.details && (
                          <details className="acp-log-details-toggle">
                            <summary>{__('View Details', 'access-control-pro')}</summary>
                            <pre className="acp-log-details-content">
                              {typeof log.details === 'string'
                                ? log.details
                                : JSON.stringify(log.details, null, 2)
                              }
                            </pre>
                          </details>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {/* Pagination */}
            {totalPages > 1 && (
              <div className="acp-pagination">
                <button
                  className="acp-pagination__btn"
                  onClick={() => setCurrentPage(Math.max(1, currentPage - 1))}
                  disabled={currentPage === 1}
                >
                  {__('Previous', 'access-control-pro')}
                </button>

                <span className="acp-pagination__info">
                  {__('Page', 'access-control-pro')} {currentPage} {__('of', 'access-control-pro')} {totalPages}
                </span>

                <button
                  className="acp-pagination__btn"
                  onClick={() => setCurrentPage(Math.min(totalPages, currentPage + 1))}
                  disabled={currentPage === totalPages}
                >
                  {__('Next', 'access-control-pro')}
                </button>
              </div>
            )}
          </>
        ) : (
          <div className="acp-empty-state">
            <div className="acp-empty-state__icon">📝</div>
            <h3 className="acp-empty-state__title">
              {__('No Activity Logs', 'access-control-pro')}
            </h3>
            <p className="acp-empty-state__description">
              {Object.values(filters).some(v => v)
                ? __('No logs found matching your filter criteria.', 'access-control-pro')
                : __('No activity has been logged yet. Activity will appear here as users interact with restricted content.', 'access-control-pro')
              }
            </p>
          </div>
        )}
      </div>
    </div>
  );
};

export default ActivityLogs;
