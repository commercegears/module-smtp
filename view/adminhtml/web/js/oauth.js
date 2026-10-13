/**
 * Copy button for the redirect URI and the Disconnect button of the OAuth row.
 */
define(['jquery', 'Magento_Ui/js/modal/confirm'], function ($, confirm) {
    'use strict';

    return function (config, element) {
        $(document).on('click', '[data-cg-smtp-copy]', function () {
            var input = document.getElementById($(this).data('cgSmtpCopy')),
                $button = $(this);

            if (!input) {
                return;
            }
            input.select();
            if (navigator.clipboard) {
                navigator.clipboard.writeText(input.value);
            } else {
                document.execCommand('copy');
            }
            $button.addClass('is-copied');
            setTimeout(function () {
                $button.removeClass('is-copied');
            }, 1500);
        });

        $(element).on('click', '[data-cg-smtp-disconnect]', function () {
            var $button = $(this);

            confirm({
                content: $button.data('confirm'),
                actions: {
                    confirm: function () {
                        $.ajax({
                            url: $button.data('cgSmtpDisconnect'),
                            type: 'POST',
                            dataType: 'json',
                            showLoader: true,
                            data: $.extend({'form_key': window.FORM_KEY}, $button.data('scope') || {})
                        }).always(function () {
                            window.location.reload();
                        });
                    }
                }
            });
        });
    };
});
