/**
 * CORREÇÃO SINGLE PAYMENT - INJEÇÃO DE CAMPOS
 * 
 * PROBLEMA: 
 * A função injectPagarmeFields() estava retornando prematuramente
 * quando o checkbox de multipagamento estava marcado, impedindo
 * a injeção dos campos de cartão para pagamentos únicos.
 * 
 * SOLUÇÃO:
 * Verificar se REALMENTE é multipagamento (checkbox marcado + 
 * múltiplos métodos selecionados) antes de bloquear a injeção.
 * 
 * VERSÃO: 1.0.0
 * DATA: 2025-11-21
 */

(function($) {
    'use strict';
    
    console.log('[Single Payment Fix] Carregando correção...');
    
    /**
     * Detectar se é REALMENTE multipagamento
     * Não basta verificar o checkbox - precisa ter múltiplos métodos selecionados
     */
    function isRealMultipayment() {
        const checkboxChecked = $('#enable-multipayment').is(':checked');
        
        // Se checkbox não está marcado, é single payment
        if (!checkboxChecked) {
            return false;
        }
        
        // Contar quantos métodos estão selecionados
        const selectedMethodsCount = $('.multipay-checkbox:checked').length;
        
        // Verificar se há valores nos campos de valor
        let methodsWithAmount = 0;
        $('.multipay-checkbox:checked').each(function() {
            const method = $(this).val();
            const amount = parseFloat($(`.multipay-amount[data-method="${method}"]`).val() || 0);
            if (amount > 0) {
                methodsWithAmount++;
            }
        });
        
        // É multipagamento REAL se:
        // 1. Checkbox marcado
        // 2. Mais de 1 método selecionado COM valor
        const isMulti = checkboxChecked && methodsWithAmount > 1;
        
        console.log('[Single Payment Fix] Detecção:', {
            checkboxChecked,
            selectedMethodsCount,
            methodsWithAmount,
            resultado: isMulti ? '🔵 MULTIPAGAMENTO' : '🟢 SINGLE PAYMENT'
        });
        
        return isMulti;
    }
    
    /**
     * Injetar campos para SINGLE PAYMENT
     * VERSÃO CORRIGIDA
     */
    function injectSinglePaymentFieldsFixed() {
        console.log('[Single Payment Fix] Iniciando injeção...');
        
        // CORREÇÃO: Usar detecção REAL de multipagamento
        if (isRealMultipayment()) {
            console.log('[Single Payment Fix] É multipagamento real - não injetar campos de single');
            return false; // Deixa o sistema de multipagamento injetar
        }
        
        console.log('[Single Payment Fix] É single payment - prosseguindo com injeção');
        
        // Verificar se é pagamento Pagar.me
        const $form = $('form.checkout, form#order_review');
        const paymentMethod = $('input[name="payment_method"]:checked').val();
        
        if (paymentMethod !== 'pagarme_gateway') {
            console.log('[Single Payment Fix] Não é Pagar.me');
            return false;
        }
        
        // Obter tipo de pagamento selecionado
        const paymentType = $('input[name="pagarme_payment_type"]:checked').val() || 'credit_card';
        console.log('[Single Payment Fix] Tipo de pagamento:', paymentType);
        
        // Se não for método de cartão, não precisa injetar
        if (!['credit_card', 'debit_card', 'voucher'].includes(paymentType)) {
            console.log('[Single Payment Fix] Método não requer campos de cartão');
            return true; // Não é erro, apenas não precisa injetar
        }
        
        // Limpar campos anteriores de single payment
        $form.find('.pagarme-single-payment-hidden').remove();
        $form.find('.pagarme-injected-field').remove();
        
        // Coletar dados dos campos visíveis
        const cardNumber = $('#pagarme-card-number').val() || '';
        const cardNumberClean = cardNumber.replace(/\D/g, '');
        const holderName = $('#pagarme-card-holder-name').val() || '';
        const expiryRaw = $('#pagarme-card-expiry').val() || '';
        const cvv = $('#pagarme-card-cvv').val() || '';
        const cardDocument = $('#pagarme-card-document').val() || '';
        const cardDocumentClean = cardDocument.replace(/\D/g, '');
        const installments = $('#pagarme-installments').val() || '1';
        
        console.log('[Single Payment Fix] Dados coletados:', {
            cardNumber: cardNumberClean ? cardNumberClean.substring(0, 6) + '***' + cardNumberClean.slice(-4) : 'VAZIO',
            holderName: holderName || 'VAZIO',
            expiryRaw: expiryRaw || 'VAZIO',
            cvv: cvv ? '***' : 'VAZIO',
            document: cardDocumentClean || 'VAZIO',
            installments: installments
        });
        
        // Validar campos críticos
        if (!cardNumberClean || cardNumberClean.length < 13) {
            console.error('[Single Payment Fix] Número do cartão inválido');
            return false;
        }
        
        if (!holderName || holderName.trim().length < 3) {
            console.error('[Single Payment Fix] Nome do titular inválido');
            return false;
        }
        
        if (!cvv || cvv.length < 3) {
            console.error('[Single Payment Fix] CVV inválido');
            return false;
        }
        
        if (!expiryRaw || expiryRaw.replace(/\D/g, '').length < 4) {
            console.error('[Single Payment Fix] Validade inválida');
            return false;
        }
        
        // Processar validade
        const expiryDigits = expiryRaw.replace(/\D/g, '');
        const expMonth = expiryDigits.substring(0, 2);
        const expYear = expiryDigits.substring(2, 4);
        const expYearFull = '20' + expYear;
        
        // Injetar campos hidden com TODOS os formatos possíveis
        const fieldsToInject = [
            // Número do cartão
            { name: 'pagarme_card_number_raw', value: cardNumberClean },
            { name: 'pagarme_card_number', value: cardNumber },
            
            // Nome do titular
            { name: 'pagarme_card_holder_name', value: holderName },
            { name: 'pagarme_card_holder', value: holderName },
            
            // Validade - TODOS os formatos
            { name: 'pagarme_card_exp_month', value: expMonth },
            { name: 'pagarme_card_exp_year', value: expYearFull },
            { name: 'pagarme_card_expiration', value: expMonth + expYear },
            { name: 'pagarme_card_expiry', value: expiryRaw },
            
            // CVV
            { name: 'pagarme_card_cvv', value: cvv },
            
            // Documento
            { name: 'pagarme_card_document', value: cardDocumentClean },
            { name: 'pagarme_holder_document', value: cardDocumentClean },
            
            // Tipo de pagamento
            { name: 'pagarme_payment_type', value: paymentType }
        ];
        
        // Adicionar parcelas apenas para crédito
        if (paymentType === 'credit_card') {
            fieldsToInject.push({ name: 'pagarme_installments', value: installments });
        }
        
        // Injetar todos os campos
        let injectedCount = 0;
        fieldsToInject.forEach(field => {
            if (field.value) {
                $form.append(
                    `<input type="hidden" 
                        name="${field.name}" 
                        value="${field.value}" 
                        class="pagarme-single-payment-hidden" />`
                );
                injectedCount++;
            }
        });
        
        console.log(`[Single Payment Fix] ${injectedCount} campos injetados com sucesso`);
        
        // Verificar se os campos foram realmente adicionados
        const verifyCount = $form.find('.pagarme-single-payment-hidden').length;
        console.log(`[Single Payment Fix] Verificação: ${verifyCount} campos no DOM`);
        
        return verifyCount > 0;
    }
    
    /**
     * Sobrescrever a função problemática
     */
    function overrideBrokenFunction() {
        // Sobrescrever a função global injectPagarmeFields
        if (window.injectPagarmeFields) {
            console.log('[Single Payment Fix] Sobrescrevendo injectPagarmeFields()');
            window.injectPagarmeFields = injectSinglePaymentFieldsFixed;
        }
        
        // Adicionar como alternativa
        window.injectSinglePaymentFieldsFixed = injectSinglePaymentFieldsFixed;
        window.isRealMultipayment = isRealMultipayment;
    }
    
    /**
     * Instalar hooks em todos os pontos de injeção
     */
    function installHooks() {
        console.log('[Single Payment Fix] Instalando hooks...');
        
        // Hook 1: Quando mudar de método de pagamento
        $(document).on('change', 'input[name="payment_method"]', function() {
            setTimeout(injectSinglePaymentFieldsFixed, 100);
        });
        
        // Hook 2: Quando mudar o tipo de pagamento Pagar.me
        $(document).on('change', 'input[name="pagarme_payment_type"]', function() {
            setTimeout(injectSinglePaymentFieldsFixed, 100);
        });
        
        // Hook 3: Quando preencher campos de cartão
        $(document).on('blur', '#pagarme-card-number, #pagarme-card-holder-name, #pagarme-card-expiry, #pagarme-card-cvv', function() {
            setTimeout(injectSinglePaymentFieldsFixed, 100);
        });
        
        // Hook 4: Antes de submeter o formulário
        $(document).on('submit', 'form.checkout, form#order_review', function(e) {
            if ($('input[name="payment_method"]:checked').val() === 'pagarme_gateway') {
                if (!isRealMultipayment()) {
                    console.log('[Single Payment Fix] Submit detectado - injetando campos');
                    injectSinglePaymentFieldsFixed();
                }
            }
        });
        
        // Hook 5: Interceptar place_order do WooCommerce
        $(document).on('checkout_place_order_pagarme_gateway', function() {
            if (!isRealMultipayment()) {
                console.log('[Single Payment Fix] Place order detectado - injetando campos');
                return injectSinglePaymentFieldsFixed();
            }
            return true;
        });
        
        // Hook 6: AJAX do WooCommerce
        $(document).ajaxSend(function(event, jqxhr, settings) {
            if (settings.url && settings.url.indexOf('wc-ajax=checkout') !== -1) {
                if ($('input[name="payment_method"]:checked').val() === 'pagarme_gateway') {
                    if (!isRealMultipayment()) {
                        console.log('[Single Payment Fix] AJAX checkout detectado - injetando campos');
                        injectSinglePaymentFieldsFixed();
                    }
                }
            }
        });
        
        console.log('[Single Payment Fix] Hooks instalados');
    }
    
    /**
     * Função de teste
     */
    window.testSinglePaymentFix = function() {
        console.log('═══════════════════════════════════════════');
        console.log('[Single Payment Fix] TESTE DE DIAGNÓSTICO');
        console.log('═══════════════════════════════════════════\n');
        
        const isMulti = isRealMultipayment();
        console.log(`Modo: ${isMulti ? 'MULTIPAGAMENTO' : 'SINGLE PAYMENT'}\n`);
        
        const paymentMethod = $('input[name="payment_method"]:checked').val();
        const paymentType = $('input[name="pagarme_payment_type"]:checked').val();
        console.log('Gateway:', paymentMethod);
        console.log('Tipo:', paymentType, '\n');
        
        if (!isMulti) {
            console.log('Tentando injetar campos...\n');
            const result = injectSinglePaymentFieldsFixed();
            console.log(`\nResultado: ${result ? 'SUCESSO ' : 'FALHA '}\n`);
            
            const fieldsCount = $('form.checkout').find('.pagarme-single-payment-hidden').length;
            console.log(`Campos injetados: ${fieldsCount}\n`);
            
            if (fieldsCount > 0) {
                console.log('Lista de campos:');
                $('form.checkout').find('.pagarme-single-payment-hidden').each(function() {
                    const name = $(this).attr('name');
                    const value = $(this).val();
                    const display = (name.includes('number') || name.includes('cvv')) 
                        ? value.substring(0, 4) + '***'
                        : value;
                    console.log(`  ${name}: ${display}`);
                });
            }
        } else {
            console.log('Modo multipagamento ativo - campos gerenciados pelo sistema multi\n');
        }
        
        console.log('═══════════════════════════════════════════');
    };
    
    /**
     * Inicialização
     */
    $(document).ready(function() {
        setTimeout(function() {
            overrideBrokenFunction();
            installHooks();
            
            console.log('[Single Payment Fix] Correção instalada com sucesso!');
            console.log('Use testSinglePaymentFix() no console para testar');
        }, 1000); // Aguardar 1s para garantir que outros scripts carregaram
    });
    
})(jQuery);