import React, { useState } from 'react';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useAppContext } from '../context/AppContext';
import Loading from '../components/Loading';

const ImportExport = () => {
  const { setError } = useAppContext();
  const [activeTab, setActiveTab] = useState('export');
  const [exportOptions, setExportOptions] = useState({
    include_users: true,
    include_roles: true,
    include_plugins: true,
    include_content: true,
    include_settings: false
  });
  const [importFile, setImportFile] = useState(null);
  const [importOptions, setImportOptions] = useState({
    overwrite_existing: false,
    backup_before_import: true,
    validate_data: true
  });
  const [loading, setLoading] = useState(false);
  const [exportData, setExportData] = useState(null);
  const [importPreview, setImportPreview] = useState(null);

  const handleExport = async () => {
    try {
      setLoading(true);

      const response = await apiFetch({
        path: '/acp/v1/export',
        method: 'POST',
        data: exportOptions
      });

      setExportData(response);

      // Auto-download the file
      const blob = new Blob([JSON.stringify(response, null, 2)], {
        type: 'application/json'
      });
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `access-control-backup-${new Date().toISOString().split('T')[0]}.json`;
      document.body.appendChild(a);
      a.click();
      window.URL.revokeObjectURL(url);
      document.body.removeChild(a);

      alert(__('Export completed successfully!', 'access-control-pro'));
    } catch (error) {
      setError(__('Failed to export data', 'access-control-pro'));
    } finally {
      setLoading(false);
    }
  };

  const handleFileSelect = async (event) => {
    const file = event.target.files[0];
    if (!file) return;

    setImportFile(file);

    try {
      const text = await file.text();
      const data = JSON.parse(text);

      // Validate the structure
      if (!data.version || !data.restrictions) {
        throw new Error(__('Invalid backup file format', 'access-control-pro'));
      }

      setImportPreview(data);
    } catch (error) {
      setError(__('Invalid file format. Please select a valid backup file.', 'access-control-pro'));
      setImportFile(null);
      setImportPreview(null);
    }
  };

  const handleImport = async () => {
    if (!importFile || !importPreview) {
      setError(__('Please select a valid backup file first', 'access-control-pro'));
      return;
    }

    if (!confirm(__('This will replace your current restrictions. Are you sure?', 'access-control-pro'))) {
      return;
    }

    try {
      setLoading(true);

      const formData = new FormData();
      formData.append('file', importFile);
      formData.append('options', JSON.stringify(importOptions));

      const response = await apiFetch({
        path: '/acp/v1/import',
        method: 'POST',
        body: formData
      });

      if (response.success) {
        alert(__('Import completed successfully!', 'access-control-pro'));
        setImportFile(null);
        setImportPreview(null);
        // Reload the page to refresh data
        window.location.reload();
      } else {
        throw new Error(response.message || __('Import failed', 'access-control-pro'));
      }
    } catch (error) {
      setError(error.message || __('Failed to import data', 'access-control-pro'));
    } finally {
      setLoading(false);
    }
  };

  const ExportTab = () => (
    <div className="acp-export-tab">
      <div className="acp-export-options">
        <h3 className="acp-section-title">
          {__('Export Options', 'access-control-pro')}
        </h3>
        <p className="acp-section-description">
          {__('Select which data to include in your backup file', 'access-control-pro')}
        </p>

        <div className="acp-checkbox-list">
          <label className="acp-checkbox-item">
            <input
              type="checkbox"
              checked={exportOptions.include_users}
              onChange={(e) => setExportOptions(prev => ({
                ...prev,
                include_users: e.target.checked
              }))}
            />
            <span className="acp-checkbox-label">
              <strong>{__('User Restrictions', 'access-control-pro')}</strong>
              <small>{__('All user-specific access restrictions', 'access-control-pro')}</small>
            </span>
          </label>

          <label className="acp-checkbox-item">
            <input
              type="checkbox"
              checked={exportOptions.include_roles}
              onChange={(e) => setExportOptions(prev => ({
                ...prev,
                include_roles: e.target.checked
              }))}
            />
            <span className="acp-checkbox-label">
              <strong>{__('Role Restrictions', 'access-control-pro')}</strong>
              <small>{__('All role-based access restrictions', 'access-control-pro')}</small>
            </span>
          </label>

          <label className="acp-checkbox-item">
            <input
              type="checkbox"
              checked={exportOptions.include_plugins}
              onChange={(e) => setExportOptions(prev => ({
                ...prev,
                include_plugins: e.target.checked
              }))}
            />
            <span className="acp-checkbox-label">
              <strong>{__('Plugin Restrictions', 'access-control-pro')}</strong>
              <small>{__('Plugin access control settings', 'access-control-pro')}</small>
            </span>
          </label>

          <label className="acp-checkbox-item">
            <input
              type="checkbox"
              checked={exportOptions.include_content}
              onChange={(e) => setExportOptions(prev => ({
                ...prev,
                include_content: e.target.checked
              }))}
            />
            <span className="acp-checkbox-label">
              <strong>{__('Content Restrictions', 'access-control-pro')}</strong>
              <small>{__('Post, page, and content access restrictions', 'access-control-pro')}</small>
            </span>
          </label>

          <label className="acp-checkbox-item">
            <input
              type="checkbox"
              checked={exportOptions.include_settings}
              onChange={(e) => setExportOptions(prev => ({
                ...prev,
                include_settings: e.target.checked
              }))}
            />
            <span className="acp-checkbox-label">
              <strong>{__('Plugin Settings', 'access-control-pro')}</strong>
              <small>{__('General plugin configuration and preferences', 'access-control-pro')}</small>
            </span>
          </label>
        </div>

        <div className="acp-export-actions">
          <button
            className="acp-btn acp-btn--primary acp-btn--large"
            onClick={handleExport}
            disabled={loading || !Object.values(exportOptions).some(v => v)}
          >
            {loading ? __('Exporting...', 'access-control-pro') : __('Export Data', 'access-control-pro')}
          </button>
        </div>
      </div>

      {exportData && (
        <div className="acp-export-result">
          <h3 className="acp-section-title">
            {__('Export Summary', 'access-control-pro')}
          </h3>

          <div className="acp-export-stats">
            <div className="acp-export-stat">
              <span className="acp-export-stat__label">{__('File Size:', 'access-control-pro')}</span>
              <span className="acp-export-stat__value">
                {(JSON.stringify(exportData).length / 1024).toFixed(2)} KB
              </span>
            </div>
            <div className="acp-export-stat">
              <span className="acp-export-stat__label">{__('Restrictions:', 'access-control-pro')}</span>
              <span className="acp-export-stat__value">
                {exportData.restrictions?.length || 0}
              </span>
            </div>
            <div className="acp-export-stat">
              <span className="acp-export-stat__label">{__('Created:', 'access-control-pro')}</span>
              <span className="acp-export-stat__value">
                {new Date(exportData.timestamp).toLocaleString()}
              </span>
            </div>
          </div>
        </div>
      )}
    </div>
  );

  const ImportTab = () => (
    <div className="acp-import-tab">
      <div className="acp-import-file">
        <h3 className="acp-section-title">
          {__('Select Backup File', 'access-control-pro')}
        </h3>
        <p className="acp-section-description">
          {__('Choose a backup file exported from Access Control Pro', 'access-control-pro')}
        </p>

        <div className="acp-file-upload">
          <input
            type="file"
            accept=".json"
            onChange={handleFileSelect}
            className="acp-file-input"
            id="import-file"
          />
          <label htmlFor="import-file" className="acp-file-label">
            <div className="acp-file-upload__content">
              <div className="acp-file-upload__icon">📁</div>
              <div className="acp-file-upload__text">
                {importFile
                  ? importFile.name
                  : __('Click to select backup file or drag and drop', 'access-control-pro')
                }
              </div>
            </div>
          </label>
        </div>
      </div>

      {importPreview && (
        <div className="acp-import-preview">
          <h3 className="acp-section-title">
            {__('Backup File Preview', 'access-control-pro')}
          </h3>

          <div className="acp-preview-stats">
            <div className="acp-preview-stat">
              <span className="acp-preview-stat__label">{__('Plugin Version:', 'access-control-pro')}</span>
              <span className="acp-preview-stat__value">{importPreview.version}</span>
            </div>
            <div className="acp-preview-stat">
              <span className="acp-preview-stat__label">{__('Created:', 'access-control-pro')}</span>
              <span className="acp-preview-stat__value">
                {new Date(importPreview.timestamp).toLocaleString()}
              </span>
            </div>
            <div className="acp-preview-stat">
              <span className="acp-preview-stat__label">{__('Restrictions:', 'access-control-pro')}</span>
              <span className="acp-preview-stat__value">
                {importPreview.restrictions?.length || 0}
              </span>
            </div>
          </div>

          <div className="acp-import-options">
            <h4 className="acp-subsection-title">
              {__('Import Options', 'access-control-pro')}
            </h4>

            <div className="acp-checkbox-list">
              <label className="acp-checkbox-item">
                <input
                  type="checkbox"
                  checked={importOptions.backup_before_import}
                  onChange={(e) => setImportOptions(prev => ({
                    ...prev,
                    backup_before_import: e.target.checked
                  }))}
                />
                <span className="acp-checkbox-label">
                  <strong>{__('Create backup before import', 'access-control-pro')}</strong>
                  <small>{__('Automatically backup current settings before importing', 'access-control-pro')}</small>
                </span>
              </label>

              <label className="acp-checkbox-item">
                <input
                  type="checkbox"
                  checked={importOptions.overwrite_existing}
                  onChange={(e) => setImportOptions(prev => ({
                    ...prev,
                    overwrite_existing: e.target.checked
                  }))}
                />
                <span className="acp-checkbox-label">
                  <strong>{__('Overwrite existing restrictions', 'access-control-pro')}</strong>
                  <small>{__('Replace existing restrictions with imported data', 'access-control-pro')}</small>
                </span>
              </label>

              <label className="acp-checkbox-item">
                <input
                  type="checkbox"
                  checked={importOptions.validate_data}
                  onChange={(e) => setImportOptions(prev => ({
                    ...prev,
                    validate_data: e.target.checked
                  }))}
                />
                <span className="acp-checkbox-label">
                  <strong>{__('Validate imported data', 'access-control-pro')}</strong>
                  <small>{__('Check data integrity before applying changes', 'access-control-pro')}</small>
                </span>
              </label>
            </div>
          </div>

          <div className="acp-import-actions">
            <button
              className="acp-btn acp-btn--primary acp-btn--large"
              onClick={handleImport}
              disabled={loading}
            >
              {loading ? __('Importing...', 'access-control-pro') : __('Import Data', 'access-control-pro')}
            </button>

            <button
              className="acp-btn acp-btn--secondary acp-btn--large"
              onClick={() => {
                setImportFile(null);
                setImportPreview(null);
              }}
            >
              {__('Cancel', 'access-control-pro')}
            </button>
          </div>
        </div>
      )}
    </div>
  );

  return (
    <div className="acp-import-export">
      <div className="acp-page-header">
        <h1 className="acp-page-title">
          {__('Import/Export', 'access-control-pro')}
          <span className="acp-pro-badge">{__('PRO', 'access-control-pro')}</span>
        </h1>
        <p className="acp-page-subtitle">
          {__('Backup and restore your access control settings', 'access-control-pro')}
        </p>
      </div>

      {/* Tab Navigation */}
      <div className="acp-tab-nav">
        <button
          className={`acp-tab-btn ${activeTab === 'export' ? 'acp-tab-btn--active' : ''}`}
          onClick={() => setActiveTab('export')}
        >
          {__('Export', 'access-control-pro')}
        </button>
        <button
          className={`acp-tab-btn ${activeTab === 'import' ? 'acp-tab-btn--active' : ''}`}
          onClick={() => setActiveTab('import')}
        >
          {__('Import', 'access-control-pro')}
        </button>
      </div>

      {/* Tab Content */}
      <div className="acp-tab-content">
        {loading && (
          <div className="acp-loading-overlay">
            <Loading message={
              activeTab === 'export'
                ? __('Exporting data...', 'access-control-pro')
                : __('Importing data...', 'access-control-pro')
            } />
          </div>
        )}

        {activeTab === 'export' && <ExportTab />}
        {activeTab === 'import' && <ImportTab />}
      </div>

      {/* Help Section */}
      <div className="acp-help-section">
        <h3 className="acp-help-title">
          {__('Import/Export Help', 'access-control-pro')}
        </h3>

        <div className="acp-help-content">
          <div className="acp-help-item">
            <h4>{__('When to Export', 'access-control-pro')}</h4>
            <ul>
              <li>{__('Before making major changes to your restrictions', 'access-control-pro')}</li>
              <li>{__('When migrating to a new WordPress installation', 'access-control-pro')}</li>
              <li>{__('As a regular backup routine', 'access-control-pro')}</li>
            </ul>
          </div>

          <div className="acp-help-item">
            <h4>{__('Import Guidelines', 'access-control-pro')}</h4>
            <ul>
              <li>{__('Always create a backup before importing', 'access-control-pro')}</li>
              <li>{__('Verify the backup file is from a compatible version', 'access-control-pro')}</li>
              <li>{__('Test imports on a staging site first', 'access-control-pro')}</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  );
};

export default ImportExport;
