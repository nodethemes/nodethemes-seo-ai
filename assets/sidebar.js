/* Block-editor sidebar: live scores next to the editor */
(function (wp, $) {
  'use strict';
  if (!wp || !wp.plugins || !wp.editPost || !wp.element) { return; }
  var el = wp.element.createElement, useState = wp.element.useState, useEffect = wp.element.useEffect;
  var Sidebar = wp.editPost.PluginSidebar, More = wp.editPost.PluginSidebarMoreMenuItem, Btn = wp.components.Button;
  var col = function (v) { return v >= 80 ? '#16a34a' : (v >= 50 ? '#d97706' : '#dc2626'); };
  function Panel() {
    var st = useState(window.NRAState || null), s = st[0], set = st[1];
    useEffect(function () { var f = function () { set(window.NRAState ? Object.assign({}, window.NRAState) : null); }; $(document).on('nra:state', f); return function () { $(document).off('nra:state', f); }; }, []);
    var probs = s ? s.checks.concat(s.geo_checks).filter(function (c) { return c.status !== 'good' && c.w > 0; }).sort(function (a, b) { return (a.status === 'bad' ? 0 : 1) - (b.status === 'bad' ? 0 : 1); }).slice(0, 6) : [];
    var click = function (sel) { return function () { var e = document.querySelector(sel); if (e) { e.click(); } }; };
    return el('div', { style: { padding: '0 16px 16px' } },
      s ? el('div', { className: 'nra-side-score' }, el('div', { style: { background: col(s.score) } }, s.score, el('small', null, 'SEO')), el('div', { style: { background: col(s.geo_score) } }, s.geo_score, el('small', null, 'AI search'))) : el('p', null, 'Analysing...'),
      el(Btn, { variant: 'primary', onClick: click('#nra-autopilot'), style: { width: '100%', justifyContent: 'center', marginBottom: 8 } }, 'Optimize with AI'),
      document.querySelector('#nra-fix') ? el(Btn, { variant: 'secondary', onClick: click('#nra-fix'), style: { width: '100%', justifyContent: 'center', marginBottom: 8 } }, 'Fix everything (aim for 100)') : null,
      el(Btn, { variant: 'tertiary', onClick: function () { var e = document.querySelector('.nra-mb'); if (e) { e.scrollIntoView({ behavior: 'smooth' }); } }, style: { width: '100%', justifyContent: 'center' } }, 'Open the full panel'),
      el('h4', null, 'To improve'), probs.length ? probs.map(function (c, i) { return el('div', { key: i, className: 'nra-side-ck' }, el('b', null, c.label), el('br'), c.msg); }) : el('p', null, 'Nothing left to improve.'));
  }
  wp.plugins.registerPlugin('nodethemes-seo-ai', { icon: 'chart-area', render: function () {
    return el(wp.element.Fragment, null, el(More, { target: 'nra-side' }, 'NodeThemes SEO AI'), el(Sidebar, { name: 'nra-side', title: 'NodeThemes SEO AI', icon: 'chart-area' }, el(Panel)));
  } });
})(window.wp, jQuery);
