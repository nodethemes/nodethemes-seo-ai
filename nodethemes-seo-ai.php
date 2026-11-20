<?php
/**
 * Plugin Name:       NodeThemes SEO AI
 * Description:       SEO for Google and for AI answer engines (ChatGPT, Gemini, Claude, Perplexity): SEO and AI-search scores for every page, meta titles and descriptions, schema, XML sitemaps, llms.txt, AI-crawler rules, redirects and a visual internal-link map, with optional AI help from your own AI provider.
 * Version:           1.4.5
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            NodeThemes
 * Author URI:        https://nodethemes.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nodethemes-seo-ai
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( defined( 'NTSEOAI_VERSION' ) ) { return; }

define( 'NTSEOAI_VERSION', '1.4.5' );
define( 'NTSEOAI_DB_VERSION', '4' );
define( 'NTSEOAI_FILE', __FILE__ );
define( 'NTSEOAI_DIR', plugin_dir_path( __FILE__ ) );
define( 'NTSEOAI_URL', plugin_dir_url( __FILE__ ) );

foreach ( array( 'util', 'jobs', 'pro-gate', 'settings', 'ai', 'analyzer', 'schema', 'frontend', 'sitemap', 'redirects', 'geo', 'extras', 'links', 'import', 'metabox', 'admin' ) as $ntseoai_f ) {
    require_once NTSEOAI_DIR . 'includes/' . $ntseoai_f . '.php';
}
unset( $ntseoai_f );

register_activation_hook( __FILE__, array( 'NTSEOAI_Core', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NTSEOAI_Core', 'deactivate' ) );

final class NTSEOAI_Core {
    public static function boot() {
        NTSEOAI_Util::ajax_safety();
        NTSEOAI_Settings::init();
        NTSEOAI_Job::init();
        NTSEOAI_Frontend::init();
        NTSEOAI_Sitemap::init();
        NTSEOAI_Redirects::init();
        NTSEOAI_Geo::init();
        NTSEOAI_Extras::init();
        NTSEOAI_Links::init();
        NTSEOAI_Import::init();
        NTSEOAI_Metabox::init();
        NTSEOAI_Admin::init();
        add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
        // An older Pro add-on (before 1.4.0) expects code that now lives in the add-on itself; do not start it until it is updated.
        if ( defined( 'NTSEOAIP_VERSION' ) && version_compare( NTSEOAIP_VERSION, '1.4.0', '<' ) ) {
            add_action( 'admin_notices', array( __CLASS__, 'old_pro_notice' ) );
            return;
        }
        do_action( 'ntseoai_loaded' ); // the Pro add-on hooks in here
    }
    public static function old_pro_notice() {
        if ( ! current_user_can( 'update_plugins' ) ) { return; }
        echo '<div class="notice notice-warning"><p>' . esc_html__( 'NodeThemes SEO AI Pro needs an update to version 1.4.0 or newer to work with this version of NodeThemes SEO AI. Go to Dashboard > Updates.', 'nodethemes-seo-ai' ) . '</p></div>';
    }
    public static function activate() {
        self::migrate_prefix();
        self::create_tables();
        NTSEOAI_Sitemap::add_rewrites();
        NTSEOAI_Geo::add_rewrites();
        flush_rewrite_rules();
        update_option( 'ntseoai_db_version', NTSEOAI_DB_VERSION );
    }
    public static function deactivate() { flush_rewrite_rules(); }
    public static function maybe_upgrade() {
        if ( get_option( 'ntseoai_db_version' ) !== NTSEOAI_DB_VERSION ) {
            self::migrate_prefix();
            self::create_tables();
            update_option( 'ntseoai_db_version', NTSEOAI_DB_VERSION );
            flush_rewrite_rules();
        }
    }
    /** Version 1.4.5 renamed the short "nra" prefix to "ntseoai": move data saved by older versions (options, SEO post meta, term and user meta, tables) to the new names. */
    public static function migrate_prefix() {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        foreach ( array( 'redirects', '404', 'links', 'bots', 'prompts', 'checks' ) as $t ) {
            $old = $wpdb->prefix . 'nra_' . $t; $new = $wpdb->prefix . 'ntseoai_' . $t;
            $quiet = $wpdb->suppress_errors( true ); // fails harmlessly when the old table is missing or the new one already exists
            $wpdb->query( "ALTER TABLE `{$old}` RENAME TO `{$new}`" );
            $wpdb->suppress_errors( $quiet );
        }
        $wpdb->query( "UPDATE IGNORE {$wpdb->options} SET option_name = CONCAT('ntseoai_', SUBSTRING(option_name, 5)) WHERE option_name LIKE 'nra\\_%'" );
        $wpdb->query( "UPDATE IGNORE {$wpdb->postmeta} SET meta_key = CONCAT('_ntseoai_', SUBSTRING(meta_key, 6)) WHERE meta_key LIKE '\\_nra\\_%'" );
        $wpdb->query( "UPDATE IGNORE {$wpdb->termmeta} SET meta_key = CONCAT('_ntseoai_', SUBSTRING(meta_key, 6)) WHERE meta_key LIKE '\\_nra\\_%'" );
        $wpdb->query( "UPDATE IGNORE {$wpdb->usermeta} SET meta_key = CONCAT('ntseoai_', SUBSTRING(meta_key, 5)) WHERE meta_key LIKE 'nra\\_%'" );
        // phpcs:enable
    }
    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;
        dbDelta( "CREATE TABLE {$p}ntseoai_redirects (
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
        dbDelta( "CREATE TABLE {$p}ntseoai_404 (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            path varchar(190) NOT NULL,
            hits int(10) unsigned NOT NULL DEFAULT 1,
            referrer varchar(255) NOT NULL DEFAULT '',
            last_seen datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY path (path)
        ) $c;" );
        dbDelta( "CREATE TABLE {$p}ntseoai_links (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            src_id bigint(20) unsigned NOT NULL,
            dst_id bigint(20) unsigned NOT NULL,
            anchor varchar(190) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY src_id (src_id),
            KEY dst_id (dst_id)
        ) $c;" );
        dbDelta( "CREATE TABLE {$p}ntseoai_bots (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            day date NOT NULL,
            bot varchar(60) NOT NULL,
            hits int(10) unsigned NOT NULL DEFAULT 1,
            last_url varchar(255) NOT NULL DEFAULT '',
            last_seen datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY day_bot (day,bot)
        ) $c;" );
        dbDelta( "CREATE TABLE {$p}ntseoai_prompts (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            prompt text NOT NULL,
            created datetime NOT NULL,
            PRIMARY KEY  (id)
        ) $c;" );
        dbDelta( "CREATE TABLE {$p}ntseoai_checks (
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
add_action( 'plugins_loaded', array( 'NTSEOAI_Core', 'boot' ) );
