(function (global, $) {
    'use strict';

    if (!$) {
        return;
    }

    const ARABIC_ROWS = [
        ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'],
        ['ض', 'ص', 'ث', 'ق', 'ف', 'غ', 'ع', 'ه', 'خ', 'ح', 'ج'],
        ['ش', 'س', 'ي', 'ب', 'ل', 'ا', 'ت', 'ن', 'م', 'ك'],
        ['ظ', 'ط', 'ذ', 'د', 'ز', 'ر', 'و', 'ة', 'ى', 'ء'],
    ];

    const LATIN_ROWS = [
        ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'],
        ['q', 'w', 'e', 'r', 't', 'y', 'u', 'i', 'o', 'p'],
        ['a', 's', 'd', 'f', 'g', 'h', 'j', 'k', 'l'],
        ['z', 'x', 'c', 'v', 'b', 'n', 'm'],
    ];

    const state = {
        activeInput: null,
        isVisible: false,
        latinMode: false,
        $panel: null,
        $toggleBtn: null,
    };

    function isEligibleInput(element) {
        if (!element || element.disabled || element.readOnly) {
            return false;
        }
        if (element.type === 'hidden' || element.type === 'password') {
            return false;
        }
        if (element.closest('.pos-pin-pad-modal, .pos-pin-pad-card, #pinPadSection, .ppm-modal, .ppm-shell, .ppm-card, .ppm-grid')) {
            return false;
        }
        if (element.closest('.d-none, [hidden]')) {
            return false;
        }
        const tag = element.tagName;
        if (tag !== 'INPUT' && tag !== 'TEXTAREA') {
            return false;
        }
        const type = (element.getAttribute('type') || 'text').toLowerCase();
        return ['text', 'tel', 'search', 'email', 'url', 'number', 'decimal'].indexOf(type) !== -1
            || tag === 'TEXTAREA';
    }

    function findDefaultInput() {
        const candidates = [
            document.getElementById('posUnifiedSearch'),
            document.getElementById('searchInput'),
            document.getElementById('posCustomerPhoneInput'),
            document.querySelector('#posForm input[type="text"]:not([readonly])'),
            document.querySelector('#posForm input[type="tel"]:not([readonly])'),
        ];
        for (let i = 0; i < candidates.length; i += 1) {
            if (candidates[i] && isEligibleInput(candidates[i])) {
                return candidates[i];
            }
        }
        return null;
    }

    function dispatchInputEvents(input) {
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function focusInput(input) {
        if (!input) {
            return;
        }
        state.activeInput = input;
        try {
            input.focus({ preventScroll: true });
        } catch (error) {
            input.focus();
        }
        if (typeof input.setSelectionRange === 'function') {
            const end = input.value.length;
            input.setSelectionRange(end, end);
        }
    }

    function insertText(text) {
        const input = state.activeInput;
        if (!input) {
            return;
        }
        const value = input.value || '';
        const start = typeof input.selectionStart === 'number' ? input.selectionStart : value.length;
        const end = typeof input.selectionEnd === 'number' ? input.selectionEnd : value.length;
        input.value = value.slice(0, start) + text + value.slice(end);
        const caret = start + text.length;
        focusInput(input);
        if (typeof input.setSelectionRange === 'function') {
            input.setSelectionRange(caret, caret);
        }
        dispatchInputEvents(input);
    }

    function backspace() {
        const input = state.activeInput;
        if (!input) {
            return;
        }
        const value = input.value || '';
        const start = typeof input.selectionStart === 'number' ? input.selectionStart : value.length;
        const end = typeof input.selectionEnd === 'number' ? input.selectionEnd : value.length;
        if (start !== end) {
            input.value = value.slice(0, start) + value.slice(end);
            focusInput(input);
            if (typeof input.setSelectionRange === 'function') {
                input.setSelectionRange(start, start);
            }
        } else if (start > 0) {
            input.value = value.slice(0, start - 1) + value.slice(start);
            const caret = start - 1;
            focusInput(input);
            if (typeof input.setSelectionRange === 'function') {
                input.setSelectionRange(caret, caret);
            }
        }
        dispatchInputEvents(input);
    }

    function clearInput() {
        const input = state.activeInput;
        if (!input) {
            return;
        }
        input.value = '';
        focusInput(input);
        dispatchInputEvents(input);
    }

    function submitInput() {
        const input = state.activeInput;
        if (!input) {
            hide();
            return;
        }
        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', code: 'Enter', bubbles: true }));
        input.dispatchEvent(new KeyboardEvent('keypress', { key: 'Enter', code: 'Enter', bubbles: true, which: 13, keyCode: 13 }));
        input.dispatchEvent(new KeyboardEvent('keyup', { key: 'Enter', code: 'Enter', bubbles: true }));
        hide();
    }

    function currentRows() {
        return state.latinMode ? LATIN_ROWS : ARABIC_ROWS;
    }

    function renderKeys() {
        if (!state.$panel) {
            return;
        }
        const $keys = state.$panel.find('.pos-vk-keys');
        $keys.empty();

        currentRows().forEach(function (row) {
            const $row = $('<div class="pos-vk-row" />');
            row.forEach(function (keyLabel) {
                const $key = $('<button type="button" class="pos-vk-key" />')
                    .attr('data-key', keyLabel)
                    .text(keyLabel);
                $row.append($key);
            });
            $keys.append($row);
        });

        const $actions = $('<div class="pos-vk-row pos-vk-row-actions" />');
        $actions.append(
            $('<button type="button" class="pos-vk-key pos-vk-key-mode" data-action="mode" />')
                .text(state.latinMode ? 'عربي' : 'EN'),
            $('<button type="button" class="pos-vk-key pos-vk-key-wide" data-action="space" />').text('مسافة'),
            $('<button type="button" class="pos-vk-key" data-action="backspace" aria-label="حذف" />').html('<i class="fas fa-backspace" aria-hidden="true"></i>'),
            $('<button type="button" class="pos-vk-key" data-action="clear" />').text('مسح'),
            $('<button type="button" class="pos-vk-key pos-vk-key-done" data-action="done" />').text('تم')
        );
        $keys.append($actions);

        state.$panel.find('[data-action="mode"]').text(state.latinMode ? 'عربي' : 'EN');
    }

    function buildPanel() {
        if (state.$panel && state.$panel.length) {
            return;
        }

        state.$panel = $(`
            <div id="posVirtualKeyboard" class="pos-virtual-keyboard" hidden aria-hidden="true">
                <div class="pos-vk-shell" role="group" aria-label="لوحة المفاتيح">
                    <div class="pos-vk-header">
                        <span class="pos-vk-title">لوحة المفاتيح</span>
                        <button type="button" class="pos-vk-close" data-action="close" aria-label="إخفاء لوحة المفاتيح">
                            <i class="fas fa-times" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="pos-vk-keys"></div>
                </div>
            </div>
        `);

        $('body').append(state.$panel);
        renderKeys();

        state.$panel.on('mousedown touchstart', function (event) {
            event.preventDefault();
        });

        state.$panel.on('click', '.pos-vk-key[data-key]', function () {
            insertText(String($(this).attr('data-key') || ''));
        });

        state.$panel.on('click', '[data-action]', function () {
            const action = $(this).attr('data-action');
            if (action === 'backspace') {
                backspace();
                return;
            }
            if (action === 'space') {
                insertText(' ');
                return;
            }
            if (action === 'clear') {
                clearInput();
                return;
            }
            if (action === 'mode') {
                state.latinMode = !state.latinMode;
                renderKeys();
                return;
            }
            if (action === 'done') {
                submitInput();
                return;
            }
            if (action === 'close') {
                hide();
            }
        });
    }

    function syncToggleButton() {
        if (!state.$toggleBtn || !state.$toggleBtn.length) {
            return;
        }
        state.$toggleBtn
            .toggleClass('is-active', state.isVisible)
            .attr('aria-pressed', state.isVisible ? 'true' : 'false');
    }

    function show(input) {
        buildPanel();
        const target = (input && isEligibleInput(input)) ? input : findDefaultInput();
        if (!target) {
            if (global.Swal) {
                global.Swal.fire({
                    icon: 'info',
                    title: 'لا يوجد حقل نصي',
                    text: 'اختر حقل بحث أو نص قبل فتح لوحة المفاتيح.',
                    confirmButtonText: 'حسناً',
                });
            }
            return;
        }

        state.isVisible = true;
        focusInput(target);
        state.$panel.removeAttr('hidden').attr('aria-hidden', 'false');
        document.body.classList.add('pos-virtual-keyboard-open');
        syncToggleButton();
    }

    function hide() {
        if (!state.$panel) {
            return;
        }
        state.isVisible = false;
        state.$panel.attr('hidden', 'hidden').attr('aria-hidden', 'true');
        document.body.classList.remove('pos-virtual-keyboard-open');
        syncToggleButton();
    }

    function toggle() {
        if (state.isVisible) {
            hide();
            return;
        }
        show(state.activeInput);
    }

    function bindEvents() {
        state.$toggleBtn = $('#posKeyboardToggleBtn');

        $(document).on('click', '#posKeyboardToggleBtn', function (event) {
            event.preventDefault();
            toggle();
        });

        $(document).on('focusin', 'input, textarea', function () {
            if (!state.isVisible || !isEligibleInput(this)) {
                return;
            }
            state.activeInput = this;
        });

        $(document).on('keydown', function (event) {
            if (event.key === 'Escape' && state.isVisible) {
                event.preventDefault();
                hide();
            }
        });
    }

    function init() {
        if (!document.getElementById('posForm')) {
            return;
        }
        buildPanel();
        bindEvents();
    }

    global.POSMAIN = global.POSMAIN || {};
    global.POSMAIN.VirtualKeyboard = {
        show: show,
        hide: hide,
        toggle: toggle,
        init: init,
    };

    $(init);
}(window, window.jQuery));
