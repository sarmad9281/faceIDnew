/* =====================================================================
   SARMAD Attendance - owner panel front-end helpers
   No dependencies, no CDN. Everything degrades gracefully without JS
   (forms still submit, links still work).
   ===================================================================== */
(function () {
    'use strict';

    /* ---------------------------------------------------- Mobile sidebar */
    var sidebar  = document.getElementById('o-sidebar');
    var burger   = document.getElementById('o-burger');
    var backdrop = document.getElementById('o-backdrop');

    function toggleSidebar(force) {
        if (!sidebar) {
            return;
        }

        var open = typeof force === 'boolean' ? force : !sidebar.classList.contains('is-open');

        sidebar.classList.toggle('is-open', open);

        if (backdrop) {
            backdrop.classList.toggle('is-open', open);
        }
    }

    if (burger) {
        burger.addEventListener('click', function () { toggleSidebar(); });
    }

    if (backdrop) {
        backdrop.addEventListener('click', function () { toggleSidebar(false); });
    }

    /* ------------------------------------------------ Filter auto submit */
    document.querySelectorAll('[data-autosubmit]').forEach(function (control) {
        control.addEventListener('change', function () {
            var form = control.closest('form');

            if (form) {
                form.submit();
            }
        });
    });

    /* -------------------------------------------- Destructive confirm */
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var message = form.getAttribute('data-confirm') || 'از انجام این عملیات مطمئن هستید؟';

            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    /* --------------------------------------------------- Copy secrets */
    document.querySelectorAll('.o-secret').forEach(function (box) {
        box.title = 'برای کپی کلیک کنید';

        box.addEventListener('click', function () {
            var text = box.textContent || '';

            if (navigator.clipboard) {
                navigator.clipboard.writeText(text.trim());
                box.style.borderColor = 'rgba(34,197,94,.8)';
                window.setTimeout(function () { box.style.borderColor = ''; }, 900);
            }
        });
    });

    /* ------------------------------------- Live "are you sure" on reset */
    document.querySelectorAll('[data-need-reason]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            var field = document.getElementById(button.getAttribute('data-need-reason'));

            if (field && field.value.trim().length < 5) {
                event.preventDefault();
                field.focus();
                field.style.borderColor = 'rgba(239,68,68,.8)';
                field.setAttribute('placeholder', 'ثبت دلیل الزامی است (حداقل ۵ کاراکتر)');
            }
        });
    });

    /* ---------------------------------------------- Double submit guard */
    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        var button = form.querySelector('button[type="submit"]');

        if (button) {
            window.setTimeout(function () { button.disabled = true; }, 0);
            window.setTimeout(function () { button.disabled = false; }, 4000);
        }
    });
})();
