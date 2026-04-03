<?php
/**
 * The template for displaying 404 pages (not found)
 */

get_header();
?>

<main id="main" class="site-main">
    <section class="error-404 not-found container" style="padding: 100px 0; text-align: center;">
        <div class="error-image" style="margin-bottom: 40px;">
            <svg width="120" height="120" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="color: var(--accent-color); opacity: 0.8;">
                <path d="M12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M12 8V12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M12 16H12.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
        
        <header class="page-header">
            <h1 class="page-title" style="font-size: 3rem; font-weight: 800; margin-bottom: 20px;">404 Page Not Found</h1>
        </header>

        <div class="page-content" style="max-width: 600px; margin: 0 auto;">
            <p style="font-size: 1.1rem; color: var(--light-text); margin-bottom: 40px;">
                Oops! The page you are looking for doesn't exist or has been moved. 
                Please check the URL or head back to the homepage to find what you need.
            </p>
            
            <div class="error-actions" style="display: flex; gap: 20px; justify-content: center;">
                <a href="<?php echo home_url('/'); ?>" class="ha-cta-clean" style="padding: 0 40px; height: 50px;">
                    Go Back Home
                </a>
                <a href="https://wa.me/447782200976" target="_blank" class="ha-top-whatsapp" style="padding: 0 30px; height: 50px; display: inline-flex; align-items: center; border-radius: 12px;">
                    Speak to an Advisor
                </a>
            </div>
        </div>
    </section>
</main>

<?php
get_footer();
