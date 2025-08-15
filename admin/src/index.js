import React from 'react';
import { createRoot } from 'react-dom/client';
import { HashRouter } from 'react-router-dom';
import App from './App';
import './styles/main.css';

// Wait for DOM to be ready
document.addEventListener('DOMContentLoaded', () => {
  const container = document.getElementById('acp-admin-app');

  if (container) {
    const root = createRoot(container);

    root.render(
      <HashRouter>
        <App />
      </HashRouter>
    );
  }
});
