import React, { useState, useEffect } from 'react';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useAppContext } from '../context/AppContext';
import Loading from '../components/Loading';

const Settings = () => {
  const { isPro, setError } = useAppContext();
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [settings, setSettings] = useState({
    enable_logging: false,
    log_retention_days: 30,
    protect_super_admin: true,
    enable_time_restrictions: false,
    default_language: 'en_US'
  });
  const [licenseData, setLicenseData] = useState({
    key: '',
    status: 'invalid',
    expires: ''
  });
  const [licenseKey, setLicenseKey] = useState('');
  const [licenseLoading, setLicenseLoading] = useState(false);

  useEffect(() => {
    loadSettings();
    if (isPro) {
      loadLicenseData();
    }
  }, [isPro]);

  const loadSettings = async () => {
    try {
      setLoading(true);
      // Load settings from WordPress options
      // This would typically come from a REST endpoint
      // For now, we'll use default values
    } catch (error) {
      setError(__('Failed to load settings', 'access-control-pro'));
    } finally {
      setLoading(false);
    }
  };

  const loadLicenseData = async () => {
    try {
      const response = await apiFetch({ path: '/acp/v1/license' });
      setLicenseData(response);
      setLicenseKey(response.key);
    } catch (error) {
      console.error('Failed to load license data:', error);
    }
  };

  const handleSettingsChange = (key, value) => {
    setSettings(prev => ({
      ...prev,
      [key]: value
    }));
  };

  const saveSettings = async () => {
    try {
      setSaving(true);

      await apiFetch({
        path: '/acp/v1/settings',
        method: 'POST',
        data: settings
      });

      alert(__('Settings saved successfully!', 'access-control-pro'));
    } catch (error) {
      setError(__('Failed to save settings', 'access-control-pro'));
    } finally {
      setSaving(false);
    }
  };

  const activateLicense = async () => {
    if (!licenseKey.trim()) {
      setError(__('Please enter a license key', 'access-control-pro'));
      return;
    }

    try {
      setLicenseLoading(true);

      const response = await apiFetch({
        path: '/acp/v1/license',
        method: 'POST',
        data: {
          action: 'activate',
          license_key: licenseKey
        }
      });

      if (response.success) {
        setLicenseData({
          key: licenseKey,
          status: 'valid',
          expires: response.expires || ''
        });
        alert(__('License activated successfully!', 'access-control-pro'));
        // Reload page to enable Pro features
        window.location.reload();
      } else {
        setError(response.message || __('Failed to activate license', 'access-control-pro'));
      }
    } catch (error) {
      setError(__('Failed to activate license', 'access-control-pro'));
    } finally {
      setLicenseLoading(false);
    }
  };

  const deactivateLicense = async () => {
    if (!confirm(__('Are you sure you want to deactivate your license?', 'access-control-pro'))) {
      return;
    }

    try {
      setLicenseLoading(true);

      await apiFetch({
        path: '/acp/v1/license',
        method: 'POST',
        data: {
          action: 'deactivate'
        }
      });

      setLicenseData({
        key: '',
        status: 'invalid',
        expires: ''
      });
      setLicenseKey('');

      alert(__('License deactivated successfully!', 'access-control-pro'));
      // Reload page to disable Pro features
      window.location.reload();
    } catch (error) {
      setError(__('Failed to deactivate license', 'access-control-pro'));
    } finally {
      setLicenseLoading(false);
    }
  };

  if (loading) {
    return <Loading message={__('Loading settings...', 'access-control-pro')} />;
  }

  return (
    <div className="acp-settings">
      <div className="acp-page-header">
        <h1 className="acp-page-title">
          {__('Settings', 'access-control-pro')}
        </h1>
        <p className="acp-page-subtitle">
          {__('Configure plugin options and license', 'access-control-pro')}
        </p>
      </div>

      <div className="acp-settings-content">
        {/* License Section */}
        <div className="acp-settings-section">
          <h2 className="acp-settings-section-title">
            {__('License Management', 'access-control-pro')}
          </h2>

          <div className="acp-license-card">
            <div className="acp-license-status">
              <div className={`acp-license-indicator ${isPro ? 'acp-license-indicator--active' : 'acp-license-indicator--inactive'}`}>
                {isPro ? '✓' : '×'}
              </div>
              <div className="acp-license-info">
                <h3 className="acp-license-title">
                  {isPro ? __('Pro License Active', 'access-control-pro') : __('Free Version', 'access-control-pro')}
                </h3>
                {isPro && licenseData.expires && (
                  <p className="acp-license-expires">
                    {__('Expires:', 'access-control-pro')} {
                      licenseData.expires === 'lifetime'
                        ? __('Never', 'access-control-pro')
                        : new Date(licenseData.expires).toLocaleDateString()
                    }
                  </p>
                )}
              </div>
            </div>

            {!isPro ? (
              <div className="acp-license-activation">
                <div className="acp-form-group">
                  <label className="acp-form-label">
                    {__('License Key', 'access-control-pro')}
                  </label>
                  <input
                    type="text"
                    className="acp-form-input"
                    value={licenseKey}
                    onChange={(e) => setLicenseKey(e.target.value)}
                    placeholder={__('Enter your license key...', 'access-control-pro')}
                  />
                </div>
                <button
                  className="acp-btn acp-btn--primary"
                  onClick={activateLicense}
                  disabled={licenseLoading}
                >
                  {licenseLoading ? __('Activating...', 'access-control-pro') : __('Activate License', 'access-control-pro')}
                </button>
              </div>
            ) : (
              <div className="acp-license-actions">
                <button
                  className="acp-btn acp-btn--secondary"
                  onClick={deactivateLicense}
                  disabled={licenseLoading}
                >
                  {licenseLoading ? __('Deactivating...', 'access-control-pro') : __('Deactivate License', 'access-control-pro')}
                </button>
              </div>
            )}
          </div>

          {!isPro && (
            <div className="acp-upgrade-notice">
              <h3 className="acp-upgrade-notice__title">
                {__('Upgrade to Pro', 'access-control-pro')}
              </h3>
              <p className="acp-upgrade-notice__text">
                {__('Unlock advanced features including unlimited restrictions, activity logging, import/export, and priority support.', 'access-control-pro')}
              </p>
              <ul className="acp-upgrade-features">
                <li>{__('Unlimited user and role restrictions', 'access-control-pro')}</li>
                <li>{__('Advanced activity logging', 'access-control-pro')}</li>
                <li>{__('Import/Export configurations', 'access-control-pro')}</li>
                <li>{__('Time-based restrictions', 'access-control-pro')}</li>
                <li>{__('Priority email support', 'access-control-pro')}</li>
              </ul>
              <a
                href="https://your-site.com/upgrade"
                target="_blank"
                rel="noopener noreferrer"
                className="acp-btn acp-btn--primary acp-btn--large"
              >
                {__('Upgrade Now', 'access-control-pro')}
              </a>
            </div>
          )}
        </div>

        {/* General Settings */}
        <div className="acp-settings-section">
          <h2 className="acp-settings-section-title">
            {__('General Settings', 'access-control-pro')}
          </h2>

          <div className="acp-settings-grid">
            <div className="acp-setting-item">
              <label className="acp-setting-label">
                <input
                  type="checkbox"
                  checked={settings.protect_super_admin}
                  onChange={(e) => handleSettingsChange('protect_super_admin', e.target.checked)}
                />
                <span className="acp-setting-title">
                  {__('Protect Super Admin', 'access-control-pro')}
                </span>
              </label>
              <p className="acp-setting-description">
                {__('Prevent restrictions from being applied to super administrators', 'access-control-pro')}
              </p>
            </div>

            <div className="acp-setting-item">
              <label className="acp-form-label">
                {__('Default Language', 'access-control-pro')}
              </label>
              <select
                className="acp-form-select"
                value={settings.default_language}
                onChange={(e) => handleSettingsChange('default_language', e.target.value)}
              >
                <option value="en_US">{__('English (US)', 'access-control-pro')}</option>
                <option value="fa_IR">{__('Persian (فارسی)', 'access-control-pro')}</option>
              </select>
              <p className="acp-setting-description">
                {__('Choose the default language for the plugin interface', 'access-control-pro')}
              </p>
            </div>
          </div>
        </div>

        {/* Pro Settings */}
        {isPro && (
          <div className="acp-settings-section">
            <h2 className="acp-settings-section-title">
              {__('Pro Settings', 'access-control-pro')}
            </h2>

            <div className="acp-settings-grid">
              <div className="acp-setting-item">
                <label className="acp-setting-label">
                  <input
                    type="checkbox"
                    checked={settings.enable_logging}
                    onChange={(e) => handleSettingsChange('enable_logging', e.target.checked)}
                  />
                  <span className="acp-setting-title">
                    {__('Enable Activity Logging', 'access-control-pro')}
                  </span>
                </label>
                <p className="acp-setting-description">
                  {__('Log all restriction-related activities for audit purposes', 'access-control-pro')}
                </p>
              </div>

              {settings.enable_logging && (
                <div className="acp-setting-item">
                  <label className="acp-form-label">
                    {__('Log Retention (Days)', 'access-control-pro')}
                  </label>
                  <input
                    type="number"
                    className="acp-form-input"
                    value={settings.log_retention_days}
                    onChange={(e) => handleSettingsChange('log_retention_days', parseInt(e.target.value))}
                    min="1"
                    max="365"
                  />
                  <p className="acp-setting-description">
                    {__('Number of days to keep activity logs (1-365)', 'access-control-pro')}
                  </p>
                </div>
              )}

              <div className="acp-setting-item">
                <label className="acp-setting-label">
                  <input
                    type="checkbox"
                    checked={settings.enable_time_restrictions}
                    onChange={(e) => handleSettingsChange('enable_time_restrictions', e.target.checked)}
                  />
                  <span className="acp-setting-title">
                    {__('Enable Time-based Restrictions', 'access-control-pro')}
                  </span>
                </label>
                <p className="acp-setting-description">
                  {__('Allow restrictions to be applied only during specific time periods', 'access-control-pro')}
                </p>
              </div>
            </div>
          </div>
        )}

        {/* System Information */}
        <div className="acp-settings-section">
          <h2 className="acp-settings-section-title">
            {__('System Information', 'access-control-pro')}
          </h2>

          <div className="acp-system-info">
            <div className="acp-info-row">
              <span className="acp-info-label">{__('Plugin Version:', 'access-control-pro')}</span>
              <span className="acp-info-value">1.0.0</span>
            </div>
            <div className="acp-info-row">
              <span className="acp-info-label">{__('WordPress Version:', 'access-control-pro')}</span>
              <span className="acp-info-value">{window.acpAdminData?.wpVersion || 'Unknown'}</span>
            </div>
            <div className="acp-info-row">
              <span className="acp-info-label">{__('PHP Version:', 'access-control-pro')}</span>
              <span className="acp-info-value">{window.acpAdminData?.phpVersion || 'Unknown'}</span>
            </div>
            <div className="acp-info-row">
              <span className="acp-info-label">{__('License Status:', 'access-control-pro')}</span>
              <span className={`acp-info-value acp-status-${isPro ? 'active' : 'inactive'}`}>
                {isPro ? __('Pro Active', 'access-control-pro') : __('Free Version', 'access-control-pro')}
              </span>
            </div>
          </div>
        </div>

        {/* Save Button */}
        <div className="acp-settings-actions">
          <button
            className="acp-btn acp-btn--primary acp-btn--large"
            onClick={saveSettings}
            disabled={saving}
          >
            {saving ? __('Saving...', 'access-control-pro') : __('Save Settings', 'access-control-pro')}
          </button>
        </div>
      </div>
    </div>
  );
};

export default Settings;
