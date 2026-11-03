/* =====================================================================
   SARMAD Attendance - public landing interactions
   ---------------------------------------------------------------------
   Progressive enhancement only: counters, pointer-driven 3D tilt and a
   consultation form that prepares a message for the operator-configured
   contact channel. The page remains complete and readable without JS.
   ===================================================================== */

(function () {
    'use strict';

    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var persianDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    function toPersianNumber(value, decimals) {
        return Number(value).toFixed(decimals).replace(/[0-9]/g, function (digit) {
            return persianDigits[Number(digit)];
        });
    }

    function animateCounter(counter) {
        var target = Number(counter.getAttribute('data-target') || '0');
        var decimals = Number(counter.getAttribute('data-decimals') || '0');

        if (!isFinite(target)) {
            return;
        }

        if (reducedMotion) {
            counter.textContent = toPersianNumber(target, decimals);
            return;
        }

        var duration = 1300;
        var startedAt = null;

        function frame(timestamp) {
            if (startedAt === null) {
                startedAt = timestamp;
            }

            var progress = Math.min((timestamp - startedAt) / duration, 1);
            var eased = 1 - Math.pow(1 - progress, 3);

            counter.textContent = toPersianNumber(target * eased, decimals);

            if (progress < 1) {
                window.requestAnimationFrame(frame);
            } else {
                counter.textContent = toPersianNumber(target, decimals);
            }
        }

        window.requestAnimationFrame(frame);
    }

    function initCounters() {
        var counters = Array.prototype.slice.call(document.querySelectorAll('.counter'));

        if (!counters.length) {
            return;
        }

        if (!('IntersectionObserver' in window) || reducedMotion) {
            counters.forEach(animateCounter);
            return;
        }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    animateCounter(entry.target);
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.35 });

        counters.forEach(function (counter) {
            observer.observe(counter);
        });
    }

    function initHologramTilt() {
        var visual = document.getElementById('hero-visual');
        var scene = document.getElementById('holo-scene');
        var finePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;

        if (!visual || !scene || !finePointer || reducedMotion) {
            return;
        }

        visual.addEventListener('pointermove', function (event) {
            var rect = visual.getBoundingClientRect();

            if (!rect.width || !rect.height) {
                return;
            }

            var x = ((event.clientX - rect.left) / rect.width) - 0.5;
            var y = ((event.clientY - rect.top) / rect.height) - 0.5;

            scene.style.setProperty('--ry', (x * 11).toFixed(2) + 'deg');
            scene.style.setProperty('--rx', (-y * 9).toFixed(2) + 'deg');
        });

        visual.addEventListener('pointerleave', function () {
            scene.style.setProperty('--ry', '0deg');
            scene.style.setProperty('--rx', '0deg');
        });
    }

    function normaliseDigits(value) {
        return String(value || '')
            .replace(/[۰-۹]/g, function (digit) {
                return String(persianDigits.indexOf(digit));
            })
            .replace(/[٠-٩]/g, function (digit) {
                return String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit));
            });
    }

    function isValidMobile(value) {
        var compact = normaliseDigits(value).replace(/[\s-]/g, '');

        return /^(\+98|0098|0)?9\d{9}$/.test(compact);
    }

    function openExternal(url) {
        var link = document.createElement('a');

        link.href = url;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        document.body.appendChild(link);
        link.click();
        link.remove();
    }

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).then(function () {
                return true;
            }).catch(function () {
                return legacyCopy(text);
            });
        }

        return Promise.resolve(legacyCopy(text));
    }

    function legacyCopy(text) {
        var textarea = document.createElement('textarea');
        var copied = false;

        textarea.value = text;
        textarea.setAttribute('readonly', 'readonly');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();

        try {
            copied = document.execCommand('copy');
        } catch (error) {
            copied = false;
        }

        textarea.remove();

        return copied;
    }

    function setNote(note, message, type) {
        note.textContent = message;
        note.classList.remove('is-success', 'is-error');

        if (type) {
            note.classList.add(type);
        }
    }

    function initConsultForm() {
        var form = document.getElementById('consult-form');
        var note = document.getElementById('form-note');

        if (!form || !note) {
            return;
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var nameInput = form.elements.namedItem('name');
            var mobileInput = form.elements.namedItem('mobile');
            var companyInput = form.elements.namedItem('company');
            var subjectInput = form.elements.namedItem('subject');
            var messageInput = form.elements.namedItem('message');

            var name = nameInput ? nameInput.value.trim() : '';
            var mobile = mobileInput ? mobileInput.value.trim() : '';
            var company = companyInput ? companyInput.value.trim() : '';
            var subject = subjectInput ? subjectInput.value.trim() : 'مشاوره';
            var details = messageInput ? messageInput.value.trim() : '';

            if (name.length < 2) {
                setNote(note, 'لطفاً نام و نام خانوادگی را وارد کنید.', 'is-error');
                if (nameInput) {
                    nameInput.focus();
                }
                return;
            }

            if (!isValidMobile(mobile)) {
                setNote(note, 'لطفاً یک شماره موبایل معتبر وارد کنید.', 'is-error');
                if (mobileInput) {
                    mobileInput.focus();
                }
                return;
            }

            var lines = [
                'درخواست ' + subject + ' سامانه حضور و غیاب سرمد',
                'نام: ' + name,
                'موبایل: ' + mobile
            ];

            if (company !== '') {
                lines.push('مجموعه/شهر: ' + company);
            }

            if (details !== '') {
                lines.push('توضیحات: ' + details);
            }

            var message = lines.join('\n');
            var whatsapp = (form.getAttribute('data-whatsapp') || '').trim();
            var telegram = (form.getAttribute('data-telegram') || '').trim();

            if (whatsapp !== '') {
                var separator = whatsapp.indexOf('?') === -1 ? '?' : '&';

                openExternal(whatsapp + separator + 'text=' + encodeURIComponent(message));
                setNote(note, 'واتس‌اپ باز شد؛ پیام آماده ارسال است.', 'is-success');
                form.reset();
                return;
            }

            copyText(message).then(function (copied) {
                if (telegram !== '') {
                    openExternal(telegram);
                    setNote(
                        note,
                        copied
                            ? 'متن درخواست کپی و تلگرام باز شد؛ آن را در گفت‌وگو ارسال کنید.'
                            : 'تلگرام باز شد؛ مشخصات خود را در گفت‌وگو ارسال کنید.',
                        copied ? 'is-success' : ''
                    );

                    if (copied) {
                        form.reset();
                    }

                    return;
                }

                if (copied) {
                    setNote(note, 'متن درخواست آماده و کپی شد؛ آن را از طریق یکی از مسیرهای تماس ارسال کنید.', 'is-success');
                    form.reset();
                } else {
                    setNote(note, 'درخواست آماده است؛ لطفاً از یکی از مسیرهای تماس بالا با ما در ارتباط باشید.', '');
                }
            });
        });
    }

    initCounters();
    initHologramTilt();
    initConsultForm();
}());
