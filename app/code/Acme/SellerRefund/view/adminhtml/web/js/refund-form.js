define([
    'jquery',
    'mage/translate',
    'Magento_Ui/js/modal/confirm',
    'jquery-ui-modules/widget'
], function ($, $t, confirm) {
    'use strict';

    $.widget('acme.acmeRefundForm', {
        options: {
            calculateUrl: '',
            saveUrl: '',
            formKey: '',
            orderId: '',
            currency: 'JPY',
            selectors: {
                qtyInput: '[data-role="qty-input"]',
                lineRow: '[data-role="line-row"]',
                lineAmount: '[data-role="line-amount"]',
                grandTotal: '[data-role="grand-total"]',
                submit: '[data-role="submit"]',
                reason: '[data-role="reason"]',
                state: '[data-role="state"]',
                receiptLink: '[data-role="receipt-link"]'
            }
        },

        /** Widget bootstrap. */
        _create: function () {
            this.lastGrandTotal = '0';
            this._on(this.element.find(this.options.selectors.qtyInput), {change: '_recalculate'});
            this._on(this.element.find(this.options.selectors.submit), {click: '_onSubmit'});
        },

        /** @return {Object} order_item_id => requested qty */
        _collectItems: function () {
            var items = {};
            this.element.find(this.options.selectors.qtyInput).each(function () {
                var qty = $(this).val();
                if (qty !== '' && qty !== null) {
                    items[$(this).data('orderItemId')] = qty;
                }
            });

            return items;
        },

        /** Server-side recalculation on every quantity change. */
        _recalculate: function () {
            var self = this;

            $.ajax({
                url: this.options.calculateUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: this.options.formKey,
                    order_id: this.options.orderId,
                    items: this._collectItems()
                }
            }).done(function (response) {
                if (response.ok && response.figures) {
                    self._paint(response.figures);
                }
            });
        },

        /** Repaint the per-line amounts and the grand total from the server figures. */
        _paint: function (figures) {
            var self = this;

            this.element.find(this.options.selectors.lineAmount).text('-');
            $.each(figures.lines, function (i, line) {
                self.element
                    .find(self.options.selectors.lineRow + '[data-order-item-id="' + line.order_item_id + '"]')
                    .find(self.options.selectors.lineAmount)
                    .text(self._money(line.grand_total));
            });

            this.lastGrandTotal = figures.refund.grand_total;
            this.element.find(this.options.selectors.grandTotal).text(this._money(figures.refund.grand_total));
        },

        /** Confirmation step before any financial action. */
        _onSubmit: function () {
            var self = this;

            confirm({
                title: $t('Confirm refund'),
                content: $t('Refund total:') + ' ' + this._money(this.lastGrandTotal),
                actions: {
                    confirm: function () {
                        self._save();
                    }
                }
            });
        },

        /**
         * Issue the save. BR-11 asks that the receipt link be usable as soon as the operator
         * confirms, so the refunded state and the receipt link are shown right away and the
         * response only fills in the receipt href.
         */
        _save: function () {
            var link = this.element.find(this.options.selectors.receiptLink);

            this.element.find(this.options.selectors.state).text($t('Refunded'));
            link.prop('hidden', false);

            $.ajax({
                url: this.options.saveUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: this.options.formKey,
                    order_id: this.options.orderId,
                    reason_code: this.element.find(this.options.selectors.reason).val(),
                    items: this._collectItems()
                }
            }).done(function (response) {
                if (response.redirect_url) {
                    link.attr('href', response.redirect_url);
                }
            });
        },

        /** @return {String} */
        _money: function (amount) {
            var value = parseFloat(amount || 0);

            return this.options.currency + ' ' + value.toLocaleString('en-US', {maximumFractionDigits: 0});
        }
    });

    return $.acme.acmeRefundForm;
});
