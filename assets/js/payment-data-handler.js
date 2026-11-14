/**
 * PAYMENT DATA HANDLER - Versão Atualizada
 * 
 * Atualizado para trabalhar com PaymentMethodSelector
 * 
 * MUDANÇAS:
 * - Agora usa PaymentMethodSelector.getCurrentMethod()
 * - Sincronização melhorada entre selector e dados
 * - Restauração automática de dados do cartão
 * 
 * ARQUIVO: assets/js/payment-data-handler.js (SUBSTITUI O EXISTENTE)
 */

(function($) {
    'use strict';
    
    window.PagarmePaymentData = {
        storageKey: 'pagarme_payment_data_v2',
        saveTimeout: null,
        
        /**
         * Inicializar
         */
        init: function() {
            console.log('Inicializando PagarmePaymentData');
            
            this.setupAutoSave();
            this.setupCardDetection();
            this.setupRestoreOnStepLoad();
        },
        
        /**
         * Salvar dados do formulário
         */
        saveFormData: function() {
            var cardNumber = $('#pagarme-card-number').val();
            var cleanCardNumber = cardNumber ? cardNumber.replace(/\D/g, '') : '';
            var documentType = $('input[name="document_type"]:checked').val() || 'cpf';
            
            // Capturar método do PaymentMethodSelector primeiro
            var pagarmeMethod = null;
            
            if (window.PaymentMethodSelector && typeof window.PaymentMethodSelector.getCurrentMethod === 'function') {
                pagarmeMethod = window.PaymentMethodSelector.getCurrentMethod();
                console.log('Método do Selector:', pagarmeMethod);
            }
            
            // Fallback: buscar no DOM
            if (!pagarmeMethod) {
                pagarmeMethod = $('input[name="pagarme_payment_method"]:checked').val();
                console.log('Método do DOM:', pagarmeMethod);
            }
            
            // Método WooCommerce
            var wooMethod = $('input[name="payment_method"]:checked').val();
            
            var data = {
                // Métodos de pagamento
                woo_payment_method: wooMethod || 'pagarme_gateway',
                payment_method: pagarmeMethod || 'credit_card',
                
                // Dados do cartão
                card_holder_name: $('#pagarme-card-holder-name').val() || '',
                card_expiry: $('#pagarme-card-expiry').val() || '',
                card_document: $('#pagarme-card-document').val() || '',
                document_type: documentType,
                installments: $('#pagarme-installments').val() || '1',
                
                // Metadados
                card_last_4: cleanCardNumber.length >= 4 ? cleanCardNumber.slice(-4) : '',
                card_brand: this.detectCardBrand(cleanCardNumber),
                has_card_number: cleanCardNumber.length >= 13,
                has_cvc: $('#pagarme-card-cvc').val().length >= 3,
                timestamp: Date.now()
            };
            
            try {
                sessionStorage.setItem(this.storageKey, JSON.stringify(data));
                console.log('Dados salvos:', {
                    method: data.payment_method,
                    holder: data.card_holder_name ? '' : '❌',
                    number: data.has_card_number ? '' : '❌',
                    expiry: data.card_expiry ? '' : '❌',
                    cvc: data.has_cvc ? '' : '❌'
                });
            } catch (e) {
                console.error('Erro ao salvar dados:', e);
            }
        },
        
        /**
         * Restaurar dados do formulário
         */
        restoreFormData: function() {
            console.log('Restaurando dados...');
            
            try {
                var savedData = sessionStorage.getItem(this.storageKey);
                if (!savedData) {
                    console.log('ℹNenhum dado salvo');
                    return;
                }
                
                var data = JSON.parse(savedData);
                
                // Restaurar campos do cartão (apenas se for cartão)
                if (data.payment_method === 'credit_card') {
                    if (data.card_holder_name) {
                        $('#pagarme-card-holder-name').val(data.card_holder_name);
                    }
                    
                    if (data.card_expiry) {
                        $('#pagarme-card-expiry').val(data.card_expiry);
                    }
                    
                    if (data.card_document) {
                        $('#pagarme-card-document').val(data.card_document);
                    }
                    
                    if (data.document_type) {
                        $('input[name="document_type"][value="' + data.document_type + '"]').prop('checked', true);
                    }
                    
                    if (data.installments) {
                        $('#pagarme-installments').val(data.installments);
                    }
                    
                    console.log('Valores restaurados');
                } else {
                    console.log('ℹMétodo não é cartão, ignorando restauração de campos');
                }
                
            } catch (e) {
                console.error('Erro ao restaurar dados:', e);
            }
        },
        
        /**
         * Detectar bandeira do cartão
         */
        detectCardBrand: function(cardNumber) {
            if (!cardNumber || cardNumber.length < 4) {
                return '';
            }
            
            var patterns = {
                'visa': /^4/,
                'mastercard': /^5[1-5]/,
                'amex': /^3[47]/,
                'diners': /^3(?:0[0-5]|[68])/,
                'discover': /^6(?:011|5)/,
                'jcb': /^(?:2131|1800|35)/,
                'elo': /^(4011|4312|4389|4514|4576|5041|5066|5090|6277|6362|6363|6504|6505|6516)/
            };
            
            for (var brand in patterns) {
                if (patterns[brand].test(cardNumber)) {
                    return brand;
                }
            }
            
            return '';
        },
        
        /**
         * Configurar auto-save ao digitar
         */
        setupAutoSave: function() {
            var self = this;
            
            var fieldsToWatch = [
                '#pagarme-card-number',
                '#pagarme-card-holder-name',
                '#pagarme-card-expiry',
                '#pagarme-card-cvc',
                '#pagarme-card-document',
                '#pagarme-installments',
                'input[name="document_type"]'
            ];
            
            $(document).on('input change blur', fieldsToWatch.join(','), function() {
                clearTimeout(self.saveTimeout);
                self.saveTimeout = setTimeout(function() {
                    self.saveFormData();
                }, 300);
            });
            
            console.log('Auto-save configurado');
        },
        
        /**
         * Configurar detecção de bandeira do cartão
         */
        setupCardDetection: function() {
            var self = this;
            
            $(document).on('input', '#pagarme-card-number', function() {
                var cardNumber = $(this).val().replace(/\D/g, '');
                var brand = self.detectCardBrand(cardNumber);
                
                if (brand) {
                    $('#card-brand').text(brand.toUpperCase()).show();
                } else {
                    $('#card-brand').text('').hide();
                }
            });
        },
        
        /**
         * Configurar restauração ao carregar step
         */
        setupRestoreOnStepLoad: function() {
            var self = this;
            
            // Ao entrar no Step 3
            $(document.body).on('msc:enterStep:3', function() {
                console.log('Step 3 ativado - agendando restauração');
                
                setTimeout(function() {
                    self.restoreFormData();
                }, 800);
            });
            
            // Quando o checkout for atualizado
            $(document.body).on('updated_checkout', function() {
                if ($('.msc-step[data-step="3"]').is(':visible')) {
                    console.log('Checkout atualizado no Step 3');
                    
                    setTimeout(function() {
                        self.restoreFormData();
                    }, 300);
                }
            });
        }
    };
    
    /**
     * INICIALIZAÇÃO AUTOMÁTICA
     */
    $(document).ready(function() {
        window.PagarmePaymentData.init();
    });
    
})(jQuery);