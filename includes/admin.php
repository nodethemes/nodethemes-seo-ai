<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** menus and admin screens */
final class NTSEOAI_Admin {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( NTSEOAI_FILE ), function ( $l ) { array_unshift( $l, '<a href="' . esc_url( admin_url( 'admin.php?page=ntseoai-settings' ) ) . '">Settings</a>' ); return $l; } );
        add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
        add_action( 'admin_post_ntseoai_hide_setup', array( __CLASS__, 'hide_setup' ) );
    }
    public static function flash( $type, $msg ) { set_transient( 'ntseoai_flash_' . get_current_user_id(), array( $type, $msg ), 60 ); }
    private static function take_flash() { $k = 'ntseoai_flash_' . get_current_user_id(); $f = get_transient( $k ); if ( $f ) { delete_transient( $k ); } return $f; }

    public static function notices() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) { return; }
        if ( NTSEOAI_AI::configured() || get_user_meta( get_current_user_id(), 'ntseoai_hide_setup', true ) ) { return; }
        $hide = wp_nonce_url( admin_url( 'admin-post.php?action=ntseoai_hide_setup' ), 'ntseoai_hide_setup' );
        echo '<div class="notice notice-info"><p><strong>NodeThemes SEO AI</strong> is installed. <a href="' . esc_url( admin_url( 'admin.php?page=ntseoai-settings' ) ) . '">Connect an AI provider</a> to use the AI tools. <a href="' . esc_url( $hide ) . '">Dismiss</a></p></div>';
    }
    public static function hide_setup() {
        check_admin_referer( 'ntseoai_hide_setup' );
        update_user_meta( get_current_user_id(), 'ntseoai_hide_setup', 1 );
        wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
        exit;
    }

    public static function menu() {
        $cap = 'edit_others_posts';
        add_menu_page( 'NodeThemes SEO AI', 'NodeThemes SEO', $cap, 'ntseoai', array( __CLASS__, 'dashboard' ), 'dashicons-chart-area', 58 );
        add_submenu_page( 'ntseoai', 'Dashboard', 'Dashboard', $cap, 'ntseoai', array( __CLASS__, 'dashboard' ) );
        if ( NTSEOAI_Pro::has_code() ) { add_submenu_page( 'ntseoai', 'Auto Writer', 'Auto Writer', $cap, 'ntseoai-writer', array( __CLASS__, 'writer' ) ); } // Pro screens: only listed when the add-on is installed
        add_submenu_page( 'ntseoai', 'Link Map', 'Link Map', $cap, 'ntseoai-linkmap', array( __CLASS__, 'linkmap' ) );
        if ( NTSEOAI_Pro::has_code() ) { add_submenu_page( 'ntseoai', 'Bulk AI', 'Bulk AI', $cap, 'ntseoai-bulk', array( __CLASS__, 'bulk' ) ); } // Pro screens: only listed when the add-on is installed
        add_submenu_page( 'ntseoai', 'Insights', 'Insights', 'manage_options', 'ntseoai-insights', array( __CLASS__, 'insights' ) );
        add_submenu_page( 'ntseoai', 'AI Visibility', 'AI Visibility', $cap, 'ntseoai-ai', array( __CLASS__, 'ai_visibility' ) );
        add_submenu_page( 'ntseoai', 'Redirects & 404', 'Redirects & 404', 'manage_options', 'ntseoai-redirects', array( __CLASS__, 'redirects' ) );
        add_submenu_page( 'ntseoai', 'Settings', 'Settings', 'manage_options', 'ntseoai-settings', array( 'NTSEOAI_Settings', 'render_page' ) );
    }

    public static function assets( $hook ) {
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore
        if ( 0 !== strpos( $page, 'ntseoai' ) ) { return; }
        wp_enqueue_script( 'ntseoai-pages', NTSEOAI_URL . 'assets/pages.js', array( 'jquery', 'ntseoai-common' ), NTSEOAI_VERSION, true );
    }

    public static function insights() {
        if ( NTSEOAI_Pro::active() && has_action( 'ntseoai_insights_page' ) ) { do_action( 'ntseoai_insights_page' ); return; }
        echo '<div class="wrap ntseoai-wrap"><div class="ntseoai-hero"><div><h1>Insights</h1><p>Your Google Search Console and Analytics numbers, explained by AI, inside WordPress.</p></div></div>';
        NTSEOAI_Pro::upsell_box( 'Connect Google with one click', 'Search Console and Analytics inside WordPress, a daily AI report by email, automatic sitemap submission, ad-campaign monitoring and AI fixes for pages close to page one.' );
        echo '<div class="ntseoai-card"><h2>Free: set it up yourself</h2><p>Add your verification codes and GA4 ID under <a href="' . esc_url( admin_url( 'admin.php?page=ntseoai-settings' ) ) . '">Settings &rarr; Search &amp; Analytics</a>. Your sitemap is <code>' . esc_html( home_url( '/sitemap.xml' ) ) . '</code>.</p></div></div>';
    }

    public static function writer() {
        if ( has_action( 'ntseoai_writer_page' ) ) { do_action( 'ntseoai_writer_page' ); return; }
        echo '<div class="wrap ntseoai-wrap"><div class="ntseoai-hero"><div><h1>Auto Writer</h1><p>One topic in, a complete page out: focus keyword, titles, meta description, a direct-answer opening, takeaways, sections, a table, FAQ, internal links and checked sources. Then it repairs what still fails and saves a draft for your review.</p></div></div>';
        NTSEOAI_Pro::upsell_box( 'Auto Writer', 'The Auto Writer is part of NodeThemes SEO AI Pro.' );
        echo '</div>';
    }

    public static function bulk() {
        if ( NTSEOAI_Pro::active() && class_exists( 'NTSEOAI_Bulk' ) ) { NTSEOAI_Bulk::render_page(); return; }
        echo '<div class="wrap ntseoai-wrap"><div class="ntseoai-hero"><div><h1>Bulk AI optimisation</h1><p>Optimise hundreds of products and posts in one run: keyword, title, description, FAQ and takeaways for each.</p></div></div>';
        NTSEOAI_Pro::upsell_box();
        echo '</div>';
    }

    // ------------------------------------------------------------ dashboard
    public static function dashboard() {
        global $wpdb;
        $types = (array) NTSEOAI_Settings::get( 'post_types' );
        $ph = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ($ph)", $types ) ); // phpcs:ignore
        $scored = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_ntseoai_score'" ); // phpcs:ignore
        $avg = (int) round( (float) $wpdb->get_var( "SELECT AVG(meta_value) FROM {$wpdb->postmeta} WHERE meta_key='_ntseoai_score'" ) ); // phpcs:ignore
        $avgg = (int) round( (float) $wpdb->get_var( "SELECT AVG(meta_value) FROM {$wpdb->postmeta} WHERE meta_key='_ntseoai_geo_score'" ) ); // phpcs:ignore
        $nodesc = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_status='publish' AND p.post_type IN ($ph) AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_ntseoai_desc' AND m.meta_value<>'')", $types ) ); // phpcs:ignore
        $nokw = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_status='publish' AND p.post_type IN ($ph) AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_ntseoai_focuskw' AND m.meta_value<>'')", $types ) ); // phpcs:ignore
        $built = (int) get_option( 'ntseoai_graph_built', 0 );
        $orph = 0;
        if ( $built ) { list( $in ) = NTSEOAI_Links::counts(); foreach ( NTSEOAI_Links::post_ids_public() as $id ) { if ( empty( $in[ $id ] ) ) { $orph++; } } }
        $n404 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ntseoai_404" ); // phpcs:ignore
        $bots = NTSEOAI_Geo::bot_stats( 30 ); $bothits = 0; foreach ( $bots as $b ) { $bothits += (int) $b->hits; }
        $chk = NTSEOAI_Geo::latest_checks(); $ct = 0; $cm = 0; foreach ( $chk as $c ) { if ( null !== $c->mentioned ) { $ct++; if ( $c->mentioned ) { $cm++; } } }
        $u = NTSEOAI_AI::usage_today();
        $worst = get_posts( array( 'post_type' => $types, 'post_status' => 'publish', 'posts_per_page' => 8, 'meta_key' => '_ntseoai_score', 'orderby' => 'meta_value_num', 'order' => 'ASC', 'no_found_rows' => true ) );
        $steps = array(
            array( NTSEOAI_AI::configured(), 'Connect your AI key', admin_url( 'admin.php?page=ntseoai-settings' ) ),
            array( '' !== trim( (string) NTSEOAI_Settings::get( 'business' ) ), 'Describe your business (makes every AI result better)', admin_url( 'admin.php?page=ntseoai-settings' ) ),
            array( $total > 0 && $nodesc < $total, 'Add SEO titles and descriptions to your pages', NTSEOAI_Pro::has_code() ? admin_url( 'admin.php?page=ntseoai-bulk' ) : admin_url( 'edit.php?post_type=page' ) ),
            array( (bool) $built, 'Build the internal link map', admin_url( 'admin.php?page=ntseoai-linkmap' ) ),
        );
        if ( NTSEOAI_Pro::active() ) { $steps[] = array( $ct > 0, 'Run your first AI-visibility check', admin_url( 'admin.php?page=ntseoai-ai' ) ); }
        ?>
        <div class="wrap ntseoai-wrap">
            <div class="ntseoai-hero"><div><h1>NodeThemes SEO AI</h1><p>SEO for Google and for AI answer engines, driven by your own AI key.</p></div><div class="ntseoai-hero-r"><?php echo esc_html( NTSEOAI_Settings::provider_label() ); ?><?php echo NTSEOAI_Settings::WP_PROVIDER === NTSEOAI_Settings::get( 'provider' ) ? '' : ' &middot; ' . esc_html( NTSEOAI_Settings::model() ); ?><br><small>AI requests today: <?php echo (int) $u['calls']; ?><?php echo NTSEOAI_Settings::get( 'daily_cap' ) ? ' / ' . (int) NTSEOAI_Settings::get( 'daily_cap' ) : ''; ?></small><br><small>AI spend today (est.): $<?php echo esc_html( number_format( (float) $u['cost'], 2 ) ); ?><?php echo (float) NTSEOAI_Settings::get( 'daily_budget' ) > 0 ? ' / $' . esc_html( number_format( (float) NTSEOAI_Settings::get( 'daily_budget' ), 2 ) ) : ''; ?></small></div></div>
            <?php do_action( 'ntseoai_dashboard_top' ); ?>
            <div class="ntseoai-stats">
                <div class="ntseoai-stat"><span>Average SEO score</span><b class="<?php echo esc_attr( NTSEOAI_Analyzer::status_of( $avg ) ); ?>"><?php echo $scored ? (int) $avg : '-'; ?></b><i><?php echo (int) $scored; ?> of <?php echo (int) $total; ?> pages scored</i></div>
                <div class="ntseoai-stat"><span>AI-search readiness</span><b class="<?php echo esc_attr( NTSEOAI_Analyzer::status_of( $avgg ) ); ?>"><?php echo $scored ? (int) $avgg : '-'; ?></b><i>How quotable your pages are</i></div>
                <div class="ntseoai-stat"><span>Missing meta description</span><b class="<?php echo $nodesc ? 'bad' : 'good'; ?>"><?php echo (int) $nodesc; ?></b><i><?php if ( NTSEOAI_Pro::has_code() ) : ?><a href="<?php echo esc_url( admin_url( 'admin.php?page=ntseoai-bulk' ) ); ?>">Fix with AI</a><?php else : ?>pages<?php endif; ?></i></div>
                <div class="ntseoai-stat"><span>No focus keyword</span><b class="<?php echo $nokw ? 'ok' : 'good'; ?>"><?php echo (int) $nokw; ?></b><i>pages</i></div>
                <div class="ntseoai-stat"><span>Orphan pages</span><b class="<?php echo $built ? ( $orph ? 'bad' : 'good' ) : ''; ?>"><?php echo $built ? (int) $orph : '?'; ?></b><i><a href="<?php echo esc_url( admin_url( 'admin.php?page=ntseoai-linkmap' ) ); ?>"><?php echo $built ? 'Open link map' : 'Build link map'; ?></a></i></div>
                <div class="ntseoai-stat"><span>AI mentions you</span><b class="<?php echo $ct ? ( $cm ? 'good' : 'bad' ) : ''; ?>"><?php echo $ct ? (int) $cm . '/' . (int) $ct : '-'; ?></b><i><a href="<?php echo esc_url( admin_url( 'admin.php?page=ntseoai-ai' ) ); ?>">AI Visibility</a></i></div>
                <div class="ntseoai-stat"><span>AI crawler visits (30d)</span><b><?php echo (int) $bothits; ?></b><i>uncached requests only</i></div>
                <div class="ntseoai-stat"><span>404 URLs logged</span><b class="<?php echo $n404 ? 'ok' : 'good'; ?>"><?php echo (int) $n404; ?></b><i><a href="<?php echo esc_url( admin_url( 'admin.php?page=ntseoai-redirects' ) ); ?>">Redirects</a></i></div>
            </div>
            <div class="ntseoai-cols">
                <div class="ntseoai-card"><h2>Getting started</h2><ul class="ntseoai-steps"><?php foreach ( $steps as $s ) { echo '<li class="' . esc_attr( $s[0] ? 'done' : '' ) . '"><a href="' . esc_url( $s[2] ) . '">' . esc_html( $s[1] ) . '</a></li>'; } ?></ul>
                <p class="ntseoai-help">Sitemap: <a href="<?php echo esc_url( NTSEOAI_Sitemap::url() ); ?>" target="_blank"><?php echo esc_html( NTSEOAI_Sitemap::url() ); ?></a><br>llms.txt: <a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/llms.txt' ) ); ?></a></p></div>
                <div class="ntseoai-card"><h2>Pages that need attention</h2>
                    <?php if ( ! $worst ) : ?><p class="ntseoai-help">No scores yet. A page is scored when you open and save it<?php echo NTSEOAI_Pro::has_code() ? ', or press &quot;Re-score all pages&quot; on the <a href="' . esc_url( admin_url( 'admin.php?page=ntseoai-bulk' ) ) . '">Bulk AI</a> page' : ''; ?>.</p><?php else : ?>
                    <table class="widefat striped"><tbody><?php foreach ( $worst as $p ) { $s = (int) get_post_meta( $p->ID, '_ntseoai_score', true ); echo '<tr><td><a href="' . esc_url( get_edit_post_link( $p->ID ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></td><td><span class="ntseoai-pill ' . esc_attr( NTSEOAI_Analyzer::status_of( $s ) ) . '">' . (int) $s . '</span></td></tr>'; } ?></tbody></table><?php endif; ?>
                </div>
            </div>
            <p class="ntseoai-hint">Scores are on-page checks, not a ranking prediction. Nobody can guarantee a position; the plugin removes technical and content gaps so your pages compete properly.</p>
        </div>
        <?php
    }

    // ------------------------------------------------------------ link map
    public static function linkmap() {
        $focus = isset( $_GET['focus'] ) ? (int) $_GET['focus'] : 0; // phpcs:ignore
        ?>
        <div class="wrap ntseoai-wrap" id="ntseoai-linkmap" data-focus="<?php echo (int) $focus; ?>">
            <div class="ntseoai-hero"><div><h1>Link Map</h1><p>See how your pages link to each other. Red = nobody links to it (hard for Google to find). Click a page to see its links. With Pro, you also see which pages should link to it and add the link in one click.</p></div></div>
            <div class="ntseoai-card" id="ntseoai-lm-build"><p class="ntseoai-help" id="ntseoai-lm-status">Loading...</p><button type="button" class="ntseoai-btn primary" id="ntseoai-lm-scan">Scan my site's links</button> <?php echo wp_kses_post( NTSEOAI_Pro::active() ? '<button type="button" class="ntseoai-btn primary" id="ntseoai-lm-planall">&#9889; Smart plan for all pages that need links</button>' : NTSEOAI_Pro::lock( 'Smart plan for all pages that need links', 'smartplan' ) ); ?> <span id="ntseoai-lm-prog" class="ntseoai-hint"></span></div>
            <div class="ntseoai-lm">
                <div class="ntseoai-card ntseoai-lm-graph"><div class="ntseoai-legend"><span class="d bad"></span>no inbound links <span class="d ok"></span>1-2 <span class="d good"></span>3+ <span class="d star"></span>cornerstone</div><svg id="ntseoai-lm-svg" viewBox="0 0 900 600" preserveAspectRatio="xMidYMid meet"></svg>
                    <div class="ntseoai-inline"><select id="ntseoai-lm-pick" class="ntseoai-in"><option value="">Pick a page...</option></select></div></div>
                <div class="ntseoai-card ntseoai-lm-side" id="ntseoai-lm-side"><h2>Needs links</h2><p class="ntseoai-help">Pages with the fewest inbound links, products and cornerstone pages first.</p><div id="ntseoai-lm-list"></div></div>
            </div>
        </div>
        <?php
    }

    // ------------------------------------------------------------ AI visibility
    public static function ai_visibility() {
        $rows = NTSEOAI_Geo::latest_checks();
        $bots = NTSEOAI_Geo::bot_stats( 30 );
        $bt = NTSEOAI_Geo::bots();
        $inx = get_option( 'ntseoai_indexnow_last' );
        ?>
        <div class="wrap ntseoai-wrap" id="ntseoai-ai">
            <div class="ntseoai-hero"><div><h1>AI Visibility</h1><p>Do ChatGPT, Gemini and Claude mention your site when customers ask? Add the questions your customers ask and check them with your own AI key.</p></div></div>
            <?php
            if ( NTSEOAI_Pro::active() && has_action( 'ntseoai_visibility_questions' ) ) {
                do_action( 'ntseoai_visibility_questions', $rows );
            } else {
                echo wp_kses_post( '<div class="ntseoai-card"><h2>Questions to track ' . NTSEOAI_Pro::badge() . '</h2><p class="ntseoai-help">Add the questions your customers ask ChatGPT, Gemini or Claude, and check with your own AI key whether your site is mentioned or linked. The question tracker is part of NodeThemes SEO AI Pro.</p><p>' . NTSEOAI_Pro::lock( 'Track AI-visibility questions', 'visibility' ) . '</p>' );
                if ( $rows ) { self::visibility_table( $rows, false ); }
                echo '</div>';
            }
            ?>
            <div class="ntseoai-card"><h2>AI crawlers that visited (last 30 days)</h2>
                <?php if ( ! $bots ) : ?><p class="ntseoai-help">No AI crawler visits recorded yet. Pages served from a page cache never reach PHP, so this undercounts on cached sites.</p><?php else : ?>
                <table class="widefat striped"><thead><tr><th>Bot</th><th>Company</th><th>Purpose</th><th>Visits</th><th>Last seen</th><th>Last URL</th></tr></thead><tbody><?php foreach ( $bots as $b ) { $i = isset( $bt[ $b->bot ] ) ? $bt[ $b->bot ] : array( 'co' => '', 'type' => '' ); echo '<tr><td><code>' . esc_html( $b->bot ) . '</code></td><td>' . esc_html( $i['co'] ) . '</td><td>' . esc_html( NTSEOAI_Geo::type_label( $i['type'] ) ) . '</td><td>' . (int) $b->hits . '</td><td>' . esc_html( NTSEOAI_Util::ago( strtotime( $b->last_seen ) ) ) . '</td><td>' . esc_html( $b->last_url ) . '</td></tr>'; } ?></tbody></table><?php endif; ?>
                <p class="ntseoai-hint">Detected by user-agent text, which any client can fake. Treat it as an indication.</p>
            </div>
            <div class="ntseoai-card"><h2>Technical signals</h2><ul class="ntseoai-steps">
                <li class="<?php echo NTSEOAI_Settings::on( 'mod_llms' ) ? 'done' : ''; ?>">llms.txt <?php echo NTSEOAI_Settings::on( 'mod_llms' ) ? '<a href="' . esc_url( home_url( '/llms.txt' ) ) . '" target="_blank">published</a>' : 'off'; ?> <small>(a proposed standard; no major AI company has confirmed using it yet)</small></li>
                <li class="<?php echo NTSEOAI_Settings::on( 'mod_schema' ) ? 'done' : ''; ?>">Structured data (Organization, Article, Product, FAQ, Breadcrumbs)</li>
                <li class="<?php echo NTSEOAI_Settings::on( 'mod_sitemap' ) ? 'done' : ''; ?>">XML sitemap with lastmod</li>
                <li class="<?php echo NTSEOAI_Settings::on( 'mod_indexnow' ) ? 'done' : ''; ?>">IndexNow pings (Bing and partners)<?php echo $inx ? ' &middot; last: ' . esc_html( NTSEOAI_Util::ago( $inx['time'] ) ) . ( $inx['ok'] ? ' OK' : ' failed' ) : ''; ?></li>
                <li class="<?php echo esc_attr( array_filter( (array) NTSEOAI_Settings::get( 'bots' ), function ( $v ) { return 'default' !== $v; } ) ? 'done' : '' ); ?>">AI crawler rules in robots.txt (<a href="<?php echo esc_url( admin_url( 'admin.php?page=ntseoai-settings#geo' ) ); ?>">configure</a>)</li>
            </ul></div>
        </div>
        <?php
    }

    /** results table of the AI-visibility tracker (questions are added and checked by the Pro add-on) */
    public static function visibility_table( $rows, $actions ) {
        ?>
                <table class="widefat striped ntseoai-table" id="ntseoai-pr-table"><thead><tr><th>Question</th><th>Mentioned</th><th>Linked</th><th>Others named</th><th>Last check</th><?php if ( $actions ) : ?><th></th><?php endif; ?></tr></thead><tbody>
                <?php foreach ( $rows as $r ) :
                    $brands = $r->brands ? json_decode( $r->brands, true ) : array(); ?>
                    <tr data-id="<?php echo (int) $r->id; ?>"><td><?php echo esc_html( $r->prompt ); ?><?php if ( $r->excerpt ) { echo '<details><summary>Answer excerpt</summary><div class="ntseoai-excerpt">' . esc_html( $r->excerpt ) . '</div></details>'; } ?></td>
                    <td class="c-m"><?php echo null === $r->mentioned ? '-' : ( $r->mentioned ? '<span class="ntseoai-pill good">Yes</span>' : '<span class="ntseoai-pill bad">No</span>' ); ?></td>
                    <td class="c-c"><?php echo null === $r->mentioned ? '-' : ( $r->cited ? '<span class="ntseoai-pill good">Yes</span>' : ( $r->web ? '<span class="ntseoai-pill bad">No</span>' : '<span title="Web search was not available, so links cannot be verified">n/a</span>' ) ); ?></td>
                    <td class="c-b"><?php echo esc_html( implode( ', ', array_slice( (array) $brands, 0, 6 ) ) ); ?></td>
                    <td class="c-t"><?php echo $r->checked ? esc_html( NTSEOAI_Util::ago( strtotime( $r->checked ) ) ) . '<br><small>' . esc_html( $r->provider . ' ' . ( $r->web ? '+ web' : 'no web' ) ) . '</small>' : '-'; ?></td>
                    <?php if ( $actions ) : ?><td><button type="button" class="ntseoai-btn sm ntseoai-pr-run">Check</button> <button type="button" class="ntseoai-btn sm ntseoai-pr-del">&times;</button></td><?php endif; ?></tr>
                <?php endforeach; if ( ! $rows ) { echo '<tr class="ntseoai-empty"><td colspan="6">No questions yet.</td></tr>'; } ?>
                </tbody></table>
                <p class="ntseoai-hint">Honest limits: this asks <em>your</em> chosen model (with its web search when your plan allows it). It is a sample, not the exact answer every user sees; results vary by day, country and wording. Without web search the check only reflects what the model already knows. Use it to see trends, not as proof.</p>
        <?php
    }

    // ------------------------------------------------------------ redirects
    public static function redirects() {
        global $wpdb;
        $fl = self::take_flash();
        $rows = (array) $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ntseoai_redirects ORDER BY id DESC LIMIT 300" ); // phpcs:ignore
        $nf = (array) $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ntseoai_404 ORDER BY hits DESC LIMIT 100" ); // phpcs:ignore
        ?>
        <div class="wrap ntseoai-wrap">
            <div class="ntseoai-hero"><div><h1>Redirects &amp; 404 monitor</h1><p>When a page moves, send visitors and Google to the new address. Changing a page's URL slug creates a 301 automatically.</p></div></div>
            <?php if ( $fl ) : ?><div class="ntseoai-flash <?php echo esc_attr( $fl[0] ); ?>"><?php echo esc_html( $fl[1] ); ?></div><?php endif; ?>
            <?php if ( ! NTSEOAI_Settings::on( 'mod_redirects' ) ) : ?><div class="ntseoai-flash warn">The redirect module is switched off in Settings &gt; Modules.</div><?php endif; ?>
            <div class="ntseoai-card"><h2>Add redirect</h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ntseoai-inline" id="ntseoai-rd-form"><input type="hidden" name="action" value="ntseoai_redirect_add"><?php wp_nonce_field( 'ntseoai_redirect_add' ); ?>
                    <input class="ntseoai-in" name="source" id="ntseoai-rd-src" placeholder="/old-page" required><span>&rarr;</span><input class="ntseoai-in" name="target" id="ntseoai-rd-dst" placeholder="https://yoursite.com/new-page">
                    <select name="code" class="ntseoai-in"><option value="301">301 permanent</option><option value="302">302 temporary</option><option value="307">307</option><option value="410">410 gone</option></select>
                    <label><input type="checkbox" name="is_regex" value="1"> regex</label><button class="ntseoai-btn primary">Add</button></form></div>
            <div class="ntseoai-card"><h2>Redirects (<?php echo count( $rows ); ?>)</h2>
                <table class="widefat striped"><thead><tr><th>From</th><th>To</th><th>Code</th><th>Hits</th><th></th></tr></thead><tbody>
                <?php foreach ( $rows as $r ) { echo '<tr><td><code>' . esc_html( $r->source ) . '</code>' . ( $r->auto ? ' <small>auto</small>' : '' ) . '</td><td>' . esc_html( $r->target ) . '</td><td>' . (int) $r->code . '</td><td>' . (int) $r->hits . '</td><td><a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ntseoai_redirect_del&id=' . (int) $r->id ), 'ntseoai_redirect_del' ) ) . '">Delete</a></td></tr>'; } if ( ! $rows ) { echo '<tr><td colspan="5">None yet.</td></tr>'; } ?>
                </tbody></table></div>
            <div class="ntseoai-card"><h2>404 errors</h2>
                <table class="widefat striped"><thead><tr><th>URL</th><th>Hits</th><th>Last referrer</th><th></th></tr></thead><tbody>
                <?php foreach ( $nf as $r ) { echo '<tr><td><code>' . esc_html( $r->path ) . '</code></td><td>' . (int) $r->hits . '</td><td>' . esc_html( $r->referrer ) . '</td><td><button type="button" class="ntseoai-btn sm ntseoai-rd-from" data-path="' . esc_attr( $r->path ) . '">Redirect</button></td></tr>'; } if ( ! $nf ) { echo '<tr><td colspan="4">No 404 errors logged.</td></tr>'; } ?>
                </tbody></table>
                <?php if ( $nf ) : ?><p><a class="ntseoai-btn sm" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ntseoai_404_clear' ), 'ntseoai_404_clear' ) ); ?>">Clear log</a></p><?php endif; ?></div>
        </div>
        <?php
    }
}
