import React from 'react';
import { __ } from '@wordpress/i18n';

class ErrorBoundary extends React.Component {
  constructor(props) {
    super(props);
    this.state = { hasError: false, error: null, errorInfo: null };
  }

  static getDerivedStateFromError(error) {
    return { hasError: true };
  }

  componentDidCatch(error, errorInfo) {
    this.setState({
      error,
      errorInfo
    });

    // Log error to console in development
    if (process.env.NODE_ENV === 'development') {
      console.error('ErrorBoundary caught an error:', error, errorInfo);
    }
  }

  render() {
    if (this.state.hasError) {
      return (
        <div className="acp-error-boundary">
          <div className="acp-error-boundary__content">
            <h2 className="acp-error-boundary__title">
              {__('Something went wrong', 'access-control-pro')}
            </h2>
            <p className="acp-error-boundary__message">
              {__('An unexpected error occurred. Please refresh the page and try again.', 'access-control-pro')}
            </p>

            {process.env.NODE_ENV === 'development' && (
              <details className="acp-error-boundary__details">
                <summary>{__('Error Details', 'access-control-pro')}</summary>
                <pre className="acp-error-boundary__stack">
                  {this.state.error && this.state.error.toString()}
                  <br />
                  {this.state.errorInfo.componentStack}
                </pre>
              </details>
            )}

            <div className="acp-error-boundary__actions">
              <button
                className="button button-primary"
                onClick={() => window.location.reload()}
              >
                {__('Refresh Page', 'access-control-pro')}
              </button>

              <button
                className="button button-secondary"
                onClick={() => this.setState({ hasError: false, error: null, errorInfo: null })}
              >
                {__('Try Again', 'access-control-pro')}
              </button>
            </div>
          </div>
        </div>
      );
    }

    return this.props.children;
  }
}

export default ErrorBoundary;
