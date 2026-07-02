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

    function escapeHtml(value) {
        return $('<div>').text(String(value || '')).html();
    }

    function showToast($picker, message, type) {
        var $toast = $picker.find('.item-unit-picker__toast');
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

    function createUnit(config, name) {
        return fetch(config.saveUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json; charset=utf-8',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ name: name }),
        }).then(function (response) {
            return response.json().catch(function () {
                return { success: false, message: 'تعذر قراءة استجابة الخادم' };
            }).then(function (payload) {
                if (!response.ok) {
                    var error = new Error((payload && payload.message) || 'تعذر إنشاء الوحدة');
                    error.responseJSON = payload;
                    error.status = response.status;
                    throw error;
                }
                return payload;
            });
        });
    }

    function UnitCombobox($root) {
        this.$root = $root;
        this.$picker = $root.closest('.item-unit-picker');
        this.$hidden = $root.find('.item-unit-combobox__value');
        this.$input = $root.find('.item-unit-combobox__input');
        this.$list = $root.find('.item-unit-combobox__list');
        this.$toggle = $root.find('.item-unit-combobox__toggle');
        this.activeIndex = -1;
        this.isOpen = false;
        this.filterQuery = '';
        this.options = this.readOptions();
        this.bind();
        this.syncInputFromValue();
    }

    UnitCombobox.prototype.readOptions = function () {
        var options = [];
        this.$list.find('.item-unit-combobox__option').each(function () {
            var $item = $(this);
            options.push({
                id: String($item.data('id') || ''),
                name: String($item.data('name') || $item.text() || '')
            });
        });
        return dedupeOptions(options);
    };

    UnitCombobox.prototype.syncInputFromValue = function () {
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

    UnitCombobox.prototype.filteredOptions = function (query) {
        var term = normalizeText(query);
        if (!term) {
            return this.options.slice(0);
        }
        return this.options.filter(function (option) {
            return normalizeText(option.name).indexOf(term) !== -1;
        });
    };

    UnitCombobox.prototype.renderList = function (query) {
        var items = dedupeOptions(this.filteredOptions(query));
        var html = '';
        if (!items.length) {
            html = '<li class="item-unit-combobox__empty">لا توجد نتائج مطابقة</li>';
            this.activeIndex = -1;
        } else {
            items.forEach(function (option, index) {
                html += '<li class="item-unit-combobox__option" role="option" data-id="' +
                    escapeHtml(option.id) + '" data-name="' +
                    escapeHtml(option.name) + '" data-index="' + index + '">' +
                    escapeHtml(option.name) + '</li>';
            });
            this.activeIndex = -1;
        }
        this.$list.html(html);
        this.highlightActive();
    };

    UnitCombobox.prototype.open = function (query) {
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

    UnitCombobox.prototype.close = function () {
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

    UnitCombobox.prototype.highlightActive = function () {
        var self = this;
        this.$list.find('.item-unit-combobox__option').removeClass('is-active');
        if (this.activeIndex < 0) {
            return;
        }
        this.$list.find('.item-unit-combobox__option').each(function () {
            if (parseInt($(this).data('index'), 10) === self.activeIndex) {
                $(this).addClass('is-active');
            }
        });
    };

    UnitCombobox.prototype.setValue = function (id, name, triggerChange) {
        var value = String(id || '');
        this.$hidden.val(value);
        this.$input.val(name || '');
        if (triggerChange !== false) {
            this.$hidden.trigger('change');
        }
    };

    UnitCombobox.prototype.selectOption = function ($option) {
        if (!$option || !$option.length) {
            return;
        }
        this.setValue(String($option.data('id') || ''), String($option.data('name') || ''));
        this.close();
    };

    UnitCombobox.prototype.addOption = function (id, name, select) {
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
            this.$list.append(
                $('<li class="item-unit-combobox__option" role="option"></li>')
                    .attr('data-id', value)
                    .attr('data-name', name)
                    .text(name)
            );
        }
        if (select) {
            this.setValue(value, name);
        }
        if (this.isOpen) {
            this.renderList(this.filterQuery);
        }
    };

    UnitCombobox.prototype.bind = function () {
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
                var visibleOptions = self.$list.find('.item-unit-combobox__option');
                if (!visibleOptions.length) {
                    return;
                }
                self.activeIndex = self.activeIndex < 0 ? 0 : Math.min(self.activeIndex + 1, visibleOptions.length - 1);
                self.highlightActive();
                return;
            }

            var visibleOptions = self.$list.find('.item-unit-combobox__option');
            if (!visibleOptions.length) {
                return;
            }
            if (event.key === 'ArrowUp') {
                event.preventDefault();
                self.activeIndex = self.activeIndex <= 0 ? -1 : self.activeIndex - 1;
                self.highlightActive();
            } else if (event.key === 'Enter') {
                if (self.isOpen && self.activeIndex >= 0) {
                    event.preventDefault();
                    self.selectOption(visibleOptions.filter('[data-index="' + self.activeIndex + '"]'));
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

        this.$list.on('mousedown', '.item-unit-combobox__option', function (event) {
            event.preventDefault();
            self.selectOption($(this));
        });

        this.$list.on('mouseenter', '.item-unit-combobox__option', function () {
            self.activeIndex = parseInt($(this).data('index'), 10);
            self.highlightActive();
        });

        this.$list.on('mouseleave', function () {
            self.activeIndex = -1;
            self.highlightActive();
        });

        $(document).on('mousedown.itemUnitCombobox', function (event) {
            if (!$(event.target).closest(self.$root).length) {
                self.close();
            }
        });
    };

    function Modal($modal, config, comboboxes) {
        this.$modal = $modal;
        this.config = config;
        this.comboboxes = comboboxes;
        this.targetCombobox = null;
        this.$title = $modal.find('.item-unit-modal__title');
        this.$input = $modal.find('.item-unit-modal__input');
        this.$save = $modal.find('.item-unit-modal__save');
        this.$cancel = $modal.find('.item-unit-modal__cancel');
        this.$backdrop = $modal.find('.item-unit-modal__backdrop');
        this.$feedback = $modal.find('.item-unit-modal__feedback');
        this.bind();
    }

    Modal.prototype.setFeedback = function (message, type) {
        if (!this.$feedback.length) {
            return;
        }
        this.$feedback
            .removeClass('is-success is-error is-visible')
            .addClass(type === 'error' ? 'is-error' : 'is-success')
            .text(message || '')
            .toggleClass('is-visible', !!message);
    };

    Modal.prototype.open = function (combobox) {
        this.targetCombobox = combobox;
        this.setFeedback('');
        this.$input.val('').prop('disabled', false);
        this.$save.prop('disabled', false);
        this.$modal.attr('aria-hidden', 'false').addClass('is-open');
        window.setTimeout(function () {
            this.$input.trigger('focus');
        }.bind(this), FADE_MS);
    };

    Modal.prototype.close = function () {
        var self = this;
        this.$modal.removeClass('is-open').attr('aria-hidden', 'true');
        window.setTimeout(function () {
            self.targetCombobox = null;
            self.$input.val('');
            self.setFeedback('');
        }, FADE_MS);
    };

    Modal.prototype.save = function () {
        var self = this;
        var name = $.trim(this.$input.val() || '');
        var combobox = this.targetCombobox;
        if (!combobox) {
            this.setFeedback('تعذر تحديد حقل الوحدة. أغلق النافذة وحاول مرة أخرى.', 'error');
            return;
        }
        if (!name) {
            this.setFeedback('اكتب اسم الوحدة أولاً', 'error');
            this.$input.trigger('focus');
            return;
        }

        var $picker = combobox.$picker;
        this.$save.prop('disabled', true);
        this.$input.prop('disabled', true);
        this.setFeedback('جاري الحفظ...', 'success');

        createUnit(this.config, name)
            .then(function (response) {
                if (!response || !response.success) {
                    var message = (response && response.message) || 'تعذر إنشاء الوحدة';
                    self.setFeedback(message, 'error');
                    showToast($picker, message, 'error');
                    return;
                }
                self.comboboxes.forEach(function (entry) {
                    entry.addOption(response.id, response.name, entry === combobox);
                });
                showToast($picker, response.message || 'تم الحفظ', 'success');
                self.close();
            })
            .catch(function (error) {
                var message = (error && error.responseJSON && error.responseJSON.message)
                    || (error && error.message)
                    || 'تعذر إنشاء الوحدة';
                self.setFeedback(message, 'error');
                showToast($picker, message, 'error');
            })
            .finally(function () {
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
        this.$save.on('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
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
        $(document).on('click', '.item-unit-picker__add', function () {
            if (!self.config.canCreate) {
                return;
            }
            var $picker = $(this).closest('.item-unit-picker');
            var combobox = $picker.data('unitCombobox');
            if (combobox) {
                self.open(combobox);
            }
        });
    };

    window.initItemUnitPickers = function (config) {
        config = config || {};
        var comboboxes = [];

        $('.item-unit-combobox').each(function () {
            var combobox = new UnitCombobox($(this));
            combobox.$picker.data('unitCombobox', combobox);
            comboboxes.push(combobox);
        });

        var $modal = $('#itemCatalogUnitModal');
        if ($modal.length) {
            new Modal($modal, config, comboboxes);
        }
    };
}(window, window.jQuery));
