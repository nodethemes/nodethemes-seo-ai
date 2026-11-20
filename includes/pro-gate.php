<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Free/Pro split. The Pro features live in the separate "NodeThemes SEO AI Pro" add-on.
 * This plugin only knows their names, so it can show a button that links to the add-on.
 */
final class NTSEOAI_Pro {
    const UPGRADE_URL = 'https://nodethemes.com/product/nodethemes-seo-ai-pro/';

    /** is the Pro add-on plugin installed and active? (it announces itself through this filter) */
    public static function has_code() { return (bool) apply_filters( 'ntseoai_pro_installed', false ); }
    /** installed AND licensed: the add-on answers this filter only while its license is valid */
    public static function active() { return (bool) apply_filters( 'ntseoai_is_pro', false ); }
    /** where a locked button leads: the license page when the add-on is installed but not activated, else the product page */
    public static function license_url() { return admin_url( 'admin.php?page=ntseoai-license' ); }
    public static function url( $feature = '' ) { if ( self::has_code() && ! self::active() ) { return self::license_url(); } return add_query_arg( array( 'utm_source' => 'plugin', 'utm_medium' => 'lock', 'utm_campaign' => $feature ), self::UPGRADE_URL ); }
    /** a button that does nothing but explain; used wherever a Pro feature would be */
    public static function lock( $label, $feature, $class = 'ntseoai-btn' ) {
        return '<a class="' . esc_attr( $class ) . ' ntseoai-locked" href="' . esc_url( self::url( $feature ) ) . '" title="' . esc_attr__( 'Pro feature - click to see what it includes', 'nodethemes-seo-ai' ) . '"><span class="ntseoai-lock">&#128274;</span> ' . esc_html( $label ) . ' <span class="ntseoai-protag">PRO</span></a>';
    }
    public static function badge() { return '<span class="ntseoai-protag">PRO</span>'; }

    public static function features() {
        return array(
            'One-click Google connection: Search Console + Analytics inside WordPress',
            'Daily AI report by email, ad-campaign monitoring, pages close to page one',
            'Fix to 100 and one-click Fix with AI on every check',
            'AI outbound-link finder with live link checking',
            'Smart one-click internal linking from all relevant pages',
            'Auto Writer: one topic to a complete page aimed at 100, up to 4,000 words, many products at once and a topic schedule',
            'Long-form writer: 1,500 to 5,000 word articles and product pages',
            'Bulk AI optimisation for the whole store',
            'AI-visibility tracker: the questions your customers ask, checked for mentions and links, with competitor names',
            'Internal-link suggestions for every page with one-click linking and undo',
            'IndexNow instant indexing',
        );
    }
    /** shown on the locked Bulk page and in the license card */
    public static function upsell_box( $title = '', $text = '' ) {
        echo '<div class="ntseoai-card ntseoai-pro-box"><h2>&#128274; ' . esc_html( $title ? $title : 'Pro feature' ) . '</h2><p class="ntseoai-help">' . ( $text ? esc_html( $text ) . ' ' : '' ) . 'This part of NodeThemes SEO AI is included in <b>Pro</b>:</p><ul class="ntseoai-steps">';
        foreach ( self::features() as $f ) { echo '<li class="done">' . esc_html( $f ) . '</li>'; }
        echo '</ul><p><a class="ntseoai-btn primary lg" target="_blank" rel="noopener" href="' . esc_url( self::url( 'box' ) ) . '">See Pro</a></p></div>';
    }
    /** license panel in Settings */
    public static function license_card() {
        if ( ! self::has_code() ) {
            self::upsell_box();
            echo '<p class="ntseoai-help">Already bought Pro? Install the <b>NodeThemes SEO AI Pro</b> add-on, then enter your license key under <i>NodeThemes SEO &rarr; License</i>.</p>';
            return;
        }
        echo '<div class="ntseoai-card"><h2>Pro license</h2><p>Status: ' . ( self::active() ? '<span class="ntseoai-badge ok">Active</span>' : '<span class="ntseoai-badge">Not activated</span>' ) . '</p><p><a class="ntseoai-btn primary" href="' . esc_url( self::license_url() ) . '">' . esc_html__( 'Manage license', 'nodethemes-seo-ai' ) . '</a></p></div>';
    }
}
