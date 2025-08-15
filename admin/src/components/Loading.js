import React from 'react';

const Loading = ({ message = 'Loading...', size = 'medium' }) => {
  const sizeClasses = {
    small: 'acp-loading--small',
    medium: 'acp-loading--medium',
    large: 'acp-loading--large'
  };

  return (
    <div className={`acp-loading ${sizeClasses[size] || sizeClasses.medium}`}>
      <div className="acp-loading__spinner">
        <div className="acp-loading__bounce acp-loading__bounce--1"></div>
        <div className="acp-loading__bounce acp-loading__bounce--2"></div>
        <div className="acp-loading__bounce acp-loading__bounce--3"></div>
      </div>
      {message && (
        <p className="acp-loading__message">{message}</p>
      )}
    </div>
  );
};

export default Loading;
