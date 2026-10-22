/*
 * Yandex Home admin UI.
 *
 * Two independent controllers share this file:
 *   - the trait editor on the device card;
 *   - small settings helpers (generate IDs, copy webhook URLs, password toggle).
 *
 * No jQuery, no third-party widgets beyond the Tom Select instance already
 * present in the admin bundle.
 */
(function (window, document) {
    'use strict';

    var catalogIcons = {
        on_off: 'toggle-right',
        toggle: 'switch',
        range: 'adjustments',
        mode: 'list',
        color_setting: 'palette',
        video_stream: 'video',
        float: 'wave-sine',
        event: 'bell'
    };

    var fallbackValues = [
        'on', 'off', 'true', 'false', 'opened', 'closed', 'detected', 'not_detected',
        'leak', 'dry', 'click', 'double_click', 'long_press', 'low', 'normal', 'empty'
    ];

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"'`=/]/g, function (char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;',
                '`': '&#96;',
                '=': '&#61;',
                '/': '&#47;'
            }[char];
        });
    }

    function safeId(type) {
        return String(type).replace(/[^a-zA-Z0-9_-]/g, '-');
    }

    function showMessage(form, text, tone) {
        var box = form.querySelector('[data-yh-trait-message]');
        if (box) {
            box.textContent = text;
            box.className = 'md-yh-trait-message md-yh-trait-message--' + (tone || 'info');
            box.hidden = false;
            window.clearTimeout(box._hideTimer);
            box._hideTimer = window.setTimeout(function () {
                box.hidden = true;
            }, 6000);
            return;
        }
        if (tone === 'error' && window.MDJUIToast) {
            window.MDJUIToast.show({type: 'danger', title: 'Yandex Home', text: text});
        }
    }

    function showToast(text, type) {
        if (window.MDJUIToast) {
            window.MDJUIToast.show({type: type || 'success', title: 'Yandex Home', text: text});
        }
    }

    function initTomSelect(select, sort) {
        if (!select || !window.TomSelect) {
            return;
        }
        if (select.tomselect) {
            return;
        }
        select.dataset.mdSearchableReady = '1';
        new window.TomSelect(select, {
            allowEmptyOption: true,
            create: false,
            maxItems: 1,
            maxOptions: 500,
            plugins: [],
            sortField: sort === false ? [] : [{field: 'text', direction: 'asc'}]
        });
    }

    function destroyTomSelects(root) {
        if (!root) {
            return;
        }
        root.querySelectorAll('select').forEach(function (select) {
            if (select.tomselect) {
                select.tomselect.destroy();
            }
            delete select.dataset.mdSearchableReady;
        });
    }

    // ---------------------------------------------------------------------
    // Trait editor
    // ---------------------------------------------------------------------

    function TraitEditor(form) {
        this.form = form;
        this.container = form.querySelector('[data-yh-traits]');
        this.empty = form.querySelector('[data-yh-traits-empty]');
        this.picker = form.querySelector('[data-yh-trait-picker]');
        this.data = {};
        this.traits = {};
        this.instanceConfig = {};
        this.propertyCache = {};
        this.propertyRequests = {};
        this.ready = false;
    }

    TraitEditor.prototype.init = function () {
        if (this.ready) {
            return;
        }
        var dataNode = this.form.querySelector('#yh-editor-data');
        if (!dataNode) {
            return;
        }
        try {
            this.data = JSON.parse(dataNode.textContent || '{}');
        } catch (error) {
            this.data = {};
        }
        this.traits = this.data.traits && typeof this.data.traits === 'object' ? this.data.traits : {};
        this.instanceConfig = this.data.instanceConfig && typeof this.data.instanceConfig === 'object' ? this.data.instanceConfig : {};
        this.ready = true;

        this.form.addEventListener('click', this.onClick.bind(this));
        this.form.addEventListener('change', this.onChange.bind(this));
        this.form.addEventListener('input', this.onInput.bind(this));
        this.form.addEventListener('submit', this.onSubmit.bind(this));
        this.form.addEventListener('yh:request-properties', this.onRequestProperties.bind(this));

        this.render();
        this.bindPicker();
    };

    TraitEditor.prototype.bindPicker = function () {
        var button = this.form.querySelector('[data-yh-trait-add]');
        if (!button) {
            return;
        }
        var editor = this;
        button.addEventListener('click', function () {
            var type = editor.picker ? String(editor.picker.value || '') : '';
            if (type === '') {
                showMessage(editor.form, 'Выберите характеристику из списка.', 'warning');
                return;
            }
            editor.addTrait(type);
        });
    };

    TraitEditor.prototype.catalog = function (type) {
        return this.data.catalog && this.data.catalog[type] ? this.data.catalog[type] : null;
    };

    TraitEditor.prototype.defaultTrait = function (type) {
        var definition = this.catalog(type) || {};
        return {
            type: type,
            description: definition.description || type,
            linked_object: '',
            linked_property: '',
            reportable: false,
            value_map_preset: type === 'on' ? 'bool_onoff_10' : 'none',
            value_map: type === 'on'
                ? {'1': 'on', '0': 'off', 'true': 'on', 'false': 'off'}
                : {}
        };
    };

    TraitEditor.prototype.addTrait = function (type) {
        if (this.traits[type]) {
            showMessage(this.form, 'Это умение уже добавлено.', 'warning');
            return;
        }
        this.traits[type] = this.defaultTrait(type);
        if (this.picker && this.picker.tomselect) {
            this.picker.tomselect.clear(true);
        } else if (this.picker) {
            this.picker.value = '';
        }
        this.commit();
    };

    TraitEditor.prototype.removeTrait = function (type) {
        if (!this.traits[type]) {
            return;
        }
        delete this.traits[type];
        delete this.instanceConfig[type];
        this.commit();
    };

    TraitEditor.prototype.changeType = function (oldType, newType) {
        if (!newType || oldType === newType) {
            if (!newType) {
                this.render();
            }
            return;
        }
        if (this.traits[newType]) {
            showMessage(this.form, 'Этот тип уже добавлен.', 'warning');
            this.render();
            return;
        }
        var trait = this.traits[oldType];
        if (!trait) {
            return;
        }
        var definition = this.catalog(newType) || {};
        delete this.traits[oldType];
        trait.type = newType;
        trait.description = definition.description || newType;
        trait.value_map_preset = newType === 'on' ? 'bool_onoff_10' : 'none';
        trait.value_map = newType === 'on'
            ? {'1': 'on', '0': 'off', 'true': 'on', 'false': 'off'}
            : {};
        trait.reportable = false;
        this.traits[newType] = trait;
        delete this.instanceConfig[oldType];
        this.commit();
    };

    TraitEditor.prototype.commit = function () {
        this.syncHidden();
        this.render();
    };

    TraitEditor.prototype.syncHidden = function () {
        var traitsInput = this.form.querySelector('[data-yh-traits-json]');
        var instanceInput = this.form.querySelector('[data-yh-instance-json]');
        if (traitsInput) {
            traitsInput.value = JSON.stringify(this.traits);
        }
        if (instanceInput) {
            instanceInput.value = JSON.stringify(this.instanceConfig);
        }
    };

    TraitEditor.prototype.instanceSettings = function (type) {
        if (!this.instanceConfig[type]) {
            this.instanceConfig[type] = {parameters: {}};
        }
        if (!this.instanceConfig[type].parameters) {
            this.instanceConfig[type].parameters = {};
        }
        return this.instanceConfig[type].parameters;
    };

    TraitEditor.prototype.rangeValues = function (type) {
        var definition = this.catalog(type) || {};
        var defaults = definition.parameters && definition.parameters.range ? definition.parameters.range : {};
        var settings = this.instanceSettings(type);
        var range = settings.range || {};
        return {
            min: range.min != null ? range.min : (defaults.min != null ? defaults.min : 0),
            max: range.max != null ? range.max : (defaults.max != null ? defaults.max : 100),
            precision: range.precision != null ? range.precision : (defaults.precision != null ? defaults.precision : 1)
        };
    };

    TraitEditor.prototype.presetOptions = function (selected) {
        var presets = this.data.presets || {};
        var html = '';
        Object.keys(presets).forEach(function (key) {
            html += '<option value="' + escapeHtml(key) + '"' + (key === selected ? ' selected' : '') + '>'
                + escapeHtml(presets[key].label || key) + '</option>';
        });
        return html;
    };

    TraitEditor.prototype.typeOptions = function (currentType) {
        var groups = this.data.groups || [];
        var catalog = this.data.catalog || {};
        var html = '<option value="">Выберите тип</option>';
        groups.forEach(function (group) {
            html += '<optgroup label="' + escapeHtml(group.label) + '">';
            (group.types || []).forEach(function (type) {
                var definition = catalog[type] || {};
                var selected = type === currentType ? ' selected' : '';
                var disabled = type !== currentType && !!this.traits[type] ? ' disabled' : '';
                html += '<option value="' + escapeHtml(type) + '"' + selected + disabled + '>'
                    + escapeHtml(definition.description || type) + ' (' + escapeHtml(type) + ')</option>';
            }, this);
            html += '</optgroup>';
        }, this);
        return html;
    };

    TraitEditor.prototype.objectOptions = function (selected) {
        var objects = this.data.objects || [];
        var found = false;
        var html = '<option value="">Не привязан</option>';
        objects.forEach(function (object) {
            var title = object.title || '';
            if (title === selected) {
                found = true;
            }
            html += '<option value="' + escapeHtml(title) + '"' + (title === selected ? ' selected' : '') + '>'
                + escapeHtml(title) + (object.description ? ' — ' + escapeHtml(object.description) : '') + '</option>';
        });
        if (selected && !found) {
            html += '<option value="' + escapeHtml(selected) + '" selected>' + escapeHtml(selected) + ' (нет в объектах)</option>';
        }
        return html;
    };

    TraitEditor.prototype.propertyOptions = function (type, selected) {
        var trait = this.traits[type];
        var cache = trait && this.propertyCache[trait.linked_object] ? this.propertyCache[trait.linked_object] : [];
        var found = false;
        var html = '<option value="">Не привязано</option>';
        cache.forEach(function (property) {
            var title = property.TITLE || '';
            if (title === selected) {
                found = true;
            }
            html += '<option value="' + escapeHtml(title) + '"' + (title === selected ? ' selected' : '') + '>'
                + escapeHtml(title) + (property.DESCRIPTION ? ' — ' + escapeHtml(property.DESCRIPTION) : '') + '</option>';
        });
        if (selected && !found) {
            html += '<option value="' + escapeHtml(selected) + '" selected>' + escapeHtml(selected) + ' (не загружено)</option>';
        }
        return html;
    };

    TraitEditor.prototype.mapValues = function (type, selected) {
        var definition = this.catalog(type) || {};
        var values = definition.allowed_values && definition.allowed_values.length
            ? definition.allowed_values
            : fallbackValues;
        var html = '<option value="">Значение Яндекса</option>';
        if (selected && values.indexOf(selected) === -1) {
            html += '<option value="' + escapeHtml(selected) + '" selected>' + escapeHtml(selected) + '</option>';
        }
        values.forEach(function (value) {
            html += '<option value="' + escapeHtml(value) + '"' + (value === selected ? ' selected' : '') + '>'
                + escapeHtml(value) + '</option>';
        });
        return html;
    };

    TraitEditor.prototype.mapRows = function (type) {
        var trait = this.traits[type];
        var map = trait && trait.value_map && typeof trait.value_map === 'object' ? trait.value_map : {};
        var keys = Object.keys(map);
        var html = '<div class="md-yh-map__head"><span>Значение MajorDoMo</span><span>Значение Яндекс</span><span></span></div>';
        keys.forEach(function (source) {
            html += '<div class="md-yh-map__row" data-yh-map-row>'
                + '<input class="md-input md-input--sm" type="text" data-yh-map-src value="' + escapeHtml(source) + '" placeholder="1">'
                + '<select class="md-select md-select--sm" data-yh-map-dst>' + this.mapValues(type, map[source]) + '</select>'
                + '<button type="button" class="md-icon-btn md-icon-btn--sm md-icon-btn--danger" data-yh-map-del aria-label="Удалить соответствие"><i class="ti ti-trash" aria-hidden="true"></i></button>'
                + '</div>';
        }, this);
        html += '<button type="button" class="md-btn md-btn--ghost md-btn--sm md-yh-map__add" data-yh-map-add><i class="ti ti-plus" aria-hidden="true"></i>Добавить соответствие</button>';
        return html;
    };

    TraitEditor.prototype.cardHtml = function (type) {
        var trait = this.traits[type];
        var definition = this.catalog(type) || {};
        var capability = definition.capability || '';
        var icon = catalogIcons[capability] || 'list-check';
        var uid = safeId(type);
        var rangeHtml = '';
        var splitHtml = '';
        var reportableHtml = '';

        if (capability === 'range') {
            var range = this.rangeValues(type);
            rangeHtml = '<div class="md-yh-trait__row md-yh-trait__row--range">'
                + '<div class="md-field"><label class="md-field__label" for="yh-min-' + uid + '">Min</label><div class="md-field__control"><input class="md-input" id="yh-min-' + uid + '" type="number" step="any" data-yh-range="min" value="' + escapeHtml(range.min) + '"></div></div>'
                + '<div class="md-field"><label class="md-field__label" for="yh-max-' + uid + '">Max</label><div class="md-field__control"><input class="md-input" id="yh-max-' + uid + '" type="number" step="any" data-yh-range="max" value="' + escapeHtml(range.max) + '"></div></div>'
                + '<div class="md-field"><label class="md-field__label" for="yh-step-' + uid + '">Step</label><div class="md-field__control"><input class="md-input" id="yh-step-' + uid + '" type="number" step="any" data-yh-range="precision" value="' + escapeHtml(range.precision) + '"></div></div>'
                + '</div>';
        }

        if (capability === 'on_off') {
            var split = this.instanceSettings(type).split === true;
            splitHtml = '<label class="md-switch md-yh-trait__switch">'
                + '<input class="md-switch__input" type="checkbox" data-md-raw-checkbox data-yh-split="' + escapeHtml(type) + '"' + (split ? ' checked' : '') + '>'
                + '<span class="md-switch__track" aria-hidden="true"><span class="md-switch__thumb"></span></span>'
                + '<span class="md-switch__text"><span class="md-switch__label">Split</span><span class="md-switch__desc">Две отдельные кнопки «Включить» и «Выключить».</span></span>'
                + '</label>';
        }

        if (this.data.reportableConfigured) {
            reportableHtml = '<label class="md-switch md-yh-trait__switch">'
                + '<input class="md-switch__input" type="checkbox" data-md-raw-checkbox data-yh-reportable="' + escapeHtml(type) + '"' + (trait.reportable ? ' checked' : '') + (this.instanceSettings(type).split === true ? ' disabled' : '') + '>'
                + '<span class="md-switch__track" aria-hidden="true"><span class="md-switch__thumb"></span></span>'
                + '<span class="md-switch__text"><span class="md-switch__label">Reportable</span><span class="md-switch__desc">Отправлять изменения в Яндекс без запроса Алисы.</span></span>'
                + '</label>';
        } else {
            reportableHtml = '<div class="md-yh-trait__callout"><i class="ti ti-bell-off" aria-hidden="true"></i><span>Reportable выключен: не заданы токен и ID навыка в <a href="?view_mode=settings">настройках</a>.</span></div>';
        }

        var mapEditor = '';
        if (trait.value_map_preset === 'custom') {
            mapEditor = '<div class="md-yh-map" data-yh-map="' + escapeHtml(type) + '">' + this.mapRows(type) + '</div>';
        }

        var presetLabel = trait.value_map_preset && this.data.presets && this.data.presets[trait.value_map_preset]
            ? this.data.presets[trait.value_map_preset].label
            : '';

        return '<article class="md-yh-trait" data-yh-trait="' + escapeHtml(type) + '">'
            + '<header class="md-yh-trait__head">'
            + '<span class="md-yh-trait__icon"><i class="ti ti-' + escapeHtml(icon) + '" aria-hidden="true"></i></span>'
            + '<div class="md-yh-trait__titles">'
            + '<div class="md-yh-trait__title">' + escapeHtml(trait.description || definition.description || type) + '</div>'
            + '<div class="md-yh-trait__meta"><code>' + escapeHtml(type) + '</code><span class="md-badge md-badge--outline">' + escapeHtml(capability) + '</span></div>'
            + '</div>'
            + '<button type="button" class="md-icon-btn md-icon-btn--sm md-icon-btn--danger" data-yh-remove="' + escapeHtml(type) + '" aria-label="Удалить умение" data-bs-toggle="tooltip" data-bs-title="Удалить умение"><i class="ti ti-trash" aria-hidden="true"></i></button>'
            + '</header>'
            + '<div class="md-yh-trait__body">'
            + '<div class="md-yh-trait__row">'
            + '<div class="md-field"><label class="md-field__label" for="yh-type-' + uid + '">Тип умения</label><div class="md-field__control"><select class="md-select" id="yh-type-' + uid + '" data-yh-trait-type="' + escapeHtml(type) + '">' + this.typeOptions(type) + '</select></div></div>'
            + '<div class="md-field"><label class="md-field__label" for="yh-object-' + uid + '">Объект</label><div class="md-field__control"><select class="md-select" id="yh-object-' + uid + '" data-yh-trait-object="' + escapeHtml(type) + '">' + this.objectOptions(trait.linked_object || '') + '</select></div></div>'
            + '<div class="md-field"><label class="md-field__label" for="yh-property-' + uid + '">Свойство</label><div class="md-field__control"><select class="md-select" id="yh-property-' + uid + '" data-yh-trait-property="' + escapeHtml(type) + '">' + this.propertyOptions(type, trait.linked_property || '') + '</select></div></div>'
            + '</div>'
            + rangeHtml
            + '<div class="md-yh-trait__row md-yh-trait__row--mapping">'
            + '<div class="md-field"><label class="md-field__label" for="yh-preset-' + uid + '">Соответствие значений</label><div class="md-field__control"><select class="md-select" id="yh-preset-' + uid + '" data-yh-map-preset="' + escapeHtml(type) + '">' + this.presetOptions(trait.value_map_preset || 'none') + '</select></div><div class="md-field__hint">' + escapeHtml(presetLabel) + '</div></div>'
            + '<div class="md-yh-trait__switches">' + reportableHtml + splitHtml + '</div>'
            + '</div>'
            + mapEditor
            + '</div>'
            + '</article>';
    };

    TraitEditor.prototype.render = function () {
        if (!this.container) {
            return;
        }
        destroyTomSelects(this.container);
        var html = '';
        Object.keys(this.traits).forEach(function (type) {
            html += this.cardHtml(type);
        }, this);
        this.container.innerHTML = html;

        if (this.empty) {
            this.empty.hidden = Object.keys(this.traits).length > 0;
        }

        this.container.querySelectorAll('select').forEach(function (select) {
            var isType = select.hasAttribute('data-yh-trait-type');
            initTomSelect(select, !isType);
        }, this);

        Object.keys(this.traits).forEach(function (type) {
            var trait = this.traits[type];
            if (trait.linked_object && !this.propertyCache[trait.linked_object]) {
                this.loadProperties(type);
            }
        }, this);

        this.syncHidden();
        if (window.MDJAdminUI && typeof window.MDJAdminUI.initBootstrapWidgets === 'function') {
            window.MDJAdminUI.initBootstrapWidgets(this.container);
        }
    };

    TraitEditor.prototype.loadProperties = function (type) {
        var trait = this.traits[type];
        if (!trait || !trait.linked_object) {
            return;
        }
        var object = trait.linked_object;
        if (this.propertyCache[object]) {
            this.refreshPropertySelect(type);
            return;
        }
        if (this.propertyRequests[object]) {
            this.propertyRequests[object].then(function () {
                this.refreshPropertySelect(type);
            }.bind(this));
            return;
        }

        var url = (this.data.propertyUrl || '') + '&object=' + encodeURIComponent(object);
        var editor = this;
        this.propertyRequests[object] = window.fetch(url, {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'}
        }).then(function (response) {
            return response.json();
        }).then(function (payload) {
            editor.propertyCache[object] = payload && Array.isArray(payload.PROPERTIES) ? payload.PROPERTIES : [];
            delete editor.propertyRequests[object];
            Object.keys(editor.traits).forEach(function (traitType) {
                if (editor.traits[traitType].linked_object === object) {
                    editor.refreshPropertySelect(traitType);
                }
            });
        }).catch(function () {
            delete editor.propertyRequests[object];
            showMessage(editor.form, 'Не удалось загрузить свойства объекта «' + object + '».', 'error');
        });
    };

    TraitEditor.prototype.refreshPropertySelect = function (type) {
        var trait = this.traits[type];
        if (!trait) {
            return;
        }
        var select = this.container.querySelector('[data-yh-trait-property="' + CSS.escape(type) + '"]');
        if (!select) {
            return;
        }
        var selected = trait.linked_property || '';
        var available = (this.propertyCache[trait.linked_object] || []).map(function (property) {
            return property.TITLE || '';
        });
        if (selected && available.indexOf(selected) === -1) {
            trait.linked_property = '';
            selected = '';
        }
        if (select.tomselect) {
            select.tomselect.destroy();
        }
        delete select.dataset.mdSearchableReady;
        select.innerHTML = this.propertyOptions(type, selected);
        initTomSelect(select, true);
        this.syncHidden();
    };

    TraitEditor.prototype.onClick = function (event) {
        var remove = event.target.closest('[data-yh-remove]');
        if (remove) {
            event.preventDefault();
            this.removeTrait(remove.getAttribute('data-yh-remove'));
            return;
        }

        var mapAdd = event.target.closest('[data-yh-map-add]');
        if (mapAdd) {
            event.preventDefault();
            var mapBox = mapAdd.closest('[data-yh-map]');
            if (mapBox) {
                var row = document.createElement('div');
                row.className = 'md-yh-map__row';
                row.setAttribute('data-yh-map-row', '');
                row.innerHTML = '<input class="md-input md-input--sm" type="text" data-yh-map-src value="" placeholder="1">'
                    + '<select class="md-select md-select--sm" data-yh-map-dst>' + this.mapValues(mapBox.getAttribute('data-yh-map'), '') + '</select>'
                    + '<button type="button" class="md-icon-btn md-icon-btn--sm md-icon-btn--danger" data-yh-map-del aria-label="Удалить соответствие"><i class="ti ti-trash" aria-hidden="true"></i></button>';
                mapAdd.parentNode.insertBefore(row, mapAdd);
                initTomSelect(row.querySelector('select'), true);
            }
            return;
        }

        var mapDelete = event.target.closest('[data-yh-map-del]');
        if (mapDelete) {
            event.preventDefault();
            var rowToRemove = mapDelete.closest('[data-yh-map-row]');
            var mapContainer = mapDelete.closest('[data-yh-map]');
            if (rowToRemove) {
                rowToRemove.remove();
            }
            if (mapContainer) {
                this.collectMap(mapContainer.getAttribute('data-yh-map'));
            }
        }
    };

    TraitEditor.prototype.onChange = function (event) {
        var target = event.target;

        if (target.hasAttribute('data-yh-trait-type')) {
            var oldType = target.getAttribute('data-yh-trait-type');
            this.changeType(oldType, target.value);
            return;
        }

        if (target.hasAttribute('data-yh-trait-object')) {
            var objectType = target.getAttribute('data-yh-trait-object');
            var trait = this.traits[objectType];
            if (trait) {
                trait.linked_object = target.value;
                trait.linked_property = '';
                this.syncHidden();
                this.render();
            }
            return;
        }

        if (target.hasAttribute('data-yh-trait-property')) {
            var propertyType = target.getAttribute('data-yh-trait-property');
            if (this.traits[propertyType]) {
                this.traits[propertyType].linked_property = target.value;
                if (target.value === '') {
                    this.traits[propertyType].linked_object = '';
                }
                this.syncHidden();
                if (target.value === '' && this.traits[propertyType]) {
                    var objectSelect = this.container.querySelector('[data-yh-trait-object="' + CSS.escape(propertyType) + '"]');
                    if (objectSelect) {
                        objectSelect.value = '';
                        if (objectSelect.tomselect) {
                            objectSelect.tomselect.clear(true);
                        }
                    }
                }
            }
            return;
        }

        if (target.hasAttribute('data-yh-map-preset')) {
            var presetType = target.getAttribute('data-yh-map-preset');
            var presetTrait = this.traits[presetType];
            if (!presetTrait) {
                return;
            }
            presetTrait.value_map_preset = target.value;
            if (target.value !== 'custom') {
                var preset = this.data.presets && this.data.presets[target.value] ? this.data.presets[target.value] : null;
                var map = {};
                if (preset && preset.map) {
                    Object.keys(preset.map).forEach(function (source) {
                        map[String(source).toLowerCase()] = preset.map[source];
                    });
                }
                presetTrait.value_map = map;
            }
            this.commit();
            return;
        }

        if (target.hasAttribute('data-yh-reportable')) {
            var reportableType = target.getAttribute('data-yh-reportable');
            if (this.traits[reportableType]) {
                this.traits[reportableType].reportable = target.checked;
                this.syncHidden();
            }
            return;
        }

        if (target.hasAttribute('data-yh-split')) {
            var splitType = target.getAttribute('data-yh-split');
            var settings = this.instanceSettings(splitType);
            settings.split = target.checked;
            if (target.checked && this.traits[splitType]) {
                this.traits[splitType].reportable = false;
            }
            this.commit();
            return;
        }

        if (target.hasAttribute('data-yh-map-src') || target.hasAttribute('data-yh-map-dst')) {
            var mapElement = target.closest('[data-yh-map]');
            if (mapElement) {
                this.collectMap(mapElement.getAttribute('data-yh-map'));
            }
        }
    };

    TraitEditor.prototype.onInput = function (event) {
        var target = event.target;
        if (!target.hasAttribute('data-yh-range')) {
            return;
        }
        var card = target.closest('[data-yh-trait]');
        if (!card) {
            return;
        }
        var type = card.getAttribute('data-yh-trait');
        var settings = this.instanceSettings(type);
        settings.range = settings.range || {};
        var value = parseFloat(target.value);
        if (isFinite(value)) {
            settings.range[target.getAttribute('data-yh-range')] = value;
        } else {
            delete settings.range[target.getAttribute('data-yh-range')];
        }
        this.syncHidden();
    };

    TraitEditor.prototype.collectMap = function (type) {
        var container = this.container.querySelector('[data-yh-map="' + CSS.escape(type) + '"]');
        if (!container || !this.traits[type]) {
            return;
        }
        var map = {};
        container.querySelectorAll('[data-yh-map-row]').forEach(function (row) {
            var source = (row.querySelector('[data-yh-map-src]').value || '').toString().trim().toLowerCase();
            var target = (row.querySelector('[data-yh-map-dst]').value || '').toString().trim();
            if (source !== '' && target !== '') {
                map[source] = target;
            }
        });
        this.traits[type].value_map = map;
        this.syncHidden();
    };

    TraitEditor.prototype.onRequestProperties = function (event) {
        if (!event.detail || !event.detail.type) {
            return;
        }
        this.loadProperties(event.detail.type);
    };

    TraitEditor.prototype.onSubmit = function (event) {
        this.syncHidden();
        if (Object.keys(this.traits).length === 0) {
            event.preventDefault();
            showMessage(this.form, 'Добавьте хотя бы одно умение.', 'error');
            var card = this.form.querySelector('.md-yh-traits-card');
            if (card) {
                card.scrollIntoView({behavior: 'smooth', block: 'start'});
            }
        }
    };

    function initEditors(root) {
        (root || document).querySelectorAll('[data-yh-editor]').forEach(function (form) {
            if (form._yhEditor) {
                return;
            }
            form._yhEditor = new TraitEditor(form);
            form._yhEditor.init();
        });
    }

    // ---------------------------------------------------------------------
    // Settings helpers
    // ---------------------------------------------------------------------

    function generateUuid() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        var bytes = new Uint8Array(16);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(bytes);
        } else {
            for (var i = 0; i < 16; i++) {
                bytes[i] = Math.floor(Math.random() * 256);
            }
        }
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        var hex = Array.prototype.map.call(bytes, function (byte) {
            return byte.toString(16).padStart(2, '0');
        }).join('');
        return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
    }

    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }
        return Promise.reject(new Error('clipboard unavailable'));
    }

    function initSettings(root) {
        (root || document).querySelectorAll('[data-yh-generate]').forEach(function (button) {
            if (button.dataset.yhReady === '1') {
                return;
            }
            button.dataset.yhReady = '1';
            button.addEventListener('click', function () {
                var input = document.getElementById(button.getAttribute('data-yh-generate'));
                if (!input) {
                    return;
                }
                input.value = generateUuid();
                input.dispatchEvent(new Event('input', {bubbles: true}));
                input.focus();
                showToast('Значение сгенерировано. Сохраните настройки.');
            });
        });

        (root || document).querySelectorAll('[data-yh-toggle-password]').forEach(function (button) {
            if (button.dataset.yhReady === '1') {
                return;
            }
            button.dataset.yhReady = '1';
            button.addEventListener('click', function () {
                var input = document.getElementById(button.getAttribute('data-yh-toggle-password'));
                if (!input) {
                    return;
                }
                var visible = input.type === 'text';
                input.type = visible ? 'password' : 'text';
                button.setAttribute('aria-label', visible ? 'Показать пароль' : 'Скрыть пароль');
                var icon = button.querySelector('i');
                if (icon) {
                    icon.className = visible ? 'ti ti-eye' : 'ti ti-eye-off';
                }
            });
        });

        (root || document).querySelectorAll('[data-yh-copy]').forEach(function (button) {
            if (button.dataset.yhReady === '1') {
                return;
            }
            button.dataset.yhReady = '1';
            button.addEventListener('click', function () {
                var text = button.getAttribute('data-yh-copy') || '';
                copyText(text).then(function () {
                    showToast('Скопировано.');
                }).catch(function () {
                    showToast('Не удалось скопировать. Выделите адрес вручную.', 'warning');
                });
            });
        });
    }

    function init(root) {
        initEditors(root);
        initSettings(root);
    }

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('yandexhome', {init: init});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    } else {
        init(document);
    }
})(window, document);
