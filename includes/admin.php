<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** menus and admin screens */
final class NRA_Admin {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( NRA_FILE ), function ( $l ) { array_unshift( $l, '<a href="' . esc_url( admin_url( 'admin.php?page=nra-settings' ) ) . '">Settings</a>' ); return $l; } );
        add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
    }
    public static function flash( $type, $msg ) { set_transient( 'nra_flash_' . get_current_user_id(), array( $type, $msg ), 60 ); }
    private static function take_flash() { $k = 'nra_flash_' . get_current_user_id(); $f = get_transient( $k ); if ( $f ) { delete_transient( $k ); } return $f; }

    public static function notices() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore
        if ( 0 === strpos( $page, 'nra' ) ) { return; }
        if ( ! NRA_AI::configured() && ! get_user_meta( get_current_user_id(), 'nra_hide_setup', true ) ) {
            echo '<div class="notice notice-info"><p><strong>NodeThemes SEO AI</strong> is installed. <a href="' . esc_url( admin_url( 'admin.php?page=nra-settings' ) ) . '">Add your OpenAI, Claude or Gemini key</a> to start.</p></div>';
        }
    }

    public static function menu() {
        $cap = 'edit_others_posts';
        add_menu_page( 'NodeThemes SEO AI', 'NodeThemes SEO', $cap, 'nra', array( __CLASS__, 'dashboard' ), 'dashicons-chart-area', 58 );
        add_submenu_page( 'nra', 'Dashboard', 'Dashboard', $cap, 'nra', array( __CLASS__, 'dashboard' ) );
        add_submenu_page( 'nra', 'Link Map', 'Link Map', $cap, 'nra-linkmap', array( __CLASS__, 'linkmap' ) );
        add_submenu_page( 'nra', 'Bulk AI', 'Bulk AI', $cap, 'nra-bulk', array( __CLASS__, 'bulk' ) );
        add_submenu_page( 'nra', 'Insights', 'Insights', 'manage_options', 'nra-insights', array( __CLASS__, 'insights' ) );
        add_submenu_page( 'nra', 'AI Visibility', 'AI Visibility', $cap, 'nra-ai', array( __CLASS__, 'ai_visibility' ) );
        add_submenu_page( 'nra', 'Redirects & 404', 'Redirects & 404', 'manage_options', 'nra-redirects', array( __CLASS__, 'redirects' ) );
        add_submenu_page( 'nra', 'Settings', 'Settings', 'manage_options', 'nra-settings', array( 'NRA_Settings', 'render_page' ) );
    }

    public static function assets( $hook ) {
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore
        if ( 0 !== strpos( $page, 'nra' ) ) { return; }
        wp_enqueue_script( 'nra-pages', NRA_URL . 'assets/pages.js', array( 'jquery', 'nra-common' ), NRA_VERSION, true );
    }

    public static function insights() {
        if ( NRA_Pro::active() && has_action( 'nra_insights_page' ) ) { do_action( 'nra_insights_page' ); return; }
        echo '<div class="wrap nra-wrap"><div class="nra-hero"><div><h1>Insights</h1><p>Your Google Search Console and Analytics numbers, explained by AI, inside WordPress.</p></div></div>';
        NRA_Pro::upsell_box( 'Connect Google with one click', 'Search Console and Analytics inside WordPress, a daily AI report by email, automatic sitemap submission, ad-campaign monitoring and AI fixes for pages close to page one.' );
        echo '<div class="nra-card"><h2>Free: set it up yourself</h2><p>Add your verification codes and GA4 ID under <a href="' . esc_url( admin_url( 'admin.php?page=nra-settings' ) ) . '">Settings &rarr; Search &amp; Analytics</a>. Your sitemap is <code>' . esc_html( home_url( '/sitemap.xml' ) ) . '</code>.</p></div></div>';
    }

    public static function bulk() {
        if ( NRA_Pro::active() && class_exists( 'NRA_Bulk' ) ) { NRA_Bulk::render_page(); return; }
        echo '<div class="wrap nra-wrap"><div class="nra-hero"><div><h1>Bulk AI optimisation</h1><p>Optimise hundreds of products and posts in one run: keyword, title, description, FAQ and takeaways for each.</p></div></div>';
        NRA_Pro::upsell_box();
        echo '</div>';
    }

    // ------------------------------------------------------------ dashboard
    public static function dashboard() {
        global $wpdb;
        $types = (array) NRA_Settings::get( 'post_types' );
        $ph = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ($ph)", $types ) ); // phpcs:ignore
        $scored = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_nra_score'" ); // phpcs:ignore
        $avg = (int) round( (float) $wpdb->get_var( "SELECT AVG(meta_value) FROM {$wpdb->postmeta} WHERE meta_key='_nra_score'" ) ); // phpcs:ignore
        $avgg = (int) round( (float) $wpdb->get_var( "SELECT AVG(meta_value) FROM {$wpdb->postmeta} WHERE meta_key='_nra_geo_score'" ) ); // phpcs:ignore
        $nodesc = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_status='publish' AND p.post_type IN ($ph) AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_nra_desc' AND m.meta_value<>'')", $types ) ); // phpcs:ignore
        $nokw = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_status='publish' AND p.post_type IN ($ph) AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_nra_focuskw' AND m.meta_value<>'')", $types ) ); // phpcs:ignore
        $built = (int) get_option( 'nra_graph_built', 0 );
        $orph = 0;
        if ( $built ) { list( $in ) = NRA_Links::counts(); foreach ( NRA_Links::post_ids_public() as $id ) { if ( empty( $in[ $id ] ) ) { $orph++; } } }
        $n404 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}nra_404" ); // phpcs:ignore
        $bots = NRA_Geo::bot_stats( 30 ); $bothits = 0; foreach ( $bots as $b ) { $bothits += (int) $b->hits; }
        $chk = NRA_Geo::latest_checks(); $ct = 0; $cm = 0; foreach ( $chk as $c ) { if ( null !== $c->mentioned ) { $ct++; if ( $c->mentioned ) { $cm++; } } }
        $u = NRA_AI::usage_today();
        $worst = get_posts( array( 'post_type' => $types, 'post_status' => 'publish', 'posts_per_page' => 8, 'meta_key' => '_nra_score', 'orderby' => 'meta_value_num', 'order' => 'ASC', 'no_found_rows' => true ) );
        $steps = array(
            array( NRA_AI::configured(), 'Connect your AI key', admin_url( 'admin.php?page=nra-settings' ) ),
            array( '' !== trim( (string) NRA_Settings::get( 'business' ) ), 'Describe your business (makes every AI result better)', admin_url( 'admin.php?page=nra-settings' ) ),
            array( $total > 0 && $nodesc < $total, 'Generate SEO data for your pages', admin_url( 'admin.php?page=nra-bulk' ) ),
            array( (bool) $built, 'Build the internal link map', admin_url( 'admin.php?page=nra-linkmap' ) ),
            array( $ct > 0, 'Run your first AI-visibility check', admin_url( 'admin.php?page=nra-ai' ) ),
        );
        ?>
        <div class="wrap nra-wrap">
            <div class="nra-hero"><div><h1>NodeThemes SEO AI</h1><p>SEO for Google and for AI answer engines, driven by your own AI key.</p></div><div class="nra-hero-r"><?php echo esc_html( NRA_Settings::providers()[ NRA_Settings::get( 'provider' ) ]['label'] ); ?> &middot; <?php echo esc_html( NRA_Settings::model() ); ?><br><small>AI requests today: <?php echo (int) $u['calls']; ?><?php echo NRA_Settings::get( 'daily_cap' ) ? ' / ' . (int) NRA_Settings::get( 'daily_cap' ) : ''; ?></small><br><small>AI spend today (est.): $<?php echo esc_html( number_format( (float) $u['cost'], 2 ) ); ?><?php echo (float) NRA_Settings::get( 'daily_budget' ) > 0 ? ' / $' . esc_html( number_format( (float) NRA_Settings::get( 'daily_budget' ), 2 ) ) : ''; ?></small></div></div>
            <?php do_action( 'nra_dashboard_top' ); ?>
            <div class="nra-stats">
                <div class="nra-stat"><span>Average SEO score</span><b class="<?php echo esc_attr( NRA_Analyzer::status_of( $avg ) ); ?>"><?php echo $scored ? (int) $avg : '-'; ?></b><i><?php echo (int) $scored; ?> of <?php echo (int) $total; ?> pages scored</i></div>
                <div class="nra-stat"><span>AI-search readiness</span><b class="<?php echo esc_attr( NRA_Analyzer::status_of( $avgg ) ); ?>"><?php echo $scored ? (int) $avgg : '-'; ?></b><i>How quotable your pages are</i></div>
                <div class="nra-stat"><span>Missing meta description</span><b class="<?php echo $nodesc ? 'bad' : 'good'; ?>"><?php echo (int) $nodesc; ?></b><i><a href="<?php echo esc_url( admin_url( 'admin.php?page=nra-bulk' ) ); ?>">Fix with AI</a></i></div>
                <div class="nra-stat"><span>No focus keyword</span><b class="<?php echo $nokw ? 'ok' : 'good'; ?>"><?php echo (int) $nokw; ?></b><i>pages</i></div>
                <div class="nra-stat"><span>Orphan pages</span><b class="<?php echo $built ? ( $orph ? 'bad' : 'good' ) : ''; ?>"><?php echo $built ? (int) $orph : '?'; ?></b><i><a href="<?php echo esc_url( admin_url( 'admin.php?page=nra-linkmap' ) ); ?>"><?php echo $built ? 'Open link map' : 'Build link map'; ?></a></i></div>
                <div class="nra-stat"><span>AI mentions you</span><b class="<?php echo $ct ? ( $cm ? 'good' : 'bad' ) : ''; ?>"><?php echo $ct ? (int) $cm . '/' . (int) $ct : '-'; ?></b><i><a href="<?php echo esc_url( admin_url( 'admin.php?page=nra-ai' ) ); ?>">AI Visibility</a></i></div>
                <div class="nra-stat"><span>AI crawler visits (30d)</span><b><?php echo (int) $bothits; ?></b><i>uncached requests only</i></div>
                <div class="nra-stat"><span>404 URLs logged</span><b class="<?php echo $n404 ? 'ok' : 'good'; ?>"><?php echo (int) $n404; ?></b><i><a href="<?php echo esc_url( admin_url( 'admin.php?page=nra-redirects' ) ); ?>">Redirects</a></i></div>
            </div>
            <div class="nra-cols">
                <div class="nra-card"><h2>Getting started</h2><ul class="nra-steps"><?php foreach ( $steps as $s ) { echo '<li class="' . ( $s[0] ? 'done' : '' ) . '"><a href="' . esc_url( $s[2] ) . '">' . esc_html( $s[1] ) . '</a></li>'; } ?></ul>
                <p class="nra-help">Sitemap: <a href="<?php echo esc_url( NRA_Sitemap::url() ); ?>" target="_blank"><?php echo esc_html( NRA_Sitemap::url() ); ?></a><br>llms.txt: <a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/llms.txt' ) ); ?></a></p></div>
                <div class="nra-card"><h2>Pages that need attention</h2>
                    <?php if ( ! $worst ) : ?><p class="nra-help">No scores yet. Open <a href="<?php echo esc_url( admin_url( 'admin.php?page=nra-bulk' ) ); ?>">Bulk AI</a> and press "Re-score all pages".</p><?php else : ?>
                    <table class="widefat striped"><tbody><?php foreach ( $worst as $p ) { $s = (int) get_post_meta( $p->ID, '_nra_score', true ); echo '<tr><td><a href="' . esc_url( get_edit_post_link( $p->ID ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></td><td><span class="nra-pill ' . esc_attr( NRA_Analyzer::status_of( $s ) ) . '">' . $s . '</span></td></tr>'; } ?></tbody></table><?php endif; ?>
                </div>
            </div>
            <p class="nra-hint">Scores are on-page checks, not a ranking prediction. Nobody can guarantee a position; the plugin removes technical and content gaps so your pages compete properly.</p>
        </div>
        <?php
    }

    // ------------------------------------------------------------ link map
    public static function linkmap() {
        $focus = isset( $_GET['focus'] ) ? (int) $_GET['focus'] : 0; // phpcs:ignore
        ?>
        <div class="wrap nra-wrap" id="nra-linkmap" data-focus="<?php echo (int) $focus; ?>">
            <div class="nra-hero"><div><h1>Link Map</h1><p>See how your pages link to each other. Red = nobody links to it (invisible to Google). Click a page to see which other pages should link to it, then add the link in one click.</p></div></div>
            <div class="nra-card" id="nra-lm-build"><p class="nra-help" id="nra-lm-status">Loading...</p><button type="button" class="nra-btn primary" id="nra-lm-scan">Scan my site's links</button> <?php echo NRA_Pro::active() ? '<button type="button" class="nra-btn primary" id="nra-lm-planall">&#9889; Smart plan for all pages that need links</button>' : NRA_Pro::lock( 'Smart plan for all pages that need links', 'smartplan' ); ?> <span id="nra-lm-prog" class="nra-hint"></span></div>
            <div class="nra-lm">
                <div class="nra-card nra-lm-graph"><div class="nra-legend"><span class="d bad"></span>no inbound links <span class="d ok"></span>1-2 <span class="d good"></span>3+ <span class="d star"></span>cornerstone</div><svg id="nra-lm-svg" viewBox="0 0 900 600" preserveAspectRatio="xMidYMid meet"></svg>
                    <div class="nra-inline"><select id="nra-lm-pick" class="nra-in"><option value="">Pick a page...</option></select></div></div>
                <div class="nra-card nra-lm-side" id="nra-lm-side"><h2>Needs links</h2><p class="nra-help">Pages with the fewest inbound links, products and cornerstone pages first.</p><div id="nra-lm-list"></div></div>
            </div>
        </div>
        <?php
    }

    // ------------------------------------------------------------ AI visibility
    public static function ai_visibility() {
        $rows = NRA_Geo::latest_checks();
        $bots = NRA_Geo::bot_stats( 30 );
        $bt = NRA_Geo::bots();
        $inx = get_option( 'nra_indexnow_last' );
        ?>
        <div class="wrap nra-wrap" id="nra-ai">
            <div class="nra-hero"><div><h1>AI Visibility</h1><p>Do ChatGPT, Gemini and Claude mention your site when customers ask? Add the questions your customers ask and check them with your own AI key.</p></div></div>
            <div class="nra-card">
                <h2>Questions to track</h2>
                <textarea id="nra-pr-new" class="nra-in" rows="3" placeholder="One question per line, e.g. What is the best WooCommerce theme for a digital marketplace?"></textarea>
                <div class="nra-inline"><button type="button" class="nra-btn primary" id="nra-pr-add">Add</button><button type="button" class="nra-btn" id="nra-pr-suggest">&#10024; Suggest questions with AI</button><span class="nra-grow"></span><button type="button" class="nra-btn" id="nra-pr-runall">Check all now</button></div>
                <table class="widefat striped nra-table" id="nra-pr-table"><thead><tr><th>Question</th><th>Mentioned</th><th>Linked</th><th>Others named</th><th>Last check</th><th></th></tr></thead><tbody>
                <?php foreach ( $rows as $r ) :
                    $brands = $r->brands ? json_decode( $r->brands, true ) : array(); ?>
                    <tr data-id="<?php echo (int) $r->id; ?>"><td><?php echo esc_html( $r->prompt ); ?><?php if ( $r->excerpt ) { echo '<details><summary>Answer excerpt</summary><div class="nra-excerpt">' . esc_html( $r->excerpt ) . '</div></details>'; } ?></td>
                    <td class="c-m"><?php echo null === $r->mentioned ? '-' : ( $r->mentioned ? '<span class="nra-pill good">Yes</span>' : '<span class="nra-pill bad">No</span>' ); ?></td>
                    <td class="c-c"><?php echo null === $r->mentioned ? '-' : ( $r->cited ? '<span class="nra-pill good">Yes</span>' : ( $r->web ? '<span class="nra-pill bad">No</span>' : '<span title="Web search was not available, so links cannot be verified">n/a</span>' ) ); ?></td>
                    <td class="c-b"><?php echo esc_html( implode( ', ', array_slice( (array) $brands, 0, 6 ) ) ); ?></td>
                    <td class="c-t"><?php echo $r->checked ? esc_html( NRA_Util::ago( strtotime( $r->checked ) ) ) . '<br><small>' . esc_html( $r->provider . ' ' . ( $r->web ? '+ web' : 'no web' ) ) . '</small>' : '-'; ?></td>
                    <td><button type="button" class="nra-btn sm nra-pr-run">Check</button> <button type="button" class="nra-btn sm nra-pr-del">&times;</button></td></tr>
                <?php endforeach; if ( ! $rows ) { echo '<tr class="nra-empty"><td colspan="6">No questions yet.</td></tr>'; } ?>
                </tbody></table>
                <p class="nra-hint">Honest limits: this asks <em>your</em> chosen model (with its web search when your plan allows it). It is a sample, not the exact answer every user sees; results vary by day, country and wording. Without web search the check only reflects what the model already knows. Use it to see trends, not as proof.</p>
            </div>
            <div class="nra-card"><h2>AI crawlers that visited (last 30 days)</h2>
                <?php if ( ! $bots ) : ?><p class="nra-help">No AI crawler visits recorded yet. Pages served from a page cache never reach PHP, so this undercounts on cached sites.</p><?php else : ?>
                <table class="widefat striped"><thead><tr><th>Bot</th><th>Company</th><th>Purpose</th><th>Visits</th><th>Last seen</th><th>Last URL</th></tr></thead><tbody><?php foreach ( $bots as $b ) { $i = isset( $bt[ $b->bot ] ) ? $bt[ $b->bot ] : array( 'co' => '', 'type' => '' ); echo '<tr><td><code>' . esc_html( $b->bot ) . '</code></td><td>' . esc_html( $i['co'] ) . '</td><td>' . esc_html( NRA_Geo::type_label( $i['type'] ) ) . '</td><td>' . (int) $b->hits . '</td><td>' . esc_html( NRA_Util::ago( strtotime( $b->last_seen ) ) ) . '</td><td>' . esc_html( $b->last_url ) . '</td></tr>'; } ?></tbody></table><?php endif; ?>
                <p class="nra-hint">Detected by user-agent text, which any client can fake. Treat it as an indication.</p>
            </div>
            <div class="nra-card"><h2>Technical signals</h2><ul class="nra-steps">
                <li class="<?php echo NRA_Settings::on( 'mod_llms' ) ? 'done' : ''; ?>">llms.txt <?php echo NRA_Settings::on( 'mod_llms' ) ? '<a href="' . esc_url( home_url( '/llms.txt' ) ) . '" target="_blank">published</a>' : 'off'; ?> <small>(a proposed standard; no major AI company has confirmed using it yet)</small></li>
                <li class="<?php echo NRA_Settings::on( 'mod_schema' ) ? 'done' : ''; ?>">Structured data (Organization, Article, Product, FAQ, Breadcrumbs)</li>
                <li class="<?php echo NRA_Settings::on( 'mod_sitemap' ) ? 'done' : ''; ?>">XML sitemap with lastmod</li>
                <li class="<?php echo NRA_Settings::on( 'mod_indexnow' ) ? 'done' : ''; ?>">IndexNow pings (Bing and partners)<?php echo $inx ? ' &middot; last: ' . esc_html( NRA_Util::ago( $inx['time'] ) ) . ( $inx['ok'] ? ' OK' : ' failed' ) : ''; ?></li>
                <li class="<?php echo array_filter( (array) NRA_Settings::get( 'bots' ), function ( $v ) { return 'default' !== $v; } ) ? 'done' : ''; ?>">AI crawler rules in robots.txt (<a href="<?php echo esc_url( admin_url( 'admin.php?page=nra-settings#geo' ) ); ?>">configure</a>)</li>
            </ul></div>
        </div>
        <?php
    }

    // ------------------------------------------------------------ redirects
    public static function redirects() {
        global $wpdb;
        $fl = self::take_flash();
        $rows = (array) $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}nra_redirects ORDER BY id DESC LIMIT 300" ); // phpcs:ignore
        $nf = (array) $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}nra_404 ORDER BY hits DESC LIMIT 100" ); // phpcs:ignore
        ?>
        <div class="wrap nra-wrap">
            <div class="nra-hero"><div><h1>Redirects &amp; 404 monitor</h1><p>When a page moves, send visitors and Google to the new address. Changing a page's URL slug creates a 301 automatically.</p></div></div>
            <?php if ( $fl ) : ?><div class="nra-flash <?php echo esc_attr( $fl[0] ); ?>"><?php echo esc_html( $fl[1] ); ?></div><?php endif; ?>
            <?php if ( ! NRA_Settings::on( 'mod_redirects' ) ) : ?><div class="nra-flash warn">The redirect module is switched off in Settings &gt; Modules.</div><?php endif; ?>
            <div class="nra-card"><h2>Add redirect</h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nra-inline" id="nra-rd-form"><input type="hidden" name="action" value="nra_redirect_add"><?php wp_nonce_field( 'nra_redirect_add' ); ?>
                    <input class="nra-in" name="source" id="nra-rd-src" placeholder="/old-page" required><span>&rarr;</span><input class="nra-in" name="target" id="nra-rd-dst" placeholder="https://yoursite.com/new-page">
                    <select name="code" class="nra-in"><option value="301">301 permanent</option><option value="302">302 temporary</option><option value="307">307</option><option value="410">410 gone</option></select>
                    <label><input type="checkbox" name="is_regex" value="1"> regex</label><button class="nra-btn primary">Add</button></form></div>
            <div class="nra-card"><h2>Redirects (<?php echo count( $rows ); ?>)</h2>
                <table class="widefat striped"><thead><tr><th>From</th><th>To</th><th>Code</th><th>Hits</th><th></th></tr></thead><tbody>
                <?php foreach ( $rows as $r ) { echo '<tr><td><code>' . esc_html( $r->source ) . '</code>' . ( $r->auto ? ' <small>auto</small>' : '' ) . '</td><td>' . esc_html( $r->target ) . '</td><td>' . (int) $r->code . '</td><td>' . (int) $r->hits . '</td><td><a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=nra_redirect_del&id=' . (int) $r->id ), 'nra_redirect_del' ) ) . '">Delete</a></td></tr>'; } if ( ! $rows ) { echo '<tr><td colspan="5">None yet.</td></tr>'; } ?>
                </tbody></table></div>
            <div class="nra-card"><h2>404 errors</h2>
                <table class="widefat striped"><thead><tr><th>URL</th><th>Hits</th><th>Last referrer</th><th></th></tr></thead><tbody>
                <?php foreach ( $nf as $r ) { echo '<tr><td><code>' . esc_html( $r->path ) . '</code></td><td>' . (int) $r->hits . '</td><td>' . esc_html( $r->referrer ) . '</td><td><button type="button" class="nra-btn sm nra-rd-from" data-path="' . esc_attr( $r->path ) . '">Redirect</button></td></tr>'; } if ( ! $nf ) { echo '<tr><td colspan="4">No 404 errors logged.</td></tr>'; } ?>
                </tbody></table>
                <?php if ( $nf ) : ?><p><a class="nra-btn sm" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=nra_404_clear' ), 'nra_404_clear' ) ); ?>">Clear log</a></p><?php endif; ?></div>
        </div>
        <?php
    }
}
