(function (window, $) {
    'use strict';

    if (!$) {
        return;
    }

    var FADE_MS = 50;

    function normalizeText(value) {
        return $.trim(String(value || '')).toLowerCase();
    }

    function dedupeOptions(options) {
        var seenIds = Object.create(null);
        var seenNames = Object.create(null);
        var result = [];

        options.forEach(function (option) {
            var id = String(option.id || '');
            var nameKey = normalizeText(option.name);
            if (id && seenIds[id]) {
                return;
            }
            if (nameKey && seenNames[nameKey]) {
                return;
            }
            if (id) {
                seenIds[id] = true;
            }
            if (nameKey) {
                seenNames[nameKey] = true;
            }
            result.push(option);
        });

        return result;
    }

    function showToast($picker, message, type) {
        var $toast = $picker.find('.item-catalog-group-picker__toast');
        $toast
            .removeClass('is-success is-error is-visible')
            .addClass(type === 'error' ? 'is-error' : 'is-success')
            .text(message)
            .addClass('is-visible');

        window.clearTimeout($picker.data('toastTimer'));
        $picker.data('toastTimer', window.setTimeout(function () {
            $toast.removeClass('is-visible');
        }, 2800));
    }

    function createGroup(config, type, name) {
        return $.ajax({
            url: config.saveUrl,
            method: 'POST',
            contentType: 'application/json; charset=utf-8',
            dataType: 'json',
            data: JSON.stringify({ type: type, name: name })
        });
    }

    function Combobox($root, config) {
        this.$root = $root;
        this.config = config;
        this.type = String($root.data('group-type') || '');
        this.$picker = $root.closest('.item-catalog-group-picker');
        this.$hidden = $root.find('.item-catalog-combobox__value');
        this.$input = $root.find('.item-catalog-combobox__input');
        this.$list = $root.find('.item-catalog-combobox__list');
        this.$toggle = $root.find('.item-catalog-combobox__toggle');
        this.activeIndex = -1;
        this.isOpen = false;
        this.filterQuery = '';
        this.options = this.readOptions();
        this.bind();
        this.syncInputFromValue();
    }

    Combobox.prototype.readOptions = function () {
        var options = [];
        this.$list.find('.item-catalog-combobox__option').each(function () {
            var $item = $(this);
            options.push({
                id: String($item.data('id') || ''),
                name: String($item.data('name') || $item.text() || '')
            });
        });
        return dedupeOptions(options);
    };

    Combobox.prototype.syncInputFromValue = function () {
        var value = String(this.$hidden.val() || '');
        if (!value) {
            return;
        }
        var match = this.options.find(function (option) {
            return option.id === value;
        });
        if (match) {
            this.$input.val(match.name);
        }
    };

    Combobox.prototype.filteredOptions = function (query) {
        var term = normalizeText(query);
        if (!term) {
            return this.options.slice(0);
        }
        return this.options.filter(function (option) {
            return normalizeText(option.name).indexOf(term) !== -1;
        });
    };

    Combobox.prototype.renderList = function (query) {
        var items = dedupeOptions(this.filteredOptions(query));
        var html = '';
        if (!items.length) {
            html = '<li class="item-catalog-combobox__empty">لا توجد نتائج مطابقة</li>';
            this.activeIndex = -1;
        } else {
            items.forEach(function (option, index) {
                html += '<li class="item-catalog-combobox__option" role="option" data-id="' +
                    $('<div>').text(option.id).html() + '" data-name="' +
                    $('<div>').text(option.name).html() + '" data-index="' + index + '">' +
                    $('<div>').text(option.name).html() + '</li>';
            });
            this.activeIndex = -1;
        }
        this.$list.html(html);
        this.highlightActive();
    };

    Combobox.prototype.open = function (query) {
        if (this.isOpen) {
            return;
        }
        this.isOpen = true;
        this.$root.addClass('is-open');
        this.$input.attr('aria-expanded', 'true');
        if (query === undefined) {
            query = this.filterQuery;
        }
        this.renderList(query);
        this.$list.prop('hidden', false);
    };

    Combobox.prototype.close = function () {
        if (!this.isOpen) {
            return;
        }
        this.isOpen = false;
        this.$root.removeClass('is-open');
        this.$input.attr('aria-expanded', 'false');
        this.$list.prop('hidden', true);
        this.activeIndex = -1;
        this.filterQuery = '';
        this.syncInputFromValue();
    };

    Combobox.prototype.highlightActive = function () {
        var self = this;
        this.$list.find('.item-catalog-combobox__option').removeClass('is-active');
        if (this.activeIndex < 0) {
            return;
        }
        this.$list.find('.item-catalog-combobox__option').each(function () {
            if (parseInt($(this).data('index'), 10) === self.activeIndex) {
                $(this).addClass('is-active');
            }
        });
    };

    Combobox.prototype.selectOption = function ($option) {
        if (!$option || !$option.length) {
            return;
        }
        var id = String($option.data('id') || '');
        var name = String($option.data('name') || '');
        this.$hidden.val(id);
        this.$input.val(name);
        this.close();
    };

    Combobox.prototype.addOption = function (id, name, select) {
        var value = String(id);
        var nameKey = normalizeText(name);
        var exists = this.options.some(function (option) {
            return option.id === value || normalizeText(option.name) === nameKey;
        });
        if (!exists) {
            this.options.push({ id: value, name: name });
            this.options.sort(function (a, b) {
                return a.name.localeCompare(b.name, 'ar');
            });
        }
        if (select) {
            this.$hidden.val(value);
            this.$input.val(name);
        }
        if (this.isOpen) {
            this.renderList(this.filterQuery);
        }
    };

    Combobox.prototype.bind = function () {
        var self = this;

        this.$input.on('focus', function () {
            self.filterQuery = '';
            self.open('');
        });

        this.$input.on('input', function () {
            self.$hidden.val('');
            self.filterQuery = self.$input.val();
            if (!self.isOpen) {
                self.open(self.filterQuery);
            } else {
                self.renderList(self.filterQuery);
            }
        });

        this.$input.on('keydown', function (event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                if (!self.isOpen) {
                    self.filterQuery = '';
                    self.open('');
                }
                var visibleOptions = self.$list.find('.item-catalog-combobox__option');
                if (!visibleOptions.length) {
                    return;
                }
                if (self.activeIndex < 0) {
                    self.activeIndex = 0;
                } else {
                    self.activeIndex = Math.min(self.activeIndex + 1, visibleOptions.length - 1);
                }
                self.highlightActive();
                return;
            }

            var visibleOptions = self.$list.find('.item-catalog-combobox__option');
            if (!visibleOptions.length) {
                return;
            }
            if (event.key === 'ArrowUp') {
                event.preventDefault();
                if (self.activeIndex <= 0) {
                    self.activeIndex = -1;
                } else {
                    self.activeIndex -= 1;
                }
                self.highlightActive();
            } else if (event.key === 'Enter') {
                if (self.isOpen && self.activeIndex >= 0) {
                    event.preventDefault();
                    var $target = visibleOptions.filter('[data-index="' + self.activeIndex + '"]');
                    self.selectOption($target);
                }
            } else if (event.key === 'Escape') {
                self.close();
            }
        });

        this.$toggle.on('click', function () {
            if (self.isOpen) {
                self.close();
            } else {
                self.filterQuery = '';
                self.$input.trigger('focus');
                self.open('');
            }
        });

        this.$list.on('mousedown', '.item-catalog-combobox__option', function (event) {
            event.preventDefault();
            self.selectOption($(this));
        });

        this.$list.on('mouseenter', '.item-catalog-combobox__option', function () {
            self.activeIndex = parseInt($(this).data('index'), 10);
            self.highlightActive();
        });

        this.$list.on('mouseleave', function () {
            self.activeIndex = -1;
            self.highlightActive();
        });

        $(document).on('mousedown.itemCatalogCombobox', function (event) {
            if (!$(event.target).closest(self.$root).length) {
                self.close();
            }
        });
    };

    function Modal($modal, config, comboboxMap) {
        this.$modal = $modal;
        this.config = config;
        this.comboboxMap = comboboxMap;
        this.activeType = null;
        this.$title = $modal.find('.item-catalog-modal__title');
        this.$input = $modal.find('.item-catalog-modal__input');
        this.$save = $modal.find('.item-catalog-modal__save');
        this.$cancel = $modal.find('.item-catalog-modal__cancel');
        this.$backdrop = $modal.find('.item-catalog-modal__backdrop');
        this.bind();
    }

    Modal.prototype.titles = function (type) {
        return type === 'group1'
            ? { heading: 'تصنيف جديد', placeholder: 'اسم التصنيف', save: 'حفظ التصنيف' }
            : { heading: 'مجموعة فرعية جديدة', placeholder: 'اسم المجموعة الفرعية', save: 'حفظ المجموعة' };
    };

    Modal.prototype.open = function (type) {
        var labels = this.titles(type);
        this.activeType = type;
        this.$title.text(labels.heading);
        this.$input.attr('placeholder', labels.placeholder).val('').prop('disabled', false);
        this.$save.text(labels.save).prop('disabled', false);
        this.$modal.attr('aria-hidden', 'false').addClass('is-open');
        window.setTimeout(function () {
            this.$input.trigger('focus');
        }.bind(this), FADE_MS);
    };

    Modal.prototype.close = function () {
        var self = this;
        this.$modal.removeClass('is-open').attr('aria-hidden', 'true');
        window.setTimeout(function () {
            self.activeType = null;
            self.$input.val('');
        }, FADE_MS);
    };

    Modal.prototype.save = function () {
        var self = this;
        var type = this.activeType;
        var name = $.trim(this.$input.val() || '');
        if (!type || !name) {
            this.$input.trigger('focus');
            return;
        }

        var combobox = this.comboboxMap[type];
        var $picker = combobox.$picker;

        this.$save.prop('disabled', true);
        this.$input.prop('disabled', true);

        createGroup(this.config, type, name)
            .done(function (response) {
                if (!response || !response.success) {
                    showToast($picker, (response && response.message) || 'تعذر الإنشاء', 'error');
                    return;
                }
                combobox.addOption(response.id, response.name, true);
                showToast($picker, response.message || 'تم الحفظ', 'success');
                self.close();
            })
            .fail(function (xhr) {
                var response = xhr.responseJSON || {};
                showToast($picker, response.message || 'تعذر الإنشاء', 'error');
            })
            .always(function () {
                self.$save.prop('disabled', false);
                self.$input.prop('disabled', false);
            });
    };

    Modal.prototype.bind = function () {
        var self = this;
        this.$cancel.on('click', function () {
            self.close();
        });
        this.$backdrop.on('click', function () {
            self.close();
        });
        this.$save.on('click', function () {
            self.save();
        });
        this.$input.on('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                self.save();
            } else if (event.key === 'Escape') {
                self.close();
            }
        });
        $(document).on('click', '.item-catalog-group-picker__add', function () {
            if (!self.config.canCreate) {
                return;
            }
            var type = String($(this).closest('.item-catalog-group-picker').data('picker-type') || '');
            if (type) {
                self.open(type);
            }
        });
    };

    window.initItemCatalogGroupPickers = function (config) {
        config = config || {};
        var comboboxMap = {};

        $('.item-catalog-combobox').each(function () {
            var combobox = new Combobox($(this), config);
            comboboxMap[combobox.type] = combobox;
        });

        var $modal = $('#itemCatalogGroupModal');
        if ($modal.length) {
            new Modal($modal, config, comboboxMap);
        }
    };
}(window, window.jQuery));
