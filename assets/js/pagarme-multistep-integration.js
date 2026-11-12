/**
 * INTEGRAÇÃO PAGAR.ME + MULTISTEP CHECKOUT
 *
 * Este script garante sincronização perfeita entre:
 * - Pagar.me Gateway v3.0.2
 * - Multistep Checkout Pro v4
 *
 * Funções:
 * Detectar mudanças de step
 * Forçar re-renderização quando necessário
 * Sincronizar dados entre steps
 * Prevenir conflitos de eventos
 *
 * Data: 03/11/2025
 */

(function($) {
    'use strict';

    console.log('Pagar.me + Multistep Integration carregado');

    var PagarMeMultistepIntegration = {

        initialized: false,
        currentStep: 1,
        lastRenderAttempt: 0,
        renderDebounceDelay: 500,

        init: function() {
            if (this.initialized) {
                console.log('Integração já inicializada');
                return;
            }

            console.log('Inicializando integração Pagar.me + Multistep');

            this.bindMultistepEvents();
            this.setupPaymentMethodObserver();
            this.patchWooCommerceEvents();

            this.initialized = true;
            console.log('Integração inicializada');
        },

        /**
         * Bind eventos do Multistep
         */
        bindMultistepEvents: function() {
            const self = this;

            // Evento de mudança de step
            $(document.body).on('msc_step_changed', function(e, step) {
                console.log('MSC Step changed:', step);
                self.currentStep = step;
                self.handleStepChange(step);
            });

            // Evento antes de mudar step
            $(document.body).on('msc_before_step_change', function(e, fromStep, toStep) {
                console.log('MSC Before step change:', fromStep, '→', toStep);
                
                if (toStep === 3 || toStep === '3') {
                    self.preparePaymentStep();
                }
            });

            // Evento após o Multistep atualizar fragmentos
            $(document.body).on('msc_fragments_updated', function() {
                console.log('MSC Fragments updated');
                self.checkAndRenderPaymentFields();
            });
        },

        /**
         * Lidar com mudança de step
         */
        handleStepChange: function(step) {
            const self = this;

            // Step 3 = Pagamento
            if (step === 3 || step === '3') {
                console.log('Entrando no step de pagamento');

                // Múltiplas tentativas de renderização com delays crescentes
                const attempts = [
                    { delay: 300, label: 'Tentativa 1' },
                    { delay: 600, label: 'Tentativa 2' },
                    { delay: 1000, label: 'Tentativa 3' },
                    { delay: 1500, label: 'Tentativa 4 (final)' }
                ];

                attempts.forEach(function(attempt) {
                    setTimeout(function() {
                        self.checkAndRenderPaymentFields(attempt.label);
                    }, attempt.delay);
                });

                // Forçar atualização do WooCommerce
                setTimeout(function() {
                    if (typeof window.wc_pagarme_update_checkout === 'function') {
                        console.log('Forçando wc_pagarme_update_checkout');
                        window.wc_pagarme_update_checkout();
                    }
                }, 800);
            }

            // Step 4 = Revisão
            if (step === 4 || step === '4') {
                console.log('Step 4 - Revisão');
                this.syncPaymentDataToReview();
            }
        },

        /**
         * Preparar step de pagamento
         */
        preparePaymentStep: function() {
            console.log('Preparando step de pagamento...');

            // Garantir que o gateway Pagar.me esteja visível
            const $pagarmeGateway = $('li.payment_method_pagarme_gateway');
            
            if ($pagarmeGateway.length > 0) {
                $pagarmeGateway.show();
                
                // Se não estiver marcado, marcar
                const $radio = $pagarmeGateway.find('input[type="radio"]');
                if ($radio.length > 0 && !$radio.is(':checked')) {
                    $radio.prop('checked', true).trigger('change');
                }
            }
        },

        /**
         * Verificar e renderizar campos de pagamento
         */
        checkAndRenderPaymentFields: function(label) {
            label = label || 'Check';
            
            // Debounce para evitar múltiplas chamadas simultâneas
            const now = Date.now();
            if (now - this.lastRenderAttempt < this.renderDebounceDelay) {
                console.log('⏳ Debouncing render check...');
                return;
            }
            this.lastRenderAttempt = now;

            console.log('' + label + ': Verificando campos de pagamento...');

            // Verificar se o Pagar.me está selecionado
            const selectedPayment = $('input[name="payment_method"]:checked').val();
            
            if (selectedPayment !== 'pagarme_gateway') {
                console.log('ℹPagar.me não está selecionado');
                return;
            }

            // Verificar se os campos já existem
            let $paymentBox = $('.payment_box.payment_method_pagarme_gateway');
            const $existingFields = $('input[name="pagarme_payment_type"]');

            // PATCH: GARANTIR QUE O CONTAINER EXISTA E ESTEJA VISÍVEL
            if ($paymentBox.length === 0 || $paymentBox.html().trim() === '') {
                if ($paymentBox.length === 0) {
                    console.log('Payment box não encontrado.');
                } else {
                    console.log('Payment box encontrado, mas está vazio.');
                }
                
                const $paymentMethod = $('li.payment_method_pagarme_gateway');
                
                if ($paymentMethod.length > 0) {
                    $paymentBox = $paymentMethod.find('.payment_box.payment_method_pagarme_gateway');
                    
                    if ($paymentBox.length === 0) {
                         // Recriar a div se foi removida pelo AJAX/DOM manipulation
                        $paymentMethod.append('<div class="payment_box payment_method_pagarme_gateway" style="display: block; opacity: 1; height: auto;"></div>');
                        $paymentBox = $('.payment_box.payment_method_pagarme_gateway');
                        console.log('Payment box recriado dinamicamente');
                    } else {
                        // Se o box existia mas estava hidden, forçar visibilidade
                        $paymentBox.css({'display': 'block', 'opacity': 1, 'height': 'auto'});
                    }
                } else {
                    console.log('Item LI do Pagar.me não encontrado. Não é possível renderizar.');
                    return;
                }
            }
            
            // Se o box existir, garantir que esteja visível (override CSS do Multistep/WC)
            if ($paymentBox.length > 0) {
                $paymentBox.css({'display': 'block', 'opacity': 1, 'height': 'auto'});
            }

            if ($existingFields.length > 0) {
                console.log('Campos já existem (' + $existingFields.length + ')');
                return;
            }

            // Campos não existem, forçar renderização
            console.log('Forçando renderização dos campos...');
            
            if (typeof window.PagarMeGatewayCheckout !== 'undefined') {
                // Esta função está no checkout.js e agora terá um alvo garantido
                window.PagarMeGatewayCheckout.forceRenderFields();
            } else {
                console.error('PagarMeGatewayCheckout não está disponível!');
            }
        },

        /**
         * Tentar criar payment box se não existir
         */
        tryCreatePaymentBox: function() {
            // Funções de criação movidas para checkAndRenderPaymentFields
        },

        /**
         * Observer para mudanças no DOM relacionadas ao pagamento
         */
        setupPaymentMethodObserver: function() {
            const self = this;

            // Observar o container de métodos de pagamento
            const targetNode = document.querySelector('#payment') || document.querySelector('form.checkout');
            
            if (!targetNode) {
                console.log('Container de pagamento não encontrado para observar');
                return;
            }

            console.log('Iniciando observer do container de pagamento');

            const observer = new MutationObserver(function(mutations) {
                let shouldCheck = false;

                mutations.forEach(function(mutation) {
                    // Verificar se houve mudanças relevantes
                    if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
                        mutation.addedNodes.forEach(function(node) {
                            if (node.nodeType === 1) { // Element node
                                const $node = $(node);
                                
                                // Se adicionou o payment_method_pagarme_gateway ou payment_box
                                if ($node.hasClass('payment_method_pagarme_gateway') || 
                                    $node.find('.payment_method_pagarme_gateway').length > 0 ||
                                    $node.hasClass('payment_box')) {
                                    shouldCheck = true;
                                }
                            }
                        });
                    }
                });

                if (shouldCheck) {
                    console.log('Observer detectou mudança relevante');
                    setTimeout(function() {
                        self.checkAndRenderPaymentFields('Observer trigger');
                    }, 300);
                }
            });

            observer.observe(targetNode, {
                childList: true,
                subtree: true
            });
        },

        /**
         * Patch nos eventos do WooCommerce para garantir compatibilidade
         */
        patchWooCommerceEvents: function() {
            const self = this;

            // Quando WooCommerce atualizar o checkout
            $(document.body).on('updated_checkout', function() {
                console.log('WooCommerce updated_checkout (via integration)');
                
                // Se estiver no step 3, verificar campos
                if (self.currentStep === 3 || self.currentStep === '3') {
                    setTimeout(function() {
                        self.checkAndRenderPaymentFields('updated_checkout');
                    }, 600);
                }
            });

            // Quando trocar método de pagamento
            $(document).on('change', 'input[name="payment_method"]', function() {
                const method = $(this).val();
                console.log('Método de pagamento mudou para:', method);
                
                if (method === 'pagarme_gateway') {
                    setTimeout(function() {
                        self.checkAndRenderPaymentFields('payment_method change');
                    }, 400);
                }
            });

            // Payment box revelado
            $(document.body).on('payment_method_selected', function(e, method) {
                console.log('Payment method selected:', method);
                
                if (method === 'pagarme_gateway') {
                    setTimeout(function() {
                        self.checkAndRenderPaymentFields('payment_method_selected');
                    }, 300);
                }
            });
        },

        /**
         * Sincronizar dados de pagamento para revisão (Step 4)
         */
        syncPaymentDataToReview: function() {
            console.log('Sincronizando dados de pagamento para revisão...');

            const selectedMethod = $('#pagarme-selected-method').val() || 
                                  $('input[name="pagarme_payment_type"]:checked').val();

            if (selectedMethod) {
                console.log('Método selecionado:', selectedMethod);
                
                // Aqui você pode adicionar lógica adicional para exibir
                // informações de pagamento no step de revisão
                
                // Exemplo: atualizar um elemento de resumo
                const methodLabel = this.getMethodLabel(selectedMethod);
                $('.payment-method-review').text(methodLabel);
            }
        },

        /**
         * Get method label
         */
        getMethodLabel: function(method) {
            const labels = {
                'credit_card': 'Cartão de Crédito',
                'debit_card': 'Cartão de Débito',
                'pix': 'PIX',
                'boleto': 'Boleto Bancário',
                'voucher': 'Voucher'
            };
            return labels[method] || method;
        }
    };

    // Expor globalmente
    window.PagarMeMultistepIntegration = PagarMeMultistepIntegration;

    // Inicializar quando estiver pronto
    $(document).ready(function() {
        // Aguardar um pouco para garantir que tudo está carregado
        setTimeout(function() {
            PagarMeMultistepIntegration.init();
        }, 1000);
    });

})(jQuery);