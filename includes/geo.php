<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** AI-search side: llms.txt, AI-crawler rules and log, AI visibility tracker */
final class NRA_Geo {

    public static function init() {
        add_action( 'init', array( __CLASS__, 'add_rewrites' ) );
        add_filter( 'query_vars', function ( $v ) { $v[] = 'nra_llms'; return $v; } );
        add_action( 'template_redirect', array( __CLASS__, 'serve_llms' ), 0 );
        add_filter( 'robots_txt', array( __CLASS__, 'robots' ), 10, 2 );
        add_action( 'template_redirect', array( __CLASS__, 'log_bot' ), 1 );
        add_action( 'save_post', array( __CLASS__, 'bump' ) );
        add_action( 'wp_ajax_nra_prompt_add', array( __CLASS__, 'ajax_prompt_add' ) );
        add_action( 'wp_ajax_nra_prompt_del', array( __CLASS__, 'ajax_prompt_del' ) );
        add_action( 'wp_ajax_nra_prompt_run', array( __CLASS__, 'ajax_prompt_run' ) );
        add_action( 'wp_ajax_nra_prompt_suggest', array( __CLASS__, 'ajax_prompt_suggest' ) );
    }
    public static function add_rewrites() { add_rewrite_rule( '^llms\.txt$', 'index.php?nra_llms=1', 'top' ); }
    public static function bump() { update_option( 'nra_llms_ver', time(), false ); }

    // ------------------------------------------------------------ crawler catalogue
    public static function bots() {
        return array(
            'GPTBot'          => array( 'co' => 'OpenAI', 'type' => 'train', 'ua' => 'GPTBot', 'desc' => 'Collects pages that may be used to train OpenAI models.' ),
            'OAI-SearchBot'   => array( 'co' => 'OpenAI', 'type' => 'search', 'ua' => 'OAI-SearchBot', 'desc' => 'Builds the ChatGPT search index. Needed to appear in ChatGPT search results.' ),
            'ChatGPT-User'    => array( 'co' => 'OpenAI', 'type' => 'user', 'ua' => 'ChatGPT-User', 'desc' => 'Opens your page when a ChatGPT user asks about it.' ),
            'ClaudeBot'       => array( 'co' => 'Anthropic', 'type' => 'train', 'ua' => 'ClaudeBot', 'desc' => 'Collects pages that may be used to train Claude.' ),
            'Claude-SearchBot' => array( 'co' => 'Anthropic', 'type' => 'search', 'ua' => 'Claude-SearchBot', 'desc' => 'Indexes pages so Claude search answers can cite them.' ),
            'Claude-User'     => array( 'co' => 'Anthropic', 'type' => 'user', 'ua' => 'Claude-User', 'desc' => 'Opens your page when a Claude user asks about it.' ),
            'Google-Extended' => array( 'co' => 'Google', 'type' => 'train', 'ua' => 'Google-Extended', 'desc' => 'Controls use of your content for Gemini training/grounding. Does not affect Google Search ranking.' ),
            'PerplexityBot'   => array( 'co' => 'Perplexity', 'type' => 'search', 'ua' => 'PerplexityBot', 'desc' => 'Indexes pages for Perplexity answers.' ),
            'Perplexity-User' => array( 'co' => 'Perplexity', 'type' => 'user', 'ua' => 'Perplexity-User', 'desc' => 'Opens your page when a Perplexity user asks about it.' ),
            'Applebot-Extended' => array( 'co' => 'Apple', 'type' => 'train', 'ua' => 'Applebot-Extended', 'desc' => 'Controls use of your content for Apple AI training.' ),
            'CCBot'           => array( 'co' => 'Common Crawl', 'type' => 'train', 'ua' => 'CCBot', 'desc' => 'Open web archive used to train many AI models.' ),
            'Bytespider'      => array( 'co' => 'ByteDance', 'type' => 'train', 'ua' => 'Bytespider', 'desc' => 'ByteDance crawler, often aggressive.' ),
            'meta-externalagent' => array( 'co' => 'Meta', 'type' => 'train', 'ua' => 'meta-externalagent', 'desc' => 'Meta AI training crawler.' ),
            'Amazonbot'       => array( 'co' => 'Amazon', 'type' => 'search', 'ua' => 'Amazonbot', 'desc' => 'Amazon crawler (Alexa answers).' ),
            'DuckAssistBot'   => array( 'co' => 'DuckDuckGo', 'type' => 'search', 'ua' => 'DuckAssistBot', 'desc' => 'DuckDuckGo AI answers.' ),
            'MistralAI-User'  => array( 'co' => 'Mistral', 'type' => 'user', 'ua' => 'MistralAI-User', 'desc' => 'Opens pages for Le Chat users.' ),
        );
    }
    public static function type_label( $t ) {
        $m = array( 'search' => 'Search / answers', 'user' => 'User request', 'train' => 'Training' );
        return isset( $m[ $t ] ) ? $m[ $t ] : $t;
    }

    public static function robots( $out, $public ) {
        if ( '0' === (string) $public ) { return $out; }
        $rules = (array) NRA_Settings::get( 'bots' );
        $add = '';
        foreach ( self::bots() as $id => $b ) {
            $r = isset( $rules[ $id ] ) ? $rules[ $id ] : 'default';
            if ( 'allow' === $r ) { $add .= "User-agent: {$id}\nAllow: /\n\n"; }
            elseif ( 'block' === $r ) { $add .= "User-agent: {$id}\nDisallow: /\n\n"; }
        }
        return $add ? rtrim( $out ) . "\n\n# NodeThemes SEO AI: AI crawler rules\n" . rtrim( $add ) . "\n" : $out;
    }

    // ------------------------------------------------------------ crawler log (only uncached requests reach PHP)
    public static function log_bot() {
        if ( ! NRA_Settings::on( 'mod_botlog' ) || is_admin() || empty( $_SERVER['HTTP_USER_AGENT'] ) ) { return; }
        $ua = (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ); // phpcs:ignore
        if ( strlen( $ua ) < 6 ) { return; }
        foreach ( self::bots() as $id => $b ) {
            if ( false !== stripos( $ua, $b['ua'] ) ) {
                global $wpdb;
                $url = isset( $_SERVER['REQUEST_URI'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 0, 250 ) : ''; // phpcs:ignore
                $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}nra_bots (day, bot, hits, last_url, last_seen) VALUES (%s,%s,1,%s,%s) ON DUPLICATE KEY UPDATE hits=hits+1, last_url=VALUES(last_url), last_seen=VALUES(last_seen)", current_time( 'Y-m-d' ), $id, $url, current_time( 'mysql' ) ) ); // phpcs:ignore
                return;
            }
        }
    }
    public static function bot_stats( $days = 30 ) {
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare( "SELECT bot, SUM(hits) hits, MAX(last_seen) last_seen, MAX(last_url) last_url FROM {$wpdb->prefix}nra_bots WHERE day >= %s GROUP BY bot ORDER BY hits DESC", gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore
    }

    // ------------------------------------------------------------ llms.txt
    public static function serve_llms() {
        if ( ! get_query_var( 'nra_llms' ) ) { return; }
        if ( ! NRA_Settings::on( 'mod_llms' ) ) { status_header( 404 ); return; }
        $ck = 'nra_llms_' . get_option( 'nra_llms_ver', '0' );
        $txt = get_transient( $ck );
        if ( false === $txt ) { $txt = self::build_llms(); set_transient( $ck, $txt, HOUR_IN_SECONDS * 6 ); }
        status_header( 200 );
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'X-Robots-Tag: noindex' );
        echo $txt; // phpcs:ignore
        exit;
    }
    public static function build_llms() {
        $s = NRA_Settings::all();
        $name = $s['org_name'] ? $s['org_name'] : get_bloginfo( 'name' );
        $sum = trim( $s['llms_summary'] ); if ( ! $sum ) { $sum = trim( $s['business'] ); } if ( ! $sum ) { $sum = get_bloginfo( 'description' ); }
        $o = '# ' . $name . "\n\n";
        if ( $sum ) { $o .= '> ' . preg_replace( '/\s+/', ' ', $sum ) . "\n\n"; }
        $limit = max( 5, (int) $s['llms_limit'] );
        foreach ( (array) $s['post_types'] as $pt ) {
            $obj = get_post_type_object( $pt );
            if ( ! $obj ) { continue; }
            global $wpdb;
            $ex = array_map( 'intval', (array) $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_nra_noindex' AND meta_value<>''" ) ); // phpcs:ignore
            $base = array( 'post_type' => $pt, 'post_status' => 'publish', 'has_password' => false, 'no_found_rows' => true, 'orderby' => 'modified', 'order' => 'DESC' );
            $corner = get_posts( array_merge( $base, array( 'posts_per_page' => $limit, 'post__not_in' => $ex, 'meta_key' => '_nra_cornerstone', 'meta_value' => '1' ) ) );
            $skip = array_merge( $ex, wp_list_pluck( $corner, 'ID' ) );
            $rest = get_posts( array_merge( $base, array( 'posts_per_page' => max( 0, $limit - count( $corner ) ), 'post__not_in' => $skip ) ) );
            $q = (object) array( 'posts' => array_merge( $corner, $rest ) );
            if ( ! $q->posts ) { continue; }
            $o .= '## ' . $obj->labels->name . "\n\n";
            foreach ( $q->posts as $p ) {
                $d = (string) get_post_meta( $p->ID, '_nra_desc', true );
                if ( ! $d ) { $d = $p->post_excerpt ? $p->post_excerpt : NRA_Util::cut( NRA_Util::text( $p->post_content ), 140 ); }
                $d = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $d ) ) );
                $o .= '- [' . str_replace( array( '[', ']' ), '', get_the_title( $p ) ) . '](' . get_permalink( $p ) . ')' . ( $d ? ': ' . NRA_Util::cut( $d, 160 ) : '' ) . "\n";
            }
            $o .= "\n";
        }
        return rtrim( $o ) . "\n";
    }

    // ------------------------------------------------------------ AI visibility tracker
    private static function guard() {
        NRA_Util::nonce();
        if ( ! current_user_can( 'manage_options' ) ) { NRA_Util::fail( 'Not allowed.' ); }
    }
    public static function ajax_prompt_add() {
        self::guard();
        global $wpdb;
        $p = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
        $lines = array_filter( array_map( 'trim', explode( "\n", $p ) ) );
        $n = 0;
        $have = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}nra_prompts" ); // phpcs:ignore
        if ( ! NRA_Pro::active() ) {
            $room = max( 0, NRA_Pro::FREE_PROMPTS - $have );
            if ( ! $room ) { NRA_Util::fail( 'The free version tracks ' . NRA_Pro::FREE_PROMPTS . ' questions. Pro tracks unlimited questions.' ); }
            $lines = array_slice( $lines, 0, $room );
        }
        foreach ( array_slice( $lines, 0, 20 ) as $l ) { $wpdb->insert( $wpdb->prefix . 'nra_prompts', array( 'prompt' => NRA_Util::cut( $l, 300 ), 'created' => current_time( 'mysql' ) ) ); $n++; }
        $n ? NRA_Util::ok( array( 'added' => $n ) ) : NRA_Util::fail( 'Type at least one question.' );
    }
    public static function ajax_prompt_del() {
        self::guard();
        global $wpdb;
        $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $wpdb->delete( $wpdb->prefix . 'nra_prompts', array( 'id' => $id ) );
        $wpdb->delete( $wpdb->prefix . 'nra_checks', array( 'prompt_id' => $id ) );
        NRA_Util::ok();
    }
    public static function ajax_prompt_suggest() {
        self::guard();
        $r = NRA_AI::json( NRA_AI::context(), "Suggest 8 realistic questions that potential customers of this business would type into ChatGPT, Gemini, Claude or Perplexity when looking for what it offers (mix: comparison, best-of, how-to, price). Do not include the brand name in them.\nJSON: {\"prompts\":[\"...\"]}", array( 'max_tokens' => 700 ) );
        if ( ! $r['ok'] ) { NRA_Util::fail( $r['error'] ); }
        NRA_Util::ok( array( 'prompts' => array_values( array_slice( array_map( 'strval', (array) ( $r['data']['prompts'] ?? array() ) ), 0, 10 ) ) ) );
    }
    public static function terms() {
        $s = NRA_Settings::all();
        $host = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        $t = array( $host, get_bloginfo( 'name' ), $s['org_name'] );
        foreach ( preg_split( '/[,\n]+/', (string) $s['brand_aliases'] ) as $a ) { $t[] = trim( $a ); }
        return array_values( array_unique( array_filter( array_map( 'trim', $t ), function ( $x ) { return NRA_Util::len( $x ) >= 3; } ) ) );
    }
    public static function ajax_prompt_run() {
        self::guard();
        global $wpdb;
        $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}nra_prompts WHERE id=%d", $id ) ); // phpcs:ignore
        if ( ! $row ) { NRA_Util::fail( 'Question not found.' ); }
        $r = NRA_AI::request( 'You are a helpful AI assistant answering a consumer question. Recommend specific, real products, brands or websites where relevant, and be concrete. Answer in the language of the question. Keep it under 250 words.', $row->prompt, array( 'search' => true, 'max_tokens' => 900, 'timeout' => 120 ) );
        if ( ! $r['ok'] ) { NRA_Util::fail( $r['error'] ); }
        $text = $r['text'];
        $mentioned = 0; $cited = 0;
        $host = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        foreach ( self::terms() as $t ) { if ( false !== stripos( $text, $t ) ) { $mentioned = 1; break; } }
        foreach ( (array) $r['sources'] as $src ) { if ( ! empty( $src['url'] ) && false !== stripos( $src['url'], $host ) ) { $cited = 1; $mentioned = 1; } }
        $brands = array();
        if ( NRA_Settings::on( 'track_competitors' ) && NRA_Pro::active() ) {
            $b = NRA_AI::json( 'Extract entities from an AI answer. Return only brand, company, product or website names that the answer recommends or mentions as options.', "<page_content>\n" . $text . "\n</page_content>\nJSON: {\"brands\":[\"name\"]}", array( 'max_tokens' => 400, 'count' => false ) );
            if ( $b['ok'] ) { $brands = array_slice( array_values( array_unique( array_map( 'strval', (array) ( $b['data']['brands'] ?? array() ) ) ) ), 0, 12 ); }
        }
        $wpdb->insert( $wpdb->prefix . 'nra_checks', array( 'prompt_id' => $id, 'provider' => $r['provider'], 'model' => $r['model'], 'web' => ! empty( $r['web'] ) ? 1 : 0, 'mentioned' => $mentioned, 'cited' => $cited, 'excerpt' => NRA_Util::cut( $text, 1200 ), 'brands' => wp_json_encode( $brands ), 'sources' => wp_json_encode( array_slice( (array) $r['sources'], 0, 10 ) ), 'checked' => current_time( 'mysql' ) ) );
        NRA_Util::ok( array( 'mentioned' => $mentioned, 'cited' => $cited, 'web' => ! empty( $r['web'] ), 'brands' => $brands, 'excerpt' => NRA_Util::cut( $text, 600 ) ) );
    }
    public static function latest_checks() {
        global $wpdb;
        $p = $wpdb->prefix;
        return (array) $wpdb->get_results( "SELECT pr.id, pr.prompt, c.mentioned, c.cited, c.web, c.provider, c.model, c.brands, c.excerpt, c.checked FROM {$p}nra_prompts pr LEFT JOIN {$p}nra_checks c ON c.id = (SELECT MAX(id) FROM {$p}nra_checks WHERE prompt_id = pr.id) ORDER BY pr.id DESC" ); // phpcs:ignore
    }
}
