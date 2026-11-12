/**
 * CORREÇÃO BOLETO E PIX - LIMPEZA DE CAMPOS
 * 
 * PROBLEMA: 
 * Quando o usuário seleciona Boleto ou PIX após ter preenchido
 * dados de cartão, os campos hidden do cartão permanecem no
 * formulário e são enviados ao PHP, causando erro de validação.
 * 
 * SOLUÇÃO:
 * Limpar TODOS os campos hidden de cartão quando o método
 * selecionado não for de cartão (boleto, PIX, etc).
 * 
 * VERSÃO: 1.0.0
 * DATA: 2025-11-21
 */

(function($) {
    'use strict';
    
    console.log('[Boleto/PIX Fix] Carregando correção...');
    
    /**
     * Limpar TODOS os campos hidden de cartão do formulário
     */
    function clearAllCardFields() {
        const $form = $('form.checkout, form#order_review');
        
        // Limpar campos hidden
        $form.find('.pagarme-single-payment-hidden').remove();
        $form.find('.pagarme-injected-field').remove();
        $form.find('input[name^="pagarme_card_"]').remove();
        $form.find('input[name="pagarme_holder_document"]').remove();
        $form.find('input[name="pagarme_installments"]').remove();
        
        // Limpar campos visíveis também (opcional, mas recomendado)
        $('#pagarme-card-number').val('');
        $('#pagarme-card-holder-name').val('');
        $('#pagarme-card-expiry').val('');
        $('#pagarme-card-cvv').val('');
        $('#pagarme-card-document').val('');
        $('#pagarme-installments').val('1');
        
        console.log('[Boleto/PIX Fix] Campos de cartão limpos');
    }
    
    /**
     * Verificar se o método é de cartão
     */
    function isCardMethod(method) {
        return ['credit_card', 'debit_card', 'voucher'].includes(method);
    }
    
    /**
     * Handler para mudança de método de pagamento
     */
    function handlePaymentMethodChange() {
        const paymentMethod = $('input[name="pagarme_payment_type"]:checked').val();
        
        if (!paymentMethod) {
            return;
        }
        
        console.log('[Boleto/PIX Fix] Método selecionado:', paymentMethod);
        
        // Se NÃO for método de cartão, limpar campos
        if (!isCardMethod(paymentMethod)) {
            clearAllCardFields();
            console.log('[Boleto/PIX Fix] Método não-cartão detectado, campos limpos');
        }
    }
    
    /**
     * Limpar campos antes do submit
     */
    function cleanBeforeSubmit() {
        const paymentGateway = $('input[name="payment_method"]:checked').val();
        
        // Só processar se for Pagar.me
        if (paymentGateway !== 'pagarme_gateway') {
            return true;
        }
        
        const paymentType = $('input[name="pagarme_payment_type"]:checked').val();
        
        console.log('[Boleto/PIX Fix] Submit - Gateway:', paymentGateway);
        console.log('[Boleto/PIX Fix] Submit - Tipo:', paymentType);
        
        // Se não for cartão, garantir limpeza
        if (!isCardMethod(paymentType)) {
            clearAllCardFields();
            console.log('[Boleto/PIX Fix] Campos limpos antes do submit');
        }
        
        return true;
    }
    
    /**
     * Instalar hooks
     */
    function installHooks() {
        console.log('[Boleto/PIX Fix] Instalando hooks...');
        
        // Hook 1: Quando mudar tipo de pagamento Pagar.me
        $(document).on('change', 'input[name="pagarme_payment_type"]', function() {
            setTimeout(handlePaymentMethodChange, 50);
        });
        
        // Hook 2: Antes de submeter o formulário
        $(document).on('submit', 'form.checkout, form#order_review', function(e) {
            cleanBeforeSubmit();
        });
        
        // Hook 3: Interceptar place_order
        $(document).on('checkout_place_order_pagarme_gateway', function() {
            return cleanBeforeSubmit();
        });
        
        // Hook 4: AJAX do WooCommerce
        $(document).ajaxSend(function(event, jqxhr, settings) {
            if (settings.url && settings.url.indexOf('wc-ajax=checkout') !== -1) {
                cleanBeforeSubmit();
            }
        });
        
        console.log('[Boleto/PIX Fix] Hooks instalados');
    }
    
    /**
     * Função de teste
     */
    window.testBoletoPIXFix = function() {
        console.log('═══════════════════════════════════════════');
        console.log('[Boleto/PIX Fix] TESTE DE DIAGNÓSTICO');
        console.log('═══════════════════════════════════════════\n');
        
        const paymentMethod = $('input[name="pagarme_payment_type"]:checked').val();
        console.log('Método selecionado:', paymentMethod);
        console.log('É método de cartão?', isCardMethod(paymentMethod), '\n');
        
        const $form = $('form.checkout');
        const cardFieldsCount = $form.find('input[name^="pagarme_card_"]').length;
        const hiddenFieldsCount = $form.find('.pagarme-single-payment-hidden').length;
        
        console.log('Campos de cartão (name^="pagarme_card_"):', cardFieldsCount);
        console.log('Campos hidden (.pagarme-single-payment-hidden):', hiddenFieldsCount, '\n');
        
        if (!isCardMethod(paymentMethod) && (cardFieldsCount > 0 || hiddenFieldsCount > 0)) {
            console.log('PROBLEMA DETECTADO: Campos de cartão presentes para método não-cartão\n');
            console.log('Executando limpeza...\n');
            clearAllCardFields();
            console.log('Limpeza concluída!\n');
        } else if (!isCardMethod(paymentMethod)) {
            console.log('OK: Nenhum campo de cartão presente (correto para boleto/PIX)\n');
        } else {
            console.log('OK: Campos de cartão presentes (correto para método de cartão)\n');
        }
        
        console.log('═══════════════════════════════════════════');
    };
    
    /**
     * Inicialização
     */
    $(document).ready(function() {
        setTimeout(function() {
            installHooks();
            
            console.log('[Boleto/PIX Fix] Correção instalada com sucesso!');
            console.log('Use testBoletoPIXFix() no console para testar');
        }, 1100); // Aguardar um pouco mais para garantir que o single-payment-fix carregou
    });
    
})(jQuery);