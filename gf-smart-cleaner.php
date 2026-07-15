<?php
/*
Plugin Name: Gravity Forms Smart Spam Cleaner
Description: Automatically detects and deletes spam entries from Gravity Forms based on gibberish content and blocked emails. Learns over time by saving blocked emails to a list.
Version: 2.0.0
Author: Costin Botez
Text Domain: gf-smart-cleaner
License: MIT
*/

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'GFSC_VERSION', '2.0.0' );
define( 'GFSC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GFSC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once GFSC_PLUGIN_DIR . 'includes/class-gfsc-logger.php';
require_once GFSC_PLUGIN_DIR . 'includes/class-gfsc-spam-engine.php';
require_once GFSC_PLUGIN_DIR . 'includes/class-gfsc-admin.php';
require_once GFSC_PLUGIN_DIR . 'includes/class-gfsc-ajax.php';
require_once GFSC_PLUGIN_DIR . 'includes/class-gfsc-plugin.php';

add_action( 'plugins_loaded', array( 'GFSC_Plugin', 'init' ) );
