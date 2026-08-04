import React, { useState, useRef } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';

interface ImageData {
  id: number;
  url: string;
  preview_url: string;
  width?: number;
  height?: number;
}

interface ImageSelectorProps {
  currentImage?: ImageData | null;
  position: 'before' | 'after';
  onImageSelect: (imageData: ImageData | null) => void;
  onPositionChange: (position: 'before' | 'after') => void;
  isPremium: boolean;
}

export function ImageSelector({ 
  currentImage, 
  position, 
  onImageSelect, 
  onPositionChange,
  isPremium 
}: ImageSelectorProps) {
  const [isUploading, setIsUploading] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const handleFileSelect = async (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    if (!file) return;

    // Validate file type
    if (!file.type.startsWith('image/')) {
      setUploadError('Please select an image file.');
      return;
    }

    // Validate file size (max 5MB)
    if (file.size > 5 * 1024 * 1024) {
      setUploadError('Image size must be less than 5MB.');
      return;
    }

    setIsUploading(true);
    setUploadError(null);

    try {
      const formData = new FormData();
      formData.append('action', 'llmagnet_ai_seo_upload_image');
      formData.append('nonce', window.llmagnetLlmsTxtAdmin.nonce);
      formData.append('image', file);

      const response = await fetch(window.llmagnetLlmsTxtAdmin.ajaxUrl, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
      });

      const result = await response.json();

      if (result.success && result.data) {
        onImageSelect({
          id: result.data.attachment_id,
          url: result.data.url,
          preview_url: result.data.preview_url,
          width: result.data.width,
          height: result.data.height,
        });
      } else {
        setUploadError(result.data?.message || 'Upload failed. Please try again.');
      }
    } catch (error) {
      console.error('Upload error:', error);
      setUploadError('Upload failed. Please check your connection and try again.');
    } finally {
      setIsUploading(false);
      // Reset file input
      if (fileInputRef.current) {
        fileInputRef.current.value = '';
      }
    }
  };

  const openMediaLibrary = () => {
    if (typeof wp !== 'undefined' && wp.media) {
      const mediaUploader = wp.media({
        title: 'Select LLM Response Image',
        button: {
          text: 'Use this image'
        },
        multiple: false,
        library: {
          type: 'image'
        }
      });

      mediaUploader.on('select', () => {
        const attachment = mediaUploader.state().get('selection').first().toJSON();
        
        onImageSelect({
          id: attachment.id,
          url: attachment.url,
          preview_url: attachment.sizes?.medium?.url || attachment.url,
          width: attachment.sizes?.medium?.width || attachment.width,
          height: attachment.sizes?.medium?.height || attachment.height,
        });
      });

      mediaUploader.open();
    } else {
      // Fallback to file input if WordPress media library is not available
      fileInputRef.current?.click();
    }
  };

  const removeImage = () => {
    onImageSelect(null);
  };

  if (!isPremium) {
    return (
      <div className="bg-gray-50 border border-gray-200 rounded-md p-4">
        <h4 className="font-medium mb-2 text-gray-700">LLM Response Image (Premium Feature)</h4>
        <p className="text-sm text-gray-600 mb-3">
          Attach an image that will be displayed with responses from all Large Language Models (ChatGPT, Claude, Gemini, GPT-4, etc.) and included in the llms.txt file.
        </p>
        <div className="bg-yellow-50 border border-yellow-200 text-yellow-800 p-3 rounded-md text-sm">
          <strong>Premium Feature:</strong> This feature is available for premium users only. 
          Upgrade to attach images to your LLM responses.
        </div>
      </div>
    );
  }

  return (
    <div className="bg-white border border-gray-200 rounded-md p-4">
      <h4 className="font-medium mb-2">LLM Response Image</h4>
      <p className="text-sm text-gray-600 mb-4">
        Select an image to display with responses from all Large Language Models. This image will be included in the llms.txt file and will work with ChatGPT, Claude, Gemini, GPT-4, and other LLMs.
      </p>

      {currentImage ? (
        <div className="mb-4">
          <div className="flex items-start space-x-4">
            <div className="flex-shrink-0">
              <img 
                src={currentImage.preview_url} 
                alt="Selected image" 
                className="w-24 h-24 object-cover rounded-md border border-gray-200"
              />
            </div>
            <div className="flex-grow">
              <p className="text-sm font-medium mb-1">Current Image</p>
              <p className="text-xs text-gray-500 mb-2">
                {currentImage.width && currentImage.height 
                  ? `${currentImage.width} × ${currentImage.height}px` 
                  : 'Image selected'}
              </p>
              <div className="flex space-x-2">
                <Button 
                  type="button" 
                  variant="outline" 
                  size="sm"
                  onClick={openMediaLibrary}
                >
                  Change Image
                </Button>
                <Button 
                  type="button" 
                  variant="outline" 
                  size="sm"
                  onClick={removeImage}
                >
                  Remove
                </Button>
              </div>
            </div>
          </div>
        </div>
      ) : (
        <div className="mb-4">
          <div className="border-2 border-dashed border-gray-300 rounded-md p-6 text-center">
            <div className="space-y-3">
              <div className="text-gray-400">
                <svg className="mx-auto h-12 w-12" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                  <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
              </div>
              <div>
                <p className="text-sm text-gray-600 mb-2">No image selected</p>
                <div className="flex justify-center space-x-2">
                  <Button 
                    type="button" 
                    variant="outline" 
                    size="sm"
                    onClick={openMediaLibrary}
                    disabled={isUploading}
                  >
                    Select from Media Library
                  </Button>
                  <Button 
                    type="button" 
                    variant="outline" 
                    size="sm"
                    onClick={() => fileInputRef.current?.click()}
                    disabled={isUploading}
                  >
                    {isUploading ? 'Uploading...' : 'Upload New'}
                  </Button>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Position Setting */}
      <div className="mb-4">
        <Label className="text-sm font-medium mb-2 block">Image Position</Label>
        <div className="space-y-2">
          <div className="flex items-center space-x-2">
            <Checkbox 
              id="position-before"
              checked={position === 'before'}
              onCheckedChange={(checked) => checked && onPositionChange('before')}
            />
            <Label htmlFor="position-before" className="text-sm">Before text response</Label>
          </div>
          <div className="flex items-center space-x-2">
            <Checkbox 
              id="position-after"
              checked={position === 'after'}
              onCheckedChange={(checked) => checked && onPositionChange('after')}
            />
            <Label htmlFor="position-after" className="text-sm">After text response</Label>
          </div>
        </div>
      </div>

      {/* Hidden file input */}
      <input
        ref={fileInputRef}
        type="file"
        accept="image/*"
        onChange={handleFileSelect}
        className="hidden"
      />

      {/* Error display */}
      {uploadError && (
        <div className="bg-red-50 border border-red-200 text-red-700 p-3 rounded-md text-sm">
          {uploadError}
        </div>
      )}
    </div>
  );
}
