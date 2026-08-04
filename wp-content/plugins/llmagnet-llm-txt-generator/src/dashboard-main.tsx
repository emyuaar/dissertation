import React from 'react'
import ReactDOM from 'react-dom/client'
import { Dashboard } from './components/dashboard'
import './index.css'

declare global {
  interface Window {
    llmagnetDashboardData: {
      ajaxUrl: string;
      nonce: string;
      rootPath: string;
      isWritable: boolean;
      lastGenerated: string | null;
      llmsTxtExists: boolean;
      llmsTxtUrl: string;
      llmsTxtSize?: string;
      postsCount: number;
      markdownCount: number;
      settings: {
        post_types: string[];
        full_content: boolean;
        days_to_include: number;
        delete_on_uninstall: boolean;
        llm_response_images?: Array<{
          id: number;
          position: 'before' | 'after';
        }>;
        // Keep old fields for backward compatibility
        llm_response_image_id?: number;
        llm_response_image_position?: 'before' | 'after';
      };
      postTypes: Array<{
        name: string;
        label: string;
      }>;
      isPremium: boolean;
      imageData?: {
        id: number;
        url: string;
        preview_url: string;
        width?: number;
        height?: number;
      } | null;
      pluginVersion: string;
      wordpressVersion: string;
      pluginUrl: string;
    };
  }
}

function DashboardApp() {
  const [isLoading, setIsLoading] = React.useState(true);
  const [error, setError] = React.useState<string | null>(null);
  const [data, setData] = React.useState<typeof window.llmagnetDashboardData | null>(null);

  React.useEffect(() => {
    try {
      if (window.llmagnetDashboardData) {
        setData(window.llmagnetDashboardData);
      } else {
        setError('Dashboard data not found.');
      }
    } catch (err) {
      console.error('Error loading dashboard data:', err);
      setError('Error loading dashboard data.');
    } finally {
      setIsLoading(false);
    }
  }, []);

  const handleGenerateNow = async () => {
    if (!data) return { success: false, message: 'No data available' };

    try {
      const formData = new FormData();
      formData.append('action', 'llmagnet_ai_seo_generate_now');
      formData.append('nonce', data.nonce);

      const response = await fetch(data.ajaxUrl, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
      });

      const result = await response.json();

      return {
        success: result.success,
        message: result.data?.message || 'Unknown error',
        timestamp: result.data?.timestamp,
      };
    } catch (error) {
      console.error('Error generating LLMS.txt:', error);
      return {
        success: false,
        message: 'Error generating LLMS.txt. Please check server permissions.',
      };
    }
  };

  const handleImagesChange = async (images: Array<{ id: number; position: 'before' | 'after' }>) => {
    if (!data) return;

    // Update local state immediately
    setData(prev => prev ? {
      ...prev,
      settings: {
        ...prev.settings,
        llm_response_images: images
      }
    } : null);

    // Save to server
    try {
      const newSettings = {
        ...data.settings,
        llm_response_images: images
      };

      const formData = new FormData();
      formData.append('action', 'llmagnet_ai_seo_save_settings');
      formData.append('nonce', data.nonce);
      formData.append('settings', JSON.stringify(newSettings));

      const response = await fetch(data.ajaxUrl, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
      });

      const result = await response.json();
      
      if (!result.success) {
        console.error('Failed to save images setting:', result.data?.message);
      }
    } catch (error) {
      console.error('Error saving images setting:', error);
    }
  };

  const handleSettingsChange = async (settings: any) => {
    if (!data) return;

    // Update local state immediately
    setData(prev => prev ? {
      ...prev,
      settings: {
        ...prev.settings,
        ...settings
      }
    } : null);

    // Save to server
    try {
      const newSettings = {
        ...data.settings,
        ...settings
      };

      const formData = new FormData();
      formData.append('action', 'llmagnet_ai_seo_save_settings');
      formData.append('nonce', data.nonce);
      formData.append('settings', JSON.stringify(newSettings));

      const response = await fetch(data.ajaxUrl, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
      });

      const result = await response.json();
      
      if (!result.success) {
        console.error('Failed to save settings:', result.data?.message);
      }
    } catch (error) {
      console.error('Error saving settings:', error);
    }
  };

  if (isLoading) {
    return <div className="p-4">Loading dashboard...</div>;
  }

  if (error || !data) {
    return <div className="p-4 text-red-600">{error || 'Unknown error'}</div>;
  }

  return (
    <div className="llms-txt-react-app">
      <div className="wrap">
        <Dashboard 
          data={data}
          onGenerateNow={handleGenerateNow}
          onImagesChange={handleImagesChange}
          onSettingsChange={handleSettingsChange}
        />
      </div>
    </div>
  );
}

const container = document.getElementById('llms-txt-dashboard-app');
if (container) {
  const root = ReactDOM.createRoot(container);
  root.render(<DashboardApp />);
}
