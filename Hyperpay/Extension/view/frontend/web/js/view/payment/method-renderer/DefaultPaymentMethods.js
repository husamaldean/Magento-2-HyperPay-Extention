define(
    [
        'jquery',
        'ko',
        'Hyperpay_Extension/js/model/iframe',
        'Magento_Checkout/js/view/payment/default',
        'mage/url',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/url-builder',
        'mage/storage',
        'Magento_Customer/js/model/customer',
        'Magento_Checkout/js/model/full-screen-loader',
        'Magento_Checkout/js/action/select-payment-method',
        'Magento_Checkout/js/checkout-data',
        'Magento_Ui/js/modal/modal'
    ],
    function ($, ko, iframe, Component, url, quote, urlBuilder, storage, customer, fullScreenLoader,
              selectPaymentMethodAction,checkoutData,modal
    ) {
        'use strict';

        return Component.extend(
            {
                defaults: {
                    template: 'Hyperpay_Extension/payment/hyperpay',
                    paymentReady: false,
                    iframeIsLoaded: false
                },
                redirectAfterPlaceOrder: false,
                isInAction: iframe.isInAction,

                /** Returns payment acceptance mark image path */
                getPaymentAcceptanceMarkSrc: function () {
                    return window.checkoutConfig.payment[this.getCode()].paymentAcceptanceMarkSrc;
                },
                /**
                 * @return {exports}
                 */
                initObservable: function () {
                    this._super()
                        .observe('paymentReady');
                    this._super()
                        .observe('iframeIsLoaded');
                    return this;
                },
                /**
                 * @return {Boolean}
                 */
                selectPaymentMethod: function () {
                    selectPaymentMethodAction(this.getData());
                    checkoutData.setSelectedPaymentMethod(this.item.method);
                    this.isInAction(true);
                    return true;
                },
                openModal: function () {
                    this.paymentReady(true);
                    fullScreenLoader.startLoader(true);

                    var options = {
                        type: 'popup',
                        responsive: true,
                        innerScroll: true,
                        title: '',
                        buttons:  [{
                            text: $.mage.__('Close'),
                            class: '',
                            click: function () {
                                this.closeModal();
                            }
                        }],
                        backdrop: false,
                        keyboard: false
                };
                var popupp = "#"+this.item.method+"-popup";
                var popup = modal(options, $(popupp));
                $(popupp).modal("openModal");
                $('.modal-popup._inner-scroll .modal-inner-wrap').css('background-color','transparent');
                $('.modal-popup._inner-scroll .modal-inner-wrap').css('box-shadow','none');
                $('.modal-popup._inner-scroll .modal-inner-wrap').css('top','25%');
                $('.action-close').css('color','#ffffff');



                },
            /**
             * @return {*}
             */
                isPaymentReady: function () {
                    return this.paymentReady();
                },
                /**
                 * Hide loader when iframe is fully loaded.
                 */
                iframeLoaded: function () {
                    fullScreenLoader.stopLoader(true);
                },
                BioLink: function () {
                    var serviceUrl,
                        payload,
                        paymentData = quote.paymentMethod();
                    /**
                     * Checkout for guest and registered customer.
                     */
                    if (!customer.isLoggedIn()) {
                        serviceUrl = urlBuilder.createUrl('/guest-carts/:cartId/set-payment-information', {
                            cartId: quote.getQuoteId()
                        });
                        payload = {
                            cartId: quote.getQuoteId(),
                            email: quote.guestEmail,
                            paymentMethod: {method: this.getCode()},
                            billingAddress: quote.billingAddress()
                        };
                    } else {
                        serviceUrl = urlBuilder.createUrl('/carts/mine/set-payment-information', {});
                        payload = {
                            cartId: quote.getQuoteId(),
                            paymentMethod: {method: this.getCode()},
                            billingAddress: quote.billingAddress()
                        };
                    }
                    storage.post(
                        serviceUrl, JSON.stringify(payload)
                    );
                    this.iframeIsLoaded(true);
                    return url.build('hyperpay/index/request?code=' + this.getCode());                }
            });
    }
);
