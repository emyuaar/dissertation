# LLMagnet AI SEO Optimizer (WordPress Plugin with React UI)

This WordPress plugin generates a `llms.txt` file and associated Markdown files for LLM crawlers. The admin interface has been built with React and Shadcn UI for a modern user experience.

## Features

- Generate `llms.txt` file in WordPress root directory
- Create Markdown exports of your content
- Modern React-based admin interface
- Configure which post types to include
- Set time period for content inclusion
- Option to include full content or excerpts only
- Automatic regeneration when content is updated
- **🆕 Premium: LLM Response Images** - Attach images to responses from all Large Language Models (ChatGPT, Claude, Gemini, etc.)
- **🆕 LLM Bot Analytics** - Track and visualize visits from AI bots like ChatGPT, Claude, Gemini, and others
- **🆕 Email Reports** - Configure and send analytics reports to monitor LLM bot engagement
- **🆕 Bot Detection** - Automatically identifies various LLM crawlers visiting your site

## Development

This plugin uses a modern React frontend with Vite, TypeScript, and Shadcn UI.

### Requirements

- Node.js 16+
- npm or yarn
- WordPress 5.8+
- PHP 7.4+

### Development Setup

1. Clone the repository to your WordPress plugins directory:
   ```
   cd wp-content/plugins/
   git clone https://github.com/yourusername/llmagnet-ai-seo-optimizer.git
   ```

2. Install dependencies:
   ```
   cd llmagnet-ai-seo-optimizer
   npm install
   ```

3. Start the development server:
   ```
   npm run dev
   ```

4. Set the development mode constant in the main plugin file:
   ```php
   define('LLMS_TXT_DEV_MODE', true);
   ```

5. Activate the plugin in WordPress admin

### Building for Production

1. Build the React app:
   ```
   npm run build
   ```

2. Make sure to set the development mode constant to false:
   ```php
   define('LLMS_TXT_DEV_MODE', false);
   ```

## Structure

- `includes/` - PHP classes for the plugin functionality
- `assets/` - CSS, JS, and built React files
- `src/` - React source code
  - `components/` - React components
    - `image-selector.tsx` - Premium LLM image attachment component
    - `dashboard-analytics.tsx` - LLM bot analytics visualization component
    - `analytics.tsx` - Full analytics dashboard component
  - `lib/` - Utility functions
- `CHATGPT_IMAGES_FEATURE.md` - Documentation for the premium images feature
- `test-image-feature.php` - Testing utilities for image functionality

## License

GPL v2 or later 