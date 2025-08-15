import React, { useState, useEffect } from 'react';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useAppContext } from '../context/AppContext';
import Loading from '../components/Loading';

const Dashboard = () => {
  const { stats, setStats, setError, isPro } = useAppContext();
  const [loading, setLoading] = useState(false);
  const [recentActivity, setRecentActivity] = useState([]);

  useEffect(() => {
    loadDashboardData();
  }, []);

  const loadDashboardData = async () => {
    try {
      setLoading(true);

      const [statsData, activityData] = await Promise.all([
        apiFetch({ path: '/acp/v1/dashboard/stats' }),
        isPro ? apiFetch({ path: '/acp/v1/logs?limit=5' }) : Promise.resolve([])
      ]);

      setStats(statsData);
      setRecentActivity(activityData);
    } catch (error) {
      setError(__('Failed to load dashboard data', 'access-control-pro'));
    } finally {
      setLoading(false);
    }
  };

  const StatCard = ({ title, value, icon, color = 'blue' }) => (
    <div className={`acp-stat-card acp-stat-card--${color}`}>
      <div className="acp-stat-card__icon">
        {icon}
      </div>
      <div className="acp-stat-card__content">
        <h3 className="acp-stat-card__title">{title}</h3>
        <div className="acp-stat-card__value">{value}</div>
      </div>
    </div>
  );

  if (loading) {
    return <Loading message={__('Loading dashboard...', 'access-control-pro')} />;
  }

  return (
    <div className="acp-dashboard">
      <div className="acp-dashboard__header">
        <h1 className="acp-dashboard__title">
          {__('Access Control Dashboard', 'access-control-pro')}
        </h1>
        <p className="acp-dashboard__subtitle">
          {__('Overview of your access restrictions and system status', 'access-control-pro')}
        </p>
      </div>

      {/* Statistics Grid */}
      <div className="acp-dashboard__stats">
        <StatCard
          title={__('Total Restrictions', 'access-control-pro')}
          value={stats.total_restrictions || 0}
          icon="🛡️"
          color="blue"
        />

        <StatCard
          title={__('User Restrictions', 'access-control-pro')}
          value={stats.user_restrictions || 0}
          icon="👤"
          color="green"
        />

        <StatCard
          title={__('Role Restrictions', 'access-control-pro')}
          value={stats.role_restrictions || 0}
          icon="👥"
          color="orange"
        />

        <StatCard
          title={__('Active Plugins', 'access-control-pro')}
          value={`${stats.active_plugins || 0}/${stats.total_plugins || 0}`}
          icon="🔌"
          color="purple"
        />
      </div>

      <div className="acp-dashboard__content">
        {/* Quick Actions */}
        <div className="acp-dashboard__section">
          <h2 className="acp-dashboard__section-title">
            {__('Quick Actions', 'access-control-pro')}
          </h2>

          <div className="acp-quick-actions">
            <a href="#/user-restrictions" className="acp-quick-action">
              <div className="acp-quick-action__icon">👤</div>
              <div className="acp-quick-action__content">
                <h3 className="acp-quick-action__title">
                  {__('Add User Restriction', 'access-control-pro')}
                </h3>
                <p className="acp-quick-action__description">
                  {__('Restrict specific users from accessing certain features', 'access-control-pro')}
                </p>
              </div>
            </a>

            <a href="#/role-restrictions" className="acp-quick-action">
              <div className="acp-quick-action__icon">👥</div>
              <div className="acp-quick-action__content">
                <h3 className="acp-quick-action__title">
                  {__('Add Role Restriction', 'access-control-pro')}
                </h3>
                <p className="acp-quick-action__description">
                  {__('Apply restrictions to entire user roles', 'access-control-pro')}
                </p>
              </div>
            </a>

            <a href="#/plugin-control" className="acp-quick-action">
              <div className="acp-quick-action__icon">🔌</div>
              <div className="acp-quick-action__content">
                <h3 className="acp-quick-action__title">
                  {__('Control Plugin Access', 'access-control-pro')}
                </h3>
                <p className="acp-quick-action__description">
                  {__('Manage which plugins users can access', 'access-control-pro')}
                </p>
              </div>
            </a>

            <a href="#/settings" className="acp-quick-action">
              <div className="acp-quick-action__icon">⚙️</div>
              <div className="acp-quick-action__content">
                <h3 className="acp-quick-action__title">
                  {__('Plugin Settings', 'access-control-pro')}
                </h3>
                <p className="acp-quick-action__description">
                  {__('Configure plugin options and preferences', 'access-control-pro')}
                </p>
              </div>
            </a>
          </div>
        </div>

        {/* Recent Activity (Pro feature) */}
        {isPro && (
          <div className="acp-dashboard__section">
            <h2 className="acp-dashboard__section-title">
              {__('Recent Activity', 'access-control-pro')}
            </h2>

            {recentActivity.length > 0 ? (
              <div className="acp-recent-activity">
                {recentActivity.map((activity, index) => (
                  <div key={index} className="acp-activity-item">
                    <div className="acp-activity-item__icon">
                      {getActivityIcon(activity.action)}
                    </div>
                    <div className="acp-activity-item__content">
                      <div className="acp-activity-item__action">
                        {getActivityText(activity)}
                      </div>
                      <div className="acp-activity-item__meta">
                        {activity.user_name} • {formatDate(activity.created_at)}
                      </div>
                    </div>
                  </div>
                ))}

                <div className="acp-recent-activity__footer">
                  <a href="#/logs" className="acp-link">
                    {__('View All Activity', 'access-control-pro')} →
                  </a>
                </div>
              </div>
            ) : (
              <div className="acp-empty-state">
                <p>{__('No recent activity to display', 'access-control-pro')}</p>
              </div>
            )}
          </div>
        )}

        {/* System Status */}
        <div className="acp-dashboard__section">
          <h2 className="acp-dashboard__section-title">
            {__('System Status', 'access-control-pro')}
          </h2>

          <div className="acp-system-status">
            <div className="acp-status-item">
              <div className="acp-status-item__indicator acp-status-item__indicator--success"></div>
              <span className="acp-status-item__label">
                {__('Plugin Status', 'access-control-pro')}
              </span>
              <span className="acp-status-item__value">
                {__('Active', 'access-control-pro')}
              </span>
            </div>

            <div className="acp-status-item">
              <div className={`acp-status-item__indicator ${isPro ? 'acp-status-item__indicator--success' : 'acp-status-item__indicator--warning'}`}></div>
              <span className="acp-status-item__label">
                {__('License Status', 'access-control-pro')}
              </span>
              <span className="acp-status-item__value">
                {isPro ? __('Pro Active', 'access-control-pro') : __('Free Version', 'access-control-pro')}
              </span>
            </div>

            <div className="acp-status-item">
              <div className="acp-status-item__indicator acp-status-item__indicator--success"></div>
              <span className="acp-status-item__label">
                {__('Database', 'access-control-pro')}
              </span>
              <span className="acp-status-item__value">
                {__('Connected', 'access-control-pro')}
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

// Helper functions
const getActivityIcon = (action) => {
  const icons = {
    'restriction_added': '➕',
    'restriction_updated': '✏️',
    'restriction_deleted': '❌',
    'user_blocked': '🚫',
    'plugin_restricted': '🔌',
    'default': '📝'
  };

  return icons[action] || icons.default;
};

const getActivityText = (activity) => {
  const actions = {
    'restriction_added': __('Added new restriction', 'access-control-pro'),
    'restriction_updated': __('Updated restriction', 'access-control-pro'),
    'restriction_deleted': __('Deleted restriction', 'access-control-pro'),
    'user_blocked': __('Blocked user access', 'access-control-pro'),
    'plugin_restricted': __('Restricted plugin access', 'access-control-pro')
  };

  return actions[activity.action] || activity.action;
};

const formatDate = (dateString) => {
  const date = new Date(dateString);
  const now = new Date();
  const diffTime = Math.abs(now - date);
  const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

  if (diffDays === 1) {
    return __('1 day ago', 'access-control-pro');
  } else if (diffDays < 7) {
    return sprintf(__('%d days ago', 'access-control-pro'), diffDays);
  } else {
    return date.toLocaleDateString();
  }
};

export default Dashboard;
