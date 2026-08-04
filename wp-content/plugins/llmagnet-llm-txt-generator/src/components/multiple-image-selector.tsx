import React, { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
// Simple toast replacement
const useToast = () => ({
  toast: ({ title, description, variant }: { title: string; description: string; variant?: string }) => {
    if (variant === 'destructive') {
      alert(`Error: ${title}\n${description}`);
    } else {
      alert(`${title}\n${description}`);
    }
  }
});

interface ImageData {
  id: number;
  url: string;
  preview_url: string;
  width?: number;
  height?: number;
}

interface LLMImage {
  id: number;
  position: 'before' | 'after';
}

interface MultipleImageSelectorProps {
  currentImages: LLMImage[];
  onImagesChange: (images: LLMImage[]) => void;
  isPremium: boolean;
}

export function MultipleImageSelector({ currentImages, onImagesChange, isPremium }: MultipleImageSelectorProps) {
  const { toast } = useToast();
  const [isUploading, setIsUploading] = useState(false);
  const [imageDataCache, setImageDataCache] = useState<{ [key: number]: ImageData }>({});

  // Load image data for display
  React.useEffect(() => {
    const loadImageData = async () => {
      for (const image of currentImages) {
        if (!imageDataCache[image.id]) {
          // In a real implementation, you'd fetch image data from WordPress
          // For now, we'll use the wp.media library or AJAX calls
          const attachment = wp.media.attachment(image.id);
          attachment.fetch().then(() => {
            const data = {
              id: image.id,
              url: attachment.get('url'),
              preview_url: attachment.get('sizes')?.medium?.url || attachment.get('url'),
              width: attachment.get('width'),
              height: attachment.get('height'),
            };
            setImageDataCache(prev => ({ ...prev, [image.id]: data }));
          });
        }
      }
    };

    if (currentImages.length > 0) {
      loadImageData();
    }
  }, [currentImages]);

  const handleFileUpload = async (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    if (!file) return;

    if (!file.type.startsWith('image/')) {
      toast({
        title: "Error",
        description: "Please select an image file.",
        variant: "destructive",
      });
      return;
    }

    setIsUploading(true);

    try {
      const formData = new FormData();
      formData.append('action', 'llmagnet_ai_seo_upload_image');
      formData.append('nonce', (window as any).llmagnetDashboardData?.nonce || '');
      formData.append('image', file);

      const response = await fetch((window as any).llmagnetDashboardData?.ajaxUrl || '/wp-admin/admin-ajax.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
      });

      const result = await response.json();

      if (result.success) {
        const newImage: LLMImage = {
          id: result.data.attachment_id,
          position: 'after'
        };
        
        const newImages = [...currentImages, newImage];
        onImagesChange(newImages);
        
        // Cache the image data
        setImageDataCache(prev => ({
          ...prev,
          [result.data.attachment_id]: result.data
        }));

        toast({
          title: "Success",
          description: "Image uploaded successfully!",
        });
      } else {
        toast({
          title: "Upload Error",
          description: result.data?.message || "Failed to upload image.",
          variant: "destructive",
        });
      }
    } catch (error) {
      console.error('Upload error:', error);
      toast({
        title: "Upload Error",
        description: "An error occurred while uploading the image.",
        variant: "destructive",
      });
    } finally {
      setIsUploading(false);
      // Reset the input
      event.target.value = '';
    }
  };

  const openMediaLibrary = () => {
    if (typeof wp !== 'undefined' && wp.media) {
      const mediaUploader = wp.media({
        title: 'Select LLM Response Images',
        button: {
          text: 'Select Images'
        },
        multiple: true
      });

      mediaUploader.on('select', () => {
        const attachments = mediaUploader.state().get('selection').toJSON();
        const newImages: LLMImage[] = [];
        const newImageData: { [key: number]: ImageData } = {};

        attachments.forEach((attachment: any) => {
          newImages.push({
            id: attachment.id,
            position: 'after'
          });

          newImageData[attachment.id] = {
            id: attachment.id,
            url: attachment.url,
            preview_url: attachment.sizes?.medium?.url || attachment.url,
            width: attachment.width,
            height: attachment.height,
          };
        });

        const updatedImages = [...currentImages, ...newImages];
        onImagesChange(updatedImages);
        setImageDataCache(prev => ({ ...prev, ...newImageData }));

        toast({
          title: "Success",
          description: `${attachments.length} image(s) selected successfully!`,
        });
      });

      mediaUploader.open();
    }
  };

  const handleRemoveImage = (imageId: number) => {
    const updatedImages = currentImages.filter(img => img.id !== imageId);
    onImagesChange(updatedImages);
    
    // Remove from cache
    setImageDataCache(prev => {
      const newCache = { ...prev };
      delete newCache[imageId];
      return newCache;
    });

    toast({
      title: "Success",
      description: "Image removed successfully!",
    });
  };

  const handlePositionChange = (imageId: number, position: 'before' | 'after') => {
    const updatedImages = currentImages.map(img => 
      img.id === imageId ? { ...img, position } : img
    );
    onImagesChange(updatedImages);
  };

  if (!isPremium) {
    return (
      <div className="bg-gray-50 border border-gray-200 rounded-md p-4">
        <h4 className="font-medium mb-2 text-gray-700">LLM Response Images (Premium Feature)</h4>
        <p className="text-sm text-gray-600 mb-3">
          Attach multiple images that will be displayed with responses from all Large Language Models (ChatGPT, Claude, Gemini, GPT-4, etc.) and included in the llms.txt file.
        </p>
        <div className="bg-yellow-50 border border-yellow-200 text-yellow-800 p-3 rounded-md text-sm">
          <strong>Premium Feature:</strong> This feature is available for premium users only. 
          Upgrade to attach multiple images to your LLM responses.
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6">

      {/* Current Images */}
      {currentImages.length > 0 && (
        <div className="mb-6 space-y-4">
          <h4 className="font-semibold mb-4 text-sm text-gray-800 uppercase tracking-wide">Current Images ({currentImages.length})</h4>
          {currentImages.map((image, index) => {
            const imageData = imageDataCache[image.id];
            return (
              <div key={image.id} className="flex items-start gap-4 p-4 bg-gradient-to-r from-gray-50 to-gray-100/50 rounded-xl border border-gray-200/60 hover:shadow-md transition-all duration-200">
                {imageData && (
                  <div className="relative">
                    <img 
                      src={imageData.preview_url} 
                      alt={`LLM Response Image ${index + 1}`}
                      className="w-24 h-24 object-cover rounded-lg border-2 border-white shadow-sm"
                    />
                    <div className="absolute -top-1 -right-1 bg-blue-500 text-white text-xs font-medium px-1.5 py-0.5 rounded-full">
                      {index + 1}
                    </div>
                  </div>
                )}
                
                <div className="flex-1 min-w-0">
                  <div className="flex items-center justify-between mb-3">
                    <div>
                      <h6 className="font-semibold text-gray-900 mb-1">Image {index + 1}</h6>
                      {imageData && (
                        <div className="text-sm text-gray-600 bg-white px-2 py-1 rounded-md inline-block">
                          {imageData.width} × {imageData.height}px
                        </div>
                      )}
                    </div>
                    <Button
                      onClick={() => handleRemoveImage(image.id)}
                      variant="outline"
                      size="sm"
                      className="text-red-600 hover:text-red-700 hover:bg-red-50 border-red-200"
                    >
                      Remove
                    </Button>
                  </div>
                  
                  <div className="bg-white p-3 rounded-lg border border-gray-200/60">
                    <Label className="text-sm font-semibold text-gray-700 mb-2 block">Display Position</Label>
                    <div className="flex gap-3">
                      <label className="flex items-center gap-2 cursor-pointer p-2 rounded-md hover:bg-gray-50 transition-colors">
                        <input
                          type="radio"
                          name={`position-${image.id}`}
                          value="before"
                          checked={image.position === 'before'}
                          onChange={() => handlePositionChange(image.id, 'before')}
                          className="w-4 h-4 text-blue-500"
                        />
                        <span className="text-sm font-medium text-gray-700">Before text</span>
                      </label>
                      <label className="flex items-center gap-2 cursor-pointer p-2 rounded-md hover:bg-gray-50 transition-colors">
                        <input
                          type="radio"
                          name={`position-${image.id}`}
                          value="after"
                          checked={image.position === 'after'}
                          onChange={() => handlePositionChange(image.id, 'after')}
                          className="w-4 h-4 text-blue-500"
                        />
                        <span className="text-sm font-medium text-gray-700">After text</span>
                      </label>
                    </div>
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Add New Images */}
      <div className="space-y-4">
        <h4 className="font-semibold mb-4 text-sm text-gray-800 uppercase tracking-wide">Add New Images</h4>
        
        <div className="flex items-center gap-4 p-3 rounded-xl bg-white/60">
          <div className="flex gap-3">
            <Button
              onClick={openMediaLibrary}
              variant="outline"
              className="px-4 py-2 text-sm font-medium border-gray-300/50 hover:bg-gray-50 hover:border-gray-400"
              disabled={isUploading}
            >
              Select from Media Library
            </Button>
            
            <div className="relative">
              <Input
                type="file"
                accept="image/*"
                onChange={handleFileUpload}
                disabled={isUploading}
                className="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
              />
              <Button
                variant="outline"
                className="px-4 py-2 text-sm font-medium border-gray-300/50 hover:bg-gray-50 hover:border-gray-400"
                disabled={isUploading}
              >
                {isUploading ? 'Uploading...' : 'Upload New Image'}
              </Button>
            </div>
          </div>
        </div>
        
        <p className="text-sm font-medium text-gray-600">
          You can select multiple images from the media library or upload them one by one.
        </p>
      </div>
    </div>
  );
}
