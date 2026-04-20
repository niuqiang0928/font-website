(function () {
  var STORAGE_KEY = 'font_auth_token';

  function getToken() {
    try { return localStorage.getItem(STORAGE_KEY) || ''; } catch (e) { return ''; }
  }

  function setToken(token) {
    try {
      if (token) localStorage.setItem(STORAGE_KEY, token);
    } catch (e) {}
  }

  function clearToken() {
    try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
  }

  function getRedirectUrl() {
    return window.location.pathname + window.location.search + window.location.hash;
  }

  function buildLoginUrl() {
    return '/login.html?redirect=' + encodeURIComponent(getRedirectUrl());
  }

  function buildRegisterUrl() {
    return '/register.html?redirect=' + encodeURIComponent(getRedirectUrl());
  }

  function escapeHtml(str) {
    return String(str || '').replace(/[&<>"']/g, function (m) {
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m];
    });
  }

  function appendToken(url) {
    var token = getToken();
    if (!token) return url;
    return url + (url.indexOf('?') === -1 ? '?' : '&') + 'token=' + encodeURIComponent(token);
  }

  function updateAuthBar(user) {
    var authBar = document.getElementById('authBar');
    if (!authBar) return;

    var linkClass = authBar.querySelector('.auth-btn') ? 'auth-btn' : 'auth-link';

    if (user && user.username) {
      authBar.innerHTML = '<span class="user-name">' + escapeHtml(user.username) + '</span>' +
        '<button type="button" class="' + linkClass + '" id="logoutBtn" style="background:none;cursor:pointer">退出</button>';
      var logoutBtn = document.getElementById('logoutBtn');
      if (logoutBtn) {
        logoutBtn.addEventListener('click', function () {
          fetch('/wp-json/font-auth/v1/logout', { method: 'POST', credentials: 'include' })
            .catch(function () {})
            .finally(function () {
              clearToken();
              window.location.reload();
            });
        });
      }
    } else {
      authBar.innerHTML = '<a href="' + buildLoginUrl() + '" class="' + linkClass + '">登录</a>' +
        '<span style="color:#555">|</span>' +
        '<a href="' + buildRegisterUrl() + '" class="' + linkClass + '">注册</a>';
    }
  }

  function showLoginModal() {
    var modal = document.getElementById('loginModal');
    if (!modal) {
      window.location.href = buildLoginUrl();
      return;
    }
    var loginBtn = modal.querySelector('.login-modal-btn');
    if (loginBtn) loginBtn.href = buildLoginUrl();
    var registerBtn = modal.querySelector('.register-modal-btn');
    if (registerBtn) registerBtn.href = buildRegisterUrl();
    modal.classList.add('active');
  }

  function closeLoginModal() {
    var modal = document.getElementById('loginModal');
    if (modal) modal.classList.remove('active');
  }

  window.closeLoginModal = closeLoginModal;

  function triggerProtectedDownload(url) {
    if (!url) return;
    window.location.href = appendToken(url);
  }

  function bindDownload(user) {
    var btn = document.getElementById('downloadBtn');
    if (!btn || btn.dataset.bound === '1') return;
    btn.dataset.bound = '1';
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      var url = btn.getAttribute('data-url');
      if (!user) {
        showLoginModal();
        return;
      }
      triggerProtectedDownload(url);
    });
  }

  function bindModal() {
    var modal = document.getElementById('loginModal');
    if (!modal || modal.dataset.bound === '1') return;
    modal.dataset.bound = '1';
    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeLoginModal();
    });
  }

  function applyAuth(user) {
    updateAuthBar(user || null);
    bindDownload(user || null);
    bindModal();
  }

  function checkAuth() {
    var token = getToken();
    var headers = {};
    if (token) headers.Authorization = 'Bearer ' + token;

    fetch('/wp-json/font-auth/v1/me', {
      method: 'GET',
      credentials: 'include',
      headers: headers
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data && data.logged_in) {
        if (data.token) setToken(data.token);
        applyAuth(data.user || null);
      } else {
        if (token) clearToken();
        applyAuth(null);
      }
    })
    .catch(function () {
      applyAuth(null);
    });
  }

  window.fontAuth = {
    getToken: getToken,
    setToken: setToken,
    clearToken: clearToken,
    checkAuth: checkAuth,
    showLoginModal: showLoginModal,
    closeLoginModal: closeLoginModal
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', checkAuth);
  } else {
    checkAuth();
  }
})();
