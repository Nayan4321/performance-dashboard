// Remember light/dark choice; clear the bell badge once the dropdown is opened.
(function () {
    var toggle = document.getElementById('light-dark-mode');
    if (toggle) toggle.addEventListener('click', function () {
        try { localStorage.setItem('theme', document.documentElement.getAttribute('data-bs-theme')); } catch (e) {}
        document.dispatchEvent(new CustomEvent('themechange'));
    });

    var bell = document.getElementById('notif-toggle');
    if (bell) bell.addEventListener('shown.bs.dropdown', function () {
        var badge = bell.querySelector('.alert-badge');
        if (!badge) return;
        var token = document.querySelector('meta[name=csrf-token]');
        fetch(bell.dataset.readUrl, {method: 'POST', headers: {'X-CSRF-TOKEN': token ? token.content : '', 'Accept': 'application/json'}})
            .then(function () { badge.remove(); }).catch(function () {});
    });
})();
