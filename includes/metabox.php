<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** editor meta box + all per-page AI actions */
final class NTSEOAI_Metabox {

    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'add' ) );
        add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
        add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'sidebar' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
        foreach ( array( 'analyze', 'keywords', 'meta', 'short', 'article', 'faq', 'coach', 'autopilot' ) as $a ) {
            add_action( 'wp_ajax_ntseoai_ai_' . $a, array( __CLASS__, 'ajax_' . $a ) );
        }
        foreach ( array( 'category', 'post_tag', 'product_cat', 'product_tag' ) as $tax ) {
            add_action( $tax . '_edit_form_fields', array( __CLASS__, 'term_fields' ) );
            add_action( 'edited_' . $tax, array( __CLASS__, 'term_save' ) );
        }
        foreach ( (array) NTSEOAI_Settings::get( 'post_types' ) as $pt ) {
            add_filter( 'manage_' . $pt . '_posts_columns', array( __CLASS__, 'col' ) );
            add_filter( 'manage_edit-' . $pt . '_sortable_columns', array( __CLASS__, 'sortable' ) );
            add_action( 'manage_' . $pt . '_posts_custom_column', array( __CLASS__, 'col_cell' ), 10, 2 );
        }
        add_action( 'pre_get_posts', array( __CLASS__, 'sort_query' ) );
        add_action( 'restrict_manage_posts', array( __CLASS__, 'filter_dropdown' ) );
        add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
    }

    public static function col( $c ) { $c['ntseoai'] = 'SEO / AI'; return $c; }
    public static function col_cell( $col, $id ) {
        if ( 'ntseoai' !== $col ) { return; }
        $s = get_post_meta( $id, '_ntseoai_score', true );
        if ( '' === $s ) {
            $r = NTSEOAI_Analyzer::analyze_post( $id ); // lazy: score the row the first time it is shown
            if ( ! $r ) { echo '&ndash;'; return; }
            $s = $r['score'];
        }
        $g = (int) get_post_meta( $id, '_ntseoai_geo_score', true );
        $kw = get_post_meta( $id, '_ntseoai_focuskw', true );
        echo '<a href="' . esc_url( get_edit_post_link( $id ) ) . '#ntseoai" class="ntseoai-cell" title="' . esc_attr( 'SEO ' . (int) $s . ' / AI search ' . (int) $g . ( $kw ? ' | keyword: ' . $kw : ' | no focus keyword' ) ) . '"><span class="ntseoai-pill ' . esc_attr( NTSEOAI_Analyzer::status_of( (int) $s ) ) . '">' . (int) $s . '</span> <span class="ntseoai-pill ai ' . esc_attr( NTSEOAI_Analyzer::status_of( $g ) ) . '">AI ' . (int) $g . '</span></a>';
    }
    public static function sortable( $c ) { $c['ntseoai'] = 'ntseoai_score'; return $c; }
    public static function sort_query( $q ) {
        if ( ! is_admin() || ! $q->is_main_query() ) { return; }
        if ( 'ntseoai_score' === $q->get( 'orderby' ) ) { $q->set( 'meta_key', '_ntseoai_score' ); $q->set( 'orderby', 'meta_value_num' ); }
        $f = isset( $_GET['ntseoai_f'] ) ? sanitize_key( $_GET['ntseoai_f'] ) : ''; // phpcs:ignore
        if ( $f ) {
            if ( 'none' === $f ) { $q->set( 'meta_query', array( array( 'key' => '_ntseoai_focuskw', 'compare' => 'NOT EXISTS' ) ) ); }
            else { $r = array( 'good' => array( 80, 100 ), 'ok' => array( 50, 79 ), 'bad' => array( 0, 49 ) ); if ( isset( $r[ $f ] ) ) { $q->set( 'meta_query', array( array( 'key' => '_ntseoai_score', 'value' => $r[ $f ], 'type' => 'NUMERIC', 'compare' => 'BETWEEN' ) ) ); } }
        }
    }
    public static function filter_dropdown( $pt ) {
        if ( ! in_array( $pt, (array) NTSEOAI_Settings::get( 'post_types' ), true ) ) { return; }
        $cur = isset( $_GET['ntseoai_f'] ) ? sanitize_key( $_GET['ntseoai_f'] ) : ''; // phpcs:ignore
        echo '<select name="ntseoai_f"><option value="">All SEO scores</option>';
        foreach ( array( 'good' => 'Good (80+)', 'ok' => 'Needs work (50-79)', 'bad' => 'Poor (under 50)', 'none' => 'No focus keyword' ) as $k => $l ) { echo '<option value="' . esc_attr( $k ) . '"' . selected( $cur, $k, false ) . '>' . esc_html( $l ) . '</option>'; }
        echo '</select>';
    }

    public static function sidebar() {
        $s = get_current_screen();
        if ( ! $s || ! in_array( $s->post_type, (array) NTSEOAI_Settings::get( 'post_types' ), true ) ) { return; }
        wp_enqueue_script( 'ntseoai-sidebar', NTSEOAI_URL . 'assets/sidebar.js', array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'jquery' ), NTSEOAI_VERSION, true );
    }
    public static function admin_bar( $bar ) {
        if ( ! current_user_can( 'edit_posts' ) || is_admin() || ! is_singular() ) { return; }
        $id = get_queried_object_id();
        if ( ! $id || ! in_array( get_post_type( $id ), (array) NTSEOAI_Settings::get( 'post_types' ), true ) ) { return; }
        $r = NTSEOAI_Analyzer::analyze_post( $id );
        if ( ! $r ) { return; }
        $dot = function ( $v ) { return $v >= 80 ? '#16a34a' : ( $v >= 50 ? '#d97706' : '#dc2626' ); };
        $bar->add_node( array( 'id' => 'ntseoai-score', 'title' => '<span style="color:' . $dot( $r['score'] ) . ';font-size:16px;line-height:1">&#9679;</span> SEO ' . (int) $r['score'] . ' &nbsp;<span style="color:' . $dot( $r['geo_score'] ) . ';font-size:16px;line-height:1">&#9679;</span> AI ' . (int) $r['geo_score'], 'href' => get_edit_post_link( $id ) . '#ntseoai', 'meta' => array( 'title' => 'NodeThemes SEO AI: click to improve this page' ) ) );
    }

    public static function add() {
        foreach ( (array) NTSEOAI_Settings::get( 'post_types' ) as $pt ) {
            if ( post_type_exists( $pt ) ) { add_meta_box( 'ntseoai_box', 'NodeThemes SEO AI - SEO & AI Search', array( __CLASS__, 'render' ), $pt, 'normal', 'high' ); }
        }
    }
    public static function assets( $hook ) {
        $screen = get_current_screen();
        if ( ! $screen ) { return; }
        $is_edit = in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && in_array( $screen->post_type, (array) NTSEOAI_Settings::get( 'post_types' ), true );
        if ( ! $is_edit && false === strpos( $hook, 'ntseoai' ) && 'toplevel_page_ntseoai' !== $hook && 'edit.php' !== $hook ) { return; }
        wp_enqueue_style( 'ntseoai-admin', NTSEOAI_URL . 'assets/admin.css', array(), NTSEOAI_VERSION );
        wp_enqueue_script( 'ntseoai-common', NTSEOAI_URL . 'assets/common.js', array( 'jquery' ), NTSEOAI_VERSION, true );
        wp_localize_script( 'ntseoai-common', 'NTSEOAI', array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'ntseoai_admin' ), 'configured' => NTSEOAI_AI::configured(), 'pro' => NTSEOAI_Pro::active(), 'upgrade' => NTSEOAI_Pro::url( 'js' ), 'settings' => admin_url( 'admin.php?page=ntseoai-settings' ), 'site' => get_bloginfo( 'name' ), 'host' => preg_replace( '#^https?://#', '', untrailingslashit( home_url() ) ) ) );
        if ( $is_edit ) {
            wp_enqueue_script( 'ntseoai-metabox', NTSEOAI_URL . 'assets/metabox.js', array( 'jquery', 'ntseoai-common' ), NTSEOAI_VERSION, true );
            wp_enqueue_media();
        }
    }

    // ------------------------------------------------------------ render
    public static function render( $post ) {
        wp_nonce_field( 'ntseoai_save_' . $post->ID, 'ntseoai_nonce' );
        $m = NTSEOAI_Analyzer::meta( $post->ID );
        $is_product = 'product' === $post->post_type;
        $faq = array_values( array_filter( (array) $m['faq'], 'is_array' ) );
        $tk = array_values( array_filter( (array) $m['takeaways'] ) );
        ?>
        <div class="ntseoai-mb" data-post="<?php echo (int) $post->ID; ?>" data-type="<?php echo esc_attr( $post->post_type ); ?>">
            <?php if ( ! NTSEOAI_AI::configured() ) : ?><div class="ntseoai-flash warn">AI is not set up yet. <a href="<?php echo esc_url( admin_url( 'admin.php?page=ntseoai-settings' ) ); ?>">Add your OpenAI / Claude / Gemini key</a> to unlock the AI buttons. Manual fields below work without it.</div><?php endif; ?>
            <div class="ntseoai-mb-top">
                <div class="ntseoai-scores"><div class="ntseoai-ring" id="ntseoai-seo-ring" data-l="SEO"><b>-</b></div><div class="ntseoai-ring" id="ntseoai-geo-ring" data-l="AI search"><b>-</b></div></div>
                <div class="ntseoai-auto"><div class="ntseoai-inline"><button type="button" class="ntseoai-btn primary" id="ntseoai-autopilot">&#10024; Optimize this page with AI</button><?php echo wp_kses_post( NTSEOAI_Pro::active() ? '<button type="button" class="ntseoai-btn primary" id="ntseoai-fix">&#9889; Fix everything with AI (aim for 100)</button>' : NTSEOAI_Pro::lock( 'Fix everything with AI (aim for 100)', 'fix100' ) ); ?></div><span class="ntseoai-hint">First button: keyword, title, description, FAQ and takeaways. Second: also edits the text, headings, image alts and adds internal links until the checks pass. You review before saving.</span></div>
            </div>
            <div class="ntseoai-tabs"><button type="button" class="ntseoai-tab on" data-t="seo">SEO</button><button type="button" class="ntseoai-tab" data-t="ai">AI search</button><button type="button" class="ntseoai-tab" data-t="write">AI writer</button><button type="button" class="ntseoai-tab" data-t="links">Links</button><button type="button" class="ntseoai-tab" data-t="social">Social</button><button type="button" class="ntseoai-tab" data-t="adv">Advanced</button></div>

            <div class="ntseoai-pane on" data-p="seo">
                <label class="ntseoai-l">Focus keyword <span class="ntseoai-hint">the search phrase this page should rank for</span></label>
                <div class="ntseoai-inline"><input type="text" id="ntseoai_focuskw" name="ntseoai_focuskw" value="<?php echo esc_attr( $m['kw'] ); ?>" placeholder="e.g. woocommerce image optimizer plugin"><button type="button" class="ntseoai-btn" id="ntseoai-find-kw">&#128269; Find keywords</button></div>
                <div id="ntseoai-kw-out" class="ntseoai-out"></div>
                <div class="ntseoai-serp" id="ntseoai-serp"><div class="ntseoai-serp-tabs"><button type="button" data-m="desktop" class="on">Desktop</button><button type="button" data-m="mobile">Mobile</button></div><div class="ntseoai-serp-card"><div class="ntseoai-serp-site"><span class="ntseoai-fav"></span><span><b id="ntseoai-serp-name"></b><i id="ntseoai-serp-url"></i></span></div><div class="ntseoai-serp-title" id="ntseoai-serp-title"></div><div class="ntseoai-serp-desc" id="ntseoai-serp-desc"></div></div></div>
                <label class="ntseoai-l">SEO title <span class="ntseoai-count" id="ntseoai-tc"></span></label>
                <input type="text" id="ntseoai_title" name="ntseoai_title" value="<?php echo esc_attr( $m['title'] ); ?>" placeholder="Leave empty to use the template from Settings">
                <div class="ntseoai-bar"><i id="ntseoai-tbar"></i></div>
                <label class="ntseoai-l">Meta description <span class="ntseoai-count" id="ntseoai-dc"></span></label>
                <textarea id="ntseoai_desc" name="ntseoai_desc" rows="3" placeholder="120-155 characters. Say what the page offers and why to click."><?php echo esc_textarea( $m['desc'] ); ?></textarea>
                <div class="ntseoai-bar"><i id="ntseoai-dbar"></i></div>
                <div class="ntseoai-inline"><button type="button" class="ntseoai-btn" id="ntseoai-gen-meta">&#10024; Suggest title &amp; description (3 variants)</button></div>
                <div id="ntseoai-meta-out" class="ntseoai-out"></div>
                <h4 class="ntseoai-h">Analysis</h4>
                <div id="ntseoai-checks" class="ntseoai-checks"><p class="ntseoai-hint">Analysing...</p></div>
                <div class="ntseoai-inline"><button type="button" class="ntseoai-btn" id="ntseoai-coach">&#129504; Ask AI for an action plan</button></div>
                <div id="ntseoai-coach-out" class="ntseoai-out"></div>
            </div>

            <div class="ntseoai-pane" data-p="ai">
                <p class="ntseoai-hint">AI engines (ChatGPT, Gemini, Claude, Perplexity) quote pages that answer questions directly, are well structured and state clear facts. These blocks feed your FAQ schema and can optionally be shown on the page (Settings &gt; Modules).</p>
                <div id="ntseoai-geo-checks" class="ntseoai-checks"></div>
                <h4 class="ntseoai-h">Key takeaways</h4>
                <div id="ntseoai-tk-list"><?php foreach ( $tk as $t ) : ?><div class="ntseoai-row2"><input type="text" name="ntseoai_tk[]" value="<?php echo esc_attr( $t ); ?>"><button type="button" class="ntseoai-x">&times;</button></div><?php endforeach; ?></div>
                <button type="button" class="ntseoai-btn sm" id="ntseoai-add-tk">+ Add takeaway</button>
                <h4 class="ntseoai-h">FAQ <span class="ntseoai-hint">real questions with short, factual answers</span></h4>
                <div id="ntseoai-faq-list"><?php foreach ( $faq as $i => $f ) : ?><div class="ntseoai-faq"><input type="text" name="ntseoai_faq_q[]" value="<?php echo esc_attr( $f['q'] ?? '' ); ?>" placeholder="Question"><textarea name="ntseoai_faq_a[]" rows="2" placeholder="Answer"><?php echo esc_textarea( $f['a'] ?? '' ); ?></textarea><button type="button" class="ntseoai-x">&times;</button></div><?php endforeach; ?></div>
                <div class="ntseoai-inline"><button type="button" class="ntseoai-btn sm" id="ntseoai-add-faq">+ Add question</button><button type="button" class="ntseoai-btn" id="ntseoai-gen-faq">&#10024; Generate FAQ &amp; takeaways from this page</button></div>
            </div>

            <div class="ntseoai-pane" data-p="write">
                <p class="ntseoai-hint">Write the full text for this page with AI and place it straight into the editor. It uses the title, focus keyword and your business description, and never invents prices or specs: missing facts appear as <code>[ADD: ...]</code> for you to fill. Always review before publishing.</p>
                <div class="ntseoai-grid2">
                    <div><label class="ntseoai-l">What to write</label><select id="ntseoai-w-kind"><option value="<?php echo $is_product ? 'product' : 'article'; ?>"><?php echo $is_product ? 'Product description' : 'Article'; ?></option><?php if ( ! $is_product ) : ?><option value="landing">Landing / service page</option><?php endif; ?><option value="howto">How-to guide</option><option value="listicle">Listicle / comparison</option></select></div>
                    <div><label class="ntseoai-l">Length</label><select id="ntseoai-w-len"><option value="short">Short (~300 words)</option><option value="medium" selected>Medium (~700 words)</option><?php foreach ( array( 'long' => 1500, 'extra' => 3000, 'epic' => 5000 ) as $k => $w ) { echo '<option data-long="' . (int) $w . '" value="' . esc_attr( $k ) . '">' . esc_html( 'Long (~' . number_format_i18n( $w ) . ' words)' ) . '</option>'; } ?></select></div>
                </div>
                <label class="ntseoai-l">Facts &amp; notes for the AI <span class="ntseoai-hint">features, specs, price, audience, tone. Only what you write here will be stated as fact.</span></label>
                <textarea id="ntseoai-w-notes" rows="4" placeholder="e.g. Plugin compresses JPG/PNG/WebP, bulk mode, works with WooCommerce, $30 one-time, 1 year updates"></textarea>
                <label class="ntseoai-chk"><input type="checkbox" id="ntseoai-w-all" checked> After inserting, also fill the page title, SEO title, description, FAQ and takeaways automatically</label>
                <div class="ntseoai-inline"><button type="button" class="ntseoai-btn primary" id="ntseoai-w-go">&#9997; Write it</button><button type="button" class="ntseoai-btn" id="ntseoai-w-short">Write short description (WooCommerce excerpt)</button></div>
                <div id="ntseoai-w-out" class="ntseoai-out"></div>
                <div class="ntseoai-inline" id="ntseoai-w-actions" style="display:none"><button type="button" class="ntseoai-btn primary" id="ntseoai-w-replace">Replace editor content</button><button type="button" class="ntseoai-btn" id="ntseoai-w-append">Add to end</button></div>
                <?php
                if ( has_action( 'ntseoai_writer_editor_box' ) ) {
                    do_action( 'ntseoai_writer_editor_box', $post );
                } else {
                    echo wp_kses_post( '<div class="ntseoai-aw-box"><h3>Auto Writer ' . NTSEOAI_Pro::badge() . '</h3><p class="ntseoai-hint">Writes the whole page from one topic (or a product from its own data), then checks and repairs it until the SEO and AI-search checks pass. Part of NodeThemes SEO AI Pro.</p><p>' . NTSEOAI_Pro::lock( 'Write the whole page', 'aw_editor' ) . '</p></div>' );
                }
                ?>
            </div>

            <div class="ntseoai-pane" data-p="links">
                <p class="ntseoai-hint">Pages on your site that mention this topic but do not link here yet. One click adds the link inside the existing sentence (Pro). The Link Map shows which pages have no inbound links.</p>
                <div class="ntseoai-inline"><?php if ( NTSEOAI_Pro::active() ) : ?><button type="button" class="ntseoai-btn" id="ntseoai-links-in">&#128279; Who should link to this page?</button><button type="button" class="ntseoai-btn" id="ntseoai-links-out">Where should this page link to?</button><?php else : echo wp_kses_post( NTSEOAI_Pro::lock( 'Link suggestions for this page', 'linksuggest' ) ); endif; ?><a class="ntseoai-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=ntseoai-linkmap&focus=' . (int) $post->ID ) ); ?>" target="_blank">Open the Link Map</a><?php echo wp_kses_post( NTSEOAI_Pro::active() ? '<button type="button" class="ntseoai-btn primary" id="ntseoai-links-plan">&#9889; Smart one-click linking to this page</button>' : NTSEOAI_Pro::lock( 'Smart one-click linking', 'smartlinks' ) ); ?></div>
                <div id="ntseoai-links-out-box" class="ntseoai-out"></div>
            </div>

            <div class="ntseoai-pane" data-p="social">
                <label class="ntseoai-l">Social title</label><input type="text" name="ntseoai_og_title" value="<?php echo esc_attr( $m['og_title'] ); ?>" placeholder="Defaults to the SEO title">
                <label class="ntseoai-l">Social description</label><textarea name="ntseoai_og_desc" rows="2" placeholder="Defaults to the meta description"><?php echo esc_textarea( $m['og_desc'] ); ?></textarea>
                <label class="ntseoai-l">Social image URL</label><div class="ntseoai-inline"><input type="text" id="ntseoai_og_img" name="ntseoai_og_img" value="<?php echo esc_attr( $m['og_img'] ); ?>" placeholder="Defaults to the featured image"><button type="button" class="ntseoai-btn" id="ntseoai-pick-img">Choose</button></div>
            </div>

            <div class="ntseoai-pane" data-p="adv">
                <div class="ntseoai-grid2">
                    <div><label class="ntseoai-l">Search engines</label><select name="ntseoai_noindex"><option value="">Allow indexing (recommended)</option><option value="1"<?php selected( $m['noindex'], '1' ); ?>>noindex - hide from Google</option></select></div>
                    <div><label class="ntseoai-l">Links on this page</label><select name="ntseoai_nofollow"><option value="">follow (default)</option><option value="1"<?php selected( $m['nofollow'], '1' ); ?>>nofollow</option></select></div>
                </div>
                <label class="ntseoai-l">Canonical URL <span class="ntseoai-hint">only if this page duplicates another</span></label><input type="url" name="ntseoai_canonical" value="<?php echo esc_attr( $m['canonical'] ); ?>">
                <div class="ntseoai-grid2">
                    <div><label class="ntseoai-l">Schema type</label><select name="ntseoai_schema_type"><?php foreach ( array( '' => 'Automatic', 'Article' => 'Article', 'BlogPosting' => 'Blog post', 'NewsArticle' => 'News article', 'Product' => 'Product', 'SoftwareApplication' => 'Software', 'none' => 'None (page only)' ) as $k => $l ) { echo '<option value="' . esc_attr( $k ) . '"' . selected( $m['schema_type'], $k, false ) . '>' . esc_html( $l ) . '</option>'; } ?></select></div>
                    <div><label class="ntseoai-l">Cornerstone content</label><select name="ntseoai_cornerstone"><option value="">No</option><option value="1"<?php selected( $m['cornerstone'], '1' ); ?>>Yes - one of my most important pages</option></select></div>
                </div>
            </div>
        </div>
        <?php
    }

    // ------------------------------------------------------------ save
    public static function save( $id, $post ) {
        if ( ! isset( $_POST['ntseoai_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ntseoai_nonce'] ) ), 'ntseoai_save_' . $id ) ) { return; }
        if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $id ) || wp_is_post_revision( $id ) ) { return; }
        $P = wp_unslash( $_POST ); // phpcs:ignore
        $t = function ( $k ) use ( $P ) { return isset( $P[ $k ] ) ? sanitize_text_field( $P[ $k ] ) : ''; };
        $set = function ( $k, $v ) use ( $id ) { if ( '' === $v ) { delete_post_meta( $id, '_ntseoai_' . $k ); } else { update_post_meta( $id, '_ntseoai_' . $k, $v ); } };
        $set( 'focuskw', $t( 'ntseoai_focuskw' ) );
        $set( 'title', $t( 'ntseoai_title' ) );
        $set( 'desc', isset( $P['ntseoai_desc'] ) ? sanitize_textarea_field( $P['ntseoai_desc'] ) : '' );
        $set( 'og_title', $t( 'ntseoai_og_title' ) );
        $set( 'og_desc', isset( $P['ntseoai_og_desc'] ) ? sanitize_textarea_field( $P['ntseoai_og_desc'] ) : '' );
        $set( 'og_img', isset( $P['ntseoai_og_img'] ) ? esc_url_raw( $P['ntseoai_og_img'] ) : '' );
        $set( 'canonical', isset( $P['ntseoai_canonical'] ) ? esc_url_raw( $P['ntseoai_canonical'] ) : '' );
        $set( 'noindex', ! empty( $P['ntseoai_noindex'] ) ? '1' : '' );
        $set( 'nofollow', ! empty( $P['ntseoai_nofollow'] ) ? '1' : '' );
        $set( 'cornerstone', ! empty( $P['ntseoai_cornerstone'] ) ? '1' : '' );
        $st = $t( 'ntseoai_schema_type' );
        $set( 'schema_type', in_array( $st, array( 'Article', 'BlogPosting', 'NewsArticle', 'Product', 'SoftwareApplication', 'none' ), true ) ? $st : '' );
        $tk = array();
        foreach ( (array) ( $P['ntseoai_tk'] ?? array() ) as $x ) { $x = sanitize_text_field( $x ); if ( '' !== $x ) { $tk[] = $x; } }
        $tk ? update_post_meta( $id, '_ntseoai_takeaways', array_slice( $tk, 0, 10 ) ) : delete_post_meta( $id, '_ntseoai_takeaways' );
        $faq = array(); $qs = (array) ( $P['ntseoai_faq_q'] ?? array() ); $as = (array) ( $P['ntseoai_faq_a'] ?? array() );
        foreach ( $qs as $i => $q ) { $q = sanitize_text_field( $q ); $a = isset( $as[ $i ] ) ? wp_kses( $as[ $i ], array( 'a' => array( 'href' => array() ), 'strong' => array(), 'em' => array(), 'br' => array() ) ) : ''; if ( '' !== $q && '' !== trim( $a ) ) { $faq[] = array( 'q' => $q, 'a' => trim( $a ) ); } }
        $faq ? update_post_meta( $id, '_ntseoai_faq', array_slice( $faq, 0, 12 ) ) : delete_post_meta( $id, '_ntseoai_faq' );
        do_action( 'ntseoai_metabox_saved', $id, $P );
        NTSEOAI_Sitemap::bump(); NTSEOAI_Geo::bump();
        NTSEOAI_Analyzer::analyze_post( $id );
    }

    // ------------------------------------------------------------ terms
    public static function term_fields( $term ) {
        ?>
        <tr class="form-field"><th>NodeThemes SEO SEO title</th><td><input type="text" name="ntseoai_term_title" value="<?php echo esc_attr( get_term_meta( $term->term_id, 'ntseoai_title', true ) ); ?>"><?php wp_nonce_field( 'ntseoai_term_' . $term->term_id, 'ntseoai_term_nonce' ); ?></td></tr>
        <tr class="form-field"><th>NodeThemes SEO meta description</th><td><textarea name="ntseoai_term_desc" rows="3"><?php echo esc_textarea( get_term_meta( $term->term_id, 'ntseoai_desc', true ) ); ?></textarea></td></tr>
        <tr class="form-field"><th>Search engines</th><td><label><input type="checkbox" name="ntseoai_term_noindex" value="1"<?php checked( get_term_meta( $term->term_id, 'ntseoai_noindex', true ), '1' ); ?>> noindex this archive</label></td></tr>
        <?php
    }
    public static function term_save( $term_id ) {
        if ( ! isset( $_POST['ntseoai_term_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ntseoai_term_nonce'] ) ), 'ntseoai_term_' . $term_id ) || ! current_user_can( 'manage_categories' ) ) { return; }
        update_term_meta( $term_id, 'ntseoai_title', isset( $_POST['ntseoai_term_title'] ) ? sanitize_text_field( wp_unslash( $_POST['ntseoai_term_title'] ) ) : '' );
        update_term_meta( $term_id, 'ntseoai_desc', isset( $_POST['ntseoai_term_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ntseoai_term_desc'] ) ) : '' );
        update_term_meta( $term_id, 'ntseoai_noindex', ! empty( $_POST['ntseoai_term_noindex'] ) ? '1' : '' );
        NTSEOAI_Sitemap::bump();
    }

    // ------------------------------------------------------------ AJAX helpers
    private static function guard() {
        NTSEOAI_Util::nonce();
        if ( ! current_user_can( 'edit_posts' ) ) { NTSEOAI_Util::fail( 'Not allowed.' ); }
    }
    private static function req() {
        if ( ! NTSEOAI_Job::$running ) { check_ajax_referer( 'ntseoai_admin', 'nonce' ); }
        $pid = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        if ( $pid && ! current_user_can( 'edit_post', $pid ) ) { NTSEOAI_Util::fail( 'Not allowed.' ); }
        $g = function ( $k, $html = false ) { if ( ! isset( $_POST[ $k ] ) ) { return ''; } $v = wp_unslash( $_POST[ $k ] ); return $html ? wp_kses_post( $v ) : sanitize_text_field( $v ); }; // phpcs:ignore
        $faq = array();
        $faq_in = isset( $_POST['faq'] ) && is_array( $_POST['faq'] ) ? map_deep( wp_unslash( $_POST['faq'] ), 'sanitize_text_field' ) : array();
        foreach ( $faq_in as $f ) { if ( is_array( $f ) ) { $faq[] = array( 'q' => (string) ( $f['q'] ?? '' ), 'a' => (string) ( $f['a'] ?? '' ) ); } }
        return array(
            'post_id' => $pid, 'title' => $g( 'title' ), 'content' => $g( 'content', true ), 'slug' => $g( 'slug' ), 'kw' => $g( 'kw' ),
            'seo_title' => $g( 'seo_title' ), 'desc' => isset( $_POST['desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['desc'] ) ) : '', 'type' => $g( 'type' ) ? $g( 'type' ) : 'post', 'faq' => $faq,
            'takeaways' => isset( $_POST['takeaways'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['takeaways'] ) ) : array(),
        );
    }
    private static function page_block( $r, $max = 9000 ) {
        $txt = NTSEOAI_AI::clip( NTSEOAI_Util::text( $r['content'] ), $max );
        return "Page type: {$r['type']}\nTitle: {$r['title']}\nCurrent focus keyword: " . ( $r['kw'] ? $r['kw'] : '(none)' ) . "\nURL slug: {$r['slug']}\n<page_content>\n" . ( $txt ? $txt : '(no body text yet - work from the title only and do not invent details)' ) . "\n</page_content>\n";
    }
    private static function out( $r ) { if ( ! $r['ok'] ) { NTSEOAI_Util::fail( $r['error'] ); } NTSEOAI_Util::ok( $r['data'] ?? array( 'text' => $r['text'] ) ); }
    private static function clean_meta( $d ) {
        foreach ( array( 'seo_title', 'meta_description', 'title', 'description' ) as $k ) { if ( isset( $d[ $k ] ) ) { $d[ $k ] = trim( wp_strip_all_tags( (string) $d[ $k ] ), " \t\n\r\"'" ); } }
        return $d;
    }

    public static function ajax_analyze() {
        self::guard();
        $r = self::req();
        $res = NTSEOAI_Analyzer::analyze( array( 'post_id' => $r['post_id'], 'title' => $r['title'], 'content' => $r['content'], 'slug' => $r['slug'], 'kw' => $r['kw'], 'seo_title' => $r['seo_title'], 'desc' => $r['desc'], 'faq' => $r['faq'], 'takeaways' => $r['takeaways'] ) );
        if ( $r['post_id'] ) { update_post_meta( $r['post_id'], '_ntseoai_score', $res['score'] ); update_post_meta( $r['post_id'], '_ntseoai_geo_score', $res['geo_score'] ); }
        NTSEOAI_Util::ok( $res );
    }

    public static function keywords_prompt( $r ) {
        return array( NTSEOAI_AI::context(), self::page_block( $r ) . "Task: pick the best focus keyword for this page and related keywords.\nRules: the focus keyword is a realistic search phrase (2-5 words) that matches what the page really offers and the searcher's intent; prefer specific over generic; it must be natural in the page language. You have no search-volume data: do NOT state volumes or difficulty, rate 'competition_guess' only as low/medium/high from your general knowledge and say so.\nJSON: {\"focus_keyword\":\"\",\"intent\":\"informational|commercial|transactional|navigational\",\"why\":\"one sentence\",\"secondary\":[\"5 related phrases\"],\"long_tail\":[\"5 longer, question-like or specific phrases\"],\"questions\":[\"4 questions people ask\"],\"competition_guess\":\"low|medium|high\"}" );
    }
    public static function ajax_keywords() {
        self::guard(); $r = self::req();
        list( $s, $u ) = self::keywords_prompt( $r );
        self::out( NTSEOAI_AI::json( $s, $u, array( 'max_tokens' => 900 ) ) );
    }
    public static function ajax_meta() {
        self::guard(); $r = self::req();
        $u = self::page_block( $r ) . "Task: write 3 different SEO title + meta description pairs.\nRules: title max 58 characters, focus keyword inside (near the start when natural); description 130-155 characters, includes the keyword once, states a concrete benefit and ends with a soft call to action; each pair uses a different angle (benefit, curiosity/question, specifics). No clickbait, no ALL CAPS, no invented facts. Include the brand '" . get_bloginfo( 'name' ) . "' only if it fits the title length.\nJSON: {\"variants\":[{\"angle\":\"\",\"title\":\"\",\"description\":\"\"}]}";
        $x = NTSEOAI_AI::json( NTSEOAI_AI::context(), $u, array( 'max_tokens' => 800 ) );
        if ( $x['ok'] && ! empty( $x['data']['variants'] ) ) { foreach ( $x['data']['variants'] as &$v ) { $v = self::clean_meta( (array) $v ); } unset( $v ); }
        self::out( $x );
    }
    public static function ajax_short() {
        self::guard(); if ( ! NTSEOAI_Job::$running ) { check_ajax_referer( 'ntseoai_admin', 'nonce' ); } $r = self::req();
        $notes = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
        $u = self::page_block( $r ) . ( $notes ? "<page_content>\nOwner notes: $notes\n</page_content>\n" : '' ) . "Task: write the short description shown near the buy button (40-70 words) for this product. Lead with the main benefit, include the focus keyword once naturally, mention only facts present in the page or notes. Output plain HTML using only <p> and optionally one <ul> with 3-4 <li> highlights. If a key fact is missing, leave it out.\nJSON: {\"html\":\"\"}";
        $x = NTSEOAI_AI::json( NTSEOAI_AI::context(), $u, array( 'max_tokens' => 600 ) );
        if ( $x['ok'] ) { $x['data']['html'] = wp_kses_post( $x['data']['html'] ?? '' ); }
        self::out( $x );
    }
    public static function ajax_article() {
        self::guard(); if ( ! NTSEOAI_Job::$running ) { check_ajax_referer( 'ntseoai_admin', 'nonce' ); } $r = self::req();
        $kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : 'article';
        $len = isset( $_POST['len'] ) ? sanitize_key( wp_unslash( $_POST['len'] ) ) : 'medium';
        $notes = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
        $words = array( 'short' => 300, 'medium' => 700, 'long' => 1500, 'extra' => 3000, 'epic' => 5000 );
        $w = isset( $words[ $len ] ) ? $words[ $len ] : 700;
        if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( min( 330, 120 + (int) ( $w / 15 ) ) ); } // phpcs:ignore
        $struct = array(
            'product' => 'Product page copy: a benefit-led opening paragraph, <h2>Key features</h2> with a <ul>, <h2>Who it is for</h2>, <h2>How it works / what is included</h2>, and a short closing call to action. Use a table only for real specs given in the notes.',
            'article' => 'Article: an opening that answers the main question in 2 sentences, then logical <h2> sections (add <h3> when needed), at least one <ul> or <ol>, and a short conclusion.',
            'landing' => 'Landing page: a clear value proposition paragraph, <h2> sections for benefits, how it works, proof points (only those in the notes), and a final call to action.',
            'howto'   => 'How-to guide: a short intro with the result the reader will achieve, then <h2>Steps</h2> as an <ol> with a short explanation per step, tips, and common mistakes.',
            'listicle' => 'Listicle/comparison: a short intro, then one <h2> per item with pros/cons as <ul>, and a short verdict. Do not rank or review specific real third-party products unless named in the notes.',
        );
        $u = self::page_block( $r, 5000 ) . "<page_content>\nOwner notes (the ONLY source of product facts): " . ( $notes ? $notes : '(none)' ) . "\n</page_content>\nTask: write the main content for this page in clean HTML (only <p>,<h2>,<h3>,<ul>,<ol>,<li>,<strong>,<em>,<table>,<tr>,<th>,<td>; no <h1>, no inline styles, no markdown).\nStructure: " . ( $struct[ $kind ] ?? $struct['article'] ) . "\nLength: about $w words. Focus keyword: " . ( $r['kw'] ? $r['kw'] : 'choose a natural main phrase from the title' ) . " - use it in the first paragraph and in one subheading, then use natural variations; never stuff.\nWrite like an expert human: specific, plain language, short paragraphs, no filler ('in today's fast-paced world'), no empty superlatives. Any fact you do not have (price, version, number, guarantee, compatibility) must be written as [ADD: what is needed] instead of guessed.\nJSON: {\"title\":\"best page title (H1), max 70 chars\",\"html\":\"\",\"suggested_slug\":\"\",\"missing_facts\":[\"facts the owner should add\"]}";
        $x = NTSEOAI_AI::json( NTSEOAI_AI::context(), $u, array( 'max_tokens' => (int) ( $w * 2.2 ) + 600, 'timeout' => min( 300, 150 + (int) ( $w / 25 ) ), 'write' => true ) );
        if ( $x['ok'] ) { $x['data']['html'] = wp_kses_post( $x['data']['html'] ?? '' ); if ( '' === trim( $x['data']['html'] ) ) { NTSEOAI_Util::fail( 'The AI returned no text. Try again.' ); } }
        self::out( $x );
    }
    public static function ajax_faq() {
        self::guard(); $r = self::req();
        $u = self::page_block( $r ) . "Task: create FAQ items and key takeaways for this page.\nRules: questions are what real customers ask (natural wording, not keyword-stuffed). Every answer (25-60 words) must be fully supported by the page content above; if the page does not contain the answer, SKIP that question - do not guess. 4-6 FAQ items, 3-5 takeaways (each under 20 words, concrete).\nJSON: {\"faq\":[{\"q\":\"\",\"a\":\"\"}],\"takeaways\":[\"\"]}";
        self::out( NTSEOAI_AI::json( NTSEOAI_AI::context(), $u, array( 'max_tokens' => 1200 ) ) );
    }
    public static function ajax_coach() {
        self::guard(); $r = self::req();
        $res = NTSEOAI_Analyzer::analyze( array( 'post_id' => $r['post_id'], 'title' => $r['title'], 'content' => $r['content'], 'slug' => $r['slug'], 'kw' => $r['kw'], 'seo_title' => $r['seo_title'], 'desc' => $r['desc'], 'faq' => $r['faq'], 'takeaways' => $r['takeaways'] ) );
        $fails = '';
        foreach ( array_merge( $res['checks'], $res['geo_checks'] ) as $c ) { if ( 'good' !== $c['status'] ) { $fails .= '- [' . $c['status'] . '] ' . $c['label'] . ': ' . $c['msg'] . "\n"; } }
        $u = self::page_block( $r, 6000 ) . "Automated checks that are not passing:\n$fails\nTask: act as a senior SEO. Give a prioritised action plan of at most 6 steps for THIS page: concrete edits (quote or point at the exact paragraph/heading when possible), highest impact first. Include at least one idea that makes AI assistants more likely to quote the page. Do not suggest buying links or keyword stuffing.\nJSON: {\"verdict\":\"one sentence\",\"steps\":[{\"impact\":\"high|medium|low\",\"action\":\"\",\"why\":\"\"}]}";
        self::out( NTSEOAI_AI::json( NTSEOAI_AI::context(), $u, array( 'max_tokens' => 1200 ) ) );
    }

    /** one call that returns everything needed for a page */
    public static function autopilot_data( $r ) {
        $u = self::page_block( $r ) . "Task: complete SEO setup for this page.\nRules:\n- focus_keyword: " . ( $r['kw'] ? 'keep the current one unless it is clearly wrong' : 'choose the best realistic search phrase (2-5 words)' ) . ".\n- seo_title: max 58 characters, keyword near the start, no clickbait.\n- meta_description: 130-155 characters, keyword once, concrete benefit, soft call to action.\n- og_title / og_description: social-friendly versions (can be a little more engaging).\n- short_description: " . ( 'product' === $r['type'] ? '40-70 words for the product summary near the buy button, HTML <p> only, facts only from the page' : 'empty string' ) . ".\n- takeaways: 3-5 concrete bullets (under 20 words each) supported by the page.\n- faq: 4-6 items, each answer 25-60 words fully supported by the page content; skip questions the page cannot answer.\n- schema_type: one of Article, BlogPosting, Product, SoftwareApplication, none, or empty for automatic.\n- Never invent facts. If the page has no text, base title/description only on the title and business description and keep claims generic.\nJSON: {\"focus_keyword\":\"\",\"secondary\":[\"\"],\"intent\":\"\",\"seo_title\":\"\",\"meta_description\":\"\",\"og_title\":\"\",\"og_description\":\"\",\"short_description\":\"\",\"takeaways\":[\"\"],\"faq\":[{\"q\":\"\",\"a\":\"\"}],\"schema_type\":\"\"}";
        $x = NTSEOAI_AI::json( NTSEOAI_AI::context(), $u, array( 'max_tokens' => 1800 ) );
        if ( $x['ok'] ) {
            $d = $x['data'];
            $d['seo_title'] = NTSEOAI_Util::cut( trim( wp_strip_all_tags( (string) ( $d['seo_title'] ?? '' ) ), " \"'" ), 70 );
            $d['meta_description'] = NTSEOAI_Util::cut( trim( wp_strip_all_tags( (string) ( $d['meta_description'] ?? '' ) ), " \"'" ), 170 );
            $d['focus_keyword'] = trim( wp_strip_all_tags( (string) ( $d['focus_keyword'] ?? '' ) ) );
            $d['og_title'] = trim( wp_strip_all_tags( (string) ( $d['og_title'] ?? '' ) ) );
            $d['og_description'] = trim( wp_strip_all_tags( (string) ( $d['og_description'] ?? '' ) ) );
            $d['short_description'] = wp_kses_post( (string) ( $d['short_description'] ?? '' ) );
            $d['takeaways'] = array_slice( array_values( array_filter( array_map( function ( $v ) { return trim( wp_strip_all_tags( (string) $v ) ); }, (array) ( $d['takeaways'] ?? array() ) ) ) ), 0, 6 );
            $faq = array();
            foreach ( (array) ( $d['faq'] ?? array() ) as $f ) { if ( is_array( $f ) && ! empty( $f['q'] ) && ! empty( $f['a'] ) ) { $faq[] = array( 'q' => trim( wp_strip_all_tags( $f['q'] ) ), 'a' => trim( wp_strip_all_tags( $f['a'] ) ) ); } }
            $d['faq'] = array_slice( $faq, 0, 8 );
            $d['schema_type'] = in_array( $d['schema_type'] ?? '', array( 'Article', 'BlogPosting', 'Product', 'SoftwareApplication', 'none' ), true ) ? $d['schema_type'] : '';
            $x['data'] = $d;
        }
        return $x;
    }
    public static function ajax_autopilot() {
        self::guard(); $r = self::req();
        self::out( self::autopilot_data( $r ) );
    }

    /** save autopilot data to a post (used by bulk) */
    public static function apply_data( $id, $d, $overwrite = false ) {
        $put = function ( $k, $v ) use ( $id, $overwrite ) {
            if ( '' === (string) $v ) { return; }
            if ( ! $overwrite && '' !== (string) get_post_meta( $id, '_ntseoai_' . $k, true ) ) { return; }
            update_post_meta( $id, '_ntseoai_' . $k, $v );
        };
        $put( 'focuskw', $d['focus_keyword'] ?? '' ); $put( 'title', $d['seo_title'] ?? '' ); $put( 'desc', $d['meta_description'] ?? '' );
        $put( 'og_title', $d['og_title'] ?? '' ); $put( 'og_desc', $d['og_description'] ?? '' ); $put( 'schema_type', $d['schema_type'] ?? '' );
        if ( ! empty( $d['takeaways'] ) && ( $overwrite || ! get_post_meta( $id, '_ntseoai_takeaways', true ) ) ) { update_post_meta( $id, '_ntseoai_takeaways', $d['takeaways'] ); }
        if ( ! empty( $d['faq'] ) && ( $overwrite || ! get_post_meta( $id, '_ntseoai_faq', true ) ) ) { update_post_meta( $id, '_ntseoai_faq', $d['faq'] ); }
        if ( ! empty( $d['short_description'] ) && 'product' === get_post_type( $id ) && ( $overwrite || '' === trim( get_post_field( 'post_excerpt', $id ) ) ) ) {
            wp_update_post( array( 'ID' => $id, 'post_excerpt' => $d['short_description'] ) );
        }
        NTSEOAI_Sitemap::bump(); NTSEOAI_Geo::bump();
        NTSEOAI_Analyzer::analyze_post( $id );
    }
}
