/**
 * PAYMENT METHOD SELECTOR - Solução Simples v1.1 (CORRIGIDO) 
 * 
 * Correções:
 * 1. Removeu a função `expandPaymentFields` (Conflitante).
 * 2. `selectMethod` agora garante o disparo do evento 'change' no radio button
 *    para que o `checkout.js` assuma o controle de expansão de campos.
 */

(function($) {
    'use strict';
    
    window.PaymentMethodSelector = {
        currentMethod: null,
        
        /**
         * Inicializar
         */
        init: function() {
            console.log('Inicializando PaymentMethodSelector (v1.1)');
            
            this.setupEventListeners();
            this.detectInitialSelection();
        },
        
        /**
         * Detectar seleção inicial
         */
        detectInitialSelection: function() {
            // 1. Tenta restaurar do sessionStorage
            var savedMethod = this.loadMethod();
            var methodToSelect = savedMethod;
            
            // 2. Se não houver sessão, checa o que está marcado no DOM
            if (!methodToSelect) {
                var $checked = $('input[name="pagarme_payment_method"]:checked');
                if ($checked.length > 0) {
                    methodToSelect = $checked.val();
                }
            }

            if (methodToSelect) {
                console.log('ℹMétodo restaurado/selecionado inicialmente:', methodToSelect);
                this.selectMethod(methodToSelect, false); // Não é um clique do usuário, mas força a seleção
            } else {
                console.log('ℹNenhum método inicial detectado');
            }
        },
        
        /**
         * Configurar event listeners
         */
        setupEventListeners: function() {
            var self = this;
            
            // CRÍTICO: Interceptar clique na opção de pagamento (para funcionar com layout customizado)
            $(document).on('click', '.payment-method-option', function(e) {
                // Ignorar se o clique foi direto no radio button (evita duplicidade)
                if ($(e.target).is('input[type="radio"]')) {
                    return;
                }
                
                var $radio = $(this).find('input[name="pagarme_payment_method"]');
                if ($radio.length > 0) {
                    var method = $radio.val();
                    self.selectMethod(method, true); // fromUser = true
                }
            });
            
            // Mudança direta no radio button (fallback)
            $(document).on('change', 'input[name="pagarme_payment_method"]', function() {
                var method = $(this).val();
                self.selectMethod(method, true); // fromUser = true
            });
            
            console.log('Event listeners configurados');
        },
        
        /**
         * Selecionar método de pagamento
         */
        selectMethod: function(method, fromUser) {
            console.log('Selecionando método:', method);
            
            // Atualizar método atual
            this.currentMethod = method;
            
            // Garantir que radio button está marcado e dispara 'change' 
            // (para que o checkout.js expanda os campos)
            this.updateRadioButtons(method);
            
            // Atualizar visual (precisa rodar DEPOIS do updateRadioButtons)
            this.updateVisualSelection(method);
            
            // Salvar no sessionStorage
            if (fromUser) {
                this.saveMethod(method);
            }
            
            // Disparar evento customizado (Pagar.me Multistep Integration pode escutar)
            $(document.body).trigger('payment_method_changed', [method]);
        },
        
        /**
         * Atualizar radio buttons (e disparar change)
         */
        updateRadioButtons: function(method) {
            var $target = $('input[name="pagarme_payment_method"][value="' + method + '"]');
            
            if ($target.length > 0) {
                var isChecked = $target.is(':checked');
                
                // Desmarcar todos (limpa o estado)
                $('input[name="pagarme_payment_method"]').prop('checked', false);
                
                // Marcar o alvo
                $target.prop('checked', true);

                // Se não estava marcado antes, ou se a seleção mudou, forçar o evento change
                if (!isChecked) {
                     $target.trigger('change');
                }
            } else {
                console.warn('Radio button para método ' + method + ' não encontrado no DOM.');
            }
        },
        
        /**
         * Atualizar visual da seleção
         */
        updateVisualSelection: function(method) {
            // Remover classe de todos
            $('.payment-method-option').removeClass('selected');
            
            // Adicionar no selecionado
            $('input[name="pagarme_payment_method"][value="' + method + '"]')
                .closest('.payment-method-option')
                .addClass('selected');
        },
        
        /**
         * Salvar método no sessionStorage
         */
        saveMethod: function(method) {
            try {
                var savedData = sessionStorage.getItem('pagarme_payment_data_v2');
                var data = savedData ? JSON.parse(savedData) : {};
                
                data.payment_method = method;
                data.woo_payment_method = 'pagarme_gateway';
                data.timestamp = Date.now();
                
                sessionStorage.setItem('pagarme_payment_data_v2', JSON.stringify(data));
                
                console.log('Método salvo no sessionStorage:', method);
                
                // Disparar salvamento completo se o handler existir
                if (window.PagarmePaymentData && typeof window.PagarmePaymentData.saveFormData === 'function') {
                    setTimeout(function() {
                        window.PagarmePaymentData.saveFormData();
                    }, 100);
                }
                
            } catch (e) {
                console.error('Erro ao salvar método:', e);
            }
        },
        
        /**
         * Carregar método do sessionStorage
         */
        loadMethod: function() {
            try {
                var savedData = sessionStorage.getItem('pagarme_payment_data_v2');
                if (savedData) {
                    var data = JSON.parse(savedData);
                    if (data.payment_method) {
                        return data.payment_method;
                    }
                }
            } catch(e) {
                console.error('Erro ao carregar método da sessão', e);
            }
            return null;
        },

        /**
         * Obter método atual
         */
        getCurrentMethod: function() {
            return this.currentMethod;
        },
    };
    
    /**
     * INICIALIZAÇÃO AUTOMÁTICA 
     */
    $(document).ready(function() {
        setTimeout(function() {
            window.PaymentMethodSelector.init();
        }, 300);
    });
    
    /**
     * Reinicializar ao atualizar checkout
     */
    $(document.body).on('updated_checkout', function() {
        console.log('Checkout atualizado - revalidando seleção');
        
        setTimeout(function() {
            var savedMethod = window.PaymentMethodSelector.loadMethod();
            if (savedMethod) {
                console.log('Restaurando método após update:', savedMethod);
                window.PaymentMethodSelector.selectMethod(savedMethod, false);
            } else {
                window.PaymentMethodSelector.detectInitialSelection();
            }
        }, 200);
    });
    
    /**
     * Restaurar ao entrar no Step 3
     */
    $(document.body).on('msc:enterStep:3', function() {
        console.log('Entrando no Step 3 - restaurando método');
        
        setTimeout(function() {
            var savedMethod = window.PaymentMethodSelector.loadMethod();
            if (savedMethod) {
                window.PaymentMethodSelector.selectMethod(savedMethod, false);
            } else {
                // Forçar seleção do primeiro se for Pagarme
                var wcMethod = $('input[name="payment_method"]:checked').val();
                if (wcMethod === 'pagarme_gateway') {
                    window.PagarMeGatewayCheckout.selectFirstPaymentMethod();
                }
            }
        }, 500);
    });
    
})(jQuery);