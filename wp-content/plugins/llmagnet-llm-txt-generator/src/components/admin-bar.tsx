import React from 'react';

export function AdminBar() {
  // Get the plugin URL from WordPress
  const pluginUrl = window.llmagnetLlmsTxtAdmin?.pluginUrl || '';

  return (
    <div className="bg-white border-b border-gray-200 shadow-sm py-2 px-4 flex justify-between items-center mb-6">
      <div className="flex items-center">
        <img 
          src={`${pluginUrl}assets/react-build/assets/fkjogo.svg`}
          alt="LLMagnet AI SEO Optimizer" 
          className="h-8"
          onError={(e) => {
            // Fallback if the image doesn't load
            const target = e.target as HTMLImageElement;
            target.onerror = null;
            target.src = `${pluginUrl}src/assets/images/fkjogo.svg`;
            console.log('Trying fallback image path:', target.src);
          }}
        />
      </div>
      <div>
        <a 
          href="#" 
          className="text-blue-600 hover:text-blue-800 font-medium"
          onClick={(e) => {
            e.preventDefault();
            // You can replace this with actual support functionality
            window.open('https://llmagnet.com', '_blank');
          }}
        >
          Support
        </a>
      </div>
    </div>
  );
} 