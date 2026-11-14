/**
 * CORREÇÃO DEFINITIVA - MULTIPAGAMENTO PAGAR.ME
 * PROTEÇÃO CONTRA LIMPEZA DO SESSIONSTORAGE
 * Versão: 5.0.0
 */

(function($) {
    'use strict';

    function $checkoutForms(){
        return jQuery('form.checkout, form#order_review, form[name="checkout"], form[id*="checkout"]');
    }


    const STORAGE_KEY = 'pagarme_multipay_protected_v5';

    function log(message, data) {
        console.log(`[Protected Fix] ${message}`, data || '');
    }

    window.PagarmeMultipaymentProtectedFix = {
        
        /**
         * SALVAR SNAPSHOT (Lê diretamente do DOM)
         */
        saveSnapshot: function() {
            try {
                const isMultipayment = $('#enable-multipayment').is(':checked');
                
                if (!isMultipayment) {
                    return false;
                }

                const methods = [];
                
                $('.multipay-checkbox:checked').each(function() {
                    const method = $(this).val();
                    const amount = parseFloat($(`.multipay-amount[data-method="${method}"]`).val() || 0);

                    if (amount > 0) {
                        const methodData = {
                            method: method,
                            amount: amount
                        };

                        if (['credit_card', 'debit_card', 'voucher'].includes(method)) {
                            methodData.card = {
                                number: $(`.multipay-card-number[data-method="${method}"]`).val() || '',
                                holder: $(`.multipay-card-holder[data-method="${method}"]`).val() || '',
                                expiry: $(`.multipay-card-expiry[data-method="${method}"]`).val() || '',
                                cvv: $(`.multipay-card-cvv[data-method="${method}"]`).val() || '',
                                document: $(`.multipay-card-document[data-method="${method}"]`).val() || '',
                                installments: $(`.multipay-installments[data-method="${method}"]`).val() || '1'
                            };
                        }

                        methods.push(methodData);
                    }
                });

                if (methods.length > 0) {
                    const data = {
                        is_multipayment: true,
                        methods: methods,
                        timestamp: Date.now()
                    };

                    // Salvar em MÚLTIPLAS chaves para garantir backup
                    sessionStorage.setItem(STORAGE_KEY, JSON.stringify(data));
                    sessionStorage.setItem('pagarme_backup_1', JSON.stringify(data));
                    sessionStorage.setItem('pagarme_backup_2', JSON.stringify(data));
                    
                    log(`💾 Salvou ${methods.length} métodos`);
                    return true;
                }

                return false;

            } catch (error) {
                log('❌ Erro ao salvar', error);
                return false;
            }
        },

        /**
         * RECUPERAR DADOS (Com fallback em múltiplas chaves)
         */
        getData: function() {
            try {
                // Tentar recuperar de múltiplas fontes
                const keys = [STORAGE_KEY, 'pagarme_backup_1', 'pagarme_backup_2'];
                
                for (const key of keys) {
                    const stored = sessionStorage.getItem(key);
                    if (stored) {
                        const data = JSON.parse(stored);
                        if (data && data.methods && data.methods.length > 0) {
                            log(`Recuperou ${data.methods.length} métodos de ${key}`);
                            return data;
                        }
                    }
                }

                log('❌ Nenhum backup válido encontrado');
                return null;

            } catch (error) {
                log('❌ Erro ao recuperar', error);
                return null;
            }
        },

        /**
         * INJETAR CAMPOS DO SESSIONSTORAGE
         */
        injectFields: function() {
            try {
                const $form = $checkoutForms();
                const isMultipayment = $('#enable-multipayment').is(':checked');
                
                if (!isMultipayment) {
                    return false;
                }

                log('💉 Injetando campos');

                // Recuperar dados
                const data = this.getData();
                
                if (!data) {
                    log('❌ Sem dados - tentando salvar agora');
                    this.saveSnapshot();
                    
                    const retryData = this.getData();
                    if (!retryData) {
                        log('❌ Ainda sem dados!');
                        return false;
                    }
                }

                const finalData = data || this.getData();

                // Remover campos antigos
                $form.find('[class*="pagarme-multipay"]').remove();
                $form.find('input[name^="multi_"]').remove();
                $form.find('input[name="pagarme_multipayment"]').remove();
                $form.find('input[name="is_multipayment"]').remove();

                // Flags
                $form.append('<input type="hidden" name="pagarme_multipayment" value="yes" class="pagarme-multipay-v5" />');
                $form.append('<input type="hidden" name="is_multipayment" value="1" class="pagarme-multipay-v5" />');

                let methodsCount = 0;

                // Injetar cada método
                finalData.methods.forEach(methodData => {
                    const method = methodData.method;
                    const amount = methodData.amount;

                    if (amount > 0) {
                        methodsCount++;

                        $form.append(`<input type="hidden" name="multi_method_${method}" value="yes" class="pagarme-multipay-v5" />`);
                $form.append(`<input type="hidden" name="pagarme_multipayment_methods[]" value="${method}" class="pagarme-multipay-v5" />`);
                $form.append(`<input type="hidden" name="pagarme_multipayment_amounts[${method}]" value="${amount.toFixed(2)}" class="pagarme-multipay-v5" />`);

                        log(`  ${method}: R$ ${amount.toFixed(2)}`);

                        if (methodData.card) {
                            const card = methodData.card;

                            const cardNumberClean = (card.number || '').replace(/\D/g, '');
                            $form.append(`<input type="hidden" name="multi_card_${method}_number_raw" value="${cardNumberClean}" class="pagarme-multipay-v5" />`);
                            $form.append(`<input type="hidden" name="multi_card_${method}_holder" value="${card.holder || ''}" class="pagarme-multipay-v5" />`);

                            const expiry = (card.expiry || '').replace(/\D/g, '');
                            if (expiry.length === 4) {
                                const mm = expiry.substring(0, 2);
                                const yy = expiry.substring(2, 4);
                                const yyyy = '20' + yy;

                                $form.append(`<input type="hidden" name="multi_card_${method}_exp_month" value="${mm}" class="pagarme-multipay-v5" />`);
                                $form.append(`<input type="hidden" name="multi_card_${method}_exp_year" value="${yyyy}" class="pagarme-multipay-v5" />`);
                                $form.append(`<input type="hidden" name="multi_card_${method}_expiration" value="${mm}${yy}" class="pagarme-multipay-v5" />`);
                            }

                            $form.append(`<input type="hidden" name="multi_card_${method}_cvv" value="${card.cvv || ''}" class="pagarme-multipay-v5" />`);

                            const documentClean = (card.document || '').replace(/\D/g, '');
                            $form.append(`<input type="hidden" name="multi_card_${method}_document" value="${documentClean}" class="pagarme-multipay-v5" />`);

                            if (method === 'credit_card') {
                                $form.append(`<input type="hidden" name="multi_card_${method}_installments" value="${card.installments || 1}" class="pagarme-multipay-v5" />`);
                            }
                        }
                    }
                });

                const total = $form.find('.pagarme-multipay-v5').length;
                log(`Total: ${total} campos (${methodsCount} métodos)`);

                return methodsCount > 0;

            } catch (error) {
                log('❌ Erro ao injetar', error);
                return false;
            }
        },

        /**
         * CONFIGURAR AUTO-SAVE
         */
        setupAutoSave: function() {
            const self = this;

            $(document).on('change', '.multipay-checkbox', function() {
                setTimeout(() => self.saveSnapshot(), 100);
            });

            $(document).on('input change', '.multipay-amount', function() {
                setTimeout(() => self.saveSnapshot(), 100);
            });

            $(document).on('input change', '.multipay-card-number, .multipay-card-holder, .multipay-card-expiry, .multipay-card-cvv, .multipay-card-document, .multipay-installments', function() {
                setTimeout(() => self.saveSnapshot(), 100);
            });

            $(document).on('change', '#enable-multipayment', function() {
                if ($(this).is(':checked')) {
                    setTimeout(() => self.saveSnapshot(), 100);
                }
            });
        },

        /**
         * CONFIGURAR LISTENERS DE INJEÇÃO
         */
        setupInjectionListeners: function() {
            const self = this;

            // ANTES de validar Step 3 - CRÍTICO!
            $(document).on('msc:before:validate:step', function(e, data) {
                if (data && data.step === 3) {
                    log('🔍 ANTES validar Step 3 - salvando AGORA');
                    self.saveSnapshot();
                }
            });

            // Submit
            $(document).on('submit', 'form.checkout', function(e) {
                const isMultipayment = $('#enable-multipayment').is(':checked');
                
                if (isMultipayment) {
                    log('🎯 Submit - salvando e injetando');
                    self.saveSnapshot();
                    self.injectFields();
                }
            });

            // Botão finalizar
            $(document).on('click', '#place_order, .msc-place-order', function(e) {
                const isMultipayment = $('#enable-multipayment').is(':checked');
                
                if (isMultipayment) {
                    log('🎯 Botão - salvando e injetando');
                    self.saveSnapshot();
                    
                    setTimeout(function() {
                        self.injectFields();
                    }, 50);
                }
            });

            // Hook WooCommerce
            $(document.body).on('checkout_place_order_pagarme_gateway', function() {
                const isMultipayment = $('#enable-multipayment').is(':checked');
                
                if (isMultipayment) {
                    log('🎯 Hook WC - injetando');
                    return self.injectFields();
                }
                
                return true;
            });

            // AJAX
            $(document).ajaxSend(function(event, jqxhr, settings) {
                if (settings.url && settings.url.includes('wc-ajax=checkout')) {
                    const isMultipayment = $('#enable-multipayment').is(':checked');
                    
                    if (isMultipayment) {
                        log('🎯 AJAX - salvando e injetando');
                        self.saveSnapshot();
                        self.injectFields();
                    }
                }
            });
        },

        /**
         * DESABILITAR FUNÇÕES ANTIGAS
         */
        disableOldFunctions: function() {
            if (window.injectMultipaymentFieldsRobust) {
                window.injectMultipaymentFieldsRobust = function() { return true; };
            }

            if (window.PagarMeGatewayCheckout && window.PagarMeGatewayCheckout.clearMultipaymentData) {
                const originalClear = window.PagarMeGatewayCheckout.clearMultipaymentData;
                
                window.PagarMeGatewayCheckout.clearMultipaymentData = function() {
                    log('⚠️ clearMultipaymentData chamado - mas sessionStorage protegido!');
                    originalClear.call(this);
                    // NÃO limpar sessionStorage!
                };
            }
        },

        /**
         * FUNÇÃO DE TESTE
         */
        setupTestFunction: function() {
            window.testMultipaymentProtected = function() {
                log('🧪 TESTE PROTEÇÃO');
                log('===================');
                
                const data = window.PagarmeMultipaymentProtectedFix.getData();
                log('📦 Dados:', data);
                
                if (data) {
                    log(`  Métodos: ${data.methods.length}`);
                    data.methods.forEach(m => {
                        log(`    → ${m.method}: R$ ${m.amount}`);
                    });
                }
                
                log('💾 Salvando...');
                const saved = window.PagarmeMultipaymentProtectedFix.saveSnapshot();
                log(`  Resultado: ${saved}`);
                
                log('💉 Injetando...');
                const injected = window.PagarmeMultipaymentProtectedFix.injectFields();
                log(`  Resultado: ${injected}`);
                
                const $form = $checkoutForms();
                const count = {
                    method: $form.find('input[name^="multi_method_"]').length,
                    amount: $form.find('input[name^="multi_amount_"]').length,
                    card: $form.find('input[name^="multi_card_"]').length
                };
                
                log('📊 Campos:');
                log(`  method: ${count.method}`);
                log(`  amount: ${count.amount}`);
                log(`  card: ${count.card}`);
                log('===================');
                
                return { data, saved, injected, count };
            };
        },

        /**
         * INICIALIZAÇÃO
         */
        init: function() {
            log('🚀 Inicializando proteção v5.0');
            
            this.disableOldFunctions();
            this.setupAutoSave();
            this.setupInjectionListeners();
            this.setupTestFunction();
            
            log('Proteção instalada');
        }
    };

    $(document).ready(function() {
        window.PagarmeMultipaymentProtectedFix.init();
    });

})(jQuery);