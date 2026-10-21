/* =============================================================================
   SARMAD Attendance - manager panel behaviour.
   Progressive enhancement only: every page works without this file.
   ========================================================================== */
(function () {
    'use strict';

    var body = document.body;

    /* ---------------------------------------------------------- sidebar -- */
    var burger = document.getElementById('m-burger');
    var backdrop = document.getElementById('m-backdrop');
    var sidebar = document.getElementById('m-sidebar');

    function closeNav() {
        body.classList.remove('nav-open');
        if (backdrop) { backdrop.hidden = true; }
        if (burger) { burger.setAttribute('aria-expanded', 'false'); }
    }

    if (burger && sidebar) {
        burger.addEventListener('click', function () {
            var open = body.classList.toggle('nav-open');
            if (backdrop) { backdrop.hidden = !open; }
            burger.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    if (backdrop) { backdrop.addEventListener('click', closeNav); }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { closeNav(); }
    });

    /* ------------------------------------------------- confirm on submit -- */
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !form.getAttribute) { return; }
        var message = form.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });

    /* ------------------------------------------------------------- copy -- */
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest ? event.target.closest('[data-copy]') : null;
        if (!trigger) { return; }
        var value = trigger.getAttribute('data-copy') || '';
        if (!value || !navigator.clipboard) { return; }
        event.preventDefault();
        navigator.clipboard.writeText(value).then(function () {
            var original = trigger.textContent;
            trigger.textContent = 'کپی شد';
            window.setTimeout(function () { trigger.textContent = original; }, 1600);
        });
    });

    /* ------------------------------------------------ auto submit filters -- */
    document.querySelectorAll('[data-autosubmit]').forEach(function (field) {
        field.addEventListener('change', function () {
            var form = field.closest('form');
            if (form) { form.submit(); }
        });
    });

    /* -------------------------------------------------- client side search -- */
    document.querySelectorAll('[data-table-filter]').forEach(function (input) {
        var table = document.querySelector(input.getAttribute('data-table-filter'));
        if (!table) { return; }
        input.addEventListener('input', function () {
            var needle = input.value.trim().toLowerCase();
            table.querySelectorAll('tbody tr').forEach(function (row) {
                row.hidden = needle !== '' && row.textContent.toLowerCase().indexOf(needle) === -1;
            });
        });
    });

    /* -------------------------------------------------------- row toggling -- */
    document.querySelectorAll('[data-open-editor]').forEach(function (trigger) {
        trigger.addEventListener('click', function (event) {
            var id = trigger.getAttribute('data-open-editor');
            var target = document.getElementById(id);
            if (!target) { return; }
            event.preventDefault();
            var hidden = target.hasAttribute('hidden');
            if (hidden) { target.removeAttribute('hidden'); } else { target.setAttribute('hidden', 'hidden'); }
        });
    });

    /* --------------------------------------------- max length live counter -- */
    document.querySelectorAll('[data-counter]').forEach(function (field) {
        var output = document.querySelector(field.getAttribute('data-counter'));
        if (!output) { return; }
        var update = function () { output.textContent = String(field.value.length); };
        field.addEventListener('input', update);
        update();
    });
})();
