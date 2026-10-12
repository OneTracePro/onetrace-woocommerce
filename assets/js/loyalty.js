/* Bonus points in the classic cart and checkout (OneTrace): apply or remove, then WooCommerce recalculates the totals. */
(function ($) {
    'use strict';

    if (!window.onetraceLoyalty) {
        return;
    }

    var settings = window.onetraceLoyalty;

    function refresh() {
        if ($('form.woocommerce-checkout').length) {
            $(document.body).trigger('update_checkout');
        } else {
            $(document.body).trigger('wc_update_cart');
        }
    }

    function send(data, $row) {
        $row.css('opacity', 0.5);

        $.post(settings.url, $.extend({ action: settings.action, nonce: settings.nonce }, data))
            .done(refresh)
            .fail(function (response) {
                var message = response.responseJSON && response.responseJSON.data && response.responseJSON.data.message;
                window.alert(message || settings.error);
            })
            .always(function () {
                $row.css('opacity', '');
            });
    }

    $(document.body).on('click', '.onetrace-loyalty__apply', function (event) {
        event.preventDefault();
        var $row = $(this).closest('.onetrace-loyalty');
        send({ mode: 'apply', points: $row.find('.onetrace-loyalty__points').val() }, $row);
    });

    $(document.body).on('keydown', '.onetrace-loyalty__points', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            $(this).closest('.onetrace-loyalty').find('.onetrace-loyalty__apply').trigger('click');
        }
    });

    $(document.body).on('click', '.onetrace-loyalty__remove', function (event) {
        event.preventDefault();
        send({ mode: 'remove' }, $(this).closest('.onetrace-loyalty'));
    });
})(jQuery);
