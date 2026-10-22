(function () {
  document.addEventListener('click', function (e) {
    var a = e.target.closest('[data-cn-confirm]');
    if (a && !window.confirm(a.getAttribute('data-cn-confirm'))) {
      e.preventDefault();
    }
  });

  function show(type) {
    document.querySelectorAll('.cn-type').forEach(function (el) {
      el.classList.toggle('is-on', el.getAttribute('data-cn-type') === type);
    });
    document.querySelectorAll('[data-cn-hide]').forEach(function (el) {
      el.hidden = el.getAttribute('data-cn-hide').split(',').indexOf(type) !== -1;
    });
    document.querySelectorAll('[data-cn-show]').forEach(function (el) {
      el.hidden = el.getAttribute('data-cn-show').split(',').indexOf(type) === -1;
    });
  }

  var typeSel = document.querySelector('.cn-form select[name="type"]');
  if (typeSel) {
    typeSel.addEventListener('change', function () { show(typeSel.value); });
    show(typeSel.value);
  }

  var map = document.querySelector('[data-cn-panel-types]');
  var panelSel = document.querySelector('.cn-form select[name="panel_id"]');
  if (map && panelSel) {
    var types = JSON.parse(map.getAttribute('data-cn-panel-types') || '{}');
    var sync = function () { show(types[panelSel.value] || ''); };
    panelSel.addEventListener('change', sync);
    sync();
  }
})();
