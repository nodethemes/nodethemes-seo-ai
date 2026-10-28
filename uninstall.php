<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
global $wpdb;
foreach ( array( 'redirects', '404', 'links', 'bots', 'prompts', 'checks' ) as $t ) { $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}nra_{$t}" ); } // phpcs:ignore
foreach ( array( 'nra_settings', 'nra_keys', 'nra_usage', 'nra_db_version', 'nra_indexnow_key', 'nra_indexnow_last', 'nra_sm_ver', 'nra_llms_ver', 'nra_graph_built', 'nra_models_openai', 'nra_models_anthropic', 'nra_models_gemini' ) as $o ) { delete_option( $o ); }
// SEO post meta (_nra_*) is kept on purpose so nothing is lost if you reinstall.
