(function () {
    var config = window.SuiteAdminThemeConfig || { name: 'suite-theme', domain: '' };
    var COOKIE = /^[A-Za-z][A-Za-z0-9_-]{0,63}$/.test(config.name) ? config.name : 'suite-theme';
    var DOMAIN = typeof config.domain === 'string' && /^[.]?[A-Za-z0-9.-]+$/.test(config.domain) ? config.domain : '';
    var root = document.documentElement;
    var styleTimer = 0;

    function getTheme() {
        var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + COOKIE + '=(dark|light)(?:;|$)'));
        if (m) { return m[1]; }
        try {
            var ls = localStorage.getItem(COOKIE);
            if (ls === 'dark' || ls === 'light') { return ls; }
        } catch (e) {}
        return (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
    }

    function setTheme(t, animate) {
        if (animate) {
            root.classList.add('lg-theme-switching');
            clearTimeout(styleTimer);
        }
        root.setAttribute('data-theme', t);
        try { localStorage.setItem(COOKIE, t); } catch (e) {}
        document.cookie = COOKIE + '=' + t + '; Max-Age=31536000; Path=/' + (DOMAIN ? '; Domain=' + DOMAIN : '') + '; SameSite=Lax; Secure';
        var b = document.querySelector('.lg-theme-toggle');
        if (b) {
            b.textContent = '◐';
            b.setAttribute('title', t === 'dark' ? '切换到浅色主题' : '切换到深色主题');
            b.setAttribute('aria-pressed', t === 'dark' ? 'true' : 'false');
            if (animate) {
                b.classList.remove('is-rotating');
                void b.offsetWidth;
                b.classList.add('is-rotating');
                setTimeout(function () { b.classList.remove('is-rotating'); }, 700);
            }
        }
        if (animate) {
            styleTimer = setTimeout(function () {
                root.classList.remove('lg-theme-switching');
                styleTimer = 0;
            }, 280);
        }
    }

    function init() {
        var op = document.querySelector('.typecho-head-nav .operate');
        if (op && !op.querySelector('.lg-theme-toggle')) {
            var b = document.createElement('button');
            b.className = 'lg-theme-toggle theme-toggle';
            b.type = 'button';
            b.setAttribute('aria-label', '切换主题');
            b.addEventListener('click', function (e) {
                e.preventDefault();
                var cur = root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
                setTheme(cur === 'dark' ? 'light' : 'dark', true);
            });
            op.appendChild(b);
        }
        setTheme(getTheme(), false);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
