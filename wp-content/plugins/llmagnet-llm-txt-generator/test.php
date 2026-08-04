<?php
/**
 * Test file to diagnose plugin activation issues
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Output plugin directory and file information
echo esc_html("Plugin Directory: " . dirname(__FILE__)) . "\n";
echo esc_html("Plugin File: " . __FILE__) . "\n";

// Check if all required files exist
$required_files = [
    dirname(__FILE__) . '/includes/class-main.php',
    dirname(__FILE__) . '/includes/class-generator.php',
    dirname(__FILE__) . '/includes/class-admin.php',
    dirname(__FILE__) . '/includes/class-cron.php',
    dirname(__FILE__) . '/includes/class-cli.php',
    dirname(__FILE__) . '/includes/class-lifecycle-helpers.php',
    dirname(__FILE__) . '/includes/class-brevo-client.php',
    dirname(__FILE__) . '/includes/class-email-state.php',
    dirname(__FILE__) . '/includes/class-email-log.php',
    dirname(__FILE__) . '/includes/class-lifecycle-emails.php',
];

foreach ($required_files as $file) {
    echo esc_html("File " . basename($file) . ": " . (file_exists($file) ? "Exists" : "Missing")) . "\n";
}

// Try to include each file and catch any errors
foreach ($required_files as $file) {
    if (file_exists($file)) {
        echo esc_html("Including " . basename($file) . "... ");
        try {
            include_once $file;
            echo esc_html("Success") . "\n";
        } catch (Exception $e) {
            echo esc_html("Error: " . $e->getMessage()) . "\n";
        }
    }
}

// Check if classes exist
$required_classes = [
    'LLMagnet_AI_SEO_Optimizer\Main',
    'LLMagnet_AI_SEO_Optimizer\Generator',
    'LLMagnet_AI_SEO_Optimizer\Admin',
    'LLMagnet_AI_SEO_Optimizer\Cron',
    'LLMagnet_AI_SEO_Optimizer\CLI',
    'LLMagnet\Lifecycle\Brevo_Client',
    'LLMagnet\Lifecycle\Email_State',
    'LLMagnet\Lifecycle\Email_Log',
    'LLMagnet\Lifecycle\Lifecycle_Emails',
];

foreach ($required_classes as $class) {
    echo esc_html("Class " . $class . ": " . (class_exists($class) ? "Exists" : "Not found")) . "\n";
} 