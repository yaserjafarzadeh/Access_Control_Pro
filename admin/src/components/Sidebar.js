import React from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { __ } from '@wordpress/i18n';
import { useAppContext } from '../context/AppContext';

const Sidebar = () => {
  const { isPro } = useAppContext();
  const location = useLocation();

  const menuItems = [
    {
      path: '/dashboard',
      label: __('Dashboard', 'access-control-pro'),
      icon: '📊',
      pro: false
    },
    {
      path: '/user-restrictions',
      label: __('User Restrictions', 'access-control-pro'),
      icon: '👤',
      pro: false
    },
    {
      path: '/role-restrictions',
      label: __('Role Restrictions', 'access-control-pro'),
      icon: '👥',
      pro: false
    },
    {
      path: '/plugin-control',
      label: __('Plugin Control', 'access-control-pro'),
      icon: '🔌',
      pro: false
    },
    {
      path: '/logs',
      label: __('Activity Logs', 'access-control-pro'),
      icon: '📝',
      pro: true
    },
    {
      path: '/import-export',
      label: __('Import/Export', 'access-control-pro'),
      icon: '📤',
      pro: true
    },
    {
      path: '/settings',
      label: __('Settings', 'access-control-pro'),
      icon: '⚙️',
      pro: false
    }
  ];

  const isActivePath = (path) => {
    if (path === '/dashboard') {
      return location.pathname === '/' || location.pathname === '/dashboard';
    }
    return location.pathname === path;
  };

  return (
    <nav className="acp-sidebar">
      <div className="acp-sidebar__header">
        <h2 className="acp-sidebar__title">
          {__('Access Control Pro', 'access-control-pro')}
        </h2>
        {isPro && (
          <span className="acp-sidebar__pro-badge">
            {__('PRO', 'access-control-pro')}
          </span>
        )}
      </div>

      <ul className="acp-sidebar__menu">
        {menuItems.map((item) => {
          // Show pro items only if pro version is active
          if (item.pro && !isPro) {
            return (
              <li key={item.path} className="acp-sidebar__item acp-sidebar__item--pro-disabled">
                <div className="acp-sidebar__link acp-sidebar__link--disabled">
                  <span className="acp-sidebar__icon">{item.icon}</span>
                  <span className="acp-sidebar__label">{item.label}</span>
                  <span className="acp-sidebar__pro-tag">
                    {__('PRO', 'access-control-pro')}
                  </span>
                </div>
              </li>
            );
          }

          return (
            <li key={item.path} className="acp-sidebar__item">
              <NavLink
                to={item.path}
                className={`acp-sidebar__link ${isActivePath(item.path) ? 'acp-sidebar__link--active' : ''}`}
              >
                <span className="acp-sidebar__icon">{item.icon}</span>
                <span className="acp-sidebar__label">{item.label}</span>
              </NavLink>
            </li>
          );
        })}
      </ul>

      {!isPro && (
        <div className="acp-sidebar__upgrade">
          <div className="acp-sidebar__upgrade-content">
            <h3 className="acp-sidebar__upgrade-title">
              {__('Upgrade to Pro', 'access-control-pro')}
            </h3>
            <p className="acp-sidebar__upgrade-text">
              {__('Unlock advanced features and unlimited restrictions', 'access-control-pro')}
            </p>
            <a
              href="https://your-site.com/upgrade"
              target="_blank"
              rel="noopener noreferrer"
              className="acp-sidebar__upgrade-button"
            >
              {__('Upgrade Now', 'access-control-pro')}
            </a>
          </div>
        </div>
      )}

      <div className="acp-sidebar__footer">
        <p className="acp-sidebar__version">
          {__('Version', 'access-control-pro')} 1.0.0
        </p>
      </div>
    </nav>
  );
};

export default Sidebar;
