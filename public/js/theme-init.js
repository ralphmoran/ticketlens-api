(function () {
    var theme = localStorage.getItem('tl-theme');
    var authPaths = [
        '/console/login',
        '/console/register',
        '/console/verify-email',
        '/console/forgot-password',
        '/console/reset-password',
    ];
    var isAuthPage = authPaths.some(function (path) {
        return window.location.pathname === path || window.location.pathname.startsWith(path + '/');
    });
    if (theme === 'light' || isAuthPage) {
        document.documentElement.setAttribute('data-theme', 'light');
    }

    var fontPreload = document.getElementById('tl-font-preload');
    if (fontPreload) {
        fontPreload.onload = function () {
            fontPreload.onload = null;
            fontPreload.rel = 'stylesheet';
        };
    }
})();
