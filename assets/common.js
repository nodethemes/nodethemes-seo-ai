/* NodeThemes SEO AI shared helpers */
(function ($) {
  'use strict';
  window.NTSEOAIx = {
    esc: function (s) { return $('<div>').text(s == null ? '' : String(s)).html(); },
    slow: ['ntseoai_ai_keywords', 'ntseoai_ai_meta', 'ntseoai_ai_short', 'ntseoai_ai_article', 'ntseoai_ai_faq', 'ntseoai_ai_coach', 'ntseoai_ai_autopilot', 'ntseoai_ai_fix', 'ntseoai_ext_suggest', 'ntseoai_ins_data', 'ntseoai_ins_report', 'ntseoai_ins_rewrite', 'ntseoai_ins_setup', 'ntseoai_ai_outline', 'ntseoai_ai_section', 'ntseoai_links_plan', 'ntseoai_links_suggest_in', 'ntseoai_links_suggest_out', 'ntseoai_prompt_run', 'ntseoai_prompt_suggest', 'ntseoai_suggest_summary'],
    // slow AI calls run as a background job and are polled, so no request stays open while the model thinks
    job: function (action, data) {
      var d = $.Deferred(), t0 = Date.now(), fails = 0;
      NTSEOAIx.raw('ntseoai_job_start', $.extend({ job_action: action }, data || {})).done(function (r) {
        var poll = function () {
          if (Date.now() - t0 > 600000) { d.reject('This took too long. Choose a faster model in Settings and try again.'); return; }
          NTSEOAIx.raw('ntseoai_job_status', { job: r.job }).done(function (s) {
            if (s.state === 'done') { d.resolve(s.data || {}); } else { setTimeout(poll, 1500); }
          }).fail(function (m, hard) {
            // a status check that died (host limit) is retried: the job record then holds the real reason
            if (hard && fails < 3) { fails++; setTimeout(poll, 1500); } else { d.reject(m); }
          });
        };
        setTimeout(poll, 1200);
      }).fail(function (m) { d.reject(m); });
      return d.promise();
    },
    post: function (action, data) {
      if (NTSEOAIx.slow.indexOf(action) >= 0) { return NTSEOAIx.job(action, data); }
      return NTSEOAIx.raw(action, data);
    },
    raw: function (action, data) {
      var d = $.Deferred();
      $.ajax({ url: NTSEOAI.ajax, type: 'POST', dataType: 'json', timeout: 190000, data: $.extend({ action: action, nonce: NTSEOAI.nonce }, data || {}) })
        .done(function (r) {
          if (r && r.success) { d.resolve(r.data || {}); }
          else { d.reject((r && r.data && r.data.message) ? r.data.message : 'Request failed.'); }
        })
        .fail(function (x, st) {
          var i = (x.responseText || '').indexOf('{"success":false');
          if (i >= 0) { try { var j = JSON.parse(x.responseText.slice(i)); if (j && j.data && j.data.message) { d.reject(j.data.message); return; } } catch (e) {} }
          var m = st === 'timeout' ? 'The request timed out. Try again or use a faster model.' : (x.status === 0 ? 'Network error.' : 'Server error ' + x.status + '. ' + (x.status >= 500 ? 'The server stopped the request, usually because the AI answered too slowly for your hosting time limit. Choose a faster model (for example gpt-4o-mini or gpt-4.1-mini) in Settings and try again.' : 'Check the PHP error log.'));
          if (x.responseText === '0' || x.responseText === '-1') { m = 'Session expired. Reload the page.'; }
          d.rejectWith(null, [m, !(x.responseJSON)]);
        });
      return d.promise();
    },
    busy: function ($b, on) {
      $b.prop('disabled', !!on).toggleClass('ntseoai-loading', !!on);
      var el = $b.get(0); if (!el) { return; }
      if (on) {
        if ($b.data('ntseoaiProg')) { return; }
        var $p = $('<div class="ntseoai-prog" role="progressbar" aria-valuemin="0" aria-valuemax="100"><div class="ntseoai-prog-track"><div class="ntseoai-prog-bar"></div></div><div class="ntseoai-prog-txt"></div></div>');
        $b.parent().after($p); var t0 = Date.now();
        var stages = [[0, 'Reading the page'], [3, 'Sending it to the AI'], [8, 'The AI is writing'], [25, 'Still working, long pages take longer'], [50, 'Almost there, slow models can take a minute']];
        var tick = function () {
          var cur = $b.data('ntseoaiProg'), t = (Date.now() - t0) / 1000, pct = Math.min(95, Math.round(95 * (1 - Math.exp(-t / 16)))), lab = stages[0][1];
          for (var i = 0; i < stages.length; i++) { if (t >= stages[i][0]) { lab = stages[i][1]; } }
          if (cur && cur.manual) { pct = cur.pct; lab = cur.lab; }
          $p.attr('aria-valuenow', pct).find('.ntseoai-prog-bar').css('width', pct + '%'); $p.find('.ntseoai-prog-txt').text(pct + '%  ' + lab + '  (' + Math.floor(t) + 's)');
        };
        tick(); $b.data('ntseoaiProg', { el: $p, iv: setInterval(tick, 400) });
      } else {
        var d = $b.data('ntseoaiProg'); if (!d) { return; }
        clearInterval(d.iv); $b.removeData('ntseoaiProg'); d.el.find('.ntseoai-prog-bar').css('width', '100%'); d.el.find('.ntseoai-prog-txt').text('100%  Done');
        setTimeout(function () { d.el.fadeOut(300, function () { d.el.remove(); }); }, 700);
      }
    },
    // real progress for multi-step jobs (overrides the time-based estimate)
    progress: function ($b, pct, lab) { var d = $b.data('ntseoaiProg'); if (d) { d.manual = true; d.pct = Math.max(0, Math.min(100, Math.round(pct))); d.lab = lab || ''; } },
    msg: function ($el, type, text) { $el.html('<div class="ntseoai-flash ' + type + '">' + NTSEOAIx.esc(text) + '</div>'); }
  };
})(jQuery);
