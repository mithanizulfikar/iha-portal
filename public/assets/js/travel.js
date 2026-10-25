(function () {
  var box = document.getElementById('travel-records');
  var list = document.getElementById('record-list');
  var tpl = document.getElementById('record-template');
  if (!box || !list || !tpl) { return; }

  function anyYes() {
    return !!document.querySelector('input[name^="ans_"][value="1"]:checked');
  }
  function sync() { box.classList.toggle('d-none', !anyYes()); }

  document.addEventListener('change', function (ev) {
    if (ev.target.name && ev.target.name.indexOf('ans_') === 0) { sync(); }
  });
  document.addEventListener('click', function (ev) {
    if (ev.target.closest('[data-add-record]')) {
      list.appendChild(tpl.content.cloneNode(true));
      return;
    }
    var rm = ev.target.closest('[data-remove-record]');
    if (rm) { rm.closest('.travel-record').remove(); }
  });
  sync();
})();
