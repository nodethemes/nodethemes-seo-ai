/* NodeThemes SEO AI editor box */
(function ($) {
  'use strict';
  var esc = NTSEOAIx.esc;
  $(function () {
    var $mb = $('.ntseoai-mb'); if (!$mb.length) { return; }
    var pid = $mb.data('post'), ptype = $mb.data('type');
    var isG = !!(window.wp && wp.data && wp.data.select && wp.data.select('core/editor') && wp.data.select('core/editor').getEditedPostAttribute);
    var writerHtml = '';

    // ---------- editor bridge
    function ed() { return wp.data.select('core/editor'); }
    function getTitle() { return isG ? (ed().getEditedPostAttribute('title') || '') : ($('#title').val() || ''); }
    function getSlug() { return isG ? (ed().getEditedPostAttribute('slug') || '') : ($('#post_name').val() || $('#editable-post-name-full').text() || ''); }
    function getContent() {
      if (isG) { return ed().getEditedPostContent() || ''; }
      if (window.tinyMCE && tinyMCE.get('content') && !tinyMCE.get('content').isHidden()) { return tinyMCE.get('content').getContent(); }
      return $('#content').val() || '';
    }
    function setContent(html, append) {
      if (isG) {
        var blocks = (wp.blocks.rawHandler ? wp.blocks.rawHandler({ HTML: html }) : wp.blocks.parse(html));
        if (append) { wp.data.dispatch('core/block-editor').insertBlocks(blocks); } else { wp.data.dispatch('core/block-editor').resetBlocks(blocks); }
        return;
      }
      var t = window.tinyMCE && tinyMCE.get('content');
      if (t && !t.isHidden()) { t.setContent(append ? t.getContent() + html : html); t.save(); }
      else { var $c = $('#content'); $c.val(append ? $c.val() + '\n' + html : html); }
    }
    function setSerialized(html) { // content that already has block markup
      if (isG) { wp.data.dispatch('core/block-editor').resetBlocks(wp.blocks.parse(html)); } else { setContent(html, false); }
    }
    function setExcerpt(html) {
      if (isG) { wp.data.dispatch('core/editor').editPost({ excerpt: $('<div>').html(html).text() }); return true; }
      var t = window.tinyMCE && tinyMCE.get('excerpt');
      if (t) { t.setContent(html); t.save(); return true; }
      var $e = $('#excerpt, #post-excerpt, textarea[name=excerpt]').first();
      if ($e.length) { $e.val(html); return true; }
      return false;
    }
    function collect(extra) {
      var faq = [];
      $('#ntseoai-faq-list .ntseoai-faq').each(function () { var q = $(this).find('input').val(), a = $(this).find('textarea').val(); if (q && a) { faq.push({ q: q, a: a }); } });
      var tk = $('#ntseoai-tk-list input').map(function () { return $(this).val(); }).get();
      return $.extend({ post_id: pid, type: ptype, title: getTitle(), content: getContent(), slug: getSlug(), kw: $('#ntseoai_focuskw').val(), seo_title: $('#ntseoai_title').val(), desc: $('#ntseoai_desc').val(), faq: faq, takeaways: tk }, extra || {});
    }

    // block editor: meta boxes live in a collapsed strip by default; open it once so the panel is visible
    if (isG) {
      try {
        if (!window.localStorage || !localStorage.getItem('ntseoai_pane_opened')) {
          var pref = wp.data.dispatch('core/preferences');
          if (pref && pref.set) { pref.set('core/edit-post', 'metaBoxesMainIsOpen', true); pref.set('core/edit-post', 'metaBoxesMainOpenHeight', 560); }
          if (window.localStorage) { localStorage.setItem('ntseoai_pane_opened', '1'); }
        }
      } catch (e) {}
    }

    // ---------- tabs
    $mb.on('click', '.ntseoai-tab', function () {
      var t = $(this).data('t'); $mb.find('.ntseoai-tab').removeClass('on'); $(this).addClass('on');
      $mb.find('.ntseoai-pane').removeClass('on').filter('[data-p=' + t + ']').addClass('on');
    });

    // ---------- SERP preview + counters
    function serp() {
      var t = $('#ntseoai_title').val() || (getTitle() + ' - ' + NTSEOAI.site);
      var d = $('#ntseoai_desc').val() || '';
      $('#ntseoai-serp-name').text(NTSEOAI.site); $('#ntseoai-serp-url').text(NTSEOAI.host + ' › ' + (getSlug() || '...'));
      $('#ntseoai-serp-title').text(t); $('#ntseoai-serp-desc').text(d || 'No meta description: Google will pick text from the page.');
      var tl = ($('#ntseoai_title').val() || '').length, dl = d.length;
      $('#ntseoai-tc').text(tl ? tl + ' / 60' : 'template'); $('#ntseoai-dc').text(dl + ' / 155');
      $('#ntseoai-tbar').css({ width: Math.min(100, tl / 60 * 100) + '%', background: tl > 60 ? '#dc2626' : (tl >= 30 ? '#16a34a' : '#d97706') });
      $('#ntseoai-dbar').css({ width: Math.min(100, dl / 160 * 100) + '%', background: dl > 160 ? '#dc2626' : (dl >= 120 ? '#16a34a' : '#d97706') });
    }
    $mb.on('click', '.ntseoai-serp-tabs button', function () { $('.ntseoai-serp-tabs button').removeClass('on'); $(this).addClass('on'); $('#ntseoai-serp').toggleClass('mobile', $(this).data('m') === 'mobile'); });

    // ---------- live analysis
    var timer = null, last = '', busy = false;
    function ring($r, v) { $r.removeClass('good ok bad').addClass(v >= 80 ? 'good' : (v >= 50 ? 'ok' : 'bad')).css('--p', v).find('b').text(v); }
    function list(c) {
      return c.map(function (x) {
        var btn = '';
        if (x.fix && x.status !== 'good') {
          btn = '<button type="button" class="ntseoai-fixone' + (NTSEOAI.pro ? '' : ' locked') + '" data-id="' + esc(x.id) + '" data-fix="' + esc(x.fix) + '">' + (x.fix === 'ext' ? 'Find sources' : (x.fix === 'link' ? 'Add links' : 'Fix with AI')) + (NTSEOAI.pro ? '' : ' (PRO)') + '</button>';
        }
        return '<div class="ntseoai-ck ' + x.status + '"><i></i><div><b>' + esc(x.label) + '</b><span>' + esc(x.msg) + '</span>' + btn + '</div></div>';
      }).join('');
    }
    $mb.on('click', '.ntseoai-fixone', function () {
      var $b = $(this);
      if (!NTSEOAI.pro) { window.open(NTSEOAI.upgrade, '_blank'); return; }
      if (needAI()) { return; }
      $(document).trigger('ntseoai:fixone', [$b.data('id'), $b.data('fix'), $b]);
    });
    function analyze(force) {
      var d = collect(); var sig = [d.title, d.content.length, d.slug, d.kw, d.seo_title, d.desc, JSON.stringify(d.faq), d.takeaways.join('|')].join('~');
      serp();
      if (!force && sig === last) { return; }
      if (busy) { return; }
      last = sig; busy = true;
      NTSEOAIx.post('ntseoai_ai_analyze', d).done(function (r) {
        window.NTSEOAIState = r; $(document).trigger('ntseoai:state');
        ring($('#ntseoai-seo-ring'), r.score); ring($('#ntseoai-geo-ring'), r.geo_score);
        // problems first
        var order = { bad: 0, ok: 1, good: 2 }, sf = function (a, b) { return order[a.status] - order[b.status]; };
        $('#ntseoai-checks').html(list(r.checks.slice().sort(sf))); $('#ntseoai-geo-checks').html(list(r.geo_checks.slice().sort(sf)));
      }).fail(function (m) { $('#ntseoai-checks').html('<p class="ntseoai-hint">' + esc(m) + '</p>'); }).always(function () { busy = false; });
    }
    function later() { clearTimeout(timer); timer = setTimeout(function () { analyze(false); }, 1100); }
    $mb.on('input change', 'input,textarea,select', later);
    $(document).on('input', '#title', later);
    setInterval(function () { if (document.hidden) { return; } try { later(); } catch (e) {} }, 6000);
    $(function () { setTimeout(function () { analyze(true); }, 1200); });

    function needAI() { if (!NTSEOAI.configured) { alert('Add your AI key first (NodeThemes SEO AI > Settings).'); return true; } return false; }
    function run($btn, action, extra, ok, $out) {
      if (needAI()) { return; }
      NTSEOAIx.busy($btn, true); if ($out) { $out.empty(); }
      NTSEOAIx.post(action, collect(extra)).done(ok).fail(function (m) { if ($out) { NTSEOAIx.msg($out, 'err', m); } else { alert(m); } }).always(function () { NTSEOAIx.busy($btn, false); });
    }

    // ---------- keywords
    $('#ntseoai-find-kw').on('click', function () {
      var $o = $('#ntseoai-kw-out');
      run($(this), 'ntseoai_ai_keywords', {}, function (r) {
        var tags = function (a) { return (a || []).map(function (k) { return '<button type="button" class="ntseoai-tag" data-k="' + esc(k) + '">' + esc(k) + '</button>'; }).join(''); };
        $o.html('<div class="ntseoai-kwbox"><b>Suggested focus keyword:</b> <button type="button" class="ntseoai-tag" data-k="' + esc(r.focus_keyword) + '">' + esc(r.focus_keyword) + '</button> <small>intent: ' + esc(r.intent) + ' &middot; competition (AI guess, no volume data): ' + esc(r.competition_guess) + '</small><p class="ntseoai-hint">' + esc(r.why) + '</p>' +
          '<b>Related</b><div class="ntseoai-tags">' + tags(r.secondary) + '</div><b>Long-tail</b><div class="ntseoai-tags">' + tags(r.long_tail) + '</div><b>Questions people ask</b><div class="ntseoai-tags">' + tags(r.questions) + '</div><p class="ntseoai-hint">Click a phrase to use it as the focus keyword.</p></div>');
      }, $o);
    });
    $mb.on('click', '.ntseoai-tag', function () { $('#ntseoai_focuskw').val($(this).data('k')).trigger('change'); });

    // ---------- meta variants
    $('#ntseoai-gen-meta').on('click', function () {
      var $o = $('#ntseoai-meta-out');
      run($(this), 'ntseoai_ai_meta', {}, function (r) {
        $o.html((r.variants || []).map(function (v, i) { return '<div class="ntseoai-var"><small>' + esc(v.angle) + '</small><b>' + esc(v.title) + '</b> <small>(' + (v.title || '').length + ' chars)</small><p>' + esc(v.description) + ' <small>(' + (v.description || '').length + ')</small></p><button type="button" class="ntseoai-btn sm ntseoai-use" data-i="' + i + '">Use this</button></div>'; }).join(''));
        $o.data('v', r.variants);
      }, $o);
    });
    $mb.on('click', '.ntseoai-use', function () { var v = ($('#ntseoai-meta-out').data('v') || [])[$(this).data('i')]; if (v) { $('#ntseoai_title').val(v.title); $('#ntseoai_desc').val(v.description).trigger('change'); } });

    // ---------- FAQ / takeaways
    function addFaq(q, a) { $('#ntseoai-faq-list').append('<div class="ntseoai-faq"><input type="text" name="ntseoai_faq_q[]" placeholder="Question"><textarea name="ntseoai_faq_a[]" rows="2" placeholder="Answer"></textarea><button type="button" class="ntseoai-x">&times;</button></div>'); var $l = $('#ntseoai-faq-list .ntseoai-faq').last(); $l.find('input').val(q || ''); $l.find('textarea').val(a || ''); }
    function addTk(t) { $('#ntseoai-tk-list').append('<div class="ntseoai-row2"><input type="text" name="ntseoai_tk[]"><button type="button" class="ntseoai-x">&times;</button></div>'); $('#ntseoai-tk-list input').last().val(t || ''); }
    $('#ntseoai-add-faq').on('click', function () { addFaq(); }); $('#ntseoai-add-tk').on('click', function () { addTk(); });
    $mb.on('click', '.ntseoai-x', function () { $(this).parent().remove(); later(); });
    $('#ntseoai-gen-faq').on('click', function () {
      var $b = $(this);
      run($b, 'ntseoai_ai_faq', {}, function (r) {
        if ((r.faq || []).length) { $('#ntseoai-faq-list').empty(); r.faq.forEach(function (f) { addFaq(f.q, f.a); }); }
        if ((r.takeaways || []).length) { $('#ntseoai-tk-list').empty(); r.takeaways.forEach(addTk); }
        later();
      });
    });

    // ---------- coach
    $('#ntseoai-coach').on('click', function () {
      var $o = $('#ntseoai-coach-out');
      run($(this), 'ntseoai_ai_coach', {}, function (r) {
        $o.html('<div class="ntseoai-kwbox"><b>' + esc(r.verdict) + '</b>' + (r.steps || []).map(function (s) { return '<div class="ntseoai-step"><em class="' + esc(s.impact) + '">' + esc(s.impact) + '</em><div>' + esc(s.action) + '<br><span class="ntseoai-hint">' + esc(s.why) + '</span></div></div>'; }).join('') + '</div>');
      }, $o);
    });

    // ---------- autopilot
    function applyAuto(r) {
      $('#ntseoai_focuskw').val(r.focus_keyword || $('#ntseoai_focuskw').val()); $('#ntseoai_title').val(r.seo_title); $('#ntseoai_desc').val(r.meta_description);
      $('input[name=ntseoai_og_title]').val(r.og_title); $('textarea[name=ntseoai_og_desc]').val(r.og_description);
      if (r.schema_type) { $('select[name=ntseoai_schema_type]').val(r.schema_type); }
      $('#ntseoai-faq-list').empty(); (r.faq || []).forEach(function (f) { addFaq(f.q, f.a); });
      $('#ntseoai-tk-list').empty(); (r.takeaways || []).forEach(addTk);
      var extra = '';
      if (r.short_description && ptype === 'product') { extra = setExcerpt(r.short_description) ? ' The product short description was filled too.' : ''; }
      return extra;
    }
    function autopilot($b, silent, done) {
      if (needAI()) { return; }
      NTSEOAIx.busy($b, true);
      NTSEOAIx.post('ntseoai_ai_autopilot', collect()).done(function (r) {
        var extra = applyAuto(r);
        $('#ntseoai-kw-out').html('<div class="ntseoai-flash ok">Done. Review the fields, then save the page.' + esc(extra) + '</div>');
        if (!silent) { $('.ntseoai-tab[data-t=seo]').click(); }
        analyze(true); if (done) { done(true); }
      }).fail(function (m) { alert(m); if (done) { done(false); } }).always(function () { NTSEOAIx.busy($b, false); });
    }
    $('#ntseoai-autopilot').on('click', function () {
      var has = $('#ntseoai_title').val() || $('#ntseoai_desc').val() || $('#ntseoai_focuskw').val();
      if (has && !window.confirm('Replace the current SEO fields (keyword, title, description, FAQ, takeaways) with AI suggestions? Nothing is published until you save the page.')) { return; }
      autopilot($(this), false);
    });
    $mb.on('click', '.ntseoai-locked', function () { /* link opens the upgrade page */ });
    function setTitle(t) { if (isG) { wp.data.dispatch('core/editor').editPost({ title: t }); } else { $('#title').val(t).trigger('input'); $('#title-prompt-text').addClass('screen-reader-text'); } }
    function setSlug(sl) { if (!sl) { return; } if (isG) { wp.data.dispatch('core/editor').editPost({ slug: sl }); } else { $('#post_name').val(sl); } }

    // ---------- writer
    var wTitle = '', wSlug = '';
    function showWriter(html, title, slug, missing) {
      var $o = $('#ntseoai-w-out'); writerHtml = html; wTitle = title || ''; wSlug = slug || '';
      var cur = $.trim($('<div>').html(getContent()).text());
      $o.html((title ? '<p><b>Title:</b> ' + esc(title) + '</p>' : '') + '<div class="ntseoai-preview">' + html + '</div>' + ((missing || []).length ? '<div class="ntseoai-flash warn"><b>Add these facts yourself:</b> ' + missing.map(esc).join(' &middot; ') + '</div>' : '') + (cur ? '<p class="ntseoai-hint">The editor already has text. "Add to end" keeps it.</p>' : ''));
      $('#ntseoai-w-actions').show();
    }
    function afterInsert(replaced) {
      if ($('#ntseoai-w-all').is(':checked')) {
        if (wTitle && (replaced || !$.trim(getTitle()) || /^auto.?draft|^\s*$/i.test(getTitle()))) { setTitle(wTitle); }
        if (wSlug && !$('#ntseoai_focuskw').val()) { setSlug(wSlug); }
        $('#ntseoai-w-out').append('<div class="ntseoai-flash ok">Text inserted. Filling the SEO fields...</div>');
        setTimeout(function () { autopilot($('#ntseoai-w-go'), true, function () { if (window.NTSEOAIPro && NTSEOAIPro.afterWrite) { NTSEOAIPro.afterWrite(); } }); }, 600);
      }
      analyze(true);
    }
    $('#ntseoai-w-go').on('click', function () {
      var $o = $('#ntseoai-w-out'), $opt = $('#ntseoai-w-len option:selected'); $('#ntseoai-w-actions').hide();
      if ($opt.data('long') && window.NTSEOAIPro && NTSEOAIPro.writeLong) {
        if (needAI()) { return; }
        NTSEOAIPro.writeLong($(this), +$opt.data('long'));
        return;
      }
      run($(this), 'ntseoai_ai_article', { kind: $('#ntseoai-w-kind').val(), len: $('#ntseoai-w-len').val(), notes: $('#ntseoai-w-notes').val() }, function (r) { showWriter(r.html, r.title, r.suggested_slug, r.missing_facts); }, $o);
    });
    $('#ntseoai-w-replace').on('click', function () { if (writerHtml && window.confirm('Replace everything in the editor with this text?')) { setContent(writerHtml, false); afterInsert(true); } });
    $('#ntseoai-w-append').on('click', function () { if (writerHtml) { setContent(writerHtml, true); afterInsert(false); } });
    $('#ntseoai-w-short').on('click', function () {
      var $o = $('#ntseoai-w-out');
      run($(this), 'ntseoai_ai_short', { notes: $('#ntseoai-w-notes').val() }, function (r) {
        var ok = setExcerpt(r.html);
        $o.html('<div class="ntseoai-preview">' + r.html + '</div>' + (ok ? '<div class="ntseoai-flash ok">Placed in the short description / excerpt field. Save to keep it.</div>' : '<div class="ntseoai-flash warn">Could not find the excerpt field. Copy the text above.</div>'));
      }, $o);
    });

    // ---------- links
    function item(i, kind) {
      return '<div class="ntseoai-lnk"><div><a href="' + esc(i.edit || i.url || '#') + '" target="_blank"><b>' + esc(i.title) + '</b></a> <small>' + esc(i.type) + '</small><small>' + esc(i.snippet) + '</small><small>Anchor: <mark>' + esc(i.anchor) + '</mark></small></div><button type="button" class="ntseoai-btn sm ntseoai-apply" data-kind="' + kind + '" data-id="' + (kind === 'in' ? i.src_id : i.dst_id) + '" data-anchor="' + esc(i.anchor) + '">Add link</button></div>';
    }
    $('#ntseoai-links-in').on('click', function () {
      var $o = $('#ntseoai-links-out-box'), $b = $(this); NTSEOAIx.busy($b, true);
      NTSEOAIx.post('ntseoai_links_suggest_in', { target: pid }).done(function (r) { if (r.more) { setTimeout(function () { $o.append('<div class="ntseoai-flash warn">&#128274; ' + r.more + ' more suggestion(s) hidden. <a href="' + esc(NTSEOAI.upgrade) + '" target="_blank">Pro</a> shows all of them and adds the safe ones in one click.</div>'); }, 0); } $o.html(r.items.length ? r.items.map(function (i) { return item(i, 'in'); }).join('') : '<p class="ntseoai-hint">No unlinked mentions found. Run the link scan in the Link Map, or write more related content that mentions this topic.</p>'); }).fail(function (m) { NTSEOAIx.msg($o, 'err', m); }).always(function () { NTSEOAIx.busy($b, false); });
    });
    $('#ntseoai-links-out').on('click', function () {
      var $o = $('#ntseoai-links-out-box'), $b = $(this); NTSEOAIx.busy($b, true);
      NTSEOAIx.post('ntseoai_links_suggest_out', { source: pid }).done(function (r) { $o.html(r.items.length ? r.items.map(function (i) { return item(i, 'out'); }).join('') : '<p class="ntseoai-hint">Nothing to add: no unlinked mentions of your other pages in this text.</p>'); }).fail(function (m) { NTSEOAIx.msg($o, 'err', m); }).always(function () { NTSEOAIx.busy($b, false); });
    });
    $mb.on('click', '.ntseoai-apply', function () {
      var $b = $(this), kind = $b.data('kind'), id = $b.data('id'), data;
      if (kind === 'in') { data = { source: id, target: pid, anchor: $b.data('anchor') }; }
      else { data = { source: pid, target: id, anchor: $b.data('anchor'), content: getContent() }; }
      NTSEOAIx.busy($b, true);
      NTSEOAIx.post('ntseoai_links_apply', data).done(function (r) { if (r.content) { setSerialized(r.content); } $b.replaceWith('<span class="ntseoai-pill good">Added</span>'); }).fail(function (m) { alert(m); NTSEOAIx.busy($b, false); });
    });

    // ---------- media picker
    $('#ntseoai-pick-img').on('click', function (e) {
      e.preventDefault();
      var f = wp.media({ title: 'Social image', multiple: false, library: { type: 'image' } });
      f.on('select', function () { $('#ntseoai_og_img').val(f.state().get('selection').first().toJSON().url); });
      f.open();
    });
    window.NTSEOAIEditor = { setExcerpt: setExcerpt, collect: collect, setContent: setContent, setSerialized: setSerialized, setTitle: setTitle, setSlug: setSlug, analyze: analyze, addFaq: addFaq, addTk: addTk, autopilot: autopilot, showWriter: showWriter, getContent: getContent, getTitle: getTitle, isG: isG, pid: pid, ptype: ptype };
    if (location.hash === '#ntseoai') { setTimeout(function () { var e = document.querySelector('.ntseoai-mb'); if (e && e.scrollIntoView) { e.scrollIntoView(); } }, 1500); }
    serp();
  });
})(jQuery);
