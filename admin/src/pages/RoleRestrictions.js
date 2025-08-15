import React, { useState, useEffect } from 'react';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useAppContext } from '../context/AppContext';
import Loading from '../components/Loading';

const RoleRestrictions = () => {
  const { roles, plugins, adminMenu, setError, isPro } = useAppContext();
  const [loading, setLoading] = useState(false);
  const [selectedRole, setSelectedRole] = useState(null);
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
      const response = await apiFetch({ path: '/acp/v1/restrictions?type=role' });
      setExistingRestrictions(response);
    } catch (error) {
      setError(__('Failed to load existing restrictions', 'access-control-pro'));
    }
  };

  const handleRoleSelect = async (role) => {
    setSelectedRole(role);
    setShowRestrictionForm(true);

    try {
      setLoading(true);
      const response = await apiFetch({
        path: `/acp/v1/restrictions/role/${role.key}`
      });

      if (response && response.restrictions) {
        setRestrictions(response.restrictions);
      } else {
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
      console.error('Error loading role restrictions:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleSaveRestrictions = async () => {
    if (!selectedRole) return;

    // Check free version limits
    if (!isPro) {
      const existingRoleCount = existingRestrictions.filter(r => r.type === 'role').length;
      if (existingRoleCount >= 3 && !existingRestrictions.find(r => r.target_value === selectedRole.key)) {
        setError(__('Free version allows restricting up to 3 roles. Upgrade to Pro for unlimited restrictions.', 'access-control-pro'));
        return;
      }
    }

    try {
      setLoading(true);

      await apiFetch({
        path: '/acp/v1/restrictions',
        method: 'POST',
        data: {
          type: 'role',
          target_value: selectedRole.key,
          restrictions: restrictions
        }
      });

      await loadExistingRestrictions();
      setShowRestrictionForm(false);
      setSelectedRole(null);

      alert(__('Role restrictions saved successfully!', 'access-control-pro'));

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
    <div className="acp-role-restrictions">
      <div className="acp-page-header">
        <h1 className="acp-page-title">
          {__('Role Restrictions', 'access-control-pro')}
        </h1>
        <p className="acp-page-subtitle">
          {__('Control access for entire user roles', 'access-control-pro')}
        </p>
      </div>

      {!showRestrictionForm ? (
        <div className="acp-page-content">
          {/* Role Selection */}
          <div className="acp-section">
            <h2 className="acp-section-title">
              {__('Select Role to Restrict', 'access-control-pro')}
              {!isPro && (
                <span className="acp-limit-note">
                  {__('(Free version: up to 3 roles)', 'access-control-pro')}
                </span>
              )}
            </h2>

            <div className="acp-role-grid">
              {roles.map(role => {
                const hasRestrictions = existingRestrictions.find(r => r.target_value === role.key);
                const isDisabled = !isPro &&
                  existingRestrictions.filter(r => r.type === 'role').length >= 3 &&
                  !hasRestrictions;

                return (
                  <div
                    key={role.key}
                    className={`acp-role-card ${isDisabled ? 'acp-role-card--disabled' : ''}`}
                    onClick={() => !isDisabled && handleRoleSelect(role)}
                  >
                    <div className="acp-role-card__header">
                      <div className="acp-role-card__icon">
                        👥
                      </div>
                      <div className="acp-role-card__info">
                        <h3 className="acp-role-card__name">{role.name}</h3>
                        <p className="acp-role-card__key">{role.key}</p>
                      </div>
                      {hasRestrictions && (
                        <div className="acp-role-card__badge">
                          {__('Restricted', 'access-control-pro')}
                        </div>
                      )}
                    </div>

                    <div className="acp-role-card__capabilities">
                      <strong>{__('Key Capabilities:', 'access-control-pro')}</strong>
                      <div className="acp-capabilities-list">
                        {Object.keys(role.capabilities || {}).slice(0, 3).map(cap => (
                          <span key={cap} className="acp-capability-tag">
                            {cap.replace(/_/g, ' ')}
                          </span>
                        ))}
                        {Object.keys(role.capabilities || {}).length > 3 && (
                          <span className="acp-capability-more">
                            +{Object.keys(role.capabilities).length - 3} more
                          </span>
                        )}
                      </div>
                    </div>

                    <div className="acp-role-card__action">
                      {isDisabled ? (
                        <span className="acp-role-card__upgrade">
                          {__('Upgrade to Pro', 'access-control-pro')}
                        </span>
                      ) : (
                        <>
                          {__('Configure', 'access-control-pro')} →
                        </>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          </div>

          {/* Existing Restrictions */}
          {existingRestrictions.length > 0 && (
            <div className="acp-section">
              <h2 className="acp-section-title">
                {__('Existing Role Restrictions', 'access-control-pro')}
              </h2>

              <div className="acp-restrictions-table">
                <table className="acp-table">
                  <thead>
                    <tr>
                      <th>{__('Role', 'access-control-pro')}</th>
                      <th>{__('Restrictions', 'access-control-pro')}</th>
                      <th>{__('Affected Users', 'access-control-pro')}</th>
                      <th>{__('Created', 'access-control-pro')}</th>
                      <th>{__('Actions', 'access-control-pro')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {existingRestrictions.map(restriction => {
                      const role = roles.find(r => r.key === restriction.target_value);
                      const restrictionData = typeof restriction.restrictions === 'string'
                        ? JSON.parse(restriction.restrictions)
                        : restriction.restrictions;

                      return (
                        <tr key={restriction.id}>
                          <td>
                            <div className="acp-role-info">
                              <strong>{role?.name || restriction.target_value}</strong>
                              <br />
                              <small>{restriction.target_value}</small>
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
                            <span className="acp-user-count">
                              {/* This would show actual count of users with this role */}
                              {role?.userCount || 0} {__('users', 'access-control-pro')}
                            </span>
                          </td>
                          <td>
                            {new Date(restriction.created_at).toLocaleDateString()}
                          </td>
                          <td>
                            <div className="acp-table-actions">
                              <button
                                className="acp-btn acp-btn--small acp-btn--secondary"
                                onClick={() => handleRoleSelect(role)}
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
              {__('Configure Restrictions for Role:', 'access-control-pro')} {selectedRole?.name}
            </h2>
            <button
              className="acp-btn acp-btn--secondary"
              onClick={() => setShowRestrictionForm(false)}
            >
              {__('← Back to Roles', 'access-control-pro')}
            </button>
          </div>

          {loading ? (
            <Loading message={__('Loading role restrictions...', 'access-control-pro')} />
          ) : (
            <div className="acp-form-content">
              {/* Role Info */}
              <div className="acp-role-info-card">
                <div className="acp-role-info-card__icon">👥</div>
                <div className="acp-role-info-card__content">
                  <h3>{selectedRole?.name}</h3>
                  <p>{__('Role Key:', 'access-control-pro')} {selectedRole?.key}</p>
                  <p>{__('Capabilities:', 'access-control-pro')} {Object.keys(selectedRole?.capabilities || {}).length}</p>
                </div>
              </div>

              {/* Admin Menu Restrictions */}
              <div className="acp-form-section">
                <h3 className="acp-form-section-title">
                  {__('Admin Menu Restrictions', 'access-control-pro')}
                </h3>
                <p className="acp-form-section-description">
                  {__('Select admin menu items to hide from users with this role', 'access-control-pro')}
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
                </h3>
                <p className="acp-form-section-description">
                  {__('Select plugins to restrict access for users with this role', 'access-control-pro')}
                </p>

                <div className="acp-plugin-grid">
                  {plugins.map(plugin => (
                    <div key={plugin.file} className="acp-plugin-card">
                      <label className="acp-plugin-label">
                        <input
                          type="checkbox"
                          checked={restrictions.plugins.includes(plugin.file)}
                          onChange={() => togglePlugin(plugin.file)}
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

export default RoleRestrictions;
