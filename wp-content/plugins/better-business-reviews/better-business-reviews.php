<?php
/**
* Plugin Name: Better Business Reviews - Trustpilot WordPress Plugin.
* Description: Display your business reviews from a Trustpilot profile.
* Version: 0.1.7
* Author: Better Business Reviews
* Author URI: https://trustpilotplugin.com/
* License: GPLv2 or later
* Text Domain: better-business-reviews
* Domain Path: /languages
*/

// Exit if accessed directly
if ( ! defined('ABSPATH') ) {
	exit;
}

add_action( 'init', 'brtpmj_init_plugin', 1 );
if (!function_exists('brtpmj_init_plugin')) {
	function brtpmj_init_plugin(){
		
		define ( 'BRTPMJ_PLUGIN_DIR', plugin_dir_path(__FILE__ ) );
		define ( 'BRTPMJ_PLUGIN_VER', '0.1.7' );
		
		global $brtpmj_plugin_url;
		$brtpmj_plugin_url = plugin_dir_url( __FILE__ );

		add_action( 'wp_enqueue_scripts', 'brtpmj_styles_scripts' );
		add_action( 'admin_enqueue_scripts', 'brtpmj_admin_script' );

		if( is_admin() ){
			include(plugin_dir_path(__FILE__ ) . 'admin/settings.php');
			include(plugin_dir_path(__FILE__ ) . 'admin/sync.php');
		}
		include(plugin_dir_path(__FILE__ ) . 'shortcode.php');
		
		load_plugin_textdomain( 'better-business-reviews', false, 'better-business-reviews' );
	}
}

// Back-end assets
if (!function_exists('brtpmj_admin_script')) {
	function brtpmj_admin_script(){
		wp_enqueue_style(
			'brtpmj-admin-style',
			plugin_dir_url( __FILE__ ) . 'admin/css/settings.css',
			[],
			BRTPMJ_PLUGIN_VER
		);
		
		wp_enqueue_script(
			'brtpmj-settings-script',
			plugins_url('admin/js/main.js',__FILE__ ),
			array('jquery'),
			BRTPMJ_PLUGIN_VER,
			array(
				'in_footer'  => true,
			)
		);
		
		wp_localize_script(
			'brtpmj-settings-script',
			'ajax_brtpmj_admin_obj',
			array( 'ajaxurl' 	=> admin_url( 'admin-ajax.php' ), 
					'nonce'		=> wp_create_nonce('brtpmj_ajax_nonce')
				 )
		);
		
	}
}

if (!function_exists('brtpmj_styles_scripts')) {
	function brtpmj_styles_scripts(){
		wp_register_style(
			'brtpmj-style',
			plugin_dir_url( __FILE__ ) . 'css/style.css',
			[],
			BRTPMJ_PLUGIN_VER
		);
		wp_register_style(
			'brtpmj-grid-style',
			plugin_dir_url( __FILE__ ) . 'css/grid.css',
			[],
			BRTPMJ_PLUGIN_VER
		);
		wp_register_style(
			'brtpmj-carousel-style',
			plugin_dir_url( __FILE__ ) . 'css/carousel.css',
			[],
			BRTPMJ_PLUGIN_VER
		);
		wp_register_script(
			'brtpmj-carousel-script',
			plugins_url('js/carousel.js',__FILE__ ),
			array('jquery'),
			BRTPMJ_PLUGIN_VER
		);
	}
}

if(is_admin()){
	// Plugin Configuration Page
	add_action( 'plugins_loaded', 'brtpmj_set_admin_menu' );
	if (!function_exists('brtpmj_set_admin_menu')) {
		function brtpmj_set_admin_menu(){
			add_action('admin_menu', 'brtpmj_admin_config', 999);
		}
	}
	
	if (!function_exists('brtpmj_admin_config')) {
		function brtpmj_admin_config() {
			add_menu_page('Better Reviews', 'Better Reviews', 'manage_options', 'brtpmj-free', 'brtpmj_config_callback', 'dashicons-empty');
			add_submenu_page('brtpmj-free', 'Settings', 'Settings', 'manage_options', 'brtpmj-free', 'brtpmj_config_callback', 1);
		}
	}
}
