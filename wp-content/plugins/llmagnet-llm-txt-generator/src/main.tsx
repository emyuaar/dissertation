import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App';
import './index.css';

// Add debugging
console.log('LLMagnet AI SEO Optimizer React app initializing...');
console.log('Looking for element with ID: llms-txt-app');

const rootElement = document.getElementById('llms-txt-app');

if (rootElement) {
  console.log('Root element found, mounting React app...');
  
  try {
    // Clear any existing content (fallback content)
    rootElement.innerHTML = '';
    
    ReactDOM.createRoot(rootElement).render(
      <React.StrictMode>
        <App />
      </React.StrictMode>,
    );
    console.log('React app mounted successfully');
  } catch (error) {
    console.error('Error mounting React app:', error);
  }
} else {
  console.error('Root element not found! Make sure there is a div with ID "llms-txt-app" in the page.');
} 