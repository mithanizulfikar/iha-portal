// Convenience only; the server validates everything again.
document.addEventListener('click', function (ev) {
  var btn = ev.target.closest('[data-toggle-password]');
  if (!btn) { return; }
  var input = document.getElementById(btn.getAttribute('data-toggle-password'));
  if (!input) { return; }
  var show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  btn.textContent = show ? 'Hide' : 'Show';
});

(function () {
  var form = document.getElementById('register-form');
  if (!form) { return; }
  var pw = document.getElementById('password');
  var confirm = document.getElementById('password_confirm');
  function check() {
    confirm.classList.toggle('is-invalid', confirm.value !== '' && confirm.value !== pw.value);
  }
  pw.addEventListener('input', check);
  confirm.addEventListener('input', check);
})();

document.addEventListener('click', function (ev) {
  if (ev.target.closest('[data-print]')) { window.print(); }
});
