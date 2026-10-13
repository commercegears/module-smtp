/**
 * Shows the notes of the selected provider under the provider select.
 */
define(['jquery'], function ($) {
    'use strict';

    return function (config, element) {
        var $root = $(element),
            $select = $(config.select);

        function update() {
            var preset = config.presets[$select.val()] || null;

            $root.find('[data-role="server"]').text(preset && preset.server ? config.serverLabel + ' ' + preset.server : '');
            $root.find('[data-role="help"]').text(preset ? preset.help : '');
            $root.toggle(Boolean(preset && preset.help));
        }

        $select.on('change', update);
        update();
    };
});
