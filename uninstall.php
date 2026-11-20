<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
global $wpdb;
foreach ( array( 'redirects', '404', 'links', 'bots', 'prompts', 'checks' ) as $t ) { $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ntseoai_{$t}" ); } // phpcs:ignore
foreach ( array( 'ntseoai_settings', 'ntseoai_keys', 'ntseoai_usage', 'ntseoai_db_version', 'ntseoai_indexnow_key', 'ntseoai_indexnow_last', 'ntseoai_sm_ver', 'ntseoai_llms_ver', 'ntseoai_graph_built', 'ntseoai_models_openai', 'ntseoai_models_anthropic', 'ntseoai_models_gemini' ) as $o ) { delete_option( $o ); }
// SEO post meta (_ntseoai_*) is kept on purpose so nothing is lost if you reinstall.
