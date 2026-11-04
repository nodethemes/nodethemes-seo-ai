<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Auto Writer: from a topic (or an existing product) to a complete page that passes the SEO and AI-search checks.
 *
 * The work is split into small AI calls so no single request hits a hosting time limit:
 *   1. plan    - keyword, titles, meta, direct answer, takeaways, outline, table, FAQ, sources
 *   2. section - one call per H2 section
 *   3. finish  - assemble, add internal and verified outbound links, repair what still fails, save or hand back to the editor
 *
 * Free: 2 runs per calendar month, up to 1,200 words. Pro: unlimited, up to 4,000 words, bulk products and a schedule (Pro add-on).
 * Nothing is published unless the owner chose "publish" AND the page scores 98+ in both scores AND has no [ADD: ...] gaps.
 */
final class NRA_Writer {
    const USAGE = 'nra_aw_usage';
    const PREFS = 'nra_aw_prefs';
    const META  = '_nra_aw';

    public static function init() {
        foreach ( array( 'plan', 'section', 'finish', 'discard', 'prefs' ) as $a ) { add_action( 'wp_ajax_nra_aw_' . $a, array( __CLASS__, 'ajax_' . $a ) ); }
        add_filter( 'nra_job_actions', function ( $a ) { return array_merge( $a, array( 'nra_aw_plan', 'nra_aw_section', 'nra_aw_finish' ) ); } );
        add_action( 'transition_post_status', array( __CLASS__, 'on_publish' ), 20, 3 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
    }

    // ------------------------------------------------------------ limits & preferences
    public static function month() { return function_exists( 'wp_date' ) ? wp_date( 'Y-m' ) : gmdate( 'Y-m' ); }
    public static function used() { $u = get_option( self::USAGE, array() ); return ( is_array( $u ) && isset( $u[ self::month() ] ) ) ? (int) $u[ self::month() ] : 0; }
    public static function left() { return NRA_Pro::active() ? -1 : max( 0, NRA_Pro::FREE_AUTOWRITER - self::used() ); }
    private static function bump() {
        $u = get_option( self::USAGE, array() ); if ( ! is_array( $u ) ) { $u = array(); }
        $m = self::month(); $u[ $m ] = ( isset( $u[ $m ] ) ? (int) $u[ $m ] : 0 ) + 1;
        krsort( $u ); update_option( self::USAGE, array_slice( $u, 0, 12, true ), false );
    }
    public static function prefs() {
        $p = get_option( self::PREFS, array() );
        $p = array_merge( array( 'mode' => 'draft', 'inbound' => 'yes', 'ext' => 'yes', 'words' => 1200 ), is_array( $p ) ? $p : array() );
        if ( ! NRA_Pro::active() && 'schedule' === $p['mode'] ) { $p['mode'] = 'draft'; }
        return $p;
    }
    /** word targets the owner can pick */
    public static function lengths() {
        $l = array( 800 => false, 1200 => false );
        foreach ( array( 1500, 2500, 4000 ) as $w ) { $l[ $w ] = ! NRA_Pro::active(); } // true = locked
        return $l;
    }
    public static function kinds() {
        return array( 'article' => 'Blog post / guide', 'howto' => 'How-to guide', 'listicle' => 'List or comparison', 'page' => 'Page (service, landing, about)', 'product' => 'Product page' );
    }

    // ------------------------------------------------------------ AJAX
    private static function guard() {
        NRA_Util::nonce();
        if ( ! current_user_can( 'edit_posts' ) ) { NRA_Util::fail( 'Not allowed.' ); }
    }
    private static function f( $k ) { return isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : ''; } // phpcs:ignore

    public static function ajax_plan() {
        self::guard();
        $r = self::plan( array(
            'post_id' => isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0, // phpcs:ignore
            'topic'   => self::f( 'topic' ), 'kw' => self::f( 'kw' ), 'kind' => sanitize_key( self::f( 'kind' ) ),
            'words'   => isset( $_POST['words'] ) ? (int) $_POST['words'] : 1200, // phpcs:ignore
            'notes'   => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '', // phpcs:ignore
            'cat'     => isset( $_POST['cat'] ) ? (int) $_POST['cat'] : 0, // phpcs:ignore
            'target'  => 'editor' === self::f( 'target' ) ? 'editor' : 'save',
        ) );
        if ( is_wp_error( $r ) ) { NRA_Util::fail( $r->get_error_message() ); }
        NRA_Util::ok( $r );
    }
    public static function ajax_section() {
        self::guard();
        $pid = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0; // phpcs:ignore
        $r = self::section( $pid, isset( $_POST['i'] ) ? (int) $_POST['i'] : 0 ); // phpcs:ignore
        if ( is_wp_error( $r ) ) { NRA_Util::fail( $r->get_error_message() ); }
        NRA_Util::ok( $r );
    }
    public static function ajax_finish() {
        self::guard();
        $pid = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0; // phpcs:ignore
        $r = self::finish( $pid, array( 'live' => ! empty( $_POST['live'] ) && NRA_Pro::active() ) ); // phpcs:ignore
        if ( is_wp_error( $r ) ) { NRA_Util::fail( $r->get_error_message() ); }
        NRA_Util::ok( $r );
    }
    public static function ajax_discard() {
        self::guard();
        $pid = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0; // phpcs:ignore
        if ( ! $pid || ! current_user_can( 'edit_post', $pid ) ) { NRA_Util::fail( 'Not allowed.' ); }
        delete_post_meta( $pid, '_nra_aw_staged' );
        NRA_Util::ok( array( 'message' => 'Discarded.' ) );
    }
    public static function ajax_prefs() {
        self::guard();
        if ( ! current_user_can( 'manage_options' ) ) { NRA_Util::fail( 'Not allowed.' ); }
        $mode = sanitize_key( self::f( 'mode' ) );
        $p = array(
            'mode'    => in_array( $mode, array( 'draft', 'publish' ), true ) ? $mode : 'draft',
            'inbound' => 'no' === self::f( 'inbound' ) ? 'no' : 'yes',
            'ext'     => 'no' === self::f( 'ext' ) ? 'no' : 'yes',
            'words'   => array_key_exists( (int) self::f( 'words' ), self::lengths() ) ? (int) self::f( 'words' ) : 1200,
        );
        update_option( self::PREFS, $p, false );
        NRA_Util::ok( array( 'message' => 'Saved.' ) );
    }

    // ------------------------------------------------------------ 1. plan
    /** product facts read from WooCommerce: the ONLY source of product claims */
    public static function product_facts( $id ) {
        if ( ! function_exists( 'wc_get_product' ) ) { return ''; }
        $p = wc_get_product( $id );
        if ( ! $p ) { return ''; }
        $f = array( 'Product name: ' . $p->get_name() );
        $cur = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
        if ( '' !== (string) $p->get_price() ) {
            $f[] = 'Price: ' . $p->get_price() . ' ' . $cur . ( $p->is_on_sale() && $p->get_regular_price() ? ' (on sale, regular ' . $p->get_regular_price() . ' ' . $cur . ')' : '' );
        }
        if ( $p->get_sku() ) { $f[] = 'SKU: ' . $p->get_sku(); }
        $cats = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'names' ) );
        if ( $cats && ! is_wp_error( $cats ) ) { $f[] = 'Categories: ' . implode( ', ', $cats ); }
        foreach ( $p->get_attributes() as $att ) {
            if ( ! is_object( $att ) || ! $att->get_visible() ) { continue; }
            $vals = $att->is_taxonomy() ? wc_get_product_terms( $id, $att->get_name(), array( 'fields' => 'names' ) ) : $att->get_options();
            $f[] = wc_attribute_label( $att->get_name() ) . ': ' . implode( ', ', array_map( 'strval', (array) $vals ) );
        }
        if ( $p->get_weight() ) { $f[] = 'Weight: ' . $p->get_weight() . ' ' . get_option( 'woocommerce_weight_unit' ); }
        if ( $p->get_length() || $p->get_width() || $p->get_height() ) { $f[] = 'Dimensions (L x W x H): ' . implode( ' x ', array_filter( array( $p->get_length(), $p->get_width(), $p->get_height() ) ) ) . ' ' . get_option( 'woocommerce_dimension_unit' ); }
        if ( $p->is_virtual() ) { $f[] = 'Virtual product (nothing is shipped)'; }
        if ( $p->is_downloadable() ) { $f[] = 'Downloadable product'; }
        $f[] = 'Stock: ' . $p->get_stock_status();
        $old = NRA_Util::text( $p->get_short_description() . "\n" . $p->get_description() );
        if ( '' !== $old ) { $f[] = "Current description (facts may be reused, wording must be new):\n" . NRA_AI::clip( $old, 3500 ); }
        return implode( "\n", $f );
    }
    /** focus keywords already used on the site, so the new page does not compete with them */
    private static function used_keywords( $exclude ) {
        global $wpdb;
        $rows = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE m.meta_key='_nra_focuskw' AND m.meta_value<>'' AND m.post_id<>%d AND p.post_status IN ('publish','draft','pending','future') LIMIT 150", (int) $exclude ) );
        return array_map( 'strval', (array) $rows );
    }
    private static function kind_rule( $kind ) {
        $k = array(
            'article'  => 'an in-depth, practical article that fully answers the search intent',
            'howto'    => 'a step-by-step how-to guide (one section is a numbered list of steps) with tips and common mistakes',
            'listicle' => 'a list or comparison guide (one H2 per item or option, with pros and cons); never rank or review real third-party products that are not named in the facts',
            'page'     => 'a service / landing page: value, benefits, how it works, who it is for and a clear call to action',
            'product'  => 'a product page: benefits, key features, who it is for, how it works / what is included, specifications (only from the facts) and a call to action',
        );
        return $k[ $kind ] ?? $k['article'];
    }

    /**
     * @param array $a post_id (existing page or 0), topic, kw, kind, words, notes, cat, target (save|editor)
     * @return array|WP_Error
     */
    public static function plan( $a ) {
        if ( ! NRA_AI::configured() ) { return new WP_Error( 'nra', 'Add your OpenAI, Claude or Gemini key in NodeThemes SEO > Settings first.' ); }
        if ( 0 === self::left() ) { return new WP_Error( 'nra', 'You have used the ' . NRA_Pro::FREE_AUTOWRITER . ' free Auto Writer runs of this month. They reset on the 1st, or get unlimited runs with NodeThemes SEO AI Pro.' ); }
        $pid  = (int) $a['post_id'];
        $kind = isset( self::kinds()[ $a['kind'] ] ) ? $a['kind'] : 'article';
        $post = $pid ? get_post( $pid ) : null;
        if ( $pid && ( ! $post || ! current_user_can( 'edit_post', $pid ) ) ) { return new WP_Error( 'nra', 'Not allowed.' ); }
        if ( $post && 'product' === $post->post_type ) { $kind = 'product'; }
        if ( 'product' === $kind && ! $post ) { return new WP_Error( 'nra', 'Open the product (or create it with a name and price) and run the Auto Writer from its SEO box.' ); }
        if ( ! $post && 'page' === $kind && ! current_user_can( 'edit_pages' ) ) { return new WP_Error( 'nra', 'Not allowed.' ); }
        $words = (int) $a['words'];
        $lens = self::lengths();
        if ( ! isset( $lens[ $words ] ) ) { $words = 1200; }
        if ( $lens[ $words ] ) { return new WP_Error( 'nra', 'Pages over 1,200 words are part of NodeThemes SEO AI Pro.' ); }
        if ( 'product' === $kind ) { $words = min( $words, NRA_Pro::active() ? 2500 : 1200 ); }

        $topic = trim( $a['topic'] );
        if ( '' === $topic && $post ) { $topic = $post->post_title; }
        if ( '' === $topic ) { return new WP_Error( 'nra', 'Write the topic (or the page title) first.' ); }
        $facts = trim( $a['notes'] );
        if ( 'product' === $kind ) { $facts = trim( self::product_facts( $pid ) . ( $facts ? "\nOwner notes: " . $facts : '' ) ); }

        $n = max( 3, min( 14, (int) round( $words / 280 ) ) );
        $used = self::used_keywords( $pid );
        $u = "Topic / working title: $topic\nFocus keyword: " . ( $a['kw'] ? $a['kw'] : '(choose the best realistic search phrase, 2-5 words, that a real person types)' ) . "\nPage type: " . self::kind_rule( $kind ) . "\nTarget length: about $words words\n<page_content>\nFacts from the owner (the ONLY source of specific claims): " . ( $facts ? $facts : '(none - stay general, never invent specifics)' ) . "\n</page_content>\n" . ( $used ? 'Focus keywords already used by other pages of this site (pick a DIFFERENT one): ' . implode( ' | ', array_slice( $used, 0, 80 ) ) . "\n" : '' ) .
            "Task: plan a page that passes every on-page SEO and AI-answer check. Return JSON:\n" .
            "{\"title\":\"page title (H1), max 65 chars, contains the focus keyword\",\"slug\":\"short latin-hyphen slug with the keyword, or empty for non-Latin languages\",\"focus_keyword\":\"\",\"seo_title\":\"38-58 characters, STARTS with the focus keyword (first 25 characters), include one true number or a strong word (guide, complete, best, simple, fast) when natural\",\"meta_description\":\"130-155 characters, contains the focus keyword once, a concrete benefit and a soft call to action\",\"og_title\":\"\",\"og_description\":\"\",\"answer\":\"opening paragraph of 45-60 words that contains the exact focus keyword and answers the topic directly; sentences under 18 words\",\"takeaways\":[\"3-5 concrete points, each under 18 words\"],\"sections\":[{\"h2\":\"\",\"points\":[\"3-5 things the section covers\"]}],\"table\":{\"after_section\":1,\"headers\":[\"\"],\"rows\":[[\"\"]]},\"faq\":[{\"q\":\"\",\"a\":\"25-50 words\"}],\"short_description\":\"\",\"tags\":[\"\"],\"sources\":[{\"url\":\"https://\",\"site\":\"\",\"anchor\":\"\"}],\"labels\":{\"takeaways\":\"Key takeaways\",\"faq\":\"Frequently asked questions\",\"sources\":\"Sources\",\"related\":\"Related reading\"},\"missing_facts\":[\"\"]}\n" .
            "Rules:\n- exactly $n sections; the first explains the topic, the last is a short conclusion with a call to action; the exact focus keyword appears in exactly two H2s (other headings use variations); at least one section is a list.\n- Use the exact focus keyword once in the answer and nowhere else in takeaways or table cells (variations are fine).\n- table: 3-6 rows x 2-4 columns, a comparison, checklist or specification table built ONLY from facts or general, verifiable knowledge (no invented prices, numbers or test results); set after_section to the index (0-based) of the section it belongs after.\n- faq: 4-6 real questions people ask (the exact focus keyword in at most one question and no answer), answers fully supported by the facts or by general knowledge; never invent product specifics.\n- short_description: " . ( 'product' === $kind ? '40-70 words of HTML (<p> and optionally one <ul> with 3-4 <li>) for the area next to the buy button, only from the facts' : 'empty string' ) . ".\n- tags: " . ( 'product' === $kind || 'page' === $kind ? 'empty array' : '3-4 short topic tags' ) . ".\n- sources: 2-4 well-known, stable, authoritative pages (official documentation, standards bodies, government or university pages, Wikipedia) that support the content; never competitors, shops, social posts, affiliate or shortened links; only URLs you are highly confident exist; anchor = a 2-5 word phrase that will appear in the text, or empty.\n- labels: translate the four labels into the page language.\n- missing_facts: facts the owner should add for a stronger page (prices, numbers, guarantees...).\n- Language: the page language (" . NRA_AI::lang_rule() . ').';
        $r = NRA_AI::json( NRA_AI::context(), $u, array( 'max_tokens' => 2600, 'timeout' => 150, 'write' => true ) );
        if ( ! $r['ok'] ) { return new WP_Error( 'nra', $r['error'] ); }
        $p = self::clean_plan( (array) $r['data'], $topic, $a['kw'] );
        if ( count( $p['sections'] ) < 3 ) { return new WP_Error( 'nra', 'The AI returned a plan that is too short. Try again (or choose a stronger model in Settings).' ); }

        if ( ! $post ) {
            $type = 'page' === $kind ? 'page' : 'post';
            $ins = array( 'post_type' => $type, 'post_status' => 'draft', 'post_title' => $p['title'], 'post_content' => '', 'post_author' => get_current_user_id() );
            if ( $p['slug'] ) { $ins['post_name'] = $p['slug']; }
            if ( 'post' === $type && $a['cat'] && term_exists( (int) $a['cat'], 'category' ) ) { $ins['post_category'] = array( (int) $a['cat'] ); }
            $pid = wp_insert_post( wp_slash( $ins ), true );
            if ( is_wp_error( $pid ) ) { return $pid; }
            if ( 'post' === $type && $p['tags'] ) { wp_set_post_tags( $pid, $p['tags'], false ); }
            $created = true;
        } else {
            $created = false;
        }
        update_post_meta( $pid, self::META, array(
            'kind' => $kind, 'words' => $words, 'facts' => $facts, 'plan' => $p, 'parts' => array(), 'created' => $created, 'topic' => $topic,
            'target' => $a['target'], 'uid' => get_current_user_id(), 'at' => time(),
        ) );
        if ( ! NRA_Pro::active() ) { self::bump(); }
        return array( 'post_id' => $pid, 'sections' => count( $p['sections'] ), 'title' => $p['title'], 'kw' => $p['focus_keyword'], 'left' => self::left() );
    }
    private static function clean_plan( $d, $topic, $kw ) {
        $s = function ( $v, $max = 300 ) { return NRA_Util::cut( trim( wp_strip_all_tags( (string) $v ), " \t\n\r\"'" ), $max ); };
        $p = array();
        $p['focus_keyword'] = $kw ? $kw : $s( $d['focus_keyword'] ?? '', 80 );
        if ( '' === $p['focus_keyword'] ) { $p['focus_keyword'] = NRA_Util::cut( $topic, 60 ); }
        $p['title'] = $s( $d['title'] ?? $topic, 90 );
        if ( '' === $p['title'] ) { $p['title'] = $topic; }
        $slug = sanitize_title( (string) ( $d['slug'] ?? '' ) );
        if ( '' === $slug && NRA_Util::is_latin( $p['focus_keyword'] ) ) { $slug = sanitize_title( $p['focus_keyword'] ); }
        $p['slug'] = ( $slug && NRA_Util::is_latin( str_replace( '-', ' ', $slug ) ) ) ? substr( $slug, 0, 70 ) : '';
        $p['seo_title'] = $s( $d['seo_title'] ?? '', 70 );
        $p['meta_description'] = $s( $d['meta_description'] ?? '', 170 );
        $p['og_title'] = $s( $d['og_title'] ?? '', 90 );
        $p['og_description'] = $s( $d['og_description'] ?? '', 200 );
        $p['answer'] = $s( $d['answer'] ?? '', 700 );
        $p['takeaways'] = array_slice( array_values( array_filter( array_map( function ( $v ) use ( $s ) { return $s( $v, 200 ); }, (array) ( $d['takeaways'] ?? array() ) ) ) ), 0, 5 );
        $p['sections'] = array();
        foreach ( (array) ( $d['sections'] ?? array() ) as $x ) {
            if ( is_array( $x ) && '' !== $s( $x['h2'] ?? '' ) ) { $p['sections'][] = array( 'h2' => $s( $x['h2'], 120 ), 'points' => array_slice( array_map( function ( $v ) use ( $s ) { return $s( $v, 200 ); }, (array) ( $x['points'] ?? array() ) ), 0, 6 ) ); }
        }
        $p['sections'] = array_slice( $p['sections'], 0, 14 );
        $t = (array) ( $d['table'] ?? array() ); $rows = array();
        foreach ( (array) ( $t['rows'] ?? array() ) as $row ) { if ( is_array( $row ) ) { $rows[] = array_slice( array_map( function ( $v ) use ( $s ) { return $s( $v, 160 ); }, $row ), 0, 5 ); } }
        $p['table'] = array( 'after' => max( 0, (int) ( $t['after_section'] ?? 1 ) ), 'headers' => array_slice( array_map( function ( $v ) use ( $s ) { return $s( $v, 60 ); }, (array) ( $t['headers'] ?? array() ) ), 0, 5 ), 'rows' => array_slice( $rows, 0, 10 ) );
        $p['faq'] = array();
        foreach ( (array) ( $d['faq'] ?? array() ) as $x ) { if ( is_array( $x ) && $s( $x['q'] ?? '' ) && $s( $x['a'] ?? '' ) ) { $p['faq'][] = array( 'q' => $s( $x['q'], 200 ), 'a' => $s( $x['a'], 600 ) ); } }
        $p['faq'] = array_slice( $p['faq'], 0, 6 );
        $p['short_description'] = wp_kses_post( (string) ( $d['short_description'] ?? '' ) );
        $p['tags'] = array_slice( array_values( array_filter( array_map( function ( $v ) use ( $s ) { return $s( $v, 40 ); }, (array) ( $d['tags'] ?? array() ) ) ) ), 0, 4 );
        $p['sources'] = array();
        foreach ( (array) ( $d['sources'] ?? array() ) as $x ) { if ( is_array( $x ) ) { $url = esc_url_raw( trim( (string) ( $x['url'] ?? '' ) ), array( 'https', 'http' ) ); if ( $url ) { $p['sources'][] = array( 'url' => $url, 'site' => $s( $x['site'] ?? '', 60 ), 'anchor' => $s( $x['anchor'] ?? '', 60 ) ); } } }
        $p['sources'] = array_slice( $p['sources'], 0, 4 );
        $lab = (array) ( $d['labels'] ?? array() );
        $p['labels'] = array();
        foreach ( array( 'takeaways' => 'Key takeaways', 'faq' => 'Frequently asked questions', 'sources' => 'Sources', 'related' => 'Related reading' ) as $k => $def ) { $v = $s( $lab[ $k ] ?? '', 60 ); $p['labels'][ $k ] = '' !== $v ? $v : $def; }
        $p['missing'] = array_slice( array_values( array_filter( array_map( function ( $v ) use ( $s ) { return $s( $v, 160 ); }, (array) ( $d['missing_facts'] ?? array() ) ) ) ), 0, 8 );
        return $p;
    }

    // ------------------------------------------------------------ 2. sections
    public static function section( $pid, $i ) {
        $aw = $pid ? get_post_meta( $pid, self::META, true ) : null;
        if ( ! $pid || ! is_array( $aw ) || ! current_user_can( 'edit_post', $pid ) ) { return new WP_Error( 'nra', 'This Auto Writer run was not found. Start again.' ); }
        $p = $aw['plan'];
        if ( ! isset( $p['sections'][ $i ] ) ) { return new WP_Error( 'nra', 'Unknown section.' ); }
        $sec = $p['sections'][ $i ];
        $n = count( $p['sections'] );
        $body = max( 300, (int) $aw['words'] - 60 - 40 * count( $p['faq'] ) - 60 );
        $per = max( 120, (int) round( $body / $n ) );
        $heads = array(); foreach ( $p['sections'] as $j => $x ) { $heads[] = ( $j === $i ? '>> ' : '' ) . $x['h2']; }
        // keyword budget: about 1.2% of the words; the opening, two headings and one FAQ question already use it
        $kww = max( 1, count( NRA_Util::words( $p['focus_keyword'] ) ) );
        $budget = max( 0, (int) round( (int) $aw['words'] * 0.012 / $kww ) - 4 );
        $slots = array(); if ( $budget > 0 ) { for ( $j = 0; $j < $budget && $j < $n; $j++ ) { $slots[] = (int) floor( $j * $n / $budget ); } }
        $kwuse = in_array( $i, $slots, true ) ? 'Use the exact focus keyword exactly once in a sentence of this section (not in the heading).' : 'Do not use the exact focus keyword in this section\'s paragraphs; use natural variations, synonyms or pronouns.';
        $u = "Page title: {$p['title']}\nFocus keyword: {$p['focus_keyword']}\nPage type: " . self::kind_rule( $aw['kind'] ) . "\nOpening paragraph (already written, do not repeat it): {$p['answer']}\nOutline (>> marks the section to write now): " . implode( ' | ', $heads ) . "\n<page_content>\nFacts from the owner (the ONLY source of specific claims): " . ( $aw['facts'] ? NRA_AI::clip( $aw['facts'], 5000 ) : '(none)' ) . "\n</page_content>\nWrite ONLY this section: <h2>" . esc_html( $sec['h2'] ) . "</h2>\nIt must cover: " . implode( '; ', $sec['points'] ) . "\nLength: about $per words.\n" . ( $i === $n - 1 ? "This is the conclusion: sum up in 2-3 short paragraphs and end with one clear call to action.\n" : '' ) .
            "Rules:\n- Output clean HTML only: <h2>, <h3>, <p>, <ul>, <ol>, <li>, <strong>, <em>. Start with <h2>" . esc_html( $sec['h2'] ) . "</h2>. No <h1>, no tables, no links, no inline styles, no markdown.\n- EVERY sentence has at most 18 words. Paragraphs have 2-4 sentences (under 80 words).\n- $kwuse\n- Expert, specific, plain language; no filler, no empty superlatives, no keyword stuffing.\n- Any fact you were not given (price, version, number, guarantee, compatibility, result) becomes [ADD: what is needed]. Never invent studies, statistics, awards, reviews or customers.\nJSON: {\"html\":\"\"}";
        $r = NRA_AI::json( NRA_AI::context(), $u, array( 'max_tokens' => (int) ( $per * 2.2 ) + 400, 'timeout' => 150, 'write' => true ) );
        if ( ! $r['ok'] ) { return new WP_Error( 'nra', $r['error'] ); }
        $html = self::clean_html( (string) ( $r['data']['html'] ?? '' ) );
        if ( '' === trim( wp_strip_all_tags( $html ) ) ) { return new WP_Error( 'nra', 'The AI returned an empty section. Try again.' ); }
        if ( ! preg_match( '/^\s*<h2\b/i', $html ) ) { $html = '<h2>' . esc_html( $sec['h2'] ) . "</h2>\n" . $html; }
        $aw = get_post_meta( $pid, self::META, true ); // re-read: parts may be written in any order
        $aw['parts'][ $i ] = $html;
        update_post_meta( $pid, self::META, $aw );
        return array( 'i' => $i, 'done' => count( $aw['parts'] ), 'total' => $n );
    }
    /** AI HTML -> safe, simple HTML (no h1, no tables from sections, no links, no styles) */
    private static function clean_html( $html ) {
        $html = wp_kses( $html, array( 'h2' => array(), 'h3' => array(), 'h4' => array(), 'p' => array(), 'ul' => array(), 'ol' => array(), 'li' => array(), 'strong' => array(), 'em' => array(), 'b' => array(), 'i' => array(), 'br' => array() ) );
        $html = preg_replace( array( '/<b>/i', '/<\/b>/i', '/<i>/i', '/<\/i>/i' ), array( '<strong>', '</strong>', '<em>', '</em>' ), $html );
        return trim( $html );
    }

    // ------------------------------------------------------------ 3. finish
    /**
     * @param array $o live: (Pro bulk) write straight into a published page instead of staging it for review
     * @return array|WP_Error
     */
    public static function finish( $pid, $o = array() ) {
        $aw = $pid ? get_post_meta( $pid, self::META, true ) : null;
        $post = $pid ? get_post( $pid ) : null;
        if ( ! $post || ! is_array( $aw ) || ! current_user_can( 'edit_post', $pid ) ) { return new WP_Error( 'nra', 'This Auto Writer run was not found. Start again.' ); }
        $p = $aw['plan'];
        $n = count( $p['sections'] );
        for ( $i = 0; $i < $n; $i++ ) { if ( empty( $aw['parts'][ $i ] ) ) { return new WP_Error( 'nra', 'Section ' . ( $i + 1 ) . ' is still missing. Run it again.' ); } }
        $prefs = self::prefs();
        $applied = array();

        // assemble
        $html = '<p>' . esc_html( $p['answer'] ) . "</p>\n";
        if ( count( $p['takeaways'] ) >= 3 && ! NRA_Settings::on( 'append_takeaways' ) ) {
            $html .= '<div class="nra-takeaways" style="border:1px solid #dbe4f0;border-left:4px solid #2563eb;border-radius:10px;background:#f8fbff;padding:14px 18px;margin:18px 0"><strong>' . esc_html( $p['labels']['takeaways'] ) . '</strong><ul>';
            foreach ( $p['takeaways'] as $t ) { $html .= '<li>' . esc_html( $t ) . '</li>'; }
            $html .= "</ul></div>\n";
        }
        $table = self::table_html( $p['table'] );
        $after = min( $n - 1, $p['table']['after'] );
        for ( $i = 0; $i < $n; $i++ ) {
            $html .= $aw['parts'][ $i ] . "\n";
            if ( $table && $i === $after && $i < $n - 1 ) { $html .= $table . "\n"; $table = ''; }
        }
        if ( $table ) { // the table belongs before the conclusion
            $parts = preg_split( '/(?=<h2\b)/i', $html ); $last = array_pop( $parts ); $html = implode( '', $parts ) . $table . "\n" . $last;
        }

        // internal links inside the text (strong matches only), then a short related list when none fit
        $int = 0;
        foreach ( NRA_Links::suggest_out( $pid, 10, $html ) as $sg ) {
            if ( $int >= 3 || $sg['rank'] > 1 ) { continue; }
            $tmp = NRA_Links::insert_link( $html, $sg['anchor'], $sg['url'] );
            if ( false !== $tmp ) { $html = $tmp; $int++; }
        }
        if ( $int ) { $applied[] = 'Added ' . $int . ' internal link(s) inside the text'; }
        if ( ! $int ) {
            $rel = self::related( $pid, $post->post_type );
            if ( $rel ) {
                $html .= '<h2>' . esc_html( $p['labels']['related'] ) . "</h2>\n<ul>";
                foreach ( $rel as $rid ) { $html .= '<li><a href="' . esc_url( get_permalink( $rid ) ) . '">' . esc_html( get_the_title( $rid ) ) . '</a></li>'; }
                $html .= "</ul>\n"; $applied[] = 'Added a related-reading list with ' . count( $rel ) . ' internal link(s)';
            }
        }

        // outbound sources: only addresses that really answer
        if ( 'yes' === $prefs['ext'] && $p['sources'] ) {
            $ok = array(); $checked = 0; $host = wp_parse_url( home_url(), PHP_URL_HOST );
            foreach ( $p['sources'] as $sx ) {
                if ( count( $ok ) >= 2 || $checked >= 4 ) { break; }
                $h = wp_parse_url( $sx['url'], PHP_URL_HOST );
                if ( ! $h || $h === $host || preg_match( '/(bit\.ly|t\.co|tinyurl\.com|goo\.gl|facebook\.com|twitter\.com|x\.com|instagram\.com|tiktok\.com|amazon\.)/i', $h ) ) { continue; }
                $checked++;
                if ( self::alive( $sx['url'] ) ) { $ok[] = $sx; }
            }
            $inline = 0; $list = array();
            foreach ( $ok as $sx ) {
                $tmp = ( '' !== $sx['anchor'] ) ? NRA_Links::insert_link( $html, $sx['anchor'], $sx['url'] ) : false;
                if ( false !== $tmp ) { $html = preg_replace( '/<a href="' . preg_quote( esc_url( $sx['url'] ), '/' ) . '">/', '<a href="' . esc_url( $sx['url'] ) . '" target="_blank" rel="noopener">', $tmp, 1 ); $inline++; }
                else { $list[] = '<a href="' . esc_url( $sx['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $sx['site'] ? $sx['site'] : wp_parse_url( $sx['url'], PHP_URL_HOST ) ) . '</a>'; }
            }
            if ( $list ) { $html .= '<p><strong>' . esc_html( $p['labels']['sources'] ) . ':</strong> ' . implode( ', ', $list ) . "</p>\n"; }
            if ( $ok ) { $applied[] = 'Added ' . count( $ok ) . ' verified outbound source link(s)'; }
            elseif ( $checked ) { $applied[] = 'None of the suggested outbound sources answered, so none was added'; }
        }

        // FAQ visible on the page (the FAQ schema uses the same questions)
        if ( count( $p['faq'] ) && ! NRA_Settings::on( 'append_faq' ) ) {
            $html .= '<h2>' . esc_html( $p['labels']['faq'] ) . "</h2>\n";
            foreach ( $p['faq'] as $f ) { $html .= '<h3>' . esc_html( $f['q'] ) . "</h3>\n<p>" . esc_html( $f['a'] ) . "</p>\n"; }
        }

        $published = 'publish' === $post->post_status;
        // page title: a new page gets the planned title; an existing page keeps its own (a product keeps its name)
        $title = $post->post_title;
        if ( 'editor' === $aw['target'] && '' !== trim( (string) ( $aw['topic'] ?? '' ) ) ) { $title = $aw['topic']; }
        if ( $aw['created'] || '' === trim( $title ) || preg_match( '/^auto.?draft$/i', trim( $title ) ) || ( 'product' !== $post->post_type && 'editor' === $aw['target'] && $title === $post->post_title && 'auto-draft' === $post->post_status ) ) { $title = $p['title']; }
        $d = array(
            'post_id' => $pid, 'type' => $post->post_type, 'title' => $title, 'content' => $html,
            'slug' => $published ? $post->post_name : ( $p['slug'] ? $p['slug'] : $post->post_name ), 'kw' => $p['focus_keyword'],
            'seo_title' => $p['seo_title'], 'desc' => $p['meta_description'], 'faq' => $p['faq'], 'takeaways' => $p['takeaways'],
        );

        // links from related pages to this one (applied now for live pages, on publish for drafts)
        $inbound = array();
        if ( 'yes' === $prefs['inbound'] ) {
            // anchors are found from the page's focus keyword: use the new one for the search (put back afterwards in editor mode)
            $old_kw = get_post_meta( $pid, '_nra_focuskw', true );
            update_post_meta( $pid, '_nra_focuskw', $d['kw'] );
            $inbound = self::plan_inbound( $pid );
            if ( 'editor' === $aw['target'] ) { '' === (string) $old_kw ? delete_post_meta( $pid, '_nra_focuskw' ) : update_post_meta( $pid, '_nra_focuskw', $old_kw ); }
        }

        // repair what is still failing
        list( $d, $fixes ) = self::repair( $d, $published );
        $applied = array_merge( $applied, $fixes );

        $res = NRA_Analyzer::analyze( array_merge( $d, array( 'aw_inbound' => count( $inbound ) ) ) );
        $left = array();
        foreach ( array_merge( $res['checks'], $res['geo_checks'] ) as $c ) { if ( 'good' !== $c['status'] && $c['w'] > 0 ) { $left[] = $c['label'] . ': ' . $c['msg']; } }
        $gaps = preg_match_all( '/\[ADD:[^\]]*\]/i', $d['content'] );
        $out = array(
            'post_id' => $pid, 'score' => $res['score'], 'geo' => $res['geo_score'], 'applied' => $applied, 'left' => $left, 'gaps' => (int) $gaps,
            'missing' => $p['missing'], 'words' => $res['stats']['words'], 'edit' => get_edit_post_link( $pid, 'raw' ),
        );
        $data = array(
            'title' => $d['title'], 'content' => $d['content'], 'slug' => $published ? '' : $d['slug'], 'kw' => $d['kw'], 'seo_title' => $d['seo_title'], 'desc' => $d['desc'],
            'og_title' => $p['og_title'], 'og_desc' => $p['og_description'], 'faq' => $d['faq'], 'takeaways' => $d['takeaways'],
            'excerpt' => 'product' === $post->post_type ? $p['short_description'] : '', 'schema_type' => 'post' === $post->post_type ? 'BlogPosting' : '',
        );

        if ( 'editor' === $aw['target'] ) {
            // the editor fills its fields; nothing is saved until the owner clicks Save / Update
            if ( $published ) { $out['inbound'] = self::apply_inbound( $pid, $inbound ); } else { self::store_inbound( $pid, $inbound ); }
            if ( '' !== trim( $post->post_content ) ) { update_post_meta( $pid, '_nra_prev_content', wp_slash( $post->post_content ) ); } // backup (products have no revisions)
            if ( '' !== trim( $post->post_excerpt ) ) { update_post_meta( $pid, '_nra_prev_excerpt', wp_slash( $post->post_excerpt ) ); }
            delete_post_meta( $pid, self::META );
            $out['data'] = $data;
            return $out;
        }
        if ( $published && empty( $o['live'] ) ) {
            // a live page is never rewritten without review: keep the result until the owner loads it in the editor
            update_post_meta( $pid, '_nra_aw_staged', wp_slash( array_merge( $data, array( 'score' => $res['score'], 'geo' => $res['geo_score'], 'at' => time(), 'inbound' => $inbound ) ) ) );
            delete_post_meta( $pid, self::META );
            $out['staged'] = true;
            return $out;
        }
        self::save( $pid, $data, $published );
        if ( $published ) { $out['inbound'] = self::apply_inbound( $pid, $inbound ); } else { self::store_inbound( $pid, $inbound ); }
        // publish only when the owner asked for it and the page is really ready
        $ready = $res['score'] >= 98 && $res['geo_score'] >= 98 && 0 === (int) $gaps;
        $mode = isset( $o['mode'] ) && in_array( $o['mode'], array( 'draft', 'publish' ), true ) ? $o['mode'] : $prefs['mode'];
        $out['status'] = get_post_status( $pid );
        if ( ! $published && 'publish' === $mode && $ready && current_user_can( 'page' === $post->post_type ? 'publish_pages' : 'publish_posts' ) ) {
            wp_update_post( array( 'ID' => $pid, 'post_status' => 'publish' ) );
            $out['status'] = 'publish';
        } elseif ( ! $published && 'publish' === $mode ) {
            $out['why_draft'] = $gaps ? 'Kept as a draft: fill the ' . $gaps . ' [ADD: ...] place(s) first.' : 'Kept as a draft: the scores are under 98.';
        }
        delete_post_meta( $pid, self::META );
        NRA_Analyzer::analyze_post( $pid );
        $out['view'] = get_permalink( $pid );
        return $out;
    }

    /** write the result into the post (with a backup of the old text) */
    public static function save( $pid, $data, $published = false ) {
        $post = get_post( $pid );
        if ( $post && '' !== trim( $post->post_content ) ) { update_post_meta( $pid, '_nra_prev_content', wp_slash( $post->post_content ) ); }
        $up = array( 'ID' => $pid, 'post_content' => $data['content'] );
        if ( '' !== $data['title'] && ! $published ) { $up['post_title'] = $data['title']; }
        if ( '' !== $data['slug'] && ! $published ) { $up['post_name'] = $data['slug']; }
        if ( '' !== $data['excerpt'] ) { if ( $post && '' !== trim( $post->post_excerpt ) ) { update_post_meta( $pid, '_nra_prev_excerpt', wp_slash( $post->post_excerpt ) ); } $up['post_excerpt'] = $data['excerpt']; }
        wp_update_post( wp_slash( $up ) );
        foreach ( array( 'focuskw' => 'kw', 'title' => 'seo_title', 'desc' => 'desc', 'og_title' => 'og_title', 'og_desc' => 'og_desc', 'schema_type' => 'schema_type' ) as $mk => $dk ) {
            if ( '' !== (string) $data[ $dk ] ) { update_post_meta( $pid, '_nra_' . $mk, $data[ $dk ] ); }
        }
        if ( $data['faq'] ) { update_post_meta( $pid, '_nra_faq', $data['faq'] ); }
        if ( $data['takeaways'] ) { update_post_meta( $pid, '_nra_takeaways', $data['takeaways'] ); }
        update_post_meta( $pid, '_nra_aw_written', time() );
        NRA_Sitemap::bump(); NRA_Geo::bump();
    }

    private static function table_html( $t ) {
        if ( count( $t['headers'] ) < 2 || count( $t['rows'] ) < 2 ) { return ''; }
        $cols = count( $t['headers'] );
        $h = '<figure class="wp-block-table"><table><thead><tr>';
        foreach ( $t['headers'] as $x ) { $h .= '<th>' . esc_html( $x ) . '</th>'; }
        $h .= '</tr></thead><tbody>';
        foreach ( $t['rows'] as $row ) { $row = array_pad( array_slice( $row, 0, $cols ), $cols, '' ); $h .= '<tr>'; foreach ( $row as $x ) { $h .= '<td>' . esc_html( $x ) . '</td>'; } $h .= '</tr>'; }
        return $h . '</tbody></table></figure>';
    }
    private static function alive( $url ) {
        $args = array( 'timeout' => 6, 'redirection' => 5, 'user-agent' => 'Mozilla/5.0 (compatible; NodeThemesSEO/' . NRA_VERSION . ')' );
        $r = wp_safe_remote_head( $url, $args );
        $code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
        if ( 0 === $code || $code >= 400 ) { $r = wp_safe_remote_get( $url, array_merge( $args, array( 'limit_response_size' => 20000 ) ) ); $code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r ); }
        return $code >= 200 && $code < 400;
    }
    /** a few published pages of the same category (or the newest ones) */
    private static function related( $pid, $type ) {
        $args = array( 'post_type' => $type, 'post_status' => 'publish', 'posts_per_page' => 3, 'post__not_in' => array( $pid ), 'fields' => 'ids', 'no_found_rows' => true );
        $tax = 'product' === $type ? 'product_cat' : ( 'post' === $type ? 'category' : '' );
        if ( $tax ) { $terms = wp_get_post_terms( $pid, $tax, array( 'fields' => 'ids' ) ); if ( $terms && ! is_wp_error( $terms ) ) { $args['tax_query'] = array( array( 'taxonomy' => $tax, 'terms' => $terms ) ); } }
        $ids = get_posts( $args );
        if ( ! $ids ) { unset( $args['tax_query'] ); $args['post_type'] = NRA_Util::post_types(); $ids = get_posts( $args ); }
        return array_map( 'intval', (array) $ids );
    }

    // ------------------------------------------------------------ inbound links (no orphan pages)
    /** pages that mention the topic and do not link here yet; Pro asks the AI which ones really fit */
    public static function plan_inbound( $pid ) {
        $list = array();
        $max = NRA_Pro::active() ? 5 : 2;
        if ( NRA_Pro::active() && class_exists( 'NRA_SmartLinks' ) ) {
            $plan = NRA_SmartLinks::plan_for( $pid );
            foreach ( (array) $plan['items'] as $it ) { if ( ! empty( $it['recommended'] ) && count( $list ) < $max ) { $list[] = array( 'src' => (int) $it['src_id'], 'anchor' => (string) $it['anchor'] ); } }
            return $list;
        }
        foreach ( NRA_Links::suggest_in( $pid, 6 ) as $sg ) {
            if ( $sg['rank'] > 1 || count( $list ) >= $max ) { continue; }
            $list[] = array( 'src' => (int) $sg['src_id'], 'anchor' => (string) $sg['anchor'] );
        }
        return $list;
    }
    private static function store_inbound( $pid, $list ) { $list ? update_post_meta( $pid, '_nra_aw_inbound', $list ) : delete_post_meta( $pid, '_nra_aw_inbound' ); }
    public static function apply_inbound( $pid, $list ) {
        $done = array();
        foreach ( (array) $list as $l ) {
            if ( NRA_Links::apply_link( (int) $l['src'], $pid, (string) $l['anchor'] ) ) { $done[] = get_the_title( (int) $l['src'] ); }
        }
        delete_post_meta( $pid, '_nra_aw_inbound' );
        return $done;
    }
    /** a draft written by the Auto Writer gets its planned inbound links the moment it goes live */
    public static function on_publish( $new, $old, $post ) {
        if ( 'publish' !== $new || 'publish' === $old || ! $post ) { return; }
        $list = get_post_meta( $post->ID, '_nra_aw_inbound', true );
        if ( ! is_array( $list ) || ! $list ) { return; }
        if ( ! get_current_user_id() && $post->post_author ) { wp_set_current_user( (int) $post->post_author ); } // scheduled posts publish from cron
        $done = self::apply_inbound( $post->ID, $list );
        if ( $done ) { update_post_meta( $post->ID, '_nra_aw_inbound_done', $done ); }
    }

    // ------------------------------------------------------------ repair
    /** split paragraphs over 110 words at a sentence end (no AI needed) */
    private static function split_long_paragraphs( $html ) {
        return preg_replace_callback( '#<p>(.*?)</p>#s', function ( $m ) {
            if ( NRA_Util::word_count( wp_strip_all_tags( $m[1] ) ) <= 110 ) { return $m[0]; }
            $s = preg_split( '/(?<=[.!?\x{061F}\x{06D4}])\s+/u', $m[1] );
            if ( count( $s ) < 2 ) { return $m[0]; }
            $half = (int) ceil( count( $s ) / 2 );
            return '<p>' . implode( ' ', array_slice( $s, 0, $half ) ) . '</p><p>' . implode( ' ', array_slice( $s, $half ) ) . '</p>';
        }, $html );
    }
    private static function replace_once( $html, $find, $rep ) {
        if ( '' === $find || $find === $rep || 1 !== substr_count( $html, $find ) ) { return array( $html, false ); }
        return array( str_replace( $find, $rep, $html ), true );
    }
    /** @return array( $d, $applied ) */
    private static function repair( $d, $published ) {
        $applied = array();
        $d['content'] = preg_replace( array( '/<h1\b/i', '/<\/h1>/i' ), array( '<h2', '</h2>' ), $d['content'] );
        $before = $d['content']; $d['content'] = self::split_long_paragraphs( $d['content'] );
        if ( $before !== $d['content'] ) { $applied[] = 'Split long paragraphs'; }
        if ( NRA_Util::len( $d['seo_title'] ) > 60 ) { $d['seo_title'] = NRA_Util::cut( $d['seo_title'], 60 ); }
        if ( NRA_Util::len( $d['desc'] ) > 158 ) { $d['desc'] = NRA_Util::cut( $d['desc'], 155 ) . '.'; }
        $skip = array( 'inbound', 'cannibal', 'ext', 'ext_follow', 'g_cite', 'int', 'kw_alt', 'alt', 'img' );
        for ( $pass = 0; $pass < 2; $pass++ ) {
            $res = NRA_Analyzer::analyze( $d );
            $issues = '';
            foreach ( array_merge( $res['checks'], $res['geo_checks'] ) as $c ) { if ( 'good' !== $c['status'] && $c['w'] > 0 && ! in_array( $c['id'], $skip, true ) ) { $issues .= '- [' . $c['id'] . '] ' . $c['label'] . ': ' . $c['msg'] . "\n"; } }
            if ( '' === $issues ) { break; }
            $u = "Title: {$d['title']}\nFocus keyword: {$d['kw']}\nSEO title: {$d['seo_title']}\nMeta description: {$d['desc']}\nSlug: {$d['slug']}\n<page_content>\n" . NRA_AI::clip( $d['content'], 14000 ) . "\n</page_content>\nFailing checks:\n$issues\nTask: fix the failing checks with the SMALLEST possible edits. Return JSON {\"seo_title\":\"\",\"meta_description\":\"\",\"slug\":\"\",\"replacements\":[{\"find\":\"exact existing text\",\"replace\":\"new text\"}],\"append_html\":\"\"}\nRules:\n- replacements: 'find' MUST be an exact, unique substring copied from the page content (inside one paragraph, list item or heading; no partial tags). Use them to shorten sentences over 20 words (split into two), add the exact focus keyword where it is missing (first paragraph, one H2), or replace some keyword uses with variations if it is overused (keep 0.5-2% of the words). Keep every link, table and FAQ untouched. Never change facts or numbers.\n- First paragraph: 40-60 words, contains the exact focus keyword.\n- seo_title: only if a title check fails: 38-58 characters, starting with the focus keyword. meta_description: only if it fails: 130-155 characters with the keyword once. Otherwise empty strings.\n- slug: only if the URL check fails" . ( $published ? ' (the page is live: leave empty)' : '' ) . "; else empty.\n- append_html: only if the text is too short, has fewer than 2 H2 or no list: one useful section (<h2> + <p>/<ul>) using only facts already on the page; else empty.";
            $r = NRA_AI::json( NRA_AI::context(), $u, array( 'max_tokens' => 2500, 'timeout' => 150, 'write' => true ) );
            if ( ! $r['ok'] ) { $applied[] = 'Repair pass skipped: ' . $r['error']; break; }
            $o = (array) $r['data']; $n = 0;
            foreach ( (array) ( $o['replacements'] ?? array() ) as $rp ) {
                if ( ! is_array( $rp ) ) { continue; }
                list( $d['content'], $ok ) = self::replace_once( $d['content'], (string) ( $rp['find'] ?? '' ), wp_kses_post( (string) ( $rp['replace'] ?? '' ) ) );
                if ( $ok ) { $n++; }
            }
            if ( $n ) { $applied[] = $n . ' small wording fix(es)'; }
            $app = self::clean_html( (string) ( $o['append_html'] ?? '' ) );
            if ( '' !== trim( wp_strip_all_tags( $app ) ) ) {
                $parts = preg_split( '/(?=<h2\b)/i', $d['content'] ); $last = count( $parts ) > 1 ? array_pop( $parts ) : '';
                $d['content'] = implode( '', $parts ) . $app . "\n" . $last; $applied[] = 'Added a short section';
            }
            $t = NRA_Util::cut( trim( wp_strip_all_tags( (string) ( $o['seo_title'] ?? '' ) ), " \"'" ), 60 );
            if ( '' !== $t ) { $d['seo_title'] = $t; $applied[] = 'Improved the SEO title'; }
            $m = NRA_Util::cut( trim( wp_strip_all_tags( (string) ( $o['meta_description'] ?? '' ) ), " \"'" ), 158 );
            if ( '' !== $m ) { $d['desc'] = $m; $applied[] = 'Improved the meta description'; }
            $sl = sanitize_title( (string) ( $o['slug'] ?? '' ) );
            if ( ! $published && '' !== $sl && NRA_Util::is_latin( str_replace( '-', ' ', $sl ) ) ) { $d['slug'] = substr( $sl, 0, 70 ); }
            $d['content'] = self::split_long_paragraphs( $d['content'] );
        }
        return array( $d, array_values( array_unique( $applied ) ) );
    }

    // ------------------------------------------------------------ screens
    public static function assets( $hook ) {
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore
        $edit = wp_script_is( 'nra-metabox', 'enqueued' );
        if ( 'nra-writer' !== $page && ! $edit ) { return; }
        wp_enqueue_script( 'nra-writer', NRA_URL . 'assets/writer.js', array( 'jquery', 'nra-common' ), NRA_VERSION, true );
    }

    /** Auto Writer box inside the editor's "AI writer" tab */
    public static function editor_box( $post ) {
        $is_product = 'product' === $post->post_type;
        $left = self::left();
        $staged = get_post_meta( $post->ID, '_nra_aw_staged', true );
        echo '<div class="nra-card nra-aw-box"><h4 class="nra-h">&#128640; Auto Writer: the whole page, aimed at 100</h4>';
        if ( is_array( $staged ) && ! empty( $staged['content'] ) ) {
            echo '<div class="nra-flash ok" id="nra-aw-staged" data-staged="' . esc_attr( wp_json_encode( $staged ) ) . '">An Auto Writer result is waiting for review (SEO ' . (int) ( $staged['score'] ?? 0 ) . ' / AI ' . (int) ( $staged['geo'] ?? 0 ) . ', written ' . esc_html( NRA_Util::ago( (int) ( $staged['at'] ?? 0 ) ) ) . '). <button type="button" class="nra-btn sm primary" id="nra-aw-load">Load it into the editor</button> <button type="button" class="nra-btn sm" id="nra-aw-discard">Discard</button></div><input type="hidden" name="nra_aw_clear" id="nra_aw_clear" value="">';
        }
        echo '<p class="nra-hint">' . ( $is_product ? 'Writes the full product description, the short description and all SEO fields from this product\'s own data (name, price, attributes, categories) and your notes above.' : 'Writes the full text, title, SEO title, description, takeaways, FAQ, a table, internal links and checked source links, then repairs what still fails.' ) . ' Everything lands in the editor; nothing is saved until you click Save / Update.</p>';
        echo '<div class="nra-inline"><select id="nra-aw-words">';
        foreach ( self::lengths() as $w => $locked ) { if ( $is_product && $w > 2500 ) { continue; } echo '<option value="' . (int) $w . '"' . ( $locked ? ' class="nra-optlock" data-lock="1"' : '' ) . selected( 1200, $w, false ) . '>' . ( $locked ? '&#128274; ' : '' ) . esc_html( number_format_i18n( $w ) ) . ' words' . ( $locked ? ' - Pro' : '' ) . '</option>'; }
        echo '</select><button type="button" class="nra-btn primary" id="nra-aw-go" data-kind="' . ( $is_product ? 'product' : ( 'page' === $post->post_type ? 'page' : 'article' ) ) . '">&#128640; Write the whole page</button>';
        echo '<span class="nra-hint">' . ( $left < 0 ? 'Unlimited with Pro.' : esc_html( $left . ' of ' . NRA_Pro::FREE_AUTOWRITER . ' free runs left this month.' ) ) . '</span></div><div id="nra-aw-out" class="nra-out"></div></div>';
    }

    public static function render_page() {
        if ( ! current_user_can( 'edit_posts' ) ) { wp_die( 'Not allowed.' ); }
        $p = self::prefs(); $left = self::left(); $pro = NRA_Pro::active();
        $cats = get_categories( array( 'hide_empty' => false ) );
        ?>
        <div class="wrap nra-wrap">
            <div class="nra-hero"><div><h1>Auto Writer</h1><p>From one topic to a complete article that is ready for Google and AI answer engines: focus keyword, title, meta description, direct answer, takeaways, sections, a table, FAQ, internal links and checked sources. Then the plugin checks it and repairs what fails, aiming for 100 in both scores.</p></div></div>
            <?php if ( ! NRA_AI::configured() ) : ?><div class="nra-flash warn">Add your OpenAI, Claude or Gemini key in <a href="<?php echo esc_url( admin_url( 'admin.php?page=nra-settings' ) ); ?>">Settings</a> first.</div><?php endif; ?>
            <?php if ( '' === trim( (string) NRA_Settings::get( 'business' ) ) ) : ?><div class="nra-flash warn">Tip: describe your business in <a href="<?php echo esc_url( admin_url( 'admin.php?page=nra-settings' ) ); ?>">Settings &rarr; About the business</a>. The writer uses it, and never states facts it was not given.</div><?php endif; ?>
            <div class="nra-card" id="nra-aw-form">
                <h2>Write a new page</h2>
                <label class="nra-l">Topic or working title <span class="nra-hint">what the page should answer</span></label>
                <input type="text" id="nra-aw-topic" placeholder="e.g. How to choose a booking system for a yoga studio">
                <div class="nra-grid2">
                    <div><label class="nra-l">Focus keyword <span class="nra-hint">optional, the AI picks one if empty</span></label><input type="text" id="nra-aw-kw" placeholder="e.g. yoga studio booking system"></div>
                    <div><label class="nra-l">Type</label><select id="nra-aw-kind"><?php foreach ( self::kinds() as $k => $l ) { if ( 'product' === $k ) { continue; } echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</option>'; } ?></select></div>
                    <div><label class="nra-l">Length</label><select id="nra-aw-words"><?php foreach ( self::lengths() as $w => $locked ) { echo '<option value="' . (int) $w . '"' . ( $locked ? ' data-lock="1"' : '' ) . selected( (int) $p['words'], $w, false ) . '>' . ( $locked ? '&#128274; ' : '' ) . esc_html( number_format_i18n( $w ) ) . ' words' . ( $locked ? ' - Pro' : '' ) . '</option>'; } ?></select></div>
                    <div><label class="nra-l">Category <span class="nra-hint">blog posts only</span></label><select id="nra-aw-cat"><option value="0">Default</option><?php foreach ( $cats as $c ) { echo '<option value="' . (int) $c->term_id . '">' . esc_html( $c->name ) . '</option>'; } ?></select></div>
                </div>
                <label class="nra-l">Facts &amp; notes for the AI <span class="nra-hint">prices, features, numbers, your experience. Only what you write here (and your business description) is stated as fact; anything missing appears as [ADD: ...].</span></label>
                <textarea id="nra-aw-notes" rows="4"></textarea>
                <div class="nra-inline" style="margin-top:12px"><button type="button" class="nra-btn primary lg" id="nra-aw-run">&#128640; Write the page</button><span class="nra-hint"><?php echo $left < 0 ? 'Unlimited with Pro.' : esc_html( $left . ' of ' . NRA_Pro::FREE_AUTOWRITER . ' free runs left this month (they reset on the 1st).' ); ?></span></div>
                <div id="nra-aw-steps" class="nra-out"></div>
            </div>

            <div class="nra-card">
                <h2>When a page is written</h2>
                <label class="nra-chk"><input type="radio" name="nra_aw_mode" value="draft" <?php checked( 'draft', $p['mode'] ); ?>> Save it as a draft so I can review it (recommended)</label>
                <label class="nra-chk"><input type="radio" name="nra_aw_mode" value="publish" <?php checked( 'publish', $p['mode'] ); ?>> Publish it, but only when both scores are 98-100 and nothing is marked [ADD: ...] (otherwise it stays a draft)</label>
                <label class="nra-chk"><input type="checkbox" id="nra-aw-inbound" <?php checked( 'yes', $p['inbound'] ); ?>> Add links to the new page from related pages of my site when it goes live (<?php echo $pro ? 'up to 5, checked by AI' : 'up to 2'; ?>, always inside an existing sentence; you see them in Link Map)</label>
                <label class="nra-chk"><input type="checkbox" id="nra-aw-ext" <?php checked( 'yes', $p['ext'] ); ?>> Add up to 2 outbound source links, only after checking that each address really loads</label>
                <p><button type="button" class="nra-btn" id="nra-aw-save-prefs">Save these choices</button> <span id="nra-aw-prefs-msg" class="nra-hint"></span></p>
                <p class="nra-hint">Live pages (for example a published product) are never rewritten behind your back: the result waits in the page editor until you load it and click Update.</p>
            </div>

            <div class="nra-card">
                <h2>Products</h2>
                <p class="nra-help">Open a product and use <b>NodeThemes SEO AI &rarr; AI writer &rarr; Write the whole page</b>. The writer reads the product's own data (name, price, attributes, categories, current text) and writes the description, the short description and all SEO fields.</p>
                <?php
                if ( $pro && has_action( 'nra_writer_products' ) ) { do_action( 'nra_writer_products' ); }
                else {
                    echo '<p>' . NRA_Pro::lock( 'Write many products at once', 'aw_bulk' ) . '</p>'; // phpcs:ignore
                    if ( post_type_exists( 'product' ) ) {
                        $low = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => 8, 'meta_key' => '_nra_score', 'orderby' => 'meta_value_num', 'order' => 'ASC', 'no_found_rows' => true ) );
                        if ( $low ) { echo '<table class="widefat striped nra-table"><thead><tr><th>Product</th><th>SEO</th><th></th></tr></thead><tbody>'; foreach ( $low as $lp ) { $sc = (int) get_post_meta( $lp->ID, '_nra_score', true ); echo '<tr><td>' . esc_html( get_the_title( $lp ) ) . '</td><td><span class="nra-pill ' . esc_attr( NRA_Analyzer::status_of( $sc ) ) . '">' . $sc . '</span></td><td><a class="nra-btn sm" href="' . esc_url( add_query_arg( 'nra_aw', '1', get_edit_post_link( $lp->ID, 'raw' ) ) ) . '#nra">Open &amp; write</a></td></tr>'; } echo '</tbody></table>'; } // phpcs:ignore
                    }
                }
                ?>
            </div>

            <div class="nra-card">
                <h2>Schedule</h2>
                <?php
                if ( $pro && has_action( 'nra_writer_schedule' ) ) { do_action( 'nra_writer_schedule' ); }
                else { echo '<p class="nra-help">Give the writer a list of topics and it writes one article a day or a few a week, on its own, as drafts or published when they score 98-100.</p><p>' . NRA_Pro::lock( 'Topic queue and schedule', 'aw_schedule' ) . '</p>'; } // phpcs:ignore
                ?>
            </div>
        </div>
        <?php
    }
}
