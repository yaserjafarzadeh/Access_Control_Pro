import React, { useState, useEffect } from 'react';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useAppContext } from '../context/AppContext';
import Loading from '../components/Loading';

const PluginControl = () => {
  const { plugins, users, roles, setError, isPro } = useAppContext();
  const [loading, setLoading] = useState(false);
  const [activeTab, setActiveTab] = useState('overview');
  const [searchTerm, setSearchTerm] = useState('');
  const [filterStatus, setFilterStatus] = useState('all');
  const [pluginRestrictions, setPluginRestrictions] = useState({});

  useEffect(() => {
    loadPluginRestrictions();
  }, []);

  const loadPluginRestrictions = async () => {
    try {
      setLoading(true);

      // Load all restrictions and categorize by plugin
      const restrictions = await apiFetch({ path: '/acp/v1/restrictions' });

      const pluginMap = {};

      restrictions.forEach(restriction => {
        const restrictionData = typeof restriction.restrictions === 'string'
          ? JSON.parse(restriction.restrictions)
          : restriction.restrictions;

        if (restrictionData.plugins) {
          restrictionData.plugins.forEach(pluginFile => {
            if (!pluginMap[pluginFile]) {
              pluginMap[pluginFile] = {
                users: [],
                roles: []
              };
            }

            if (restriction.type === 'user') {
              const user = users.find(u => u.id == restriction.user_id);
              if (user) {
                pluginMap[pluginFile].users.push(user);
              }
            } else if (restriction.type === 'role') {
              const role = roles.find(r => r.key === restriction.target_value);
              if (role) {
                pluginMap[pluginFile].roles.push(role);
              }
            }
          });
        }
      });

      setPluginRestrictions(pluginMap);
    } catch (error) {
      setError(__('Failed to load plugin restrictions', 'access-control-pro'));
    } finally {
      setLoading(false);
    }
  };

  const filteredPlugins = plugins.filter(plugin => {
    const matchesSearch = plugin.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
                         plugin.description.toLowerCase().includes(searchTerm.toLowerCase());

    const matchesFilter = filterStatus === 'all' ||
                         (filterStatus === 'active' && plugin.is_active) ||
                         (filterStatus === 'inactive' && !plugin.is_active) ||
                         (filterStatus === 'restricted' && pluginRestrictions[plugin.file]);

    return matchesSearch && matchesFilter;
  });

  const getPluginRestrictionCount = (pluginFile) => {
    const restrictions = pluginRestrictions[pluginFile];
    if (!restrictions) return 0;

    return restrictions.users.length + restrictions.roles.length;
  };

  const getPluginAffectedUsersCount = (pluginFile) => {
    const restrictions = pluginRestrictions[pluginFile];
    if (!restrictions) return 0;

    // Count direct user restrictions + users from restricted roles
    let count = restrictions.users.length;

    restrictions.roles.forEach(role => {
      // This would need to be calculated from actual user data
      // For now, we'll use a placeholder
      count += role.userCount || 0;
    });

    return count;
  };

  const PluginCard = ({ plugin }) => {
    const restrictions = pluginRestrictions[plugin.file];
    const restrictionCount = getPluginRestrictionCount(plugin.file);
    const affectedUsers = getPluginAffectedUsersCount(plugin.file);

    return (
      <div className="acp-plugin-control-card">
        <div className="acp-plugin-control-card__header">
          <div className="acp-plugin-control-card__info">
            <h3 className="acp-plugin-control-card__name">{plugin.name}</h3>
            <p className="acp-plugin-control-card__author">
              {__('by', 'access-control-pro')} {plugin.author}
            </p>
          </div>
          <div className="acp-plugin-control-card__status">
            <span className={`acp-plugin-status-badge ${plugin.is_active ? 'acp-plugin-status-badge--active' : 'acp-plugin-status-badge--inactive'}`}>
              {plugin.is_active ? __('Active', 'access-control-pro') : __('Inactive', 'access-control-pro')}
            </span>
            <span className="acp-plugin-version">v{plugin.version}</span>
          </div>
        </div>

        <div className="acp-plugin-control-card__content">
          <p className="acp-plugin-control-card__description">
            {plugin.description.length > 150
              ? plugin.description.substring(0, 150) + '...'
              : plugin.description
            }
          </p>

          {restrictions && (
            <div className="acp-plugin-restrictions-summary">
              <h4>{__('Current Restrictions:', 'access-control-pro')}</h4>

              {restrictions.users.length > 0 && (
                <div className="acp-restriction-group">
                  <strong>{__('Restricted Users:', 'access-control-pro')}</strong>
                  <div className="acp-restriction-list">
                    {restrictions.users.slice(0, 3).map(user => (
                      <span key={user.id} className="acp-restriction-tag">
                        {user.name}
                      </span>
                    ))}
                    {restrictions.users.length > 3 && (
                      <span className="acp-restriction-more">
                        +{restrictions.users.length - 3} more
                      </span>
                    )}
                  </div>
                </div>
              )}

              {restrictions.roles.length > 0 && (
                <div className="acp-restriction-group">
                  <strong>{__('Restricted Roles:', 'access-control-pro')}</strong>
                  <div className="acp-restriction-list">
                    {restrictions.roles.slice(0, 3).map(role => (
                      <span key={role.key} className="acp-restriction-tag">
                        {role.name}
                      </span>
                    ))}
                    {restrictions.roles.length > 3 && (
                      <span className="acp-restriction-more">
                        +{restrictions.roles.length - 3} more
                      </span>
                    )}
                  </div>
                </div>
              )}
            </div>
          )}
        </div>

        <div className="acp-plugin-control-card__footer">
          <div className="acp-plugin-stats">
            <div className="acp-plugin-stat">
              <span className="acp-plugin-stat__value">{restrictionCount}</span>
              <span className="acp-plugin-stat__label">{__('Restrictions', 'access-control-pro')}</span>
            </div>
            <div className="acp-plugin-stat">
              <span className="acp-plugin-stat__value">{affectedUsers}</span>
              <span className="acp-plugin-stat__label">{__('Affected Users', 'access-control-pro')}</span>
            </div>
          </div>

          <div className="acp-plugin-actions">
            <a
              href={`#/user-restrictions?plugin=${plugin.file}`}
              className="acp-btn acp-btn--small acp-btn--secondary"
            >
              {__('Manage Access', 'access-control-pro')}
            </a>
          </div>
        </div>
      </div>
    );
  };

  const OverviewTab = () => (
    <div className="acp-overview-tab">
      <div className="acp-plugin-stats-grid">
        <div className="acp-stat-card">
          <div className="acp-stat-card__icon">🔌</div>
          <div className="acp-stat-card__content">
            <h3>{__('Total Plugins', 'access-control-pro')}</h3>
            <div className="acp-stat-card__value">{plugins.length}</div>
          </div>
        </div>

        <div className="acp-stat-card">
          <div className="acp-stat-card__icon">✅</div>
          <div className="acp-stat-card__content">
            <h3>{__('Active Plugins', 'access-control-pro')}</h3>
            <div className="acp-stat-card__value">
              {plugins.filter(p => p.is_active).length}
            </div>
          </div>
        </div>

        <div className="acp-stat-card">
          <div className="acp-stat-card__icon">🚫</div>
          <div className="acp-stat-card__content">
            <h3>{__('Restricted Plugins', 'access-control-pro')}</h3>
            <div className="acp-stat-card__value">
              {Object.keys(pluginRestrictions).length}
            </div>
          </div>
        </div>

        <div className="acp-stat-card">
          <div className="acp-stat-card__icon">👥</div>
          <div className="acp-stat-card__content">
            <h3>{__('Affected Users', 'access-control-pro')}</h3>
            <div className="acp-stat-card__value">
              {Object.values(pluginRestrictions).reduce((total, restrictions) => {
                return total + getPluginAffectedUsersCount();
              }, 0)}
            </div>
          </div>
        </div>
      </div>

      {/* Most Restricted Plugins */}
      <div className="acp-section">
        <h3 className="acp-section-title">
          {__('Most Restricted Plugins', 'access-control-pro')}
        </h3>

        <div className="acp-restricted-plugins-list">
          {Object.entries(pluginRestrictions)
            .sort(([,a], [,b]) => (b.users.length + b.roles.length) - (a.users.length + a.roles.length))
            .slice(0, 5)
            .map(([pluginFile, restrictions]) => {
              const plugin = plugins.find(p => p.file === pluginFile);
              if (!plugin) return null;

              return (
                <div key={pluginFile} className="acp-restricted-plugin-item">
                  <div className="acp-restricted-plugin-info">
                    <h4>{plugin.name}</h4>
                    <p>{restrictions.users.length + restrictions.roles.length} {__('restrictions', 'access-control-pro')}</p>
                  </div>
                  <div className="acp-restricted-plugin-details">
                    {restrictions.users.length > 0 && (
                      <span className="acp-restriction-detail">
                        {restrictions.users.length} {__('users', 'access-control-pro')}
                      </span>
                    )}
                    {restrictions.roles.length > 0 && (
                      <span className="acp-restriction-detail">
                        {restrictions.roles.length} {__('roles', 'access-control-pro')}
                      </span>
                    )}
                  </div>
                </div>
              );
            })}
        </div>
      </div>
    </div>
  );

  const AllPluginsTab = () => (
    <div className="acp-all-plugins-tab">
      {/* Search and Filters */}
      <div className="acp-plugins-filters">
        <div className="acp-search-box">
          <input
            type="text"
            placeholder={__('Search plugins...', 'access-control-pro')}
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            className="acp-search-input"
          />
        </div>

        <div className="acp-filter-buttons">
          <button
            className={`acp-filter-btn ${filterStatus === 'all' ? 'acp-filter-btn--active' : ''}`}
            onClick={() => setFilterStatus('all')}
          >
            {__('All', 'access-control-pro')} ({plugins.length})
          </button>
          <button
            className={`acp-filter-btn ${filterStatus === 'active' ? 'acp-filter-btn--active' : ''}`}
            onClick={() => setFilterStatus('active')}
          >
            {__('Active', 'access-control-pro')} ({plugins.filter(p => p.is_active).length})
          </button>
          <button
            className={`acp-filter-btn ${filterStatus === 'inactive' ? 'acp-filter-btn--active' : ''}`}
            onClick={() => setFilterStatus('inactive')}
          >
            {__('Inactive', 'access-control-pro')} ({plugins.filter(p => !p.is_active).length})
          </button>
          <button
            className={`acp-filter-btn ${filterStatus === 'restricted' ? 'acp-filter-btn--active' : ''}`}
            onClick={() => setFilterStatus('restricted')}
          >
            {__('Restricted', 'access-control-pro')} ({Object.keys(pluginRestrictions).length})
          </button>
        </div>
      </div>

      {/* Plugins Grid */}
      <div className="acp-plugins-grid">
        {filteredPlugins.map(plugin => (
          <PluginCard key={plugin.file} plugin={plugin} />
        ))}
      </div>

      {filteredPlugins.length === 0 && (
        <div className="acp-empty-state">
          <p>{__('No plugins found matching your criteria.', 'access-control-pro')}</p>
        </div>
      )}
    </div>
  );

  if (loading) {
    return <Loading message={__('Loading plugin data...', 'access-control-pro')} />;
  }

  return (
    <div className="acp-plugin-control">
      <div className="acp-page-header">
        <h1 className="acp-page-title">
          {__('Plugin Access Control', 'access-control-pro')}
        </h1>
        <p className="acp-page-subtitle">
          {__('Monitor and manage plugin access restrictions', 'access-control-pro')}
        </p>
      </div>

      {/* Tab Navigation */}
      <div className="acp-tab-nav">
        <button
          className={`acp-tab-btn ${activeTab === 'overview' ? 'acp-tab-btn--active' : ''}`}
          onClick={() => setActiveTab('overview')}
        >
          {__('Overview', 'access-control-pro')}
        </button>
        <button
          className={`acp-tab-btn ${activeTab === 'all-plugins' ? 'acp-tab-btn--active' : ''}`}
          onClick={() => setActiveTab('all-plugins')}
        >
          {__('All Plugins', 'access-control-pro')}
        </button>
      </div>

      {/* Tab Content */}
      <div className="acp-tab-content">
        {activeTab === 'overview' && <OverviewTab />}
        {activeTab === 'all-plugins' && <AllPluginsTab />}
      </div>
    </div>
  );
};

export default PluginControl;
