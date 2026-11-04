/* NodeThemes SEO AI - Auto Writer (plan -> sections -> finish) */
(function ($) {
  'use strict';
  var esc = NRAx.esc;
  NRAx.slow.push('nra_aw_plan', 'nra_aw_section', 'nra_aw_finish');

  /** runs one page from start to end; onStep(pct, label) reports progress */
  function run(params, onStep) {
    var d = $.Deferred();
    onStep(4, 'Planning the page: keyword, titles, outline, table and FAQ');
    NRAx.post('nra_aw_plan', params).done(function (plan) {
      var total = plan.sections, i = 0, pid = plan.post_id;
      var next = function () {
        if (i >= total) {
          onStep(88, 'Adding links, checking sources and repairing what still fails');
          NRAx.post('nra_aw_finish', { post_id: pid, live: params.live ? 1 : '' }).done(function (r) { d.resolve($.extend({ plan: plan }, r)); }).fail(d.reject);
          return;
        }
        onStep(10 + Math.round(75 * i / total), 'Writing section ' + (i + 1) + ' of ' + total);
        NRAx.post('nra_aw_section', { post_id: pid, i: i }).done(function () { i++; next(); }).fail(function (m) {
          // one retry per section: slow models sometimes time out once
          NRAx.post('nra_aw_section', { post_id: pid, i: i }).done(function () { i++; next(); }).fail(function () { d.reject(m); });
        });
      };
      next();
    }).fail(d.reject);
    return d.promise();
  }

  function report(r) {
    var h = '<div class="nra-flash ' + (r.score >= 98 && r.geo >= 98 ? 'ok' : 'warn') + '"><b>SEO ' + r.score + ' / AI search ' + r.geo + '</b> &middot; ' + (r.words || 0) + ' words';
    if (r.status) { h += ' &middot; ' + (r.status === 'publish' ? 'published' : 'saved as a draft'); }
    if (r.staged) { h += ' &middot; waiting for your review in the editor (the live page is unchanged)'; }
    h += '</div>';
    if (r.why_draft) { h += '<p class="nra-hint">' + esc(r.why_draft) + '</p>'; }
    if ((r.applied || []).length) { h += '<p><b>Done:</b> ' + r.applied.map(esc).join(' &middot; ') + '</p>'; }
    if ((r.inbound || []).length) { h += '<p><b>Linked from:</b> ' + r.inbound.map(esc).join(', ') + '</p>'; }
    if (r.gaps) { h += '<div class="nra-flash warn">Fill the ' + r.gaps + ' place(s) marked <code>[ADD: ...]</code> before publishing.</div>'; }
    if ((r.missing || []).length) { h += '<p class="nra-hint"><b>Facts that would make it stronger:</b> ' + r.missing.map(esc).join(' &middot; ') + '</p>'; }
    if ((r.left || []).length) { h += '<p class="nra-hint"><b>Still open:</b> ' + r.left.map(esc).join(' &middot; ') + '</p>'; }
    return h;
  }

  window.NRAW = { run: run, report: report };

  $(function () {
    // ---------------------------------------------------------- Auto Writer page
    var $run = $('#nra-aw-run');
    if ($run.length) {
      $run.on('click', function () {
        var $o = $('#nra-aw-steps'), $w = $('#nra-aw-words option:selected');
        if ($w.data('lock')) { window.open(NRA.upgrade, '_blank'); return; }
        var topic = $.trim($('#nra-aw-topic').val());
        if (!topic) { NRAx.msg($o, 'warn', 'Write the topic first.'); $('#nra-aw-topic').focus(); return; }
        NRAx.busy($run, true); $o.html('');
        run({ topic: topic, kw: $.trim($('#nra-aw-kw').val()), kind: $('#nra-aw-kind').val(), words: $w.val(), cat: $('#nra-aw-cat').val(), notes: $('#nra-aw-notes').val(), target: 'save' }, function (pct, lab) { NRAx.progress($run, pct, lab); })
          .done(function (r) {
            $o.html('<h3>' + esc(r.plan.title) + '</h3>' + report(r) + '<p><a class="nra-btn primary" href="' + esc(r.edit) + '">Open in the editor</a> ' + (r.view ? '<a class="nra-btn" target="_blank" rel="noopener" href="' + esc(r.view) + '">' + (r.status === 'publish' ? 'View' : 'Preview') + '</a>' : '') + '</p>');
          })
          .fail(function (m) { NRAx.msg($o, 'err', m); })
          .always(function () { NRAx.busy($run, false); });
      });
      $('#nra-aw-save-prefs').on('click', function () {
        var $m = $('#nra-aw-prefs-msg');
        NRAx.raw('nra_aw_prefs', { mode: $('input[name=nra_aw_mode]:checked').val(), inbound: $('#nra-aw-inbound').is(':checked') ? 'yes' : 'no', ext: $('#nra-aw-ext').is(':checked') ? 'yes' : 'no', words: $('#nra-aw-words').val() })
          .done(function () { $m.text('Saved.'); }).fail(function (m) { $m.text(m); });
      });
    }

    // ---------------------------------------------------------- editor
    var $go = $('#nra-aw-go');
    if (!$go.length || !window.NRAEditor) { return; }
    var E = window.NRAEditor;

    function fill(d) {
      E.setContent(d.content, false);
      if (d.title) { E.setTitle(d.title); }
      if (d.slug) { E.setSlug(d.slug); }
      if (d.excerpt) { E.setExcerpt(d.excerpt); }
      $('#nra_focuskw').val(d.kw || '');
      $('#nra_title').val(d.seo_title || '');
      $('#nra_desc').val(d.desc || '');
      $('input[name=nra_og_title]').val(d.og_title || '');
      $('textarea[name=nra_og_desc]').val(d.og_desc || '');
      if (d.schema_type && !$('select[name=nra_schema_type]').val()) { $('select[name=nra_schema_type]').val(d.schema_type); }
      if ((d.faq || []).length) { $('#nra-faq-list').empty(); d.faq.forEach(function (f) { E.addFaq(f.q, f.a); }); }
      if ((d.takeaways || []).length) { $('#nra-tk-list').empty(); d.takeaways.forEach(function (t) { E.addTk(t); }); }
      $('#nra_focuskw, #nra_title, #nra_desc').trigger('input');
      setTimeout(function () { E.analyze(true); }, 400);
    }

    $go.on('click', function () {
      var $o = $('#nra-aw-out'), $w = $('#nra-aw-words option:selected');
      if ($w.data('lock')) { window.open(NRA.upgrade, '_blank'); return; }
      var title = $.trim(E.getTitle()), notes = $.trim($('#nra-w-notes').val());
      if (/^auto.?draft$/i.test(title)) { title = ''; }
      if (!title && !notes) { NRAx.msg($o, 'warn', 'Type a title (or a few notes in "Facts & notes") first, so the writer knows the topic.'); return; }
      var cur = $.trim($('<div>').html(E.getContent()).text());
      if (cur && !window.confirm('The writer will replace the text and the SEO fields in this editor with a new version. Nothing is saved until you click Save / Update. Continue?')) { return; }
      NRAx.busy($go, true); $o.html('');
      run({ post_id: E.pid, topic: title, kw: $.trim($('#nra_focuskw').val()), kind: $go.data('kind'), words: $w.val(), notes: notes, target: 'editor' }, function (pct, lab) { NRAx.progress($go, pct, lab); })
        .done(function (r) {
          if (r.data) { fill(r.data); }
          $o.html(report(r) + '<div class="nra-flash ok">Review the text and the fields, then click Save / Update.</div>');
        })
        .fail(function (m) { NRAx.msg($o, 'err', m); })
        .always(function () { NRAx.busy($go, false); });
    });

    // a result written in bulk (or by the schedule) for a live page
    $('#nra-aw-load').on('click', function () {
      var d = $('#nra-aw-staged').data('staged');
      if (!d || !window.confirm('Load the Auto Writer result into the editor? It replaces the current text and SEO fields. Click Update afterwards to make it live.')) { return; }
      fill(d); $('#nra_aw_clear').val('1');
      $('#nra-aw-staged').removeClass('ok').addClass('warn').html('Loaded. Review it, then click <b>Update</b> to make it live.');
    });
    $('#nra-aw-discard').on('click', function () {
      if (!window.confirm('Discard this Auto Writer result?')) { return; }
      NRAx.raw('nra_aw_discard', { post_id: E.pid }).done(function () { $('#nra-aw-staged').remove(); });
    });

    // opened from the Auto Writer page ("Open & write")
    if (/[?&]nra_aw=1/.test(window.location.search)) {
      $('.nra-tab[data-t=write]').click();
      setTimeout(function () { var el = document.getElementById('nra-aw-go'); if (el && el.scrollIntoView) { el.scrollIntoView({ block: 'center' }); } $go.addClass('nra-pulse'); }, 600);
    }
  });
})(jQuery);
