(function () {
  var ajaxurl = (window.cnAdmin && cnAdmin.ajaxurl) || '/wp-admin/admin-ajax.php';
  var nonce = (window.cnAdmin && cnAdmin.nonce) || '';

  function showFlash(msg, type) {
    var old = document.querySelector('.cn-flash');
    if (old) old.remove();
    var div = document.createElement('div');
    div.className = 'cn-flash cn-flash--' + (type || 'success');
    div.textContent = msg;
    var wrap = document.querySelector('.cn-admin');
    if (wrap) wrap.insertBefore(div, wrap.firstChild);
    setTimeout(function () { div.remove(); }, 5000);
  }

  function ajaxPost(action, data, cb) {
    var fd = new FormData();
    fd.append('action', 'cn_ajax_' + action);
    fd.append('_wpnonce', nonce);
    for (var k in data) {
      if (data[k] instanceof FileList || data[k] instanceof File) continue;
      fd.append(k, data[k]);
    }
    fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) { cb(res); })
      .catch(function () { cb({ success: false, data: { msg: 'خطای ارتباط' } }); });
  }

  // Confirm dialogs
  document.addEventListener('click', function (e) {
    var a = e.target.closest('[data-cn-confirm]');
    if (a && !window.confirm(a.getAttribute('data-cn-confirm'))) {
      e.preventDefault();
    }
  });

  // Panel type switching
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

  // Intercept forms
  document.addEventListener('submit', function (e) {
    var form = e.target.closest('.cn-form');
    if (!form) return;
    e.preventDefault();
    var action = form.querySelector('input[name="action"]');
    if (!action) return;
    var act = action.value.replace('cn_', '');
    var data = {};
    new FormData(form).forEach(function (v, k) { data[k] = v; });
    var btn = form.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; btn.textContent = 'در حال ذخیره...'; }
    ajaxPost(act, data, function (res) {
      if (btn) { btn.disabled = false; btn.textContent = 'ذخیره'; }
      showFlash(res.success ? (res.data.msg || 'ذخیره شد') : (res.data.msg || 'خطا'), res.success ? 'success' : 'error');
      if (res.success && res.data.redirect) {
        setTimeout(function () { location.href = res.data.redirect; }, 600);
      }
    });
  });

  // AJAX action links (delete, test, etc.)
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href*="admin-post.php"][href*="action=cn_"]');
    if (!a) return;
    if (a.hasAttribute('data-cn-confirm') && !window.confirm(a.getAttribute('data-cn-confirm'))) return;
    e.preventDefault();
    var url = new URL(a.href);
    var act = url.searchParams.get('action').replace('cn_', '');
    var data = {};
    url.searchParams.forEach(function (v, k) { if (k !== 'action' && k !== '_wpnonce') data[k] = v; });
    a.style.opacity = '0.5';
    ajaxPost(act, data, function (res) {
      a.style.opacity = '1';
      showFlash(res.success ? (res.data.msg || 'انجام شد') : (res.data.msg || 'خطا'), res.success ? 'success' : 'error');
      if (res.success) {
        var tr = a.closest('tr');
        if (tr && (act.indexOf('delete') !== -1)) tr.remove();
      }
    });
  });

  // Test all
  var testAll = document.querySelector('[data-cn-test-all]');
  if (testAll) {
    testAll.addEventListener('click', function (e) {
      e.preventDefault();
      document.querySelectorAll('[data-cn-test]').forEach(function (btn, i) {
        setTimeout(function () { btn.click(); }, i * 700);
      });
    });
  }

  // Dedicated AJAX test
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-cn-test]');
    if (!btn) return;
    e.preventDefault();
    var id = btn.getAttribute('data-cn-test');
    var cell = btn.closest('tr') ? btn.closest('tr').querySelector('.cn-test-result') : null;
    btn.disabled = true;
    var orig = btn.textContent;
    btn.textContent = 'در حال تست...';
    var fd = new FormData();
    fd.append('action', 'cn_ajax_test_panel');
    fd.append('id', id);
    fd.append('_wpnonce', nonce);
    fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        btn.disabled = false;
        btn.textContent = orig;
        if (cell) {
          cell.innerHTML = res.success
            ? '<span class="cn-badge cn-badge--ok">موفق</span> <span class="cn-muted">' + (res.data && res.data.msg ? res.data.msg : '') + '</span>'
            : '<span class="cn-badge cn-badge--off">خطا</span> <span class="cn-muted">' + (res.data && res.data.msg ? res.data.msg : 'ناموفق') + '</span>';
        }
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = orig;
        if (cell) cell.innerHTML = '<span class="cn-badge cn-badge--off">خطا</span>';
      });
  });

})();
