(function () {
  var box = document.getElementById('perm_same_as_current');
  var block = document.getElementById('perm-block');
  if (!box || !block) { return; }
  box.addEventListener('change', function () { block.classList.toggle('d-none', box.checked); });
})();
