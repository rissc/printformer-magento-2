var printformerInstance = null;

define([
    'jquery',
    'jquery/ui',
    'jquery/jquery.parsequery',
    'Magento_Ui/js/modal/modal',
    'mage/translate'
], function ($, $ui, $pq, $modal, $t) {
    'use strict';

    $.widget('mage.printformer', {
        formatChange: false,

        _create: function () {
            var that = this;
            this.form = this.element;
            this.callbacks = {};
            this.addToCartFormUrl = null;
            this.persoOptionAdded = false;

            if(this.isDefined(printformerInstance)) {
                printformerInstance = this;
            }
            $(document).trigger('printformer:loaded:before');

            this.runCallbacks('printformer:loaded:before');
            this._initEditorMain();

            let draftsExist = false;
            $.each(this.options.printformerProducts, function (index, printformerProduct) {
                that.initButton(printformerProduct);
                if (printformerProduct.draft_id) {
                    draftsExist = true;
                }
            });

            this._initAddBtn();
            this._initVariations();
            if(this.options.isConfigure) {
                this.hideSecondButton();
                this.setPrimaryButton();
            }

            if (
                this.isDefined(this.options.personalizations_conf) &&
                this.options.personalizations_conf &&
                this.isDefined(this.options.personalizations) &&
                this.options.personalizations > 1
            ) {
                this.initPersonalisationQty();
            }

            if (this.isDefined(this.options.personalizations) && this.options.personalizations > 1) {
                this.initPersonalisationQty();
            }

            $(document).trigger('printformer:loaded');
            this.runCallbacks('printformer:loaded:after');
            if (draftsExist) {
                            if(this.isDefined(this.options.preselection) && this.options.preselection !== null) {
                                this.runCallbacks('printformer:preselection:before');
                                if (this.options.preselection.product === this.options.ProductId) {
                                    let preselectionFormatted = [];
                                    if (this.isDefined(this.options.preselection.super_attribute)) {
                                        $.each(this.options.preselection.super_attribute, function (index, val) {
                                            preselectionFormatted[index] = val.value;
                                        });
                                        this.preselectOptions(preselectionFormatted);
                                        $.each(this.options.preselection.options, function (index, val) {
                                            let dataSelector = '[data-selector="options\\[' + index + '\\]"]';
                                            $(dataSelector).val(val.value);
                                            window.DynamicProductOptions.checkVisibilityConditions($(dataSelector));

            								if (val.value != undefined) {
            								   let dataSelector = '[id="options_'+ index +'_date"]';
            								   $(dataSelector).val(val.value);
            								   window.DynamicProductOptions.checkVisibilityConditions($(dataSelector));
            								}
                                            if (val.value.date != undefined) {
            								   let dataSelector = '[id="options_'+ index +'_date"]';
            								   $(dataSelector).val(val.value.date);
            								   window.DynamicProductOptions.checkVisibilityConditions($(dataSelector));
            								}
                                        });
                                    }
                                }
                                this.runCallbacks('printformer:preselection:after');
                            }
                        }
        },

        isDefined: function(value) {
            return typeof value !== typeof undefined;
        },

        getUploadUrl: function() {
            return this.options.urls.upload;
        },

        getUploadAndEditorUrl: function() {
            return this.options.urls.uploadAndEditor;
        },

        getPersonalizeUrl: function() {
            return this.options.urls.personalize;
        },

        getEditorUrl: function() {
            // prepare URL
            var urlParts = this.options.urls.customize.split('?'),
                url = urlParts[0],
                inputQty = $(this.options.qtySelector),
                params = $('[data-action="add-to-wishlist"]').data('post'),
                formKey = $('[name="form_key"]'),
                updateWishlistItemOptions = '';
            var action = null;
            if(params) {
                action = params.action.split('/');
            }

            url += '?';
            url += urlParts[1];

            if (inputQty.val()) {
                url += encodeURIComponent('qty/' + inputQty.val());
            }

            if (formKey) {
                url += encodeURIComponent('/form_key/' + formKey.val());
            }

            if(action !== null) {
                $.each(action, function (index, val) {
                    if (val === 'wishlist' || val === 'index') {
                        updateWishlistItemOptions += val + '/';
                    } else if (val === 'updateItemOptions') {
                        updateWishlistItemOptions += val;
                    }
                });
            }

            url += encodeURIComponent('?');
            if(params) {
                $.each(params.data, function (index, val) {
                    if (index !== 'product' && index !== 'qty' && index !== 'uenc') {
                        url += encodeURIComponent('&' + index + '=' + val);
                    }
                });
            }

            if (updateWishlistItemOptions.length) {
                url += encodeURIComponent('&updateWishlistItemOptions=' + updateWishlistItemOptions);
            }

            return url;
        },

        initPersonalisationQty: function() {
            var instance = this;
            if(this.isDefined(this.options.personalizations) && this.options.personalizations > 1) {
                var oldQtyTrans = $(this.options.qtySelector);
                if ($(oldQtyTrans).prop('tagName').toLowerCase() === 'select' && !this.persoOptionAdded) {
                    var persoOption = $('<option/>');
                    $(persoOption).val(parseFloat(this.options.personalizations));
                    $(persoOption).text(this.options.personalizations);
                    var selectChilds = $(oldQtyTrans).children();
                    for(var i = 0; i < selectChilds.length; i++) {
                        var qty = parseInt($(selectChilds[i]).val());
                        var nextQty = parseInt($(selectChilds[i + 1]).val());
                        if(
                            qty < parseInt(instance.printformerOptions.personalizations) &&
                            nextQty > parseInt(instance.printformerOptions.personalizations)
                        ) {
                            $(selectChilds[i + 1]).after($(persoOption));
                            this.persoOptionAdded = true;
                            break;
                        }
                    }
                }
                $(oldQtyTrans).val(this.options.personalizations);
                $(oldQtyTrans).data('pf-perso-count', this.options.personalizations);
                var newQtyTrans = null;
                if ($('#personalisation_qty').length < 1) {
                    newQtyTrans = $('<input/>')
                        .attr('type', 'text')
                        .attr('class', $(oldQtyTrans).attr('class'))
                        .attr('id', 'personalisation_qty')
                        .val(this.options.personalizations)
                        .prop('disabled', true);
                    $(newQtyTrans).insertAfter($(oldQtyTrans));
                }
                $(oldQtyTrans).data('pf-personalized', 'true');
                $(oldQtyTrans).trigger('change').hide();

                if ($('#printformer_personalisations').length < 1) {
                    var personalisationsInput = $('<input value="' + this.options.personalizations + '" type="hidden" id="printformer_personalisations" name="printformer_personalisations"/>');
                    $(personalisationsInput).insertAfter($(newQtyTrans));
                }
            }
        },

        initDraftPersonalizations: function(printformerProduct) {
            if(this.isDefined(printformerProduct.personalisations) && printformerProduct.personalisations > 1) {
                var oldQtyTrans = $(this.options.qtySelector);
                if ($(oldQtyTrans).prop('tagName').toLowerCase() === 'select' && !this.persoOptionAdded) {
                    var persoOption = $('<option/>');
                    $(persoOption).val(parseFloat(printformerProduct.personalisations));
                    $(persoOption).text(printformerProduct.personalisations);
                    var selectChilds = $(oldQtyTrans).children();
                    for(var i = 0; i < selectChilds.length; i++) {
                        var qty = parseInt($(selectChilds[i]).val());
                        var nextQty = parseInt($(selectChilds[i + 1]).val());
                        if(
                            qty < parseInt(printformerProduct.personalisations) &&
                            nextQty > parseInt(printformerProduct.personalisations)
                        ) {
                            $(selectChilds[i + 1]).after($(persoOption));
                            this.persoOptionAdded = true;
                            break;
                        }
                    }
                }
                $(oldQtyTrans).attr('value', printformerProduct.personalisations)
                    .data('pf-perso-count', printformerProduct.personalisations)
                    .data('pf-personalized', 'true')
                    .addClass('disabled')
                    .prop('readonly', true)
                    .trigger('change');

                if ($(oldQtyTrans).prop('readonly')) {
                    $(oldQtyTrans).on('change', function(e){
                        if (
                            parseInt($(oldQtyTrans).val()) !==  printformerProduct.personalisations ||
                            parseInt($(oldQtyTrans).attr('value')) !== printformerProduct.personalisations
                        ) {
                            $(oldQtyTrans).val(printformerProduct.personalisations);
                            $(oldQtyTrans).attr('value', printformerProduct.personalisations)
                        }
                    });
                }

                if ($('#printformer_personalisations').length < 1) {
                    var personalisationsInput = $('<input value="' + printformerProduct.personalisations + '" type="hidden" id="printformer_personalisations" name="printformer_personalisations"/>');
                    $(personalisationsInput).insertAfter($(oldQtyTrans));
                } else {
                    var personalisationsInput = $('#printformer_personalisations');
                    $(personalisationsInput).val(printformerProduct.personalisations);
                }
            }
        },

        hideSecondButton: function(){
            if($(this.uploaBtn).length) {
                $(this.uploaBtn).hide();
            }
        },

        setPrimaryButton: function() {
            var that = this;

            var editDraftIntent = this.options.currentSessionIntent;
            var draftType = 'editor';
            var ButtonText = $t('View draft');
            if(editDraftIntent === 'upload' || editDraftIntent === 'upload-and-editor') {
                draftType = 'upload';
                ButtonText = $t('View upload');
            }

            $(this.editBtn).attr('data-pf-identifier', this.options.identifier);
            $(this.editBtn).attr('data-pf-type', draftType);
            $(this.editBtn).attr('data-pf-intent', editDraftIntent);

            if(draftType === 'upload') {
                $(this.editBtn).unbind('click');
                $(this.editBtn).click({printformer: this}, function(event) {
                    var url = editDraftIntent === 'upload-and-editor' ? that.getUploadAndEditorUrl() : that.getUploadUrl();
                    event.data.printformer.editorMainOpen(url);
                });
            }

            if($(this.editBtn).length) {
                var span = $(this.editBtn).children('span');
                $(span).text(ButtonText);
            }
        },

        _initEditorMain: function () {
            this.editorMain = $(this.options.editorMainSelector);
            this.editorMain.modal({
                modalClass: 'printformer-editor-main-modal',
                title: this.options.productTitle,
                buttons: [],
                modalCloseBtnHandler: this.editorCloseOpen.bind(this)
            });
            this._initEditorClose();
            this._initEditorNotice();
        },

        editorMainOpen: function(editorUrl, beforeHtml, afterHtml) {
            if (beforeHtml === undefined || beforeHtml === null) {
                beforeHtml = '';
            }

            if (afterHtml === undefined || afterHtml === null) {
                afterHtml = '';
            }

            if (!$(this.form).valid()) {
                return;
            }

            this.addToCartFormUrl = $(this.form).attr('action');
            $(this.form).attr('action', editorUrl);
            if (this.options.displayMode === 0) {
                $(this.form).attr('target', 'printformer-main-frame');

                $('html, body').css({
                    'overflow': 'hidden',
                    'height': '100%',
                    'width': '100%'
                });
                this.editorMain.modal('openModal');

                var frameHtml = '';
                frameHtml += beforeHtml;
                frameHtml += '<iframe width="100%"'
                    + '  height="100%"'
                    + '  name="printformer-main-frame"'
                    + '  id="printformer-main-frame"'
                    + '/>'
                    + '<div style=" position:absolute; left:50%; top:50%; transform:translate(-50%, -50%);">'
                    + '<div class="loader-ring"><div></div><div></div><div></div><div></div></div>';

                var openEditorPreviewText = this.options.openEditorPreviewText;
                if(openEditorPreviewText){
                    frameHtml += '<p class="loader-ring-message">' + $t(openEditorPreviewText) + '</p>';
                }

                frameHtml += '</div>';

                frameHtml += afterHtml;

                this.editorMain.html(frameHtml);

                $('#printformer-main-frame').hide().on('load', function () {
                    $('.loader-ring').hide();
                    $('.loader-ring-message').hide();
                    $('#printformer-main-frame').show()
                });
            }

            $(this.form).off();
            $(this.form).submit();
        },

        resetForm: function() {
            $(this.form).attr('action', this.addToCartFormUrl);
            $(this.form).removeAttr('target');
        },

        _initEditorClose: function () {
            this.editorClose = $(this.options.editorCloseSelector);
            this.editorClose.modal({
                modalClass: "printformer-editor-close-modal",
                modalCloseBtnHandler: this.editorCloseCancel.bind(this),
                buttons: [
                    {
                        'text': $t('Yes'),
                        'class': 'ok-btn',
                        'click': this.editorCloseOk.bind(this)
                    },
                    {
                        'text': $t('No'),
                        'class': 'cancel-btn',
                        'click': this.editorCloseCancel.bind(this)
                    }
                ]
            });
        },

        editorCloseOpen: function() {
            this.editorClose.modal('openModal');
        },

        editorCloseOk: function() {
            this.editorMain.modal('closeModal');
            this.editorClose.modal('closeModal');
            $('html, body').css({
                'overflow': 'auto',
                'height': 'auto',
                'width': 'auto'
            });
            this.editorMain.html('');

            this.resetForm();
        },

        editorCloseCancel: function () {
            this.editorClose.modal('closeModal');
            this.resetForm();
        },

        _initEditorNotice: function () {
            this.editorNotice = $(this.options.editorNoticeSelector);
            if (!this.editorNotice) {
                return;
            }
            this.editorNotice.modal({
                modalClass: "printformer-editor-notice-modal",
                modalCloseBtnHandler: this.editorNoticeCancel.bind(this),
                buttons: [
                    {
                        'text': 'OK',
                        'class': 'ok-btn',
                        'click': this.editorNoticeOk.bind(this)
                    },
                    {
                        'text': 'Cancel',
                        'class': 'cancel-btn',
                        'click': this.editorNoticeCancel.bind(this)
                    }
                ]
            });
        },

        editorNoticeOpen: function () {
            this.editorNotice.modal('openModal');
        },

        editorNoticeOk: function () {
            this.formatChange = true;
            this.editorNotice.modal('closeModal');
            this.resetForm();
        },

        editorNoticeCancel: function () {
            this.formatChange = false;
            this.editorNotice.modal('closeModal');
            if(!this.formatChange) {
                for (var id in this.options.variationsFront) {
                    $("#" + id).val(this.options.variationsFront[id]).trigger('change', {skip: true});
                }
            }
            this.resetForm();
        },

        setButtonText: function(button, text) {
            var span = $(button).children('span');
            var icon = $(span).children('i');
            $(span).text(text);
            if($(icon).length) {
                $(span).text(' ' + $(span).text());
                $(span).prepend($(icon))
            }
        },

        _initAddBtn: function () {
            var draftIds = [];
            $.each(this.options.printformerProducts, function (index, printformerProduct) {
                if (printformerProduct['draft_id']) {
                    for (var idx = 0; idx < draftIds.length; idx++) {
                        if (draftIds[idx] === printformerProduct['draft_id']) {
                            return true;
                        }
                    }

                    draftIds.push(printformerProduct['draft_id']);
                }
            });

            this.addBtn = $(this.options.addBtnSelector);

            if (draftIds.length > 0) {
                var draftIdInput = $('<input value="' + draftIds + '" type="hidden" id="printformer_draftid" name="printformer_draftid"/>');
                $(this.options.qtySelector).after(draftIdInput);

                if (this.options.uniqueId) {
                    var uniqueIdInput = $('<input value="' + this.options.uniqueId + '" type="hidden" id="printformer_unique_session_id" name="printformer_unique_session_id"/>');
                    $(draftIdInput).after(uniqueIdInput);
                }
            }

            let dataPfTemplateDraftContainer = $(':visible[data-pf-template-container]').children('[data-pf-draft]');

            if (dataPfTemplateDraftContainer.length === 0) {
                this.addBtnEnable();
            } else {
                this.addBtnDisable();
                var addBtn = this.addBtn;
                $.each(dataPfTemplateDraftContainer, function (index, pfTemplate) {
                    if ($(pfTemplate).data('pf-draft') === 'active'){
                        addBtn.prop('disabled', false);
                    }
                }, addBtn);
            }
            this.allDraftsDone(draftIds, this.options.printformerProducts);
        },

        allDraftsDone: function(draftIds, allDrafts) {
            switch (this.options.allowSkipConfig) {
                case 0:
                    if (draftIds.length < allDrafts.length) {
                        return this.addBtnDisable();
                    }
                    break;
                case 2:
                    if (allDrafts.length > 0 && draftIds.length < 1) {
                        return this.addBtnDisable();
                    }
                    break;
                default:
                    return this.addBtnEnable();
            }
        },

        addBtnEnable: function () {
            this.addBtn.prop('disabled', false);
        },

        addBtnDisable: function () {
            this.addBtn.prop('disabled', true);
        },

        initButton: function(printformerProduct) {
            let button = $(this.options.buttonSelector + printformerProduct['product_id'] + '-' + printformerProduct['template_id']);
            let url = new URL(printformerProduct['url']);
            let search_params = url.searchParams;

            button.click({printformer: this}, function(event) {
                search_params.set('selected_product_id', $(this).parents('div').data('product-id'));
                event.data.printformer.editorMainOpen(url);
            });

            button.prop('disabled', false);

            if (printformerProduct['draft_id']) {
                if (printformerProduct['intent'] == 'upload' || printformerProduct['intent'] == 'upload-and-editor') {
                    this.setButtonText($(button), $t('View upload'));
                } else {
                    this.setButtonText($(button), $t('View draft'));
                }
                button.data('pf-draft', 'active');
                button.siblings('.printformer-delete[data-printformer-product="'+printformerProduct['template_id']+'"]').css('display', '');
            } else {
                button.siblings('.printformer-delete[data-printformer-product="'+printformerProduct['template_id']+'"]').hide();
            }

            this.initDeleteButton(printformerProduct);
        },

        initDeleteButton: function(printformerProduct) {
            var instance = this;

            if (!this.isDefined(printformerProduct.delete_url)) {
                return;
            } else {
                let url = new URL(printformerProduct.delete_url);
                let search_params = url.searchParams;
                search_params.set('selected_product_id', printformerProduct.product_id);
                search_params.set('selected_product_draft_id', printformerProduct.draft_id);
                printformerProduct.delete_url = url;
            }

            var confirmModal = $('#printformer-delete-confirm-' + printformerProduct.product_id + '-' + printformerProduct.template_id).modal({
                modalClass: "printformer-editor-close-modal",
                buttons: [
                    {
                        'text': $t('Yes'),
                        'class': 'ok-btn',
                        'click': function () {
                            window.location.href = printformerProduct.delete_url;
                            confirmModal.modal('closeModal');

                            return true;
                        }
                    }, {
                        'text': $t('No'),
                        'class': 'cancel-btn',
                        'click': function () {
                            confirmModal.modal('closeModal');

                            return true;
                        }
                    }
                ]
            });

            if (printformerProduct.draft_id !== null) {
                let button = $('#printformer-delete-' + printformerProduct.product_id + '-' + printformerProduct.template_id);
                $(button).prop('disabled', false);

                $(button).on('click', function(e) {
                    e.preventDefault();

                    confirmModal.modal('openModal');

                    return false;
                });
            }
        },

        _initVariations: function () {
            var varConf = this.options.variationsConfig;
            if (typeof varConf !== 'object') {
                return;
            }
            for (var id in varConf) {
                var input = $('#' + id);
                if (!input.length) {
                    continue;
                }
                //@Todo disable button
                input.change({printformer: this}, function(event, skip){
                    if (skip) {
                        return;
                    }
                    event.data.printformer.setVariation($(this).attr('id'), $(this).val());
                });
                for (var k in this.options.variations) {
                    if (varConf[id]['param'] === k) {
                        for (var mk in varConf[id]['map']) {
                            if (varConf[id]['map'][mk] === this.options.variations[k]) {
                                input.val(mk);
                            }
                        }
                    }
                }
                input.change();
            }

            if(this.isDefined(this.options.preselection.qty) && this.options.preselection !== null) {
                if(parseInt(this.options.preselection.qty.value) !==  parseInt(this.options.qty)) {
                    this.options.qty = this.options.preselection.qty.value
                }
            }
        },

        setVariation: function (id, value) {
            var varConf = this.options.variationsConfig;
            if (!id
                || !varConf[id]
                || !varConf[id]['param'].length) {
                return;
            }
            if (!this.formatChange
                && varConf[id]['notice']
                && this.options.variationsFront[id] !== undefined
                && this.options.variationsFront[id].length) {
                this.editorNotice.modal('openModal');
                return;
            }
            this.editBtnEnable();
            this.uploadBtnEnable();
            this.options.variationsFront[id] = value;
            this.options.variations[varConf[id]['param']] = varConf[id]['map'][value];
            for (var k in this.options.variations) {
                if (this.options.variations[k] === undefined || !this.options.variations[k].length) {
                    //@todo disable button
                }
            }
        },

        preselectOptions: function(preselectionFormatted) {
            if (this.isDefined(preselectionFormatted)) {
                let selectors = {
                        formSelector: '#product_addtocart_form',
                        swatchSelector: '.swatch-opt'
                    },
                    swatchWidgetName = 'mage-SwatchRenderer',
                    swatchWidget = $(selectors.swatchSelector).data(swatchWidgetName);

                if (!swatchWidget || !swatchWidget._EmulateSelectedByAttributeId) {
                    return this;
                }

                swatchWidget._EmulateSelectedByAttributeId(preselectionFormatted);
            }
            return this;
        },

        registerCallback: function(event, name, method) {
            if(!this.isDefined(this.callbacks[event])) {
                this.callbacks[event] = {};
            }
            this.callbacks[event][name] = method;
            $(document).trigger('printformer_callback_registered', event, name, method);
        },

        runCallbacks: function(event) {
            if(!this.isDefined(event)) {
                $.each(this.callbacks, function(i, eventCallbacks){
                    $.each(eventCallbacks, function(i, methodCallback){
                        methodCallback();
                    });
                });
            } else {
                $.each(this.callbacks[event], function(i, methodCallback){
                    methodCallback();
                });
            }
        }
    });

    return $.mage.printformer;
});
