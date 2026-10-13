/**
 * Send Test Email, Check Connection and Check DNS Records on the settings page.
 */
define(['jquery'], function ($) {
    'use strict';

    return function (config, element) {
        var $root = $(element),
            $result = $root.find('[data-role="result"]'),
            busy = false;

        function el(tag, className, text) {
            var node = document.createElement(tag);

            if (className) {
                node.className = className;
            }
            if (text !== undefined && text !== null) {
                node.textContent = String(text);
            }
            return node;
        }

        function addDetails(box, title, text) {
            var details, pre;

            if (!text) {
                return;
            }
            details = el('details', 'commercegears-smtp-details');
            details.appendChild(el('summary', '', title));
            pre = el('pre', '', text);
            details.appendChild(pre);
            box.appendChild(details);
        }

        function renderResult(data) {
            var box = el('div', 'message ' + (data.success ? 'message-success' : 'message-error') + ' commercegears-smtp-result-box'),
                table, list;

            box.appendChild(el('strong', '', data.message || ''));
            if (data.hint) {
                box.appendChild(el('p', '', data.hint));
            }
            if (data.warnings && data.warnings.length) {
                list = el('ul', 'commercegears-smtp-warnings');
                data.warnings.forEach(function (warning) {
                    list.appendChild(el('li', '', warning));
                });
                box.appendChild(list);
            }
            if (data.details && Object.keys(data.details).length) {
                table = el('div', 'commercegears-smtp-detail-list');
                Object.keys(data.details).forEach(function (key) {
                    table.appendChild(el('span', 'commercegears-smtp-detail-key', key));
                    table.appendChild(el('span', 'commercegears-smtp-detail-value', data.details[key]));
                });
                box.appendChild(table);
            }
            addDetails(box, config.labels.details, data.technical);
            addDetails(box, config.labels.transcript, data.transcript);
            return box;
        }

        function renderDns(data) {
            var table = el('table', 'commercegears-smtp-dns-table'),
                wrapper = el('div', 'commercegears-smtp-result-box');

            if (data.message) {
                wrapper.appendChild(el('p', '', data.message));
            }
            (data.checks || []).forEach(function (check) {
                var row = el('tr', 'is-' + check.status),
                    cell = el('td');

                row.appendChild(el('th', '', check.check));
                row.appendChild(el('td', 'commercegears-smtp-badge-cell')).appendChild(el('span', 'commercegears-smtp-badge is-' + check.status, check.label));
                cell.appendChild(el('div', '', check.message));
                if (check.record) {
                    cell.appendChild(el('code', 'commercegears-smtp-record', check.record));
                }
                row.appendChild(cell);
                table.appendChild(row);
            });
            wrapper.appendChild(table);
            return wrapper;
        }

        function run(action, extra) {
            if (busy) {
                return;
            }
            busy = true;
            $result.empty().append(el('p', 'commercegears-smtp-muted', config.labels.working));
            $root.find('button[data-action]').prop('disabled', true);
            $.ajax({
                url: config.urls[action],
                type: 'POST',
                dataType: 'json',
                data: $.extend({'form_key': window.FORM_KEY}, config.scope, extra || {})
            }).done(function (data) {
                $result.empty().append(action === 'dns' ? renderDns(data) : renderResult(data));
            }).fail(function () {
                $result.empty().append(renderResult({success: false, message: config.labels.failed}));
            }).always(function () {
                busy = false;
                $root.find('button[data-action]').prop('disabled', false);
                $result.trigger('focus');
            });
        }

        $root.on('click', 'button[data-action="test"]', function () {
            run('test', {to: $root.find('#cg-smtp-test-to').val()});
        });
        $root.on('click', 'button[data-action="connection"]', function () {
            run('connection');
        });
        $root.on('click', 'button[data-action="dns"]', function () {
            run('dns', {
                domain: $root.find('#cg-smtp-dns-domain').val(),
                selector: $root.find('#cg-smtp-dns-selector').val()
            });
        });
    };
});
