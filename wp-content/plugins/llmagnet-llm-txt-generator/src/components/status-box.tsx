import React, { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Toast, ToastDescription, ToastProvider, ToastTitle, ToastViewport } from '@/components/ui/toast';
import { RefreshCw, CheckCircle, FolderOpen, Clock } from "lucide-react";

interface StatusBoxProps {
  rootPath: string;
  isWritable: boolean;
  lastGenerated: string | null;
  onGenerateNow: () => Promise<{success: boolean, message: string, timestamp?: string}>;
}

export function StatusBox({ rootPath, isWritable, lastGenerated, onGenerateNow }: StatusBoxProps) {
  const [isGenerating, setIsGenerating] = useState(false);
  const [toast, setToast] = useState<{open: boolean, title: string, description: string, variant: 'default' | 'destructive'}>({
    open: false,
    title: '',
    description: '',
    variant: 'default'
  });
  const [lastGeneratedTime, setLastGeneratedTime] = useState<string | null>(lastGenerated);

  const handleGenerateNow = async () => {
    setIsGenerating(true);
    
    try {
      const result = await onGenerateNow();
      
      if (result.success) {
        setToast({
          open: true,
          title: 'Success',
          description: result.message,
          variant: 'default'
        });
        
        if (result.timestamp) {
          setLastGeneratedTime(result.timestamp);
        }
      } else {
        setToast({
          open: true,
          title: 'Error',
          description: result.message,
          variant: 'destructive'
        });
      }
    } catch (error) {
      setToast({
        open: true,
        title: 'Error',
        description: 'Error generating LLMS.txt. Please check server permissions.',
        variant: 'destructive'
      });
    } finally {
      setIsGenerating(false);
    }
  };

  return (
    <>
      <div className="bg-white border border-gray-200 shadow-sm p-4 mb-6 rounded-md">
        <div className="pb-3 border-b mb-4">
          <h2 className="text-lg font-medium flex items-center gap-2">
            <CheckCircle className="h-5 w-5 text-green-500" />
            LLMS.txt Status
          </h2>
        </div>
        
        <div className="space-y-4">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div className="space-y-2">
              <div className="flex items-center gap-2 text-sm">
                <FolderOpen className="h-4 w-4 text-gray-500" />
                <span className="font-medium">Root Directory:</span>
              </div>
              <div className="flex items-center gap-2">
                <code className="text-xs bg-gray-100 px-2 py-1 rounded truncate max-w-[250px]">{rootPath}</code>
                <span className={`text-xs px-2 py-1 rounded-full ${isWritable ? "bg-green-100 text-green-800" : "bg-red-100 text-red-800"}`}>
                  {isWritable ? "Writable" : "Not Writable"}
                </span>
              </div>
            </div>

            <div className="space-y-2">
              <div className="flex items-center gap-2 text-sm">
                <Clock className="h-4 w-4 text-gray-500" />
                <span className="font-medium">Last Generated:</span>
              </div>
              <p className="text-sm text-gray-600">{lastGeneratedTime || 'Never'}</p>
            </div>
          </div>

          <Button 
            variant="gradient"
            disabled={!isWritable || isGenerating}
            onClick={handleGenerateNow}
            className="w-full md:w-auto"
          >
            {isGenerating ? (
              <>
                <RefreshCw className="mr-2 h-4 w-4 animate-spin" />
                Generating...
              </>
            ) : (
              <>
                <RefreshCw className="mr-2 h-4 w-4" />
                Generate Now
              </>
            )}
          </Button>
        </div>
      </div>

      <ToastProvider>
        <Toast 
          open={toast.open} 
          onOpenChange={(open) => setToast(prev => ({ ...prev, open }))}
          variant={toast.variant}
        >
          <div className="grid gap-1">
            <ToastTitle>{toast.title}</ToastTitle>
            <ToastDescription>{toast.description}</ToastDescription>
          </div>
        </Toast>
        <ToastViewport />
      </ToastProvider>
    </>
  );
} 