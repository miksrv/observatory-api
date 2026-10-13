/* Observatory console — the web UI's behaviour, progressive: every page works without it (forms
   post, links navigate), this only adds the theme switch, the rail, selection bars, confirm
   dialogs, the image lightbox and auto-refresh. No dependencies. */
(function () {
    'use strict';

    var store = {
        get: function (k) {
            try {
                return localStorage.getItem(k);
            } catch (e) {
                return null;
            }
        },
        set: function (k, v) {
            try {
                localStorage.setItem(k, v);
            } catch (e) {}
        }
    };
    var $ = function (sel, root) {
        return (root || document).querySelector(sel);
    };
    var $$ = function (sel, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    };

    /* ---------- theme ---------- */

    function applyTheme(theme) {
        document.documentElement.dataset.theme = theme;
        $$('[data-theme-set]').forEach(function (b) {
            b.setAttribute('aria-pressed', b.dataset.themeSet === theme ? 'true' : 'false');
        });
    }
    applyTheme(document.documentElement.dataset.theme || 'day');
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-theme-set]');
        if (!b) return;
        store.set('obs.theme', b.dataset.themeSet);
        applyTheme(b.dataset.themeSet);
    });

    /* ---------- rail: collapse on desktop, drawer on phone ---------- */

    var shell = $('[data-shell]');
    if (shell) {
        if (store.get('obs.rail') === 'collapsed') shell.classList.add('collapsed');
        document.addEventListener('click', function (e) {
            if (e.target.closest('[data-rail-toggle]')) {
                var collapsed = shell.classList.toggle('collapsed');
                store.set('obs.rail', collapsed ? 'collapsed' : 'open');
            }
            if (e.target.closest('[data-drawer-open]')) shell.classList.add('drawer-open');
            if (e.target.closest('[data-drawer-close]')) shell.classList.remove('drawer-open');
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') shell.classList.remove('drawer-open');
        });
    }

    /* ---------- notices ---------- */

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-dismiss]');
        if (b) b.closest('.notice').remove();
    });

    /* ---------- confirm dialog (forms / submit buttons with data-confirm) ---------- */

    var dialog = $('#confirm-dialog');
    function confirmThen(el, proceed) {
        if (!dialog || typeof dialog.showModal !== 'function') {
            if (window.confirm(el.dataset.confirm)) proceed();
            return;
        }
        var danger = el.hasAttribute('data-danger');
        $('[data-confirm-title]', dialog).textContent = el.dataset.confirmTitle || 'Подтвердите действие';
        $('[data-confirm-text]', dialog).textContent = el.dataset.confirm;
        var ok = $('[data-confirm-ok]', dialog);
        ok.textContent = el.dataset.confirmOk || 'Продолжить';
        ok.className = 'btn ' + (danger ? 'danger solid' : 'primary');
        $('.modal-icon', dialog).classList.toggle('danger', danger);
        dialog.returnValue = '';
        dialog.showModal();
        $('[data-confirm-cancel]', dialog).focus();
        dialog.addEventListener(
            'close',
            function () {
                if (dialog.returnValue === 'ok') proceed();
            },
            { once: true }
        );
    }
    document.addEventListener(
        'submit',
        function (e) {
            var form = e.target;
            var submitter = e.submitter;
            // A selected <option data-confirm> (a destructive task type) asks too.
            var opt = $$('select', form)
                .map(function (sel) {
                    return sel.options[sel.selectedIndex];
                })
                .filter(function (o) {
                    return o && o.dataset.confirm;
                })[0];
            var el = submitter && submitter.dataset.confirm ? submitter : form.dataset.confirm ? form : opt || null;
            if (!el || form.dataset.confirmed === '1') return;
            e.preventDefault();
            var text = el.dataset.confirm;
            var n = selectedIn(form);
            el.dataset.confirm = text.replace('{n}', String(n));
            confirmThen(el, function () {
                form.dataset.confirmed = '1';
                // form.submit() ignores the button: carry over its formaction (the button's own
                // name/value is never relied on by the UI's forms).
                if (submitter && submitter.hasAttribute('formaction')) form.action = submitter.formAction;
                form.submit();
            });
            el.dataset.confirm = text;
        },
        true
    );

    /* ---------- selection: checkboxes + the floating action bar ---------- */

    // The bars are fixed over the page: while one shows, the page needs room beneath its last rows.
    // A one-window list page (.page.fill) keeps its size instead; its table gets padding only for
    // as much of it as the bar actually covers, so its last row can scroll out from under the bar.
    function syncSelbarRoom() {
        var bar = $$('.selbar').filter(function (b) {
            return !b.hasAttribute('data-empty');
        })[0];
        document.body.classList.toggle('selbar-open', !!bar);
        $$('.page.fill .table-wrap').forEach(function (wrap) {
            wrap.style.paddingBottom = '';
            // On a phone the page scrolls as a whole (not one window high): nothing to pad.
            if (!bar || getComputedStyle(wrap.closest('.page')).display !== 'flex') return;
            var overlap = wrap.getBoundingClientRect().bottom - bar.getBoundingClientRect().top;
            if (overlap > 0) wrap.style.paddingBottom = Math.ceil(overlap + 12) + 'px';
        });
    }
    window.addEventListener('resize', syncSelbarRoom);
    // The bar slides in: measure again where it settles.
    $$('.selbar').forEach(function (bar) {
        bar.addEventListener('transitionend', syncSelbarRoom);
    });

    function selectedIn(form) {
        var scope = form.closest('[data-select-scope]') || $('[data-select-scope]');
        return scope ? $$('.js-pick:checked', scope).length : 0;
    }
    $$('[data-select-scope]').forEach(function (scope) {
        var all = $('[data-pick-all]', scope);
        function update() {
            var picks = $$('.js-pick', scope);
            var checked = picks.filter(function (c) {
                return c.checked;
            });
            picks.forEach(function (c) {
                var row = c.closest('tr, .shot');
                if (row) row.classList.toggle('selected', c.checked);
            });
            $$('[data-sel-count]', scope).forEach(function (el) {
                el.textContent = String(checked.length);
            });
            $$('.selbar', scope).forEach(function (bar) {
                if (checked.length === 0) bar.setAttribute('data-empty', '');
                else bar.removeAttribute('data-empty');
            });
            $$('[data-min-select]', scope).forEach(function (b) {
                var min = parseInt(b.dataset.minSelect, 10) || 1;
                var need = b.dataset.needs;
                var n = need
                    ? checked.filter(function (c) {
                          return c.dataset[need] === '1';
                      }).length
                    : checked.length;
                b.disabled = n < min;
            });
            if (all) {
                all.checked = picks.length > 0 && checked.length === picks.length;
                all.indeterminate = checked.length > 0 && checked.length < picks.length;
            }
            syncSelbarRoom();
        }
        scope.addEventListener('change', function (e) {
            if (e.target === all) {
                $$('.js-pick', scope).forEach(function (c) {
                    c.checked = all.checked;
                });
            }
            if (e.target === all || e.target.classList.contains('js-pick')) update();
        });
        scope.addEventListener('click', function (e) {
            if (e.target.closest('[data-pick-none]')) {
                $$('.js-pick', scope).forEach(function (c) {
                    c.checked = false;
                });
                update();
            }
            if (e.target.closest('[data-pick-every]')) {
                $$('.js-pick', scope).forEach(function (c) {
                    c.checked = true;
                });
                update();
            }
        });
        // Shift-click selects a range.
        var last = null;
        scope.addEventListener('click', function (e) {
            var box = e.target.closest('.js-pick');
            if (!box) return;
            if (e.shiftKey && last && last !== box) {
                var picks = $$('.js-pick', scope);
                var a = picks.indexOf(last);
                var b = picks.indexOf(box);
                picks.slice(Math.min(a, b), Math.max(a, b) + 1).forEach(function (c) {
                    c.checked = box.checked;
                });
                update();
            }
            last = box;
        });
        update();
    });

    /* ---------- rows that open a page ---------- */

    document.addEventListener('click', function (e) {
        var row = e.target.closest('tr[data-href]');
        if (!row || e.target.closest('a, button, input, select, label, form')) return;
        if (window.getSelection && String(window.getSelection()).length > 0) return;
        if (row.dataset.target === '_blank' || e.metaKey || e.ctrlKey) {
            window.open(row.dataset.href, '_blank', 'noopener');
        } else {
            window.location.href = row.dataset.href;
        }
    });

    /* ---------- filters ---------- */

    document.addEventListener('change', function (e) {
        if (e.target.matches('select[data-autosubmit]')) e.target.form.submit();
    });

    /* the task type's description under the frames' action bar */
    $$('select[data-type-hint]').forEach(function (sel) {
        var out = $(sel.dataset.typeHint);
        function show() {
            var opt = sel.options[sel.selectedIndex];
            if (out) out.textContent = opt ? opt.dataset.hint || '' : '';
        }
        sel.addEventListener('change', show);
        show();
    });

    /* ---------- lightbox ---------- */

    var lightbox = $('#lightbox');
    document.addEventListener('click', function (e) {
        var a = e.target.closest('[data-lightbox]');
        if (!a || !lightbox || typeof lightbox.showModal !== 'function' || e.metaKey || e.ctrlKey) return;
        e.preventDefault();
        var href = a.getAttribute('href');
        $('img', lightbox).src = href;
        $('[data-lightbox-caption]', lightbox).textContent = a.dataset.caption || '';
        $('[data-lightbox-open]', lightbox).href = href;
        lightbox.showModal();
    });
    if (lightbox) {
        lightbox.addEventListener('click', function (e) {
            if (e.target === lightbox || e.target.closest('[data-lightbox-close]')) lightbox.close();
        });
        lightbox.addEventListener('close', function () {
            $('img', lightbox).removeAttribute('src');
        });
    }

    /* ---------- password reveal ---------- */

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-reveal]');
        if (!b) return;
        var input = $(b.dataset.reveal);
        input.type = input.type === 'password' ? 'text' : 'password';
        b.setAttribute('aria-pressed', input.type === 'text' ? 'true' : 'false');
        input.focus();
    });

    /* ---------- settings: highlight changes, warn before leaving ---------- */

    var settingsForm = $('[data-settings-form]');
    if (settingsForm) {
        var dirty = 0;
        var bar = $('[data-dirty-bar]');
        var valueOf = function (el) {
            if (el.type === 'checkbox') return el.checked ? el.dataset.on : el.dataset.off;
            return el.value;
        };
        var refresh = function () {
            dirty = 0;
            $$('[data-original]', settingsForm).forEach(function (el) {
                var changed = valueOf(el) !== el.dataset.original;
                if (changed) dirty++;
                var row = el.closest('.setting');
                if (row) row.classList.toggle('changed', changed);
                if (el.type === 'checkbox') {
                    var state = el.parentNode.querySelector('.state');
                    if (state) state.textContent = valueOf(el);
                }
            });
            $$('[data-dirty-count]').forEach(function (el) {
                el.textContent = String(dirty);
            });
            if (bar) {
                if (dirty === 0) bar.setAttribute('data-empty', '');
                else bar.removeAttribute('data-empty');
            }
            syncSelbarRoom();
        };
        settingsForm.addEventListener('input', refresh);
        settingsForm.addEventListener('change', refresh);
        settingsForm.addEventListener('reset', function () {
            setTimeout(refresh, 0);
        });
        settingsForm.addEventListener('submit', function () {
            dirty = 0;
        });
        window.addEventListener('beforeunload', function (e) {
            if (dirty > 0) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
        refresh();
    }

    /* ---------- auto-refresh (task pages while something runs) ---------- */

    var auto = document.body.dataset.autorefresh;
    if (auto) {
        var seconds = parseInt(auto, 10) || 15;
        setInterval(function () {
            var busy =
                document.visibilityState !== 'visible' ||
                $('dialog[open]') ||
                $$('.js-pick:checked').length > 0 ||
                (document.activeElement && document.activeElement.matches('input, select, textarea'));
            if (!busy) window.location.reload();
        }, seconds * 1000);
    }
})();
