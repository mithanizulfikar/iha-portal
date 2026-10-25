// Live marks calculator. Mirrors MarksCalculator.php (integer cents, half-up rounding);
// the server recalculates everything when the form is saved.
(function () {
  var body = document.getElementById('subject-rows');
  var tpl = document.getElementById('subject-row-template');
  if (!body || !tpl) { return; }

  function cents(s) {
    s = (s || '').trim();
    var m = /^(\d{1,6})(?:\.(\d{1,2}))?$/.exec(s);
    if (!m) { return null; }
    var frac = m[2] ? (m[2] + '00').slice(0, 2) : '00';
    return parseInt(m[1], 10) * 100 + parseInt(frac, 10);
  }
  function fmt(c) { return Math.floor(c / 100) + '.' + ('0' + (c % 100)).slice(-2); }
  function pct(o, t) {
    var h = Math.floor((o * 20000 + t) / (2 * t));
    return Math.floor(h / 100) + '.' + ('0' + (h % 100)).slice(-2);
  }

  function recalc() {
    var totalSum = 0, obtainedSum = 0, any = false;
    body.querySelectorAll('tr').forEach(function (row) {
      var inputs = row.querySelectorAll('input');
      var name = inputs[0].value.trim(), tRaw = inputs[1].value.trim(), oRaw = inputs[2].value.trim();
      var out = row.querySelector('[data-percent]');
      if (name === '' && tRaw === '' && oRaw === '') { out.textContent = ''; return; }
      var t = tRaw === '' ? null : cents(tRaw);
      var o = oRaw === '' ? null : cents(oRaw);
      if (name === '' || (tRaw !== '' && (t === null || t <= 0)) || (oRaw !== '' && o === null) || (t !== null && o !== null && o > t)) {
        out.textContent = 'Check values';
      } else if (t === null || o === null) {
        out.textContent = 'Incomplete';
      } else {
        out.textContent = pct(o, t) + '%';
        totalSum += t; obtainedSum += o; any = true;
      }
    });
    document.getElementById('total-sum').textContent = fmt(totalSum);
    document.getElementById('obtained-sum').textContent = fmt(obtainedSum);
    document.getElementById('overall-percent').textContent = any && totalSum > 0 ? pct(obtainedSum, totalSum) + '%' : '\u2014';
  }

  document.addEventListener('input', function (ev) { if (body.contains(ev.target)) { recalc(); } });
  document.addEventListener('click', function (ev) {
    if (ev.target.closest('[data-add-subject]')) {
      body.appendChild(tpl.content.cloneNode(true));
      recalc();
      return;
    }
    var rm = ev.target.closest('[data-remove-subject]');
    if (rm) {
      var row = rm.closest('tr');
      if (body.querySelectorAll('tr').length > 1) { row.remove(); }
      else { row.querySelectorAll('input').forEach(function (i) { i.value = ''; }); }
      recalc();
    }
  });
  recalc();
})();
