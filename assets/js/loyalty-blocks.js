/* Bonus points in the Cart and Checkout blocks (OneTrace): the state comes with the cart (extensions.onetrace.loyalty). */
(function (wp, wc) {
    'use strict';

    if (!wp || !wp.plugins || !wp.element || !wc || !wc.blocksCheckout) {
        return;
    }

    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var blocks = wc.blocksCheckout;

    function update(data) {
        return blocks.extensionCartUpdate({ namespace: 'onetrace', data: data });
    }

    function Points(props) {
        // The slot passes the extensions data of the cart as a separate prop.
        var extensions = props.extensions || (props.cart && props.cart.extensions) || {};
        var state = extensions.onetrace && extensions.onetrace.loyalty;
        var points = useState(null);
        var busy = useState(false);
        var error = useState(null);

        if (!state) {
            return null;
        }

        var value = points[0] === null ? state.max : points[0];

        function run(data) {
            busy[1](true);
            error[1](null);
            update(data)
                .catch(function (problem) {
                    error[1]((problem && problem.message) || String(problem));
                })
                .finally(function () {
                    busy[1](false);
                });
        }

        var body = [];

        if (state.link) {
            body.push(el('a', { key: 'link', href: state.link.url }, state.link.text));
        } else if (state.applied) {
            body.push(el('div', { key: 'applied' }, state.applied, ' ', el('button', { type: 'button', className: 'wc-block-components-button is-link', disabled: busy[0], onClick: function () { run({ mode: 'remove' }); } }, state.remove)));
        } else {
            body.push(el('div', { key: 'balance' }, state.balance));

            if (state.max > 0) {
                body.push(el('div', { key: 'form', style: { display: 'flex', gap: '8px', marginTop: '8px' } },
                    el('input', { type: 'number', min: 1, max: state.max, step: 1, value: value, 'aria-label': state.field, style: { width: '7em' }, disabled: busy[0], onChange: function (event) { points[1](event.target.value); } }),
                    el('button', { type: 'button', className: 'wc-block-components-button wp-element-button', disabled: busy[0], onClick: function () { run({ mode: 'apply', points: Number(value) }); } }, el('span', { className: 'wc-block-components-button__text' }, state.apply))));
                body.push(el('small', { key: 'hint', style: { display: 'block', marginTop: '4px' } }, state.hint));
            }
        }

        if (state.earn) {
            body.push(el('small', { key: 'earn', style: { display: 'block', marginTop: '4px' } }, state.earn));
        }

        if (error[0]) {
            body.push(el('div', { key: 'error', role: 'alert', style: { color: '#cc1818', marginTop: '4px' } }, error[0]));
        }

        return el('div', { className: 'wc-block-components-totals-wrapper onetrace-loyalty' },
            el('div', { className: 'wc-block-components-totals-item' },
                el('span', { className: 'wc-block-components-totals-item__label' }, state.title),
                el('div', { className: 'wc-block-components-totals-item__description', style: { width: '100%' } }, body)));
    }

    // ExperimentalOrderMeta passes cart and extensions to its children, in the cart and the checkout summary alike.
    wp.plugins.registerPlugin('onetrace-loyalty', {
        render: function () {
            return el(blocks.ExperimentalOrderMeta, null, el(Points));
        },
        scope: 'woocommerce-checkout',
    });
})(window.wp, window.wc);
