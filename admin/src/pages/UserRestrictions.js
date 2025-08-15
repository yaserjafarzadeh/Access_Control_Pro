import React, { useState, useEffect } from 'react';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useAppContext } from '../context/AppContext';
import Loading from '../components/Loading';

const UserRestrictions = () => {
  const { users, plugins, adminMenu, setError, isPro } = useAppContext();
  const [loading, setLoading] = useState(false);
  const [selectedUser, setSelectedUser] = useState(null);
  const [restrictions, setRestrictions] = useState({
    admin_menu: [],
    plugins: [],
    content: {
      post_types: [],
      posts: [],
      categories: [],
      tags: []
    }
  });
  const [showRestrictionForm, setShowRestrictionForm] = useState(false);
  const [existingRestrictions, setExistingRestrictions] = useState([]);

  useEffect(() => {
    loadExistingRestrictions();
  }, []);

  const loadExistingRestrictions = async () => {
    try {
      const response = await apiFetch({ path: '/acp/v1/restrictions?type=user' });
      setExistingRestrictions(response);
    } catch (error) {
      setError(__('Failed to load existing restrictions', 'access-control-pro'));
    }
  };

  const handleUserSelect = async (user) => {
    setSelectedUser(user);
    setShowRestrictionForm(true);

    try {
      setLoading(true);
      const response = await apiFetch({
        path: `/acp/v1/restrictions/user/${user.id}`
      });

      if (response && response.restrictions) {
        setRestrictions(response.restrictions);
      } else {
        // Reset to empty restrictions
        setRestrictions({
          admin_menu: [],
          plugins: [],
          content: {
            post_types: [],
            posts: [],
            categories: [],
            tags: []
          }
        });
      }
    } catch (error) {
      console.error('Error loading user restrictions:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleSaveRestrictions = async () => {
    if (!selectedUser) return;

    // Check free version limits
    if (!isPro) {
      const restrictedPluginsCount = restrictions.plugins?.length || 0;
      if (restrictedPluginsCount > 5) {
        setError(__('Free version allows restricting up to 5 plugins. Upgrade to Pro for unlimited restrictions.', 'access-control-pro'));
        return;
      }
    }

    try {
      setLoading(true);

      await apiFetch({
        path: '/acp/v1/restrictions',
        method: 'POST',
        data: {
          type: 'user',
          target_id: selectedUser.id,
          restrictions: restrictions
        }
      });

      // Reload existing restrictions
      await loadExistingRestrictions();

      setShowRestrictionForm(false);
      setSelectedUser(null);

      // Show success message
      alert(__('Restrictions saved successfully!', 'access-control-pro'));

    } catch (error) {
      setError(__('Failed to save restrictions', 'access-control-pro'));
    } finally {
      setLoading(false);
    }
  };

  const handleDeleteRestriction = async (restrictionId) => {
    if (!confirm(__('Are you sure you want to delete this restriction?', 'access-control-pro'))) {
      return;
    }

    try {
      await apiFetch({
        path: `/acp/v1/restrictions/${restrictionId}`,
        method: 'DELETE'
      });

      await loadExistingRestrictions();
      alert(__('Restriction deleted successfully!', 'access-control-pro'));
    } catch (error) {
      setError(__('Failed to delete restriction', 'access-control-pro'));
    }
  };

  const toggleAdminMenuItem = (menuSlug) => {
    setRestrictions(prev => ({
      ...prev,
      admin_menu: prev.admin_menu.includes(menuSlug)
        ? prev.admin_menu.filter(item => item !== menuSlug)
        : [...prev.admin_menu, menuSlug]
    }));
  };

  const togglePlugin = (pluginFile) => {
    setRestrictions(prev => ({
      ...prev,
      plugins: prev.plugins.includes(pluginFile)
        ? prev.plugins.filter(item => item !== pluginFile)
        : [...prev.plugins, pluginFile]
    }));
  };

  return (
    <div className="acp-user-restrictions">
      <div className="acp-page-header">
        <h1 className="acp-page-title">
          {__('User Restrictions', 'access-control-pro')}
        </h1>
        <p className="acp-page-subtitle">
          {__('Control access for specific users', 'access-control-pro')}
        </p>
      </div>

      {!showRestrictionForm ? (
        <div className="acp-page-content">
          {/* User Selection */}
          <div className="acp-section">
            <h2 className="acp-section-title">
              {__('Select User to Restrict', 'access-control-pro')}
            </h2>

            <div className="acp-user-grid">
              {users.map(user => (
                <div
                  key={user.id}
                  className="acp-user-card"
                  onClick={() => handleUserSelect(user)}
                >
                  <div className="acp-user-card__avatar">
                    {user.name.charAt(0).toUpperCase()}
                  </div>
                  <div className="acp-user-card__info">
                    <h3 className="acp-user-card__name">{user.name}</h3>
                    <p className="acp-user-card__email">{user.email}</p>
                    <div className="acp-user-card__roles">
                      {user.roles.map(role => (
                        <span key={role} className="acp-role-badge">
                          {role}
                        </span>
                      ))}
                    </div>
                  </div>
                  <div className="acp-user-card__action">
                    {__('Configure', 'access-control-pro')} →
                  </div>
                </div>
              ))}
            </div>
          </div>

          {/* Existing Restrictions */}
          {existingRestrictions.length > 0 && (
            <div className="acp-section">
              <h2 className="acp-section-title">
                {__('Existing User Restrictions', 'access-control-pro')}
              </h2>

              <div className="acp-restrictions-table">
                <table className="acp-table">
                  <thead>
                    <tr>
                      <th>{__('User', 'access-control-pro')}</th>
                      <th>{__('Restrictions', 'access-control-pro')}</th>
                      <th>{__('Created', 'access-control-pro')}</th>
                      <th>{__('Actions', 'access-control-pro')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {existingRestrictions.map(restriction => {
                      const user = users.find(u => u.id == restriction.user_id);
                      const restrictionData = typeof restriction.restrictions === 'string'
                        ? JSON.parse(restriction.restrictions)
                        : restriction.restrictions;

                      return (
                        <tr key={restriction.id}>
                          <td>
                            <div className="acp-user-info">
                              <strong>{user?.name || __('Unknown User', 'access-control-pro')}</strong>
                              <br />
                              <small>{user?.email}</small>
                            </div>
                          </td>
                          <td>
                            <div className="acp-restriction-summary">
                              {restrictionData.admin_menu?.length > 0 && (
                                <span className="acp-restriction-tag">
                                  {restrictionData.admin_menu.length} {__('Menu Items', 'access-control-pro')}
                                </span>
                              )}
                              {restrictionData.plugins?.length > 0 && (
                                <span className="acp-restriction-tag">
                                  {restrictionData.plugins.length} {__('Plugins', 'access-control-pro')}
                                </span>
                              )}
                            </div>
                          </td>
                          <td>
                            {new Date(restriction.created_at).toLocaleDateString()}
                          </td>
                          <td>
                            <div className="acp-table-actions">
                              <button
                                className="acp-btn acp-btn--small acp-btn--secondary"
                                onClick={() => handleUserSelect(user)}
                              >
                                {__('Edit', 'access-control-pro')}
                              </button>
                              <button
                                className="acp-btn acp-btn--small acp-btn--danger"
                                onClick={() => handleDeleteRestriction(restriction.id)}
                              >
                                {__('Delete', 'access-control-pro')}
                              </button>
                            </div>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </div>
      ) : (
        <div className="acp-restriction-form">
          <div className="acp-form-header">
            <h2 className="acp-form-title">
              {__('Configure Restrictions for', 'access-control-pro')} {selectedUser?.name}
            </h2>
            <button
              className="acp-btn acp-btn--secondary"
              onClick={() => setShowRestrictionForm(false)}
            >
              {__('← Back to Users', 'access-control-pro')}
            </button>
          </div>

          {loading ? (
            <Loading message={__('Loading user restrictions...', 'access-control-pro')} />
          ) : (
            <div className="acp-form-content">
              {/* Admin Menu Restrictions */}
              <div className="acp-form-section">
                <h3 className="acp-form-section-title">
                  {__('Admin Menu Restrictions', 'access-control-pro')}
                </h3>
                <p className="acp-form-section-description">
                  {__('Select admin menu items to hide from this user', 'access-control-pro')}
                </p>

                <div className="acp-checkbox-grid">
                  {adminMenu.map(menuItem => (
                    <div key={menuItem.slug} className="acp-checkbox-item">
                      <label className="acp-checkbox-label">
                        <input
                          type="checkbox"
                          checked={restrictions.admin_menu.includes(menuItem.slug)}
                          onChange={() => toggleAdminMenuItem(menuItem.slug)}
                        />
                        <span className="acp-checkbox-text">
                          {menuItem.title.replace(/<[^>]*>/g, '')}
                        </span>
                      </label>

                      {menuItem.submenu && menuItem.submenu.length > 0 && (
                        <div className="acp-submenu-items">
                          {menuItem.submenu.map(subItem => (
                            <label key={subItem.slug} className="acp-checkbox-label acp-checkbox-label--sub">
                              <input
                                type="checkbox"
                                checked={restrictions.admin_menu.includes(subItem.full_slug)}
                                onChange={() => toggleAdminMenuItem(subItem.full_slug)}
                              />
                              <span className="acp-checkbox-text">
                                {subItem.title.replace(/<[^>]*>/g, '')}
                              </span>
                            </label>
                          ))}
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              </div>

              {/* Plugin Restrictions */}
              <div className="acp-form-section">
                <h3 className="acp-form-section-title">
                  {__('Plugin Restrictions', 'access-control-pro')}
                  {!isPro && restrictions.plugins.length >= 5 && (
                    <span className="acp-limit-badge">
                      {__('Free Limit Reached', 'access-control-pro')}
                    </span>
                  )}
                </h3>
                <p className="acp-form-section-description">
                  {__('Select plugins to restrict access for this user', 'access-control-pro')}
                  {!isPro && (
                    <span className="acp-pro-note">
                      {__(' (Free version: up to 5 plugins)', 'access-control-pro')}
                    </span>
                  )}
                </p>

                <div className="acp-plugin-grid">
                  {plugins.map(plugin => (
                    <div key={plugin.file} className="acp-plugin-card">
                      <label className="acp-plugin-label">
                        <input
                          type="checkbox"
                          checked={restrictions.plugins.includes(plugin.file)}
                          onChange={() => togglePlugin(plugin.file)}
                          disabled={!isPro && !restrictions.plugins.includes(plugin.file) && restrictions.plugins.length >= 5}
                        />
                        <div className="acp-plugin-info">
                          <h4 className="acp-plugin-name">{plugin.name}</h4>
                          <p className="acp-plugin-description">
                            {plugin.description.substring(0, 100)}...
                          </p>
                          <div className="acp-plugin-meta">
                            <span className={`acp-plugin-status ${plugin.is_active ? 'acp-plugin-status--active' : 'acp-plugin-status--inactive'}`}>
                              {plugin.is_active ? __('Active', 'access-control-pro') : __('Inactive', 'access-control-pro')}
                            </span>
                            <span className="acp-plugin-version">v{plugin.version}</span>
                          </div>
                        </div>
                      </label>
                    </div>
                  ))}
                </div>
              </div>

              {/* Save Button */}
              <div className="acp-form-actions">
                <button
                  className="acp-btn acp-btn--primary acp-btn--large"
                  onClick={handleSaveRestrictions}
                  disabled={loading}
                >
                  {loading ? __('Saving...', 'access-control-pro') : __('Save Restrictions', 'access-control-pro')}
                </button>

                <button
                  className="acp-btn acp-btn--secondary acp-btn--large"
                  onClick={() => setShowRestrictionForm(false)}
                >
                  {__('Cancel', 'access-control-pro')}
                </button>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
};

export default UserRestrictions;
