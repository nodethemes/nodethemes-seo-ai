<?php
/**
 * Plugin Name:       NodeThemes SEO AI - SEO & AI-Search Optimization
 * Plugin URI:        https://nodethemes.com/
 * Description:       AI-powered SEO for Google AND for AI answer engines (ChatGPT, Gemini, Claude, Perplexity). Bring your own OpenAI / Claude / Gemini API key: keyword research, meta data, product & article writing, schema, sitemaps, llms.txt, AI-crawler control, AI-visibility tracking and a visual internal-link map.
 * Version:           1.2.8
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            NodeThemes
 * License:           GPL-2.0-or-later
 * Text Domain:       nodethemes-seo-ai
 * Update URI:        https://nodethemes.com/nodethemes-seo-ai
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( defined( 'NRA_VERSION' ) ) { return; }

define( 'NRA_VERSION', '1.2.8' );
define( 'NRA_DB_VERSION', '3' );
define( 'NRA_FILE', __FILE__ );
define( 'NRA_DIR', plugin_dir_path( __FILE__ ) );
define( 'NRA_URL', plugin_dir_url( __FILE__ ) );

foreach ( array( 'util', 'jobs', 'pro-gate', 'settings', 'ai', 'analyzer', 'schema', 'frontend', 'sitemap', 'redirects', 'geo', 'extras', 'links', 'import', 'metabox', 'admin' ) as $nra_f ) {
    require_once NRA_DIR . 'includes/' . $nra_f . '.php';
}
unset( $nra_f );

// Free updates from nodethemes.com.
require_once NRA_DIR . 'includes/updater.php';
NRA_Free_Updater::boot( NRA_FILE, NRA_VERSION, 'https://nodethemes.com/' );

register_activation_hook( __FILE__, array( 'NRA_Core', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NRA_Core', 'deactivate' ) );

final class NRA_Core {
    public static function boot() {
        NRA_Util::ajax_safety();
        NRA_Settings::init();
        NRA_Job::init();
        NRA_Frontend::init();
        NRA_Sitemap::init();
        NRA_Redirects::init();
        NRA_Geo::init();
        NRA_Extras::init();
        NRA_Links::init();
        NRA_Import::init();
        NRA_Metabox::init();
        NRA_Admin::init();
        add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
        do_action( 'nra_loaded' ); // the Pro add-on hooks in here
    }
    public static function activate() {
        self::create_tables();
        NRA_Sitemap::add_rewrites();
        NRA_Geo::add_rewrites();
        flush_rewrite_rules();
        update_option( 'nra_db_version', NRA_DB_VERSION );
        if ( ! get_option( 'nra_indexnow_key' ) ) { update_option( 'nra_indexnow_key', md5( wp_generate_password( 32, true, true ) ), false ); }
    }
    public static function deactivate() { flush_rewrite_rules(); }
    public static function maybe_upgrade() {
        if ( get_option( 'nra_db_version' ) !== NRA_DB_VERSION ) {
            self::create_tables();
            update_option( 'nra_db_version', NRA_DB_VERSION );
            flush_rewrite_rules();
        }
    }
    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;
        dbDelta( "CREATE TABLE {$p}nra_redirects (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source varchar(190) NOT NULL,
            target text NOT NULL,
            code smallint(3) NOT NULL DEFAULT 301,
            is_regex tinyint(1) NOT NULL DEFAULT 0,
            hits int(10) unsigned NOT NULL DEFAULT 0,
            auto tinyint(1) NOT NULL DEFAULT 0,
            created datetime NOT NULL,
            last_hit datetime NULL,
            PRIMARY KEY  (id),
            KEY source (source)
        ) $c;" );
        dbDelta( "CREATE TABLE {$p}nra_404 (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            path varchar(190) NOT NULL,
            hits int(10) unsigned NOT NULL DEFAULT 1,
            referrer varchar(255) NOT NULL DEFAULT '',
            last_seen datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY path (path)
        ) $c;" );
        dbDelta( "CREATE TABLE {$p}nra_links (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            src_id bigint(20) unsigned NOT NULL,
            dst_id bigint(20) unsigned NOT NULL,
            anchor varchar(190) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY src_id (src_id),
            KEY dst_id (dst_id)
        ) $c;" );
        dbDelta( "CREATE TABLE {$p}nra_bots (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            day date NOT NULL,
            bot varchar(60) NOT NULL,
            hits int(10) unsigned NOT NULL DEFAULT 1,
            last_url varchar(255) NOT NULL DEFAULT '',
            last_seen datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY day_bot (day,bot)
        ) $c;" );
        dbDelta( "CREATE TABLE {$p}nra_prompts (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            prompt text NOT NULL,
            created datetime NOT NULL,
            PRIMARY KEY  (id)
        ) $c;" );
        dbDelta( "CREATE TABLE {$p}nra_checks (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            prompt_id bigint(20) unsigned NOT NULL,
            provider varchar(20) NOT NULL DEFAULT '',
            model varchar(80) NOT NULL DEFAULT '',
            web tinyint(1) NOT NULL DEFAULT 0,
            mentioned tinyint(1) NOT NULL DEFAULT 0,
            cited tinyint(1) NOT NULL DEFAULT 0,
            excerpt text NULL,
            brands text NULL,
            sources text NULL,
            checked datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY prompt_id (prompt_id)
        ) $c;" );
    }
}
add_action( 'plugins_loaded', array( 'NRA_Core', 'boot' ) );
