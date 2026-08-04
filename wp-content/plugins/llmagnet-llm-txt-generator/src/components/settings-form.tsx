import React from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { ImageSelector } from './image-selector';

interface PostType {
  name: string;
  label: string;
}

interface ImageData {
  id: number;
  url: string;
  preview_url: string;
  width?: number;
  height?: number;
}

interface SettingsFormProps {
  settings: {
    post_types: string[];
    full_content: boolean;
    days_to_include: number;
    delete_on_uninstall: boolean;
    llm_response_image_id?: number;
    llm_response_image_position?: 'before' | 'after';
  };
  postTypes: PostType[];
  isPremium: boolean;
  imageData?: ImageData | null;
  onSubmit: (settings: any) => void;
}

export function SettingsForm({ settings, postTypes, isPremium, imageData, onSubmit }: SettingsFormProps) {
  const [formState, setFormState] = React.useState({
    post_types: settings.post_types,
    full_content: settings.full_content,
    days_to_include: settings.days_to_include,
    delete_on_uninstall: settings.delete_on_uninstall,
    llm_response_image_id: settings.llm_response_image_id || 0,
    llm_response_image_position: settings.llm_response_image_position || 'after',
  });

  const [currentImageData, setCurrentImageData] = React.useState<ImageData | null>(imageData || null);

  const handlePostTypeChange = (postType: string, checked: boolean) => {
    setFormState(prev => ({
      ...prev,
      post_types: checked 
        ? [...prev.post_types, postType]
        : prev.post_types.filter(pt => pt !== postType)
    }));
  };

  const handleCheckboxChange = (name: 'full_content' | 'delete_on_uninstall', checked: boolean) => {
    setFormState(prev => ({
      ...prev,
      [name]: checked
    }));
  };

  const handleDaysChange = (value: string) => {
    const days = parseInt(value, 10);
    setFormState(prev => ({
      ...prev,
      days_to_include: isNaN(days) ? 0 : days
    }));
  };

  const handleImageSelect = (imageData: ImageData | null) => {
    setCurrentImageData(imageData);
    setFormState(prev => ({
      ...prev,
      llm_response_image_id: imageData ? imageData.id : 0,
    }));
  };

  const handleImagePositionChange = (position: 'before' | 'after') => {
    setFormState(prev => ({
      ...prev,
      llm_response_image_position: position,
    }));
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    onSubmit(formState);
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-6">
      <div className="bg-white border border-gray-200 shadow-sm p-4 rounded-md">
        <h3 className="text-lg font-medium mb-4">General Settings</h3>
        <p className="mb-4 text-gray-600">Configure which content should be included in the llms.txt file and associated Markdown exports.</p>
        
        <div className="space-y-4">
          <div>
            <h4 className="font-medium mb-2">Content Types to Include</h4>
            <div className="space-y-2">
              {postTypes.map((postType) => (
                <div key={postType.name} className="flex items-center space-x-2">
                  <Checkbox 
                    id={`post-type-${postType.name}`}
                    checked={formState.post_types.includes(postType.name)}
                    onCheckedChange={(checked) => handlePostTypeChange(postType.name, !!checked)}
                  />
                  <Label htmlFor={`post-type-${postType.name}`}>{postType.label}</Label>
                </div>
              ))}
            </div>
          </div>
          
          <div>
            <h4 className="font-medium mb-2">Content Export</h4>
            <div className="flex items-center space-x-2">
              <Checkbox 
                id="full-content"
                checked={formState.full_content}
                onCheckedChange={(checked) => handleCheckboxChange('full_content', !!checked)}
              />
              <Label htmlFor="full-content">Include full content (unchecked = excerpt only)</Label>
            </div>
          </div>
          
          <div>
            <h4 className="font-medium mb-2">Time Period</h4>
            <div className="flex flex-col space-y-2">
              <Input 
                type="number" 
                min="0"
                value={formState.days_to_include}
                onChange={(e) => handleDaysChange(e.target.value)}
              />
              <p className="text-sm text-gray-500">Number of days of content to include (0 = all content)</p>
            </div>
          </div>
          
          <div>
            <h4 className="font-medium mb-2">Cleanup on Uninstall</h4>
            <div className="flex items-center space-x-2">
              <Checkbox 
                id="delete-on-uninstall"
                checked={formState.delete_on_uninstall}
                onCheckedChange={(checked) => handleCheckboxChange('delete_on_uninstall', !!checked)}
              />
              <Label htmlFor="delete-on-uninstall">Delete llms.txt and llms-docs/ directory when plugin is uninstalled</Label>
            </div>
          </div>
        </div>
      </div>
      
      {/* Image Selector Section */}
      <ImageSelector
        currentImage={currentImageData}
        position={formState.llm_response_image_position as 'before' | 'after'}
        onImageSelect={handleImageSelect}
        onPositionChange={handleImagePositionChange}
        isPremium={isPremium}
      />
      
      <Button type="submit" variant="gradient">Save Changes</Button>
    </form>
  );
} 