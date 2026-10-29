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

  // AJAX test single panel
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-cn-test]');
    if (!btn) return;
    e.preventDefault();
    var id = btn.getAttribute('data-cn-test');
    var cell = btn.closest('tr').querySelector('.cn-test-result');
    btn.disabled = true;
    btn.textContent = 'در حال تست...';
    var fd = new FormData();
    fd.append('action', 'cn_ajax_test_panel');
    fd.append('id', id);
    fd.append('_wpnonce', cnAdmin.nonce);
    fetch(cnAdmin.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        btn.disabled = false;
        btn.textContent = 'تست اتصال';
        if (cell) {
          cell.innerHTML = res.success
            ? '<span class="cn-badge cn-badge--ok">موفق</span> <span class="cn-muted">' + (res.data.msg || '') + '</span>'
            : '<span class="cn-badge cn-badge--off">خطا</span> <span class="cn-muted">' + (res.data.msg || 'ناموفق') + '</span>';
        }
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = 'تست اتصال';
        if (cell) cell.innerHTML = '<span class="cn-badge cn-badge--off">خطا</span>';
      });
  });

  // AJAX test all panels
  var testAll = document.querySelector('[data-cn-test-all]');
  if (testAll) {
    testAll.addEventListener('click', function (e) {
      e.preventDefault();
      var buttons = document.querySelectorAll('[data-cn-test]');
      var i = 0;
      function next() {
        if (i >= buttons.length) return;
        buttons[i].click();
        i++;
        setTimeout(next, 800);
      }
      next();
    });
  }
})();
