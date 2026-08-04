import React, { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { MultipleImageSelector } from './multiple-image-selector';

interface DashboardData {
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
}

interface DashboardProps {
  data: DashboardData;
  onGenerateNow: () => Promise<{ success: boolean; message: string; timestamp?: string }>;
  onImagesChange: (images: Array<{ id: number; position: 'before' | 'after' }>) => void;
  onSettingsChange: (settings: any) => void;
}

export function Dashboard({ data, onGenerateNow, onImagesChange, onSettingsChange }: DashboardProps) {
  const [isGenerating, setIsGenerating] = useState(false);
  const [generateMessage, setGenerateMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
  const [currentImages, setCurrentImages] = useState(data.settings.llm_response_images || []);
  const [formState, setFormState] = useState({
    post_types: data.settings.post_types,
    full_content: data.settings.full_content,
    days_to_include: data.settings.days_to_include,
    delete_on_uninstall: data.settings.delete_on_uninstall,
  });

  const handleGenerateNow = async () => {
    setIsGenerating(true);
    setGenerateMessage(null);
    
    try {
      const result = await onGenerateNow();
      setGenerateMessage({
        type: result.success ? 'success' : 'error',
        text: result.message
      });
      
      // Clear message after 5 seconds
      setTimeout(() => setGenerateMessage(null), 5000);
    } catch (error) {
      setGenerateMessage({
        type: 'error',
        text: 'An error occurred while generating files.'
      });
    } finally {
      setIsGenerating(false);
    }
  };

  const handleImagesChange = (images: Array<{ id: number; position: 'before' | 'after' }>) => {
    setCurrentImages(images);
    onImagesChange(images);
  };

  const handlePostTypeChange = (postType: string, checked: boolean) => {
    const newFormState = {
      ...formState,
      post_types: checked 
        ? [...formState.post_types, postType]
        : formState.post_types.filter(pt => pt !== postType)
    };
    setFormState(newFormState);
    onSettingsChange(newFormState);
  };

  const handleCheckboxChange = (name: 'full_content' | 'delete_on_uninstall', checked: boolean) => {
    const newFormState = {
      ...formState,
      [name]: checked
    };
    setFormState(newFormState);
    onSettingsChange(newFormState);
  };

  const handleDaysChange = (value: string) => {
    const days = parseInt(value, 10);
    const newFormState = {
      ...formState,
      days_to_include: isNaN(days) ? 0 : days
    };
    setFormState(newFormState);
    onSettingsChange(newFormState);
  };

  return (
    <div className="space-y-6">
      {/* Banner */}
      <div className="flex justify-center mb-6">
        <img 
          src={`${(window as any).llmagnetDashboardData?.pluginUrl || ''}assets/react-build/assets/banner_upgrade.png`} 
          alt="LLMagnet AI SEO Optimizer" 
          className="max-w-full h-auto"
          onError={(e) => {
            // Fallback if the image doesn't load
            const target = e.target as HTMLImageElement;
            target.onerror = null;
            target.src = `${(window as any).llmagnetDashboardData?.pluginUrl || ''}src/assets/images/banner_upgrade.png`;
            console.log('Trying fallback image path:', target.src);
          }}
        />
      </div>

      {/* Header */}
      <div className="mb-6">
        <div className="mb-4">
          <h1 className="text-2xl font-semibold text-gray-900 mb-2">LLMagnet Dashboard</h1>
          <p className="text-gray-600">Manage your LLMS.txt file and LLM response settings</p>
        </div>
      </div>

      {/* Status Overview */}
      <div className="bg-white border border-gray-200 shadow-sm p-6 rounded-md">
        <h3 className="text-lg font-medium mb-4">Status Overview</h3>
        
        <div className="flex flex-wrap gap-4">
          <div className="flex items-center bg-gray-50 rounded-lg px-4 py-3 min-w-0 flex-1 min-w-[160px]">
            <div className="flex-1">
              <div className="text-sm font-medium text-gray-600 mb-1">LLMS.txt Status</div>
              <div className={`text-lg font-bold mb-1 ${data.llmsTxtExists ? 'text-green-600' : 'text-red-600'}`}>
                {data.llmsTxtExists ? 'Active' : 'Not Generated'}
              </div>
              {data.llmsTxtSize && (
                <div className="text-sm text-gray-500">{data.llmsTxtSize}</div>
              )}
            </div>
          </div>
          
          <div className="flex items-center bg-gray-50 rounded-lg px-4 py-3 min-w-0 flex-1 min-w-[160px]">
            <div className="flex-1">
              <div className="text-sm font-medium text-gray-600 mb-1">Posts to Export</div>
              <div className="text-lg font-bold text-blue-600 mb-1">{data.postsCount}</div>
              <div className="text-sm text-gray-500">Content items</div>
            </div>
          </div>
          
          <div className="flex items-center bg-gray-50 rounded-lg px-4 py-3 min-w-0 flex-1 min-w-[160px]">
            <div className="flex-1">
              <div className="text-sm font-medium text-gray-600 mb-1">Markdown Files</div>
              <div className="text-lg font-bold text-purple-600 mb-1">{data.markdownCount}</div>
              <div className="text-sm text-gray-500">Generated files</div>
            </div>
          </div>
          
          <div className="flex items-center bg-gray-50 rounded-lg px-4 py-3 min-w-0 flex-1 min-w-[160px]">
            <div className="flex-1">
              <div className="text-sm font-medium text-gray-600 mb-1">LLM Images</div>
              <div className={`text-lg font-bold mb-1 ${currentImages.length > 0 ? 'text-green-600' : 'text-gray-400'}`}>
                {currentImages.length}
              </div>
              <div className="text-sm text-gray-500">Configured</div>
            </div>
          </div>
          
          <div className="flex items-center bg-gray-50 rounded-lg px-4 py-3 min-w-0 flex-1 min-w-[160px]">
            <div className="flex-1">
              <div className="text-sm font-medium text-gray-600 mb-1">Directory Status</div>
              <div className={`text-lg font-bold mb-1 ${data.isWritable ? 'text-green-600' : 'text-red-600'}`}>
                {data.isWritable ? 'Writable' : 'Not Writable'}
              </div>
              <div className="text-sm text-gray-500">Root directory</div>
            </div>
          </div>
        </div>

        {data.lastGenerated && (
          <div className="mt-4 pt-4 border-t border-gray-200">
            <div className="text-sm text-gray-600">
              <strong>Last Generated:</strong> {data.lastGenerated}
            </div>
          </div>
        )}
      </div>

      {/* Generate Section */}
      <div className="flex items-center gap-4">
        <Button 
          onClick={handleGenerateNow}
          disabled={isGenerating || !data.isWritable}
          variant="gradient"
          className="whitespace-nowrap px-6 py-3 text-base font-medium"
        >
          {isGenerating ? 'Generating...' : 'Generate Now'}
        </Button>
        
        {data.llmsTxtExists && (
          <a 
            href={data.llmsTxtUrl} 
            target="_blank" 
            rel="noopener noreferrer"
            className="text-blue-600 hover:text-blue-800 text-sm font-medium whitespace-nowrap"
          >
            View LLMS.txt →
          </a>
        )}
        
        {generateMessage && (
          <div className={`px-3 py-1 rounded-md text-sm ${
            generateMessage.type === 'success' 
              ? 'bg-green-50 text-green-800 border border-green-200'
              : 'bg-red-50 text-red-800 border border-red-200'
          }`}>
            {generateMessage.text}
          </div>
        )}
      </div>

      {/* Warning for non-writable directory */}
      {!data.isWritable && (
        <div className="bg-yellow-50 border border-yellow-200 rounded-md p-4">
          <p className="text-yellow-800 text-sm">
            <strong>Warning:</strong> Root directory is not writable. Please check file permissions before generating files.
          </p>
        </div>
      )}

      {/* Content Settings & LLM Image Settings */}
      <div className="flex flex-col lg:flex-row gap-3 items-stretch">
        {/* Content Settings */}
        <div className="bg-gradient-to-br from-white to-gray-50/50 border border-gray-200/60 shadow-lg backdrop-blur-sm p-7 rounded-2xl w-full max-w-2xl flex-shrink-0">
          <div className="mb-6">
            <h3 className="text-xl font-semibold mb-2 text-gray-900 tracking-tight">Content Settings</h3>
            <p className="text-gray-500 text-sm leading-relaxed">Configure which content should be included in the llms.txt file and associated Markdown exports.</p>
          </div>
          
          <div className="space-y-6">
            <div>
              <h4 className="font-semibold mb-4 text-sm text-gray-800 uppercase tracking-wide">Content Types to Include</h4>
              <div className="space-y-3">
                {data.postTypes.map((postType) => (
                  <div key={postType.name} className="flex items-center gap-4 p-2 rounded-xl hover:bg-white/60 transition-all duration-300">
                    <button
                      onClick={() => handlePostTypeChange(postType.name, !formState.post_types.includes(postType.name))}
                      className={`relative inline-flex h-6 w-11 items-center rounded-full transition-all duration-300 focus:outline-none focus:ring-2 focus:ring-purple-500/20 ${
                        formState.post_types.includes(postType.name) 
                          ? 'bg-gradient-to-r from-purple-500 to-pink-500 shadow-lg shadow-purple-500/25' 
                          : 'bg-gray-300 hover:bg-gray-400'
                      }`}
                    >
                      <span
                        className={`inline-block h-4 w-4 transform rounded-full bg-white transition-all duration-300 shadow-md ${
                          formState.post_types.includes(postType.name) ? 'translate-x-6 shadow-purple-500/20' : 'translate-x-1'
                        }`}
                      />
                    </button>
                    <span className="text-sm font-medium text-gray-700">
                      {postType.label}
                    </span>
                  </div>
                ))}
              </div>
            </div>
            
            <div>
              <h4 className="font-semibold mb-4 text-sm text-gray-800 uppercase tracking-wide">Content Export</h4>
              <div className="flex items-center gap-4 p-2 rounded-xl hover:bg-white/60 transition-all duration-300">
                <button
                  onClick={() => handleCheckboxChange('full_content', !formState.full_content)}
                  className={`relative inline-flex h-6 w-11 items-center rounded-full transition-all duration-300 focus:outline-none focus:ring-2 focus:ring-purple-500/20 ${
                    formState.full_content 
                      ? 'bg-gradient-to-r from-purple-500 to-pink-500 shadow-lg shadow-purple-500/25' 
                      : 'bg-gray-300 hover:bg-gray-400'
                  }`}
                >
                  <span
                    className={`inline-block h-4 w-4 transform rounded-full bg-white transition-all duration-300 shadow-md ${
                      formState.full_content ? 'translate-x-6 shadow-purple-500/20' : 'translate-x-1'
                    }`}
                  />
                </button>
                <span className="text-sm font-medium text-gray-700">
                  Include full content {!formState.full_content && '(excerpt only)'}
                </span>
              </div>
            </div>
            
            <div>
              <h4 className="font-semibold mb-4 text-sm text-gray-800 uppercase tracking-wide">Time Period</h4>
              <div className="flex items-center gap-4 p-2 rounded-xl bg-white/60">
                <div className="flex-1 max-w-32">
                  <Input 
                    type="number" 
                    min="0"
                    value={formState.days_to_include}
                    onChange={(e) => handleDaysChange(e.target.value)}
                    className="text-center font-semibold border-gray-300/50 focus:border-purple-400 focus:ring-purple-400/20 rounded-xl"
                  />
                </div>
                <span className="text-sm font-medium text-gray-600">
                  {formState.days_to_include > 0 ? `days of content` : 'all content'}
                </span>
              </div>
            </div>
            
            <div>
              <h4 className="font-semibold mb-4 text-sm text-gray-800 uppercase tracking-wide">Cleanup on Uninstall</h4>
              <div className="flex items-center gap-4 p-2 rounded-xl hover:bg-white/60 transition-all duration-300">
                <button
                  onClick={() => handleCheckboxChange('delete_on_uninstall', !formState.delete_on_uninstall)}
                  className={`relative inline-flex h-6 w-11 items-center rounded-full transition-all duration-300 focus:outline-none focus:ring-2 focus:ring-red-500/20 ${
                    formState.delete_on_uninstall 
                      ? 'bg-red-500 shadow-lg shadow-red-500/25' 
                      : 'bg-gray-300 hover:bg-gray-400'
                  }`}
                >
                  <span
                    className={`inline-block h-4 w-4 transform rounded-full bg-white transition-all duration-300 shadow-md ${
                      formState.delete_on_uninstall ? 'translate-x-6 shadow-red-500/20' : 'translate-x-1'
                    }`}
                  />
                </button>
                <span className="text-sm font-medium text-gray-700">
                  Delete files when plugin is uninstalled
                </span>
              </div>
            </div>
          </div>
        </div>

        {/* LLM Response Images Section */}
        <div className="bg-gradient-to-br from-white to-gray-50/50 border border-gray-200/60 shadow-lg backdrop-blur-sm p-7 rounded-2xl w-full max-w-2xl flex-shrink-0">
          <div className="mb-6">
            <h3 className="text-xl font-semibold mb-2 text-gray-900 tracking-tight">LLM Response Images</h3>
            <p className="text-gray-500 text-sm leading-relaxed">Select multiple images to display with responses from all Large Language Models. These images will be included in the llms.txt file and will work with ChatGPT, Claude, Gemini, GPT-4, and other LLMs.</p>
          </div>
          
          <MultipleImageSelector
            currentImages={currentImages}
            onImagesChange={handleImagesChange}
            isPremium={true} // Temporarily set to true, you'll configure this later
          />
        </div>
      </div>

      {/* System Information */}
      <div className="bg-white border border-gray-200 shadow-sm p-6 rounded-md">
        <h3 className="text-lg font-medium mb-4">System Information</h3>
        
        <div className="space-y-3">
          <div className="flex justify-between items-center">
            <Label className="text-sm font-medium text-gray-700">WordPress Version</Label>
            <span className="text-sm text-gray-600">{data.wordpressVersion}</span>
          </div>
          
          <div className="flex justify-between items-center">
            <Label className="text-sm font-medium text-gray-700">Plugin Version</Label>
            <span className="text-sm text-gray-600">{data.pluginVersion}</span>
          </div>
          
          <div className="flex justify-between items-center">
            <Label className="text-sm font-medium text-gray-700">Root Path</Label>
            <code className="text-xs text-gray-600 bg-gray-100 px-2 py-1 rounded">
              {data.rootPath}
            </code>
          </div>
        </div>
      </div>
    </div>
  );
}
