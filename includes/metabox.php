<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** editor meta box + all per-page AI actions */
final class NRA_Metabox {

    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'add' ) );
        add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
        add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'sidebar' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
        foreach ( array( 'analyze', 'keywords', 'meta', 'short', 'article', 'faq', 'coach', 'autopilot' ) as $a ) {
            add_action( 'wp_ajax_nra_ai_' . $a, array( __CLASS__, 'ajax_' . $a ) );
        }
        foreach ( array( 'category', 'post_tag', 'product_cat', 'product_tag' ) as $tax ) {
            add_action( $tax . '_edit_form_fields', array( __CLASS__, 'term_fields' ) );
            add_action( 'edited_' . $tax, array( __CLASS__, 'term_save' ) );
        }
        foreach ( (array) NRA_Settings::get( 'post_types' ) as $pt ) {
            add_filter( 'manage_' . $pt . '_posts_columns', array( __CLASS__, 'col' ) );
            add_filter( 'manage_edit-' . $pt . '_sortable_columns', array( __CLASS__, 'sortable' ) );
            add_action( 'manage_' . $pt . '_posts_custom_column', array( __CLASS__, 'col_cell' ), 10, 2 );
        }
        add_action( 'pre_get_posts', array( __CLASS__, 'sort_query' ) );
        add_action( 'restrict_manage_posts', array( __CLASS__, 'filter_dropdown' ) );
        add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
    }

    public static function col( $c ) { $c['nra'] = 'SEO / AI'; return $c; }
    public static function col_cell( $col, $id ) {
        if ( 'nra' !== $col ) { return; }
        $s = get_post_meta( $id, '_nra_score', true );
        if ( '' === $s ) {
            $r = NRA_Analyzer::analyze_post( $id ); // lazy: score the row the first time it is shown
            if ( ! $r ) { echo '&ndash;'; return; }
            $s = $r['score'];
        }
        $g = (int) get_post_meta( $id, '_nra_geo_score', true );
        $kw = get_post_meta( $id, '_nra_focuskw', true );
        echo '<a href="' . esc_url( get_edit_post_link( $id ) ) . '#nra" class="nra-cell" title="' . esc_attr( 'SEO ' . (int) $s . ' / AI search ' . $g . ( $kw ? ' | keyword: ' . $kw : ' | no focus keyword' ) ) . '"><span class="nra-pill ' . esc_attr( NRA_Analyzer::status_of( (int) $s ) ) . '">' . (int) $s . '</span> <span class="nra-pill ai ' . esc_attr( NRA_Analyzer::status_of( $g ) ) . '">AI ' . $g . '</span></a>';
    }
    public static function sortable( $c ) { $c['nra'] = 'nra_score'; return $c; }
    public static function sort_query( $q ) {
        if ( ! is_admin() || ! $q->is_main_query() ) { return; }
        if ( 'nra_score' === $q->get( 'orderby' ) ) { $q->set( 'meta_key', '_nra_score' ); $q->set( 'orderby', 'meta_value_num' ); }
        $f = isset( $_GET['nra_f'] ) ? sanitize_key( $_GET['nra_f'] ) : ''; // phpcs:ignore
        if ( $f ) {
            if ( 'none' === $f ) { $q->set( 'meta_query', array( array( 'key' => '_nra_focuskw', 'compare' => 'NOT EXISTS' ) ) ); }
            else { $r = array( 'good' => array( 80, 100 ), 'ok' => array( 50, 79 ), 'bad' => array( 0, 49 ) ); if ( isset( $r[ $f ] ) ) { $q->set( 'meta_query', array( array( 'key' => '_nra_score', 'value' => $r[ $f ], 'type' => 'NUMERIC', 'compare' => 'BETWEEN' ) ) ); } }
        }
    }
    public static function filter_dropdown( $pt ) {
        if ( ! in_array( $pt, (array) NRA_Settings::get( 'post_types' ), true ) ) { return; }
        $cur = isset( $_GET['nra_f'] ) ? sanitize_key( $_GET['nra_f'] ) : ''; // phpcs:ignore
        echo '<select name="nra_f"><option value="">All SEO scores</option>';
        foreach ( array( 'good' => 'Good (80+)', 'ok' => 'Needs work (50-79)', 'bad' => 'Poor (under 50)', 'none' => 'No focus keyword' ) as $k => $l ) { echo '<option value="' . esc_attr( $k ) . '"' . selected( $cur, $k, false ) . '>' . esc_html( $l ) . '</option>'; }
        echo '</select>';
    }

    public static function sidebar() {
        $s = get_current_screen();
        if ( ! $s || ! in_array( $s->post_type, (array) NRA_Settings::get( 'post_types' ), true ) ) { return; }
        wp_enqueue_script( 'nra-sidebar', NRA_URL . 'assets/sidebar.js', array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'jquery' ), NRA_VERSION, true );
    }
    public static function admin_bar( $bar ) {
        if ( ! current_user_can( 'edit_posts' ) || is_admin() || ! is_singular() ) { return; }
        $id = get_queried_object_id();
        if ( ! $id || ! in_array( get_post_type( $id ), (array) NRA_Settings::get( 'post_types' ), true ) ) { return; }
        $r = NRA_Analyzer::analyze_post( $id );
        if ( ! $r ) { return; }
        $dot = function ( $v ) { return $v >= 80 ? '#16a34a' : ( $v >= 50 ? '#d97706' : '#dc2626' ); };
        $bar->add_node( array( 'id' => 'nra-score', 'title' => '<span style="color:' . $dot( $r['score'] ) . ';font-size:16px;line-height:1">&#9679;</span> SEO ' . (int) $r['score'] . ' &nbsp;<span style="color:' . $dot( $r['geo_score'] ) . ';font-size:16px;line-height:1">&#9679;</span> AI ' . (int) $r['geo_score'], 'href' => get_edit_post_link( $id ) . '#nra', 'meta' => array( 'title' => 'NodeThemes SEO AI: click to improve this page' ) ) );
    }

    public static function add() {
        foreach ( (array) NRA_Settings::get( 'post_types' ) as $pt ) {
            if ( post_type_exists( $pt ) ) { add_meta_box( 'nra_box', 'NodeThemes SEO AI - SEO & AI Search', array( __CLASS__, 'render' ), $pt, 'normal', 'high' ); }
        }
    }
    public static function assets( $hook ) {
        $screen = get_current_screen();
        if ( ! $screen ) { return; }
        $is_edit = in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && in_array( $screen->post_type, (array) NRA_Settings::get( 'post_types' ), true );
        if ( ! $is_edit && false === strpos( $hook, 'nra' ) && 'toplevel_page_nra' !== $hook && 'edit.php' !== $hook ) { return; }
        wp_enqueue_style( 'nra-admin', NRA_URL . 'assets/admin.css', array(), NRA_VERSION );
        wp_enqueue_script( 'nra-common', NRA_URL . 'assets/common.js', array( 'jquery' ), NRA_VERSION, true );
        wp_localize_script( 'nra-common', 'NRA', array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'nra_admin' ), 'configured' => NRA_AI::configured(), 'pro' => NRA_Pro::active(), 'upgrade' => NRA_Pro::url( 'js' ), 'settings' => admin_url( 'admin.php?page=nra-settings' ), 'site' => get_bloginfo( 'name' ), 'host' => preg_replace( '#^https?://#', '', untrailingslashit( home_url() ) ) ) );
        if ( $is_edit ) {
            wp_enqueue_script( 'nra-metabox', NRA_URL . 'assets/metabox.js', array( 'jquery', 'nra-common' ), NRA_VERSION, true );
            wp_enqueue_media();
        }
    }

    // ------------------------------------------------------------ render
    public static function render( $post ) {
        wp_nonce_field( 'nra_save_' . $post->ID, 'nra_nonce' );
        $m = NRA_Analyzer::meta( $post->ID );
        $is_product = 'product' === $post->post_type;
        $faq = array_values( array_filter( (array) $m['faq'], 'is_array' ) );
        $tk = array_values( array_filter( (array) $m['takeaways'] ) );
        ?>
        <div class="nra-mb" data-post="<?php echo (int) $post->ID; ?>" data-type="<?php echo esc_attr( $post->post_type ); ?>">
            <?php if ( ! NRA_AI::configured() ) : ?><div class="nra-flash warn">AI is not set up yet. <a href="<?php echo esc_url( admin_url( 'admin.php?page=nra-settings' ) ); ?>">Add your OpenAI / Claude / Gemini key</a> to unlock the AI buttons. Manual fields below work without it.</div><?php endif; ?>
            <div class="nra-mb-top">
                <div class="nra-scores"><div class="nra-ring" id="nra-seo-ring" data-l="SEO"><b>-</b></div><div class="nra-ring" id="nra-geo-ring" data-l="AI search"><b>-</b></div></div>
                <div class="nra-auto"><div class="nra-inline"><button type="button" class="nra-btn primary" id="nra-autopilot">&#10024; Optimize this page with AI</button><?php echo NRA_Pro::active() ? '<button type="button" class="nra-btn primary" id="nra-fix">&#9889; Fix everything with AI (aim for 100)</button>' : NRA_Pro::lock( 'Fix everything with AI (aim for 100)', 'fix100' ); ?></div><span class="nra-hint">First button: keyword, title, description, FAQ and takeaways. Second: also edits the text, headings, image alts and adds internal links until the checks pass. You review before saving.</span></div>
            </div>
            <div class="nra-tabs"><button type="button" class="nra-tab on" data-t="seo">SEO</button><button type="button" class="nra-tab" data-t="ai">AI search</button><button type="button" class="nra-tab" data-t="write">AI writer</button><button type="button" class="nra-tab" data-t="links">Links</button><button type="button" class="nra-tab" data-t="social">Social</button><button type="button" class="nra-tab" data-t="adv">Advanced</button></div>

            <div class="nra-pane on" data-p="seo">
                <label class="nra-l">Focus keyword <span class="nra-hint">the search phrase this page should rank for</span></label>
                <div class="nra-inline"><input type="text" id="nra_focuskw" name="nra_focuskw" value="<?php echo esc_attr( $m['kw'] ); ?>" placeholder="e.g. woocommerce image optimizer plugin"><button type="button" class="nra-btn" id="nra-find-kw">&#128269; Find keywords</button></div>
                <div id="nra-kw-out" class="nra-out"></div>
                <div class="nra-serp" id="nra-serp"><div class="nra-serp-tabs"><button type="button" data-m="desktop" class="on">Desktop</button><button type="button" data-m="mobile">Mobile</button></div><div class="nra-serp-card"><div class="nra-serp-site"><span class="nra-fav"></span><span><b id="nra-serp-name"></b><i id="nra-serp-url"></i></span></div><div class="nra-serp-title" id="nra-serp-title"></div><div class="nra-serp-desc" id="nra-serp-desc"></div></div></div>
                <label class="nra-l">SEO title <span class="nra-count" id="nra-tc"></span></label>
                <input type="text" id="nra_title" name="nra_title" value="<?php echo esc_attr( $m['title'] ); ?>" placeholder="Leave empty to use the template from Settings">
                <div class="nra-bar"><i id="nra-tbar"></i></div>
                <label class="nra-l">Meta description <span class="nra-count" id="nra-dc"></span></label>
                <textarea id="nra_desc" name="nra_desc" rows="3" placeholder="120-155 characters. Say what the page offers and why to click."><?php echo esc_textarea( $m['desc'] ); ?></textarea>
                <div class="nra-bar"><i id="nra-dbar"></i></div>
                <div class="nra-inline"><button type="button" class="nra-btn" id="nra-gen-meta">&#10024; Suggest title &amp; description (3 variants)</button></div>
                <div id="nra-meta-out" class="nra-out"></div>
                <h4 class="nra-h">Analysis</h4>
                <div id="nra-checks" class="nra-checks"><p class="nra-hint">Analysing...</p></div>
                <div class="nra-inline"><button type="button" class="nra-btn" id="nra-coach">&#129504; Ask AI for an action plan</button></div>
                <div id="nra-coach-out" class="nra-out"></div>
            </div>

            <div class="nra-pane" data-p="ai">
                <p class="nra-hint">AI engines (ChatGPT, Gemini, Claude, Perplexity) quote pages that answer questions directly, are well structured and state clear facts. These blocks feed your FAQ schema and can optionally be shown on the page (Settings &gt; Modules).</p>
                <div id="nra-geo-checks" class="nra-checks"></div>
                <h4 class="nra-h">Key takeaways</h4>
                <div id="nra-tk-list"><?php foreach ( $tk as $t ) : ?><div class="nra-row2"><input type="text" name="nra_tk[]" value="<?php echo esc_attr( $t ); ?>"><button type="button" class="nra-x">&times;</button></div><?php endforeach; ?></div>
                <button type="button" class="nra-btn sm" id="nra-add-tk">+ Add takeaway</button>
                <h4 class="nra-h">FAQ <span class="nra-hint">real questions with short, factual answers</span></h4>
                <div id="nra-faq-list"><?php foreach ( $faq as $i => $f ) : ?><div class="nra-faq"><input type="text" name="nra_faq_q[]" value="<?php echo esc_attr( $f['q'] ?? '' ); ?>" placeholder="Question"><textarea name="nra_faq_a[]" rows="2" placeholder="Answer"><?php echo esc_textarea( $f['a'] ?? '' ); ?></textarea><button type="button" class="nra-x">&times;</button></div><?php endforeach; ?></div>
                <div class="nra-inline"><button type="button" class="nra-btn sm" id="nra-add-faq">+ Add question</button><button type="button" class="nra-btn" id="nra-gen-faq">&#10024; Generate FAQ &amp; takeaways from this page</button></div>
            </div>

            <div class="nra-pane" data-p="write">
                <p class="nra-hint">Write the full text for this page with AI and place it straight into the editor. It uses the title, focus keyword and your business description, and never invents prices or specs: missing facts appear as <code>[ADD: ...]</code> for you to fill. Always review before publishing.</p>
                <div class="nra-grid2">
                    <div><label class="nra-l">What to write</label><select id="nra-w-kind"><option value="<?php echo $is_product ? 'product' : 'article'; ?>"><?php echo $is_product ? 'Product description' : 'Article'; ?></option><?php if ( ! $is_product ) : ?><option value="landing">Landing / service page</option><?php endif; ?><option value="howto">How-to guide</option><option value="listicle">Listicle / comparison</option></select></div>
                    <div><label class="nra-l">Length</label><select id="nra-w-len"><option value="short">Short (~300 words)</option><option value="medium" selected>Medium (~700 words)</option><?php $pro = NRA_Pro::active(); foreach ( array( 'long' => 1500, 'extra' => 3000, 'epic' => 5000 ) as $k => $w ) { echo '<option data-long="' . (int) $w . '" value="' . esc_attr( $k ) . '"' . ( $pro ? '' : ' class="nra-optlock"' ) . '>' . ( $pro ? '' : '&#128274; ' ) . 'Long (~' . number_format_i18n( $w ) . ' words)' . ( $pro ? '' : ' - Pro' ) . '</option>'; } ?></select></div>
                </div>
                <label class="nra-l">Facts &amp; notes for the AI <span class="nra-hint">features, specs, price, audience, tone. Only what you write here will be stated as fact.</span></label>
                <textarea id="nra-w-notes" rows="4" placeholder="e.g. Plugin compresses JPG/PNG/WebP, bulk mode, works with WooCommerce, $30 one-time, 1 year updates"></textarea>
                <label class="nra-chk"><input type="checkbox" id="nra-w-all" checked> After inserting, also fill the page title, SEO title, description, FAQ and takeaways automatically</label>
                <div class="nra-inline"><button type="button" class="nra-btn primary" id="nra-w-go">&#9997; Write it</button><button type="button" class="nra-btn" id="nra-w-short">Write short description (WooCommerce excerpt)</button></div>
                <div id="nra-w-out" class="nra-out"></div>
                <div class="nra-inline" id="nra-w-actions" style="display:none"><button type="button" class="nra-btn primary" id="nra-w-replace">Replace editor content</button><button type="button" class="nra-btn" id="nra-w-append">Add to end</button></div>
            </div>

            <div class="nra-pane" data-p="links">
                <p class="nra-hint">Pages on your site that mention this topic but do not link here yet. One click adds the link inside the existing sentence.</p>
                <div class="nra-inline"><button type="button" class="nra-btn" id="nra-links-in">&#128279; Who should link to this page?</button><button type="button" class="nra-btn" id="nra-links-out">Where should this page link to?</button><a class="nra-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=nra-linkmap&focus=' . (int) $post->ID ) ); ?>" target="_blank">Open the Link Map</a><?php echo NRA_Pro::active() ? '<button type="button" class="nra-btn primary" id="nra-links-plan">&#9889; Smart one-click linking to this page</button>' : NRA_Pro::lock( 'Smart one-click linking', 'smartlinks' ); ?></div>
                <div id="nra-links-out-box" class="nra-out"></div>
            </div>

            <div class="nra-pane" data-p="social">
                <label class="nra-l">Social title</label><input type="text" name="nra_og_title" value="<?php echo esc_attr( $m['og_title'] ); ?>" placeholder="Defaults to the SEO title">
                <label class="nra-l">Social description</label><textarea name="nra_og_desc" rows="2" placeholder="Defaults to the meta description"><?php echo esc_textarea( $m['og_desc'] ); ?></textarea>
                <label class="nra-l">Social image URL</label><div class="nra-inline"><input type="text" id="nra_og_img" name="nra_og_img" value="<?php echo esc_attr( $m['og_img'] ); ?>" placeholder="Defaults to the featured image"><button type="button" class="nra-btn" id="nra-pick-img">Choose</button></div>
            </div>

            <div class="nra-pane" data-p="adv">
                <div class="nra-grid2">
                    <div><label class="nra-l">Search engines</label><select name="nra_noindex"><option value="">Allow indexing (recommended)</option><option value="1"<?php selected( $m['noindex'], '1' ); ?>>noindex - hide from Google</option></select></div>
                    <div><label class="nra-l">Links on this page</label><select name="nra_nofollow"><option value="">follow (default)</option><option value="1"<?php selected( $m['nofollow'], '1' ); ?>>nofollow</option></select></div>
                </div>
                <label class="nra-l">Canonical URL <span class="nra-hint">only if this page duplicates another</span></label><input type="url" name="nra_canonical" value="<?php echo esc_attr( $m['canonical'] ); ?>">
                <div class="nra-grid2">
                    <div><label class="nra-l">Schema type</label><select name="nra_schema_type"><?php foreach ( array( '' => 'Automatic', 'Article' => 'Article', 'BlogPosting' => 'Blog post', 'NewsArticle' => 'News article', 'Product' => 'Product', 'SoftwareApplication' => 'Software', 'none' => 'None (page only)' ) as $k => $l ) { echo '<option value="' . esc_attr( $k ) . '"' . selected( $m['schema_type'], $k, false ) . '>' . esc_html( $l ) . '</option>'; } ?></select></div>
                    <div><label class="nra-l">Cornerstone content</label><select name="nra_cornerstone"><option value="">No</option><option value="1"<?php selected( $m['cornerstone'], '1' ); ?>>Yes - one of my most important pages</option></select></div>
                </div>
            </div>
        </div>
        <?php
    }

    // ------------------------------------------------------------ save
    public static function save( $id, $post ) {
        if ( ! isset( $_POST['nra_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nra_nonce'] ) ), 'nra_save_' . $id ) ) { return; }
        if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $id ) || wp_is_post_revision( $id ) ) { return; }
        $P = wp_unslash( $_POST ); // phpcs:ignore
        $t = function ( $k ) use ( $P ) { return isset( $P[ $k ] ) ? sanitize_text_field( $P[ $k ] ) : ''; };
        $set = function ( $k, $v ) use ( $id ) { if ( '' === $v ) { delete_post_meta( $id, '_nra_' . $k ); } else { update_post_meta( $id, '_nra_' . $k, $v ); } };
        $set( 'focuskw', $t( 'nra_focuskw' ) );
        $set( 'title', $t( 'nra_title' ) );
        $set( 'desc', isset( $P['nra_desc'] ) ? sanitize_textarea_field( $P['nra_desc'] ) : '' );
        $set( 'og_title', $t( 'nra_og_title' ) );
        $set( 'og_desc', isset( $P['nra_og_desc'] ) ? sanitize_textarea_field( $P['nra_og_desc'] ) : '' );
        $set( 'og_img', isset( $P['nra_og_img'] ) ? esc_url_raw( $P['nra_og_img'] ) : '' );
        $set( 'canonical', isset( $P['nra_canonical'] ) ? esc_url_raw( $P['nra_canonical'] ) : '' );
        $set( 'noindex', ! empty( $P['nra_noindex'] ) ? '1' : '' );
        $set( 'nofollow', ! empty( $P['nra_nofollow'] ) ? '1' : '' );
        $set( 'cornerstone', ! empty( $P['nra_cornerstone'] ) ? '1' : '' );
        $st = $t( 'nra_schema_type' );
        $set( 'schema_type', in_array( $st, array( 'Article', 'BlogPosting', 'NewsArticle', 'Product', 'SoftwareApplication', 'none' ), true ) ? $st : '' );
        $tk = array();
        foreach ( (array) ( $P['nra_tk'] ?? array() ) as $x ) { $x = sanitize_text_field( $x ); if ( '' !== $x ) { $tk[] = $x; } }
        $tk ? update_post_meta( $id, '_nra_takeaways', array_slice( $tk, 0, 10 ) ) : delete_post_meta( $id, '_nra_takeaways' );
        $faq = array(); $qs = (array) ( $P['nra_faq_q'] ?? array() ); $as = (array) ( $P['nra_faq_a'] ?? array() );
        foreach ( $qs as $i => $q ) { $q = sanitize_text_field( $q ); $a = isset( $as[ $i ] ) ? wp_kses( $as[ $i ], array( 'a' => array( 'href' => array() ), 'strong' => array(), 'em' => array(), 'br' => array() ) ) : ''; if ( '' !== $q && '' !== trim( $a ) ) { $faq[] = array( 'q' => $q, 'a' => trim( $a ) ); } }
        $faq ? update_post_meta( $id, '_nra_faq', array_slice( $faq, 0, 12 ) ) : delete_post_meta( $id, '_nra_faq' );
        NRA_Sitemap::bump(); NRA_Geo::bump();
        NRA_Analyzer::analyze_post( $id );
    }

    // ------------------------------------------------------------ terms
    public static function term_fields( $term ) {
        ?>
        <tr class="form-field"><th>NodeThemes SEO SEO title</th><td><input type="text" name="nra_term_title" value="<?php echo esc_attr( get_term_meta( $term->term_id, 'nra_title', true ) ); ?>"><?php wp_nonce_field( 'nra_term_' . $term->term_id, 'nra_term_nonce' ); ?></td></tr>
        <tr class="form-field"><th>NodeThemes SEO meta description</th><td><textarea name="nra_term_desc" rows="3"><?php echo esc_textarea( get_term_meta( $term->term_id, 'nra_desc', true ) ); ?></textarea></td></tr>
        <tr class="form-field"><th>Search engines</th><td><label><input type="checkbox" name="nra_term_noindex" value="1"<?php checked( get_term_meta( $term->term_id, 'nra_noindex', true ), '1' ); ?>> noindex this archive</label></td></tr>
        <?php
    }
    public static function term_save( $term_id ) {
        if ( ! isset( $_POST['nra_term_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nra_term_nonce'] ) ), 'nra_term_' . $term_id ) || ! current_user_can( 'manage_categories' ) ) { return; }
        update_term_meta( $term_id, 'nra_title', isset( $_POST['nra_term_title'] ) ? sanitize_text_field( wp_unslash( $_POST['nra_term_title'] ) ) : '' );
        update_term_meta( $term_id, 'nra_desc', isset( $_POST['nra_term_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['nra_term_desc'] ) ) : '' );
        update_term_meta( $term_id, 'nra_noindex', ! empty( $_POST['nra_term_noindex'] ) ? '1' : '' );
        NRA_Sitemap::bump();
    }

    // ------------------------------------------------------------ AJAX helpers
    private static function guard() {
        NRA_Util::nonce();
        if ( ! current_user_can( 'edit_posts' ) ) { NRA_Util::fail( 'Not allowed.' ); }
    }
    private static function req() {
        $pid = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        if ( $pid && ! current_user_can( 'edit_post', $pid ) ) { NRA_Util::fail( 'Not allowed.' ); }
        $g = function ( $k, $html = false ) { if ( ! isset( $_POST[ $k ] ) ) { return ''; } $v = wp_unslash( $_POST[ $k ] ); return $html ? wp_kses_post( $v ) : sanitize_text_field( $v ); }; // phpcs:ignore
        $faq = array();
        foreach ( (array) ( $_POST['faq'] ?? array() ) as $f ) { if ( is_array( $f ) ) { $faq[] = array( 'q' => sanitize_text_field( wp_unslash( $f['q'] ?? '' ) ), 'a' => sanitize_text_field( wp_unslash( $f['a'] ?? '' ) ) ); } }
        return array(
            'post_id' => $pid, 'title' => $g( 'title' ), 'content' => $g( 'content', true ), 'slug' => $g( 'slug' ), 'kw' => $g( 'kw' ),
            'seo_title' => $g( 'seo_title' ), 'desc' => isset( $_POST['desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['desc'] ) ) : '', 'type' => $g( 'type' ) ? $g( 'type' ) : 'post', 'faq' => $faq,
            'takeaways' => array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) ( $_POST['takeaways'] ?? array() ) ) ),
        );
    }
    private static function page_block( $r, $max = 9000 ) {
        $txt = NRA_AI::clip( NRA_Util::text( $r['content'] ), $max );
        return "Page type: {$r['type']}\nTitle: {$r['title']}\nCurrent focus keyword: " . ( $r['kw'] ? $r['kw'] : '(none)' ) . "\nURL slug: {$r['slug']}\n<page_content>\n" . ( $txt ? $txt : '(no body text yet - work from the title only and do not invent details)' ) . "\n</page_content>\n";
    }
    private static function out( $r ) { if ( ! $r['ok'] ) { NRA_Util::fail( $r['error'] ); } NRA_Util::ok( $r['data'] ?? array( 'text' => $r['text'] ) ); }
    private static function clean_meta( $d ) {
        foreach ( array( 'seo_title', 'meta_description', 'title', 'description' ) as $k ) { if ( isset( $d[ $k ] ) ) { $d[ $k ] = trim( wp_strip_all_tags( (string) $d[ $k ] ), " \t\n\r\"'" ); } }
        return $d;
    }

    public static function ajax_analyze() {
        self::guard();
        $r = self::req();
        $res = NRA_Analyzer::analyze( array( 'post_id' => $r['post_id'], 'title' => $r['title'], 'content' => $r['content'], 'slug' => $r['slug'], 'kw' => $r['kw'], 'seo_title' => $r['seo_title'], 'desc' => $r['desc'], 'faq' => $r['faq'], 'takeaways' => $r['takeaways'] ) );
        if ( $r['post_id'] ) { update_post_meta( $r['post_id'], '_nra_score', $res['score'] ); update_post_meta( $r['post_id'], '_nra_geo_score', $res['geo_score'] ); }
        NRA_Util::ok( $res );
    }

    public static function keywords_prompt( $r ) {
        return array( NRA_AI::context(), self::page_block( $r ) . "Task: pick the best focus keyword for this page and related keywords.\nRules: the focus keyword is a realistic search phrase (2-5 words) that matches what the page really offers and the searcher's intent; prefer specific over generic; it must be natural in the page language. You have no search-volume data: do NOT state volumes or difficulty, rate 'competition_guess' only as low/medium/high from your general knowledge and say so.\nJSON: {\"focus_keyword\":\"\",\"intent\":\"informational|commercial|transactional|navigational\",\"why\":\"one sentence\",\"secondary\":[\"5 related phrases\"],\"long_tail\":[\"5 longer, question-like or specific phrases\"],\"questions\":[\"4 questions people ask\"],\"competition_guess\":\"low|medium|high\"}" );
    }
    public static function ajax_keywords() {
        self::guard(); $r = self::req();
        list( $s, $u ) = self::keywords_prompt( $r );
        self::out( NRA_AI::json( $s, $u, array( 'max_tokens' => 900 ) ) );
    }
    public static function ajax_meta() {
        self::guard(); $r = self::req();
        $u = self::page_block( $r ) . "Task: write 3 different SEO title + meta description pairs.\nRules: title max 58 characters, focus keyword inside (near the start when natural); description 130-155 characters, includes the keyword once, states a concrete benefit and ends with a soft call to action; each pair uses a different angle (benefit, curiosity/question, specifics). No clickbait, no ALL CAPS, no invented facts. Include the brand '" . get_bloginfo( 'name' ) . "' only if it fits the title length.\nJSON: {\"variants\":[{\"angle\":\"\",\"title\":\"\",\"description\":\"\"}]}";
        $x = NRA_AI::json( NRA_AI::context(), $u, array( 'max_tokens' => 800 ) );
        if ( $x['ok'] && ! empty( $x['data']['variants'] ) ) { foreach ( $x['data']['variants'] as &$v ) { $v = self::clean_meta( (array) $v ); } unset( $v ); }
        self::out( $x );
    }
    public static function ajax_short() {
        self::guard(); $r = self::req();
        $notes = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
        $u = self::page_block( $r ) . ( $notes ? "<page_content>\nOwner notes: $notes\n</page_content>\n" : '' ) . "Task: write the short description shown near the buy button (40-70 words) for this product. Lead with the main benefit, include the focus keyword once naturally, mention only facts present in the page or notes. Output plain HTML using only <p> and optionally one <ul> with 3-4 <li> highlights. If a key fact is missing, leave it out.\nJSON: {\"html\":\"\"}";
        $x = NRA_AI::json( NRA_AI::context(), $u, array( 'max_tokens' => 600 ) );
        if ( $x['ok'] ) { $x['data']['html'] = wp_kses_post( $x['data']['html'] ?? '' ); }
        self::out( $x );
    }
    public static function ajax_article() {
        self::guard(); $r = self::req();
        $kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : 'article';
        $len = isset( $_POST['len'] ) ? sanitize_key( wp_unslash( $_POST['len'] ) ) : 'medium';
        $notes = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
        $words = array( 'short' => 300, 'medium' => 700 );
        if ( ! isset( $words[ $len ] ) ) { NRA_Util::fail( 'Longer texts (1,500 to 5,000 words) are part of NodeThemes SEO AI Pro.' ); }
        $w = $words[ $len ];
        $struct = array(
            'product' => 'Product page copy: a benefit-led opening paragraph, <h2>Key features</h2> with a <ul>, <h2>Who it is for</h2>, <h2>How it works / what is included</h2>, and a short closing call to action. Use a table only for real specs given in the notes.',
            'article' => 'Article: an opening that answers the main question in 2 sentences, then logical <h2> sections (add <h3> when needed), at least one <ul> or <ol>, and a short conclusion.',
            'landing' => 'Landing page: a clear value proposition paragraph, <h2> sections for benefits, how it works, proof points (only those in the notes), and a final call to action.',
            'howto'   => 'How-to guide: a short intro with the result the reader will achieve, then <h2>Steps</h2> as an <ol> with a short explanation per step, tips, and common mistakes.',
            'listicle' => 'Listicle/comparison: a short intro, then one <h2> per item with pros/cons as <ul>, and a short verdict. Do not rank or review specific real third-party products unless named in the notes.',
        );
        $u = self::page_block( $r, 5000 ) . "<page_content>\nOwner notes (the ONLY source of product facts): " . ( $notes ? $notes : '(none)' ) . "\n</page_content>\nTask: write the main content for this page in clean HTML (only <p>,<h2>,<h3>,<ul>,<ol>,<li>,<strong>,<em>,<table>,<tr>,<th>,<td>; no <h1>, no inline styles, no markdown).\nStructure: " . ( $struct[ $kind ] ?? $struct['article'] ) . "\nLength: about $w words. Focus keyword: " . ( $r['kw'] ? $r['kw'] : 'choose a natural main phrase from the title' ) . " - use it in the first paragraph and in one subheading, then use natural variations; never stuff.\nWrite like an expert human: specific, plain language, short paragraphs, no filler ('in today's fast-paced world'), no empty superlatives. Any fact you do not have (price, version, number, guarantee, compatibility) must be written as [ADD: what is needed] instead of guessed.\nJSON: {\"title\":\"best page title (H1), max 70 chars\",\"html\":\"\",\"suggested_slug\":\"\",\"missing_facts\":[\"facts the owner should add\"]}";
        $x = NRA_AI::json( NRA_AI::context(), $u, array( 'max_tokens' => (int) ( $w * 2.2 ) + 600, 'timeout' => 150, 'write' => true ) );
        if ( $x['ok'] ) { $x['data']['html'] = wp_kses_post( $x['data']['html'] ?? '' ); if ( '' === trim( $x['data']['html'] ) ) { NRA_Util::fail( 'The AI returned no text. Try again.' ); } }
        self::out( $x );
    }
    public static function ajax_faq() {
        self::guard(); $r = self::req();
        $u = self::page_block( $r ) . "Task: create FAQ items and key takeaways for this page.\nRules: questions are what real customers ask (natural wording, not keyword-stuffed). Every answer (25-60 words) must be fully supported by the page content above; if the page does not contain the answer, SKIP that question - do not guess. 4-6 FAQ items, 3-5 takeaways (each under 20 words, concrete).\nJSON: {\"faq\":[{\"q\":\"\",\"a\":\"\"}],\"takeaways\":[\"\"]}";
        self::out( NRA_AI::json( NRA_AI::context(), $u, array( 'max_tokens' => 1200 ) ) );
    }
    public static function ajax_coach() {
        self::guard(); $r = self::req();
        $res = NRA_Analyzer::analyze( array( 'post_id' => $r['post_id'], 'title' => $r['title'], 'content' => $r['content'], 'slug' => $r['slug'], 'kw' => $r['kw'], 'seo_title' => $r['seo_title'], 'desc' => $r['desc'], 'faq' => $r['faq'], 'takeaways' => $r['takeaways'] ) );
        $fails = '';
        foreach ( array_merge( $res['checks'], $res['geo_checks'] ) as $c ) { if ( 'good' !== $c['status'] ) { $fails .= '- [' . $c['status'] . '] ' . $c['label'] . ': ' . $c['msg'] . "\n"; } }
        $u = self::page_block( $r, 6000 ) . "Automated checks that are not passing:\n$fails\nTask: act as a senior SEO. Give a prioritised action plan of at most 6 steps for THIS page: concrete edits (quote or point at the exact paragraph/heading when possible), highest impact first. Include at least one idea that makes AI assistants more likely to quote the page. Do not suggest buying links or keyword stuffing.\nJSON: {\"verdict\":\"one sentence\",\"steps\":[{\"impact\":\"high|medium|low\",\"action\":\"\",\"why\":\"\"}]}";
        self::out( NRA_AI::json( NRA_AI::context(), $u, array( 'max_tokens' => 1200 ) ) );
    }

    /** one call that returns everything needed for a page */
    public static function autopilot_data( $r ) {
        $u = self::page_block( $r ) . "Task: complete SEO setup for this page.\nRules:\n- focus_keyword: " . ( $r['kw'] ? 'keep the current one unless it is clearly wrong' : 'choose the best realistic search phrase (2-5 words)' ) . ".\n- seo_title: max 58 characters, keyword near the start, no clickbait.\n- meta_description: 130-155 characters, keyword once, concrete benefit, soft call to action.\n- og_title / og_description: social-friendly versions (can be a little more engaging).\n- short_description: " . ( 'product' === $r['type'] ? '40-70 words for the product summary near the buy button, HTML <p> only, facts only from the page' : 'empty string' ) . ".\n- takeaways: 3-5 concrete bullets (under 20 words each) supported by the page.\n- faq: 4-6 items, each answer 25-60 words fully supported by the page content; skip questions the page cannot answer.\n- schema_type: one of Article, BlogPosting, Product, SoftwareApplication, none, or empty for automatic.\n- Never invent facts. If the page has no text, base title/description only on the title and business description and keep claims generic.\nJSON: {\"focus_keyword\":\"\",\"secondary\":[\"\"],\"intent\":\"\",\"seo_title\":\"\",\"meta_description\":\"\",\"og_title\":\"\",\"og_description\":\"\",\"short_description\":\"\",\"takeaways\":[\"\"],\"faq\":[{\"q\":\"\",\"a\":\"\"}],\"schema_type\":\"\"}";
        $x = NRA_AI::json( NRA_AI::context(), $u, array( 'max_tokens' => 1800 ) );
        if ( $x['ok'] ) {
            $d = $x['data'];
            $d['seo_title'] = NRA_Util::cut( trim( wp_strip_all_tags( (string) ( $d['seo_title'] ?? '' ) ), " \"'" ), 70 );
            $d['meta_description'] = NRA_Util::cut( trim( wp_strip_all_tags( (string) ( $d['meta_description'] ?? '' ) ), " \"'" ), 170 );
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
            if ( ! $overwrite && '' !== (string) get_post_meta( $id, '_nra_' . $k, true ) ) { return; }
            update_post_meta( $id, '_nra_' . $k, $v );
        };
        $put( 'focuskw', $d['focus_keyword'] ?? '' ); $put( 'title', $d['seo_title'] ?? '' ); $put( 'desc', $d['meta_description'] ?? '' );
        $put( 'og_title', $d['og_title'] ?? '' ); $put( 'og_desc', $d['og_description'] ?? '' ); $put( 'schema_type', $d['schema_type'] ?? '' );
        if ( ! empty( $d['takeaways'] ) && ( $overwrite || ! get_post_meta( $id, '_nra_takeaways', true ) ) ) { update_post_meta( $id, '_nra_takeaways', $d['takeaways'] ); }
        if ( ! empty( $d['faq'] ) && ( $overwrite || ! get_post_meta( $id, '_nra_faq', true ) ) ) { update_post_meta( $id, '_nra_faq', $d['faq'] ); }
        if ( ! empty( $d['short_description'] ) && 'product' === get_post_type( $id ) && ( $overwrite || '' === trim( get_post_field( 'post_excerpt', $id ) ) ) ) {
            wp_update_post( array( 'ID' => $id, 'post_excerpt' => $d['short_description'] ) );
        }
        NRA_Sitemap::bump(); NRA_Geo::bump();
        NRA_Analyzer::analyze_post( $id );
    }
}
