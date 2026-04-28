/**
 * MP Creator Notifier — PAPS Checkout JS
 * Affiche en temps réel les frais de livraison PAPS selon l'adresse saisie
 */
jQuery(function ($) {
    'use strict';

    var params = window.mp_paps_params || {};
    var $info = null;

    function injectInfoBlock() {
        if ($('#mp-paps-live-fee').length) return;

        var $shippingSection = $('.woocommerce-shipping-totals, .shipping');
        if (!$shippingSection.length) return;

        $info = $(
            '<tr id="mp-paps-live-fee" style="display:none;">' +
            '  <th>Estimation livraison PAPS</th>' +
            '  <td id="mp-paps-fee-value" style="font-weight:600;color:#2271b1;">—</td>' +
            '</tr>'
        );

        $shippingSection.after($info);
    }

    function fetchFee() {
        var city    = $('#billing_city, #shipping_city').filter(':visible').first().val() || '';
        var state   = $('#billing_state, #shipping_state').filter(':visible').first().val() || '';
        var country = $('#billing_country, #shipping_country').filter(':visible').first().val() || '';

        if (!city && !country) return;

        injectInfoBlock();

        if ($info) {
            $info.show();
            $('#mp-paps-fee-value').html(
                '<span style="color:#888;font-weight:normal;">' +
                (params.loading_text || 'Calcul en cours…') +
                '</span>'
            );
        }

        $.ajax({
            url: params.ajax_url,
            type: 'POST',
            data: {
                action: 'mp_get_paps_shipping_fee',
                nonce: params.nonce,
                city: city,
                state: state,
                country: country,
            },
            success: function (response) {
                if (!$info) return;

                if (response.success) {
                    var d = response.data;
                    var html = '<strong>' + d.price_formatted + '</strong>';

                    if (d.distance && parseInt(d.distance) > 0) {
                        html += ' <small style="color:#999;font-weight:normal;">(' + d.distance + ' km · ' + d.package_size + ')</small>';
                    }

                    $('#mp-paps-fee-value').html(html);
                } else {
                    $('#mp-paps-fee-value').html(
                        '<span style="color:#999;font-size:12px;">Non disponible</span>'
                    );
                }
            },
            error: function () {
                if ($info) {
                    $('#mp-paps-fee-value').html(
                        '<span style="color:#999;font-size:12px;">Erreur réseau</span>'
                    );
                }
            },
        });
    }

    var debounceTimer = null;
    function debouncedFetch() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(fetchFee, 800);
    }

    $(document.body).on(
        'change',
        '#billing_city, #billing_state, #billing_country, #shipping_city, #shipping_state, #shipping_country',
        debouncedFetch
    );

    $(document.body).on('updated_checkout updated_cart_totals', function () {
        injectInfoBlock();
        debouncedFetch();
    });

    setTimeout(function () {
        var city = $('#billing_city, #shipping_city').filter(':visible').first().val();
        if (city) fetchFee();
    }, 500);
});