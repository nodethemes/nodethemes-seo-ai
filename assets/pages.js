/* NodeThemes SEO AI admin screens */
(function ($) {
  'use strict';
  var esc = NTSEOAIx.esc;

  // ================= settings
  $(function () {
    var $f = $('#ntseoai-settings-form'); if (!$f.length) { return; }
    function show(t) {
      if (!$('.ntseoai-wrap .ntseoai-panel[data-panel=' + t + ']').length) { t = 'ai'; }
      $f.find('.ntseoai-tabs .ntseoai-tab').removeClass('on').filter('[data-tab=' + t + ']').addClass('on');
      $('.ntseoai-wrap .ntseoai-panel').prop('hidden', true).filter('[data-panel=' + t + ']').prop('hidden', false); $f.find('.ntseoai-savebar').toggle(t !== 'license');
      $('#ntseoai-tab-field').val(t);
    }
    $f.on('click', '.ntseoai-tabs .ntseoai-tab', function () { var t = $(this).data('tab'); show(t); if (history.replaceState) { history.replaceState(null, '', '#' + t); } });
    show((location.hash || '').replace('#', '') || 'ai');
    function prov() { $('.ntseoai-prov').each(function () { $(this).toggleClass('on', $(this).data('prov') === $('#ntseoai-provider').val()); }); }
    $('#ntseoai-provider').on('change', prov); prov();
    $(document).on('click', '.ntseoai-budget-chip', function () { $('#ntseoai-budget').val($(this).data('v')); });

    $f.on('click', '.ntseoai-fetch-models', function () {
      var $b = $(this), $p = $b.closest('.ntseoai-prov'), $o = $p.find('.ntseoai-test-out'); NTSEOAIx.busy($b, true);
      NTSEOAIx.post('ntseoai_list_models', { provider: $p.data('prov'), key: $p.find('.ntseoai-key').val() }).done(function (r) {
        $('#ntseoai-models-' + $p.data('prov')).html(r.models.map(function (m) { return '<option value="' + esc(m) + '">'; }).join(''));
        var cur = $p.find('.ntseoai-model').val();
        $p.find('.ntseoai-chips').html('<span class="ntseoai-hint">Models available for your key:</span> ' + r.models.slice(0, 60).map(function (m) { return '<button type="button" class="ntseoai-chip-m' + (m === cur ? ' on' : '') + '" data-m="' + esc(m) + '">' + esc(m) + '</button>'; }).join(''));
        $o.attr('class', 'ntseoai-test-out ok').text(r.models.length + ' models loaded. Click the model box to pick one.');
      }).fail(function (m) { $o.attr('class', 'ntseoai-test-out err').text(m); }).always(function () { NTSEOAIx.busy($b, false); });
    });
    $f.on('focus', '.ntseoai-model, .ntseoai-model-fast', function () { $(this).closest('.ntseoai-prov').data('tgt', $(this).hasClass('ntseoai-model-fast') ? 'fast' : 'main'); });
    $f.on('click', '.ntseoai-chip-m', function () { var $p = $(this).closest('.ntseoai-prov'); ($p.data('tgt') === 'fast' ? $p.find('.ntseoai-model-fast') : $p.find('.ntseoai-model')).val($(this).data('m')); $p.find('.ntseoai-chip-m').removeClass('on'); $(this).addClass('on'); });
    $f.on('input', '.ntseoai-model', function () { var v = $(this).val(); $(this).closest('.ntseoai-prov').find('.ntseoai-chip-m').each(function () { $(this).toggleClass('on', $(this).data('m') === v); }); });
    $('.ntseoai-prov').each(function () { var $p = $(this); if ($p.find('.ntseoai-badge.ok').length && !$p.find('.ntseoai-chips .ntseoai-chip-m').length > 0) { /* nothing */ } if ($p.find('.ntseoai-badge.ok').length && $p.data('prov') === $('#ntseoai-provider').val()) { $p.find('.ntseoai-fetch-models').trigger('click'); } });
    $f.on('click', '.ntseoai-test-ai', function () {
      var $b = $(this), $p = $b.closest('.ntseoai-prov'), $o = $p.find('.ntseoai-test-out'); NTSEOAIx.busy($b, true); $o.attr('class', 'ntseoai-test-out').text('Testing...');
      NTSEOAIx.post('ntseoai_test_ai', { provider: $p.data('prov'), key: $p.find('.ntseoai-key').val(), model: $p.find('.ntseoai-model').val() }).done(function (r) { $o.attr('class', 'ntseoai-test-out ok').text(r.message); }).fail(function (m) { $o.attr('class', 'ntseoai-test-out err').text(m); }).always(function () { NTSEOAIx.busy($b, false); });
    });
    $f.on('click', '[data-preset]', function () {
      var p = $(this).data('preset');
      $f.find('.ntseoai-bot').each(function () {
        var t = $(this).data('type'), v = 'default';
        if (p === 'all') { v = 'allow'; } else if (p === 'none') { v = 'block'; } else if (p === 'visible') { v = (t === 'train') ? 'block' : 'allow'; }
        $(this).val(v);
      });
    });
    $('#ntseoai-suggest-summary').on('click', function () {
      var $b = $(this), $o = $('#ntseoai-summary-out'); NTSEOAIx.busy($b, true);
      NTSEOAIx.post('ntseoai_suggest_summary', {}).done(function (r) { $('textarea[name=llms_summary]').val(r.text); $o.text('Review and save.'); }).fail(function (m) { $o.text(m); }).always(function () { NTSEOAIx.busy($b, false); });
    });
    // import
    $f.on('click', '.ntseoai-import', function () {
      var $b = $(this), src = $b.data('src'), $o = $('.ntseoai-import-out[data-for=' + src + ']'), total = 0; NTSEOAIx.busy($b, true);
      (function step(off) {
        NTSEOAIx.post('ntseoai_import_run', { src: src, offset: off }).done(function (r) {
          total += r.imported; $o.text('Imported ' + total + '...');
          if (r.done) { $o.text('Done: ' + total + ' pages imported.'); NTSEOAIx.busy($b, false); } else { step(r.next); }
        }).fail(function (m) { $o.text(m); NTSEOAIx.busy($b, false); });
      })(0);
    });
  });

  // ================= bulk
  $(function () {
    var $t = $('#ntseoai-bulk-table'); if (!$t.length) { return; }
    $('#ntseoai-bulk-all').on('change', function () { $t.find('.ntseoai-bulk-cb').prop('checked', this.checked); });
    $('#ntseoai-bulk-go').on('click', function () {
      var ids = $t.find('.ntseoai-bulk-cb:checked').map(function () { return +this.value; }).get(), $m = $('#ntseoai-bulk-msg'), over = $('#ntseoai-bulk-over').is(':checked') ? 1 : 0, $b = $(this), i = 0, errs = 0;
      if (!ids.length) { $m.text('Tick at least one page.'); return; }
      if (!NTSEOAI.configured) { $m.text('Add your AI key first.'); return; }
      NTSEOAIx.busy($b, true);
      (function next() {
        if (i >= ids.length) { NTSEOAIx.busy($b, false); $m.text('Finished: ' + (ids.length - errs) + ' optimised' + (errs ? ', ' + errs + ' failed' : '') + '.'); return; }
        var id = ids[i], $r = $t.find('tr[data-id=' + id + ']'); $r.find('.c-st').text('Working...'); $m.text('Page ' + (i + 1) + ' of ' + ids.length + '...');
        NTSEOAIx.post('ntseoai_bulk_run', { id: id, overwrite: over }).done(function (r) {
          $r.find('.c-kw').text(r.kw); $r.find('.c-s').html('<span class="ntseoai-pill ' + (r.score >= 80 ? 'good' : r.score >= 50 ? 'ok' : 'bad') + '">' + r.score + '</span>'); $r.find('.c-g').html('<span class="ntseoai-pill ' + (r.geo >= 80 ? 'good' : r.geo >= 50 ? 'ok' : 'bad') + '">' + r.geo + '</span>'); $r.find('.c-st').text('Done');
        }).fail(function (m) { errs++; $r.find('.c-st').text(m); if (/limit|budget|key|quota|rate/i.test(m)) { i = ids.length; } }).always(function () { i++; next(); });
      })();
    });
    $('#ntseoai-rescore').on('click', function () {
      var $b = $(this); NTSEOAIx.busy($b, true);
      (function step(off) { NTSEOAIx.post('ntseoai_bulk_score', { offset: off }).done(function (r) { $b.text('Scoring ' + Math.min(r.next, r.total) + '/' + r.total); if (r.done) { location.reload(); } else { step(r.next); } }).fail(function (m) { alert(m); NTSEOAIx.busy($b, false); }); })(0);
    });
  });

  // ================= redirects
  $(document).on('click', '.ntseoai-rd-from', function () { $('#ntseoai-rd-src').val($(this).data('path')); $('#ntseoai-rd-dst').focus(); $('html,body').animate({ scrollTop: 0 }, 200); });

  // ================= AI visibility
  $(function () {
    var $w = $('#ntseoai-ai'); if (!$w.length) { return; }
    function reload() { location.reload(); }
    $('#ntseoai-pr-add').on('click', function () {
      var $b = $(this); NTSEOAIx.busy($b, true);
      NTSEOAIx.post('ntseoai_prompt_add', { prompt: $('#ntseoai-pr-new').val() }).done(reload).fail(function (m) { alert(m); NTSEOAIx.busy($b, false); });
    });
    $('#ntseoai-pr-suggest').on('click', function () {
      var $b = $(this); if (!NTSEOAI.configured) { alert('Add your AI key first.'); return; } NTSEOAIx.busy($b, true);
      NTSEOAIx.post('ntseoai_prompt_suggest', {}).done(function (r) { $('#ntseoai-pr-new').val(r.prompts.join('\n')); }).fail(function (m) { alert(m); }).always(function () { NTSEOAIx.busy($b, false); });
    });
    function runOne($tr, cb) {
      var $b = $tr.find('.ntseoai-pr-run'); NTSEOAIx.busy($b, true);
      NTSEOAIx.post('ntseoai_prompt_run', { id: $tr.data('id') }).done(function (r) {
        $tr.find('.c-m').html('<span class="ntseoai-pill ' + (r.mentioned ? 'good' : 'bad') + '">' + (r.mentioned ? 'Yes' : 'No') + '</span>');
        $tr.find('.c-c').html(r.web ? '<span class="ntseoai-pill ' + (r.cited ? 'good' : 'bad') + '">' + (r.cited ? 'Yes' : 'No') + '</span>' : 'n/a');
        $tr.find('.c-b').text((r.brands || []).slice(0, 6).join(', ')); $tr.find('.c-t').text('just now');
        cb && cb(true);
      }).fail(function (m) { alert(m); cb && cb(false); }).always(function () { NTSEOAIx.busy($b, false); });
    }
    $w.on('click', '.ntseoai-pr-run', function () { runOne($(this).closest('tr')); });
    $w.on('click', '.ntseoai-pr-del', function () { var $tr = $(this).closest('tr'); if (confirm('Delete this question and its history?')) { NTSEOAIx.post('ntseoai_prompt_del', { id: $tr.data('id') }).done(function () { $tr.remove(); }); } });
    $('#ntseoai-pr-runall').on('click', function () {
      var rows = $('#ntseoai-pr-table tbody tr[data-id]').get(), i = 0, $b = $(this);
      if (!NTSEOAI.configured) { alert('Add your AI key first.'); return; }
      NTSEOAIx.busy($b, true);
      (function next() { if (i >= rows.length) { NTSEOAIx.busy($b, false); return; } runOne($(rows[i++]), function (ok) { if (ok) { next(); } else { NTSEOAIx.busy($b, false); } }); })();
    });
  });

  // ================= link map
  $(function () {
    var $w = $('#ntseoai-linkmap'); if (!$w.length) { return; }
    var data = null, sel = 0, NS = 'http://www.w3.org/2000/svg';
    function col(n) { return n.in === 0 ? '#dc2626' : (n.in < 3 ? '#d97706' : '#16a34a'); }
    function layout(nodes, edges) {
      var W = 900, H = 600, idx = {};
      nodes.forEach(function (n, i) { idx[n.id] = i; var a = i / nodes.length * 6.283; n.x = W / 2 + Math.cos(a) * 250 * (0.4 + (i % 3) * 0.3); n.y = H / 2 + Math.sin(a) * 200 * (0.4 + (i % 3) * 0.3); n.vx = 0; n.vy = 0; });
      for (var it = 0; it < 220; it++) {
        var k = 1 - it / 260;
        for (var i = 0; i < nodes.length; i++) { for (var j = i + 1; j < nodes.length; j++) {
          var a = nodes[i], b = nodes[j], dx = a.x - b.x, dy = a.y - b.y, d2 = dx * dx + dy * dy + 0.01, f = 2600 / d2, d = Math.sqrt(d2);
          a.vx += dx / d * f; a.vy += dy / d * f; b.vx -= dx / d * f; b.vy -= dy / d * f; } }
        edges.forEach(function (e) { var a = nodes[idx[e[0]]], b = nodes[idx[e[1]]]; if (!a || !b) { return; } var dx = b.x - a.x, dy = b.y - a.y, d = Math.sqrt(dx * dx + dy * dy) + 0.01, f = (d - 90) * 0.012; a.vx += dx / d * f; a.vy += dy / d * f; b.vx -= dx / d * f; b.vy -= dy / d * f; });
        nodes.forEach(function (n) { n.vx += (W / 2 - n.x) * 0.002; n.vy += (H / 2 - n.y) * 0.003; n.x = Math.max(30, Math.min(W - 30, n.x + n.vx * k)); n.y = Math.max(20, Math.min(H - 20, n.y + n.vy * k)); n.vx *= 0.6; n.vy *= 0.6; });
      }
    }
    function fit(nodes) {
      if (nodes.length < 2) { return; }
      var x0 = 1e9, x1 = -1e9, y0 = 1e9, y1 = -1e9;
      nodes.forEach(function (n) { x0 = Math.min(x0, n.x); x1 = Math.max(x1, n.x); y0 = Math.min(y0, n.y); y1 = Math.max(y1, n.y); });
      var w = Math.max(1, x1 - x0), h = Math.max(1, y1 - y0);
      nodes.forEach(function (n) { n.x = 60 + (n.x - x0) / w * 700; n.y = 40 + (n.y - y0) / h * 520; });
    }
    function el(n, a) { var e = document.createElementNS(NS, n); for (var k in a) { e.setAttribute(k, a[k]); } return e; }
    function draw() {
      var svg = document.getElementById('ntseoai-lm-svg'); while (svg.firstChild) { svg.removeChild(svg.firstChild); }
      var by = {}; data.nodes.forEach(function (n) { by[n.id] = n; });
      data.edges.forEach(function (e) { var a = by[e[0]], b = by[e[1]]; if (a && b) { svg.appendChild(el('line', { x1: a.x, y1: a.y, x2: b.x, y2: b.y, 'data-s': e[0], 'data-d': e[1], 'marker-end': '' })); } });
      data.nodes.forEach(function (n) {
        var g = el('g', { 'class': 'n', 'data-id': n.id }), r = 5 + Math.min(11, Math.sqrt(n.in + n.out) * 3);
        var c = el('circle', { cx: n.x, cy: n.y, r: r, fill: col(n), stroke: n.corner ? '#7c3aed' : '#fff', 'stroke-width': n.corner ? 3 : 1.5 }); g.appendChild(c);
        var t = el('text', { x: n.x + r + 3, y: n.y + 3 }); t.textContent = (n.title || '').slice(0, 22); g.appendChild(t);
        var ti = el('title', {}); ti.textContent = n.title + ' - ' + n.in + ' inbound, ' + n.out + ' outbound'; g.appendChild(ti);
        g.addEventListener('click', function () { pick(n.id); }); svg.appendChild(g);
      });
      mark();
    }
    function mark() {
      $('#ntseoai-lm-svg circle').removeClass('sel'); $('#ntseoai-lm-svg line').removeClass('hl');
      if (!sel) { return; }
      $('#ntseoai-lm-svg g[data-id=' + sel + '] circle').addClass('sel');
      $('#ntseoai-lm-svg line').filter(function () { return +$(this).attr('data-s') === sel || +$(this).attr('data-d') === sel; }).addClass('hl');
    }
    function list() {
      var rows = data.nodes.slice().sort(function (a, b) { return (a.in - b.in) || ((b.type === 'product') - (a.type === 'product')) || (b.corner - a.corner); }).slice(0, 25);
      $('#ntseoai-lm-list').html(rows.map(function (n) { return '<div class="ntseoai-li" data-id="' + n.id + '"><b>' + esc(n.title) + '</b><span>' + esc(n.type) + ' &middot; ' + n.in + ' inbound &middot; ' + n.out + ' outbound</span></div>'; }).join(''));
      var $s = $('#ntseoai-lm-pick'); $s.find('option:gt(0)').remove();
      data.nodes.slice().sort(function (a, b) { return a.title.localeCompare(b.title); }).forEach(function (n) { $s.append('<option value="' + n.id + '">' + esc(n.title) + ' (' + n.in + ')</option>'); });
    }
    function pick(id) {
      sel = +id; mark(); $('#ntseoai-lm-pick').val(id);
      var n = data.nodes.filter(function (x) { return x.id === sel; })[0]; var $s = $('#ntseoai-lm-side');
      $s.html('<h2>' + esc(n ? n.title : '') + '</h2><p class="ntseoai-help">' + (n ? n.in + ' pages link here, it links to ' + n.out + '.' : '') + ' <a href="' + esc(n && n.edit) + '" target="_blank">Edit page</a> &middot; <a href="#" id="ntseoai-lm-back">Back to list</a></p><div class="ntseoai-inline">' + (NTSEOAI.pro ? '<button type="button" class="ntseoai-btn primary" id="ntseoai-lm-plan" data-t="' + sel + '" data-title="' + esc(n ? n.title : '') + '">&#9889; Smart one-click linking</button>' : '<a class="ntseoai-btn ntseoai-locked" target="_blank" href="' + esc(NTSEOAI.upgrade) + '"><span class="ntseoai-lock">&#128274;</span> Smart one-click linking <span class="ntseoai-protag">PRO</span></a>') + '</div><div id="ntseoai-lm-sug"><p class="ntseoai-hint ntseoai-loading">Looking for pages that mention this topic</p></div>');
      if (!NTSEOAI.pro) { $('#ntseoai-lm-sug').html('<p class="ntseoai-help">Suggestions of pages that should link here, with one-click linking, are part of <a href="' + esc(NTSEOAI.upgrade) + '" target="_blank">NodeThemes SEO AI Pro</a>. You can still open the pages and add links by hand.</p>'); return; }
      NTSEOAIx.post('ntseoai_links_suggest_in', { target: sel }).done(function (r) {
        var h = '<p class="ntseoai-help">Target phrase: <b>' + esc(r.target.kw) + '</b>. These pages mention it but do not link:</p>';
        h += r.items.length ? r.items.map(function (i) { return '<div class="ntseoai-lnk"><div><a href="' + esc(i.edit) + '" target="_blank"><b>' + esc(i.title) + '</b></a><small>' + esc(i.snippet) + '</small><small>Anchor: <mark>' + esc(i.anchor) + '</mark></small></div><button type="button" class="ntseoai-btn sm ntseoai-lm-apply" data-s="' + i.src_id + '" data-a="' + esc(i.anchor) + '">Add link</button></div>'; }).join('') : '<p class="ntseoai-hint">No existing page mentions this topic in a way that can be linked. The best fix is a new helpful article about related subtopics that links here: use the AI writer.</p>';
        $('#ntseoai-lm-sug').html(h);
      }).fail(function (m) { NTSEOAIx.msg($('#ntseoai-lm-sug'), 'err', m); });
    }
    $w.on('click', '#ntseoai-lm-back', function (e) { e.preventDefault(); sel = 0; mark(); $('#ntseoai-lm-side').html('<h2>Needs links</h2><p class="ntseoai-help">Pages with the fewest inbound links.</p><div id="ntseoai-lm-list"></div>'); list(); });
    $w.on('click', '.ntseoai-li', function () { pick($(this).data('id')); });
    $w.on('change', '#ntseoai-lm-pick', function () { if (this.value) { pick(this.value); } });
    $w.on('click', '.ntseoai-lm-apply', function () {
      var $b = $(this); NTSEOAIx.busy($b, true);
      NTSEOAIx.post('ntseoai_links_apply', { source: $b.data('s'), target: sel, anchor: $b.data('a') }).done(function () { $b.replaceWith('<span class="ntseoai-pill good">Added</span>'); }).fail(function (m) { alert(m); NTSEOAIx.busy($b, false); });
    });
    window.NTSEOAIMap = { nodes: function () { return data ? data.nodes : []; } };
    function load() {
      NTSEOAIx.post('ntseoai_links_graph_data', {}).done(function (r) {
        data = r;
        $('#ntseoai-lm-status').text(r.built ? 'Link data built ' + new Date(r.built * 1000).toLocaleString() + ' for ' + r.total + ' pages. It updates automatically when you save pages.' : 'Not scanned yet. Press the button to read all your pages and map their links.');
        $('#ntseoai-lm-scan').text(r.built ? 'Rescan' : "Scan my site's links");
        if (!r.nodes.length) { $('#ntseoai-lm-svg').hide(); return; }
        layout(r.nodes, r.edges); fit(r.nodes); draw(); list(); var f = +$w.data('focus'); if (f) { pick(f); }
      }).fail(function (m) { NTSEOAIx.msg($('#ntseoai-lm-build'), 'err', m); });
    }
    $('#ntseoai-lm-scan').on('click', function () {
      var $b = $(this); NTSEOAIx.busy($b, true);
      (function step(off) { NTSEOAIx.post('ntseoai_links_graph_batch', { offset: off }).done(function (r) { $('#ntseoai-lm-prog').text('Scanned ' + Math.min(r.next, r.total) + ' of ' + r.total); if (r.done) { NTSEOAIx.busy($b, false); load(); } else { step(r.next); } }).fail(function (m) { alert(m); NTSEOAIx.busy($b, false); }); })(0);
    });
    load();
  });
})(jQuery);
