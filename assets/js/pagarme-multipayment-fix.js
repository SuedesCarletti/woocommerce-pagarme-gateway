/**
 * CORREÇÃO MULTIPAGAMENTO - VERSÃO 3.0.0 (CACHE GLOBAL PERSISTENTE)
 * 
 * PROBLEMA CRÍTICO RESOLVIDO:
 * - Cache estava UNDEFINED porque era variável local na IIFE
 * - Dados sendo perdidos entre navegação de steps e eventos updated_checkout
 * 
 * SOLUÇÃO:
 * 1. Cache exposto GLOBALMENTE via window.PagarMeMultipaymentCache
 * 2. Proteção ABSOLUTA contra sobrescrita com dados vazios
 * 3. Persistência garantida até checkbox ser EXPLICITAMENTE desmarcado
 * 
 * VERSÃO: 3.0.0 - CACHE GLOBAL PERSISTENTE
 * DATA: 2025-11-27
 */

(function($) {
    'use strict';
    
    console.log('[MULTI FIX V3] Carregando com cache global persistente...');
    
    // CACHE GLOBAL - ACESSÍVEL DE QUALQUER LUGAR
    window.PagarMeMultipaymentCache = window.PagarMeMultipaymentCache || {
        data: null,
        lastUpdate: null,
        isLocked: false
    };
    
    /**
     * CAPTURAR dados do multipagamento do DOM
     */
    function captureMultipaymentData() {
        const data = {
            methods: []
        };
        
        $('.multipay-checkbox:checked').each(function() {
            const method = $(this).val();
            const $amountField = $(`.multipay-amount[data-method="${method}"]`);
            const amount = parseFloat($amountField.val() || 0);
            
            if (amount > 0) {
                const methodData = {
                    method: method,
                    amount: amount
                };
                
                // Se for método de cartão, capturar dados do cartão também
                if (['credit_card', 'debit_card', 'voucher'].includes(method)) {
                    methodData.cardData = captureCardData(method);
                }
                
                data.methods.push(methodData);
            }
        });
        
        return data;
    }
    
    /**
     * CAPTURAR dados do cartão
     */
    function captureCardData(method) {
        // Tentar campos específicos do método primeiro
        let cardNumber = $(`.multipay-card-number[data-method="${method}"]`).val();
        let holderName = $(`.multipay-card-holder[data-method="${method}"]`).val();
        let expiry = $(`.multipay-card-expiry[data-method="${method}"]`).val();
        let cvv = $(`.multipay-card-cvv[data-method="${method}"]`).val();
        let document = $(`.multipay-card-document[data-method="${method}"]`).val();
        let installments = $(`.multipay-installments[data-method="${method}"]`).val();
        
        // Fallback para campos gerais se não encontrar específicos
        if (!cardNumber) cardNumber = $('#pagarme-card-number').val();
        if (!holderName) holderName = $('#pagarme-card-holder-name').val();
        if (!expiry) expiry = $('#pagarme-card-expiry').val();
        if (!cvv) cvv = $('#pagarme-card-cvv').val();
        if (!document) document = $('#pagarme-card-document').val();
        if (!installments || installments === '1') {
            installments = $('#pagarme-installments').val() || '1';
        }
        
        // Tentar capturar de campos com prefixo multi_card_
        if (!cardNumber) cardNumber = $(`input[name="multi_card_${method}_number_raw"]`).val();
        if (!holderName) holderName = $(`input[name="multi_card_${method}_holder"]`).val();
        if (!expiry) expiry = $(`input[name="multi_card_${method}_expiration"]`).val();
        if (!cvv) cvv = $(`input[name="multi_card_${method}_cvv"]`).val();
        if (!document) document = $(`input[name="multi_card_${method}_document"]`).val();
        if (!installments) installments = $(`input[name="multi_card_${method}_installments"]`).val();
        
        return {
            number: cardNumber || '',
            holder: holderName || '',
            expiry: expiry || '',
            cvv: cvv || '',
            document: document || '',
            installments: installments || '1'
        };
    }
    
    /**
     * ATUALIZAR cache de forma PROTEGIDA
     * NUNCA sobrescreve dados válidos com DOM vazio a menos que explicitamente solicitado
     */
    function updateCacheProtected() {
        const $interface = $('#multipayment-interface');
        const isVisible = $interface.is(':visible') && $interface.css('display') !== 'none';
        const isChecked = $('#enable-multipayment').is(':checked');

        // 1. Se checkbox desmarcado = LIMPAR CACHE (ação explícita do usuário)
        if (!isChecked) {
            window.PagarMeMultipaymentCache.data = null;
            window.PagarMeMultipaymentCache.isLocked = false;
            console.log('[MULTI FIX V3] Checkbox desmarcado - cache limpo');
            return;
        }

        // 2. Se cache está LOCKED, não fazer nada (proteção absoluta)
        if (window.PagarMeMultipaymentCache.isLocked) {
            console.log('[MULTI FIX V3] Cache BLOQUEADO - mantendo dados protegidos');
            return;
        }

        // 3. Capturar dados atuais do DOM
        const newData = captureMultipaymentData();
        const isNewDataEmpty = !newData.methods || newData.methods.length === 0;
        const isCacheValid = window.PagarMeMultipaymentCache.data && 
                            window.PagarMeMultipaymentCache.data.methods && 
                            window.PagarMeMultipaymentCache.data.methods.length > 0;

        if (isNewDataEmpty) {
            // DOM está vazio
            if (isCacheValid) {
                if (!isVisible) {
                    // PROTEÇÃO CRÍTICA: Interface oculta + DOM vazio + Cache válido = MANTER CACHE
                    console.log('[MULTI FIX V3] Interface oculta, DOM vazio. MANTENDO cache protegido.');
                    // BLOQUEAR cache para evitar qualquer sobrescrita acidental
                    window.PagarMeMultipaymentCache.isLocked = true;
                    return;
                } else {
                    // Interface visível + DOM vazio = usuário limpou os campos propositalmente
                    console.log('[MULTI FIX V3] Interface visível, dados vazios. Cache sobrescrito (ação do usuário).');
                    window.PagarMeMultipaymentCache.data = newData;
                    window.PagarMeMultipaymentCache.lastUpdate = new Date().toISOString();
                }
            } else {
                // Cache já estava vazio, manter vazio
                window.PagarMeMultipaymentCache.data = newData;
            }
        } else {
            // Novos dados VÁLIDOS capturados do DOM = SEMPRE atualizar
            window.PagarMeMultipaymentCache.data = newData;
            window.PagarMeMultipaymentCache.lastUpdate = new Date().toISOString();
            window.PagarMeMultipaymentCache.isLocked = false; // Desbloquear para permitir novas atualizações
            console.log('[MULTI FIX V3] Cache atualizado:', newData.methods.length, 'métodos');
            
            // Log detalhado dos métodos
            newData.methods.forEach(function(m, i) {
                console.log(`   [${i+1}] ${m.method}: R$ ${m.amount.toFixed(2)}`);
            });
        }
    }
    
    /**
     * INJETAR campos de multipagamento no formulário
     */
    function injectMultipaymentFieldsFixed() {
        console.log('[MULTI FIX V3] Iniciando injeção...');
        
        const $form = $('form.checkout, form#order_review');
        
        // Verificar se multipagamento está ativo
        const isMultipaymentChecked = $('#enable-multipayment').is(':checked');
        
        if (!isMultipaymentChecked) {
            console.log('[MULTI FIX V3] Checkbox não marcado - não é multipagamento');
            return false;
        }
        
        console.log('[MULTI FIX V3] Checkbox marcado - processando multipagamento');
        
        // 1. GARANTIR que o cache esteja atualizado ANTES de usar
        updateCacheProtected();
        
        // 2. Usar dados do CACHE GLOBAL
        const dataToUse = window.PagarMeMultipaymentCache.data;

        // 3. VALIDAÇÃO CRÍTICA: Se cache vazio após proteção = ABORTAR
        if (!dataToUse || !dataToUse.methods || dataToUse.methods.length === 0) {
            console.error('[MULTI FIX V3] ABORTANDO: Cache vazio após proteção. Nenhum método válido encontrado.');
            
            // Tentar uma última captura forçada do DOM
            console.log('[MULTI FIX V3] Tentando captura FORÇADA do DOM como último recurso...');
            const emergencyData = captureMultipaymentData();
            
            if (emergencyData.methods && emergencyData.methods.length > 0) {
                console.log('[MULTI FIX V3] Captura de emergência bem-sucedida!');
                window.PagarMeMultipaymentCache.data = emergencyData;
                window.PagarMeMultipaymentCache.lastUpdate = new Date().toISOString();
            } else {
                console.error('[MULTI FIX V3] Captura de emergência falhou. Não é possível prosseguir.');
                return false;
            }
        }
        
        // 4. LIMPAR campos antigos
        $form.find('.pagarme-multipay-hidden').remove();
        $form.find('input[name^="multi_method_"]').remove();
        $form.find('input[name^="multi_amount_"]').remove();
        $form.find('input[name^="multi_card_"]').remove();
        
        // 5. INJETAR flags principais
        $form.append('<input type="hidden" name="pagarme_multipayment" value="yes" class="pagarme-multipay-hidden" />');
        $form.append('<input type="hidden" name="is_multipayment" value="1" class="pagarme-multipay-hidden" />');
        
        let methodsInjected = 0;
        let totalAmount = 0;
        
        // 6. INJETAR cada método do cache
        const finalData = window.PagarMeMultipaymentCache.data;
        
        if (finalData && finalData.methods) {
            finalData.methods.forEach(function(methodData) {
                const method = methodData.method;
                const amount = methodData.amount;
                
                console.log(`[MULTI FIX V3] Injetando: ${method} = R$ ${amount.toFixed(2)}`);
                
                // Injetar campos do método
                $form.append(`<input type="hidden" name="multi_method_${method}" value="yes" class="pagarme-multipay-hidden" />`);
                $form.append(`<input type="hidden" name="multi_amount_${method}" value="${amount.toFixed(2)}" class="pagarme-multipay-hidden" />`);
                
                methodsInjected++;
                totalAmount += amount;
                
                console.log(`[MULTI FIX V3] Método injetado: ${method}`);
                
                // Se for método de cartão E tiver dados do cartão, injetar
                if (methodData.cardData && ['credit_card', 'debit_card', 'voucher'].includes(method)) {
                    injectCardDataFromCache(method, methodData.cardData, $form);
                }
            });
        }
        
        console.log(`[MULTI FIX V3] Total de métodos injetados: ${methodsInjected}`);
        console.log(`[MULTI FIX V3] Soma total: R$ ${totalAmount.toFixed(2)}`);
        console.log(`[MULTI FIX V3] Total de campos hidden: ${$form.find('.pagarme-multipay-hidden').length}`);
        
        return methodsInjected > 0;
    }
    
    /**
     * INJETAR dados do cartão a partir do cache
     */
    function injectCardDataFromCache(method, cardData, $form) {
        console.log(`[MULTI FIX V3] Injetando dados de cartão para: ${method}`);
        
        const cardNumber = cardData.number || '';
        const holderName = cardData.holder || '';
        const expiry = cardData.expiry || '';
        const cvv = cardData.cvv || '';
        const document = cardData.document || '';
        const installments = cardData.installments || '1';
        
        // Limpar
        const cardNumberClean = cardNumber.replace(/\D/g, '');
        const documentClean = document.replace(/\D/g, '');
        const expiryClean = expiry.replace(/\D/g, '');
        
        // Verificar se tem dados mínimos
        if (!cardNumberClean || !holderName || !expiryClean || !cvv) {
            console.log(`[MULTI FIX V3] Dados de cartão incompletos para ${method} - pulando injeção`);
            return;
        }
        
        // Extrair mês e ano
        let expMonth = '';
        let expYear = '';
        
        if (expiryClean.length === 4) {
            expMonth = expiryClean.substring(0, 2);
            expYear = expiryClean.substring(2, 4);
        } else if (expiryClean.length === 6) {
            expMonth = expiryClean.substring(0, 2);
            expYear = expiryClean.substring(2, 6);
        }
        
        // Injetar campos do cartão
        $form.append(`<input type="hidden" name="multi_card_${method}_number_raw" value="${cardNumberClean}" class="pagarme-multipay-hidden" />`);
        $form.append(`<input type="hidden" name="multi_card_${method}_holder" value="${holderName}" class="pagarme-multipay-hidden" />`);
        $form.append(`<input type="hidden" name="multi_card_${method}_exp_month" value="${expMonth}" class="pagarme-multipay-hidden" />`);
        $form.append(`<input type="hidden" name="multi_card_${method}_exp_year" value="${expYear}" class="pagarme-multipay-hidden" />`);
        $form.append(`<input type="hidden" name="multi_card_${method}_expiration" value="${expiryClean}" class="pagarme-multipay-hidden" />`);
        $form.append(`<input type="hidden" name="multi_card_${method}_cvv" value="${cvv}" class="pagarme-multipay-hidden" />`);
        $form.append(`<input type="hidden" name="multi_card_${method}_document" value="${documentClean}" class="pagarme-multipay-hidden" />`);
        $form.append(`<input type="hidden" name="multi_card_${method}_installments" value="${installments}" class="pagarme-multipay-hidden" />`);
        
        console.log(`[MULTI FIX V3] Dados de cartão injetados para ${method}`);
    }
    
    /**
     * INSTALAR hooks em todos os eventos críticos
     */
    function installHooks() {
        console.log('[MULTI FIX V3] Instalando hooks...');
        
        // Hook 1: Antes de navegar para próximo step (MSC)
        $(document).on('msc_before_next_step', function() {
            if ($('#enable-multipayment').is(':checked')) {
                console.log('[MULTI FIX V3] MSC next step - capturando e injetando');
                updateCacheProtected();
                injectMultipaymentFieldsFixed();
            }
        });
        
        // Hook 2: Validação do Step 3
        $(document).on('msc:before:validate:step', function(e, data) {
            if (data && data.step === 3) {
                if ($('#enable-multipayment').is(':checked')) {
                    console.log('[MULTI FIX V3] Validando Step 3 - CAPTURANDO e BLOQUEANDO cache');
                    updateCacheProtected();
                    // Bloquear cache IMEDIATAMENTE após captura
                    if (window.PagarMeMultipaymentCache.data && 
                        window.PagarMeMultipaymentCache.data.methods &&
                        window.PagarMeMultipaymentCache.data.methods.length > 0) {
                        window.PagarMeMultipaymentCache.isLocked = true;
                        console.log('[MULTI FIX V3] Cache BLOQUEADO após validação do Step 3');
                    }
                }
            }
        });
        
        // Hook 3: Botão "Finalizar Pedido"
        $(document).on('click', 'button[name="woocommerce_checkout_place_order"], #place_order', function(e) {
            if ($('#enable-multipayment').is(':checked')) {
                console.log('[MULTI FIX V3] Botão finalizar - INJETANDO do cache bloqueado');
                injectMultipaymentFieldsFixed();
            }
        });
        
        // Hook 4: Submit do formulário
        $('form.checkout').on('submit', function() {
            if ($('#enable-multipayment').is(':checked')) {
                console.log('[MULTI FIX V3] Submit form - INJETANDO do cache bloqueado');
                injectMultipaymentFieldsFixed();
            }
        });
        
        // Hook 5: AJAX checkout
        $(document).on('checkout_place_order_pagarme_gateway', function() {
            if ($('#enable-multipayment').is(':checked')) {
                console.log('[MULTI FIX V3] AJAX checkout - INJETANDO do cache bloqueado');
                injectMultipaymentFieldsFixed();
            }
        });
        
        // Hook 6: WooCommerce updated_checkout
        $(document.body).on('updated_checkout', function() {
            // NÃO chamar updateCacheProtected aqui se cache estiver bloqueado
            if ($('#enable-multipayment').is(':checked')) {
                if (window.PagarMeMultipaymentCache.isLocked) {
                    console.log('[MULTI FIX V3] updated_checkout ignorado - cache bloqueado');
                } else {
                    console.log('[MULTI FIX V3] updated_checkout - atualizando cache');
                    updateCacheProtected();
                }
            }
        });
        
        // Hook 7: Checkbox de multipagamento
        $(document).on('change', '#enable-multipayment', function() {
            console.log('[MULTI FIX V3] Checkbox alterado');
            updateCacheProtected();
        });
        
        // Hook 8: Checkboxes de métodos
        $(document).on('change', '.multipay-checkbox', function() {
            if (!window.PagarMeMultipaymentCache.isLocked) {
                console.log('[MULTI FIX V3] Método alterado');
                updateCacheProtected();
            }
        });
        
        // Hook 9: Valores alterados
        $(document).on('blur change', '.multipay-amount', function() {
            if (!window.PagarMeMultipaymentCache.isLocked) {
                console.log('[MULTI FIX V3] Valor alterado');
                updateCacheProtected();
            }
        });
        
        // Hook 10: Campos de cartão alterados
        $(document).on('blur', '#pagarme-card-number, #pagarme-card-holder-name, #pagarme-card-expiry, #pagarme-card-cvv, #pagarme-card-document, .multipay-card-number, .multipay-card-holder, .multipay-card-expiry, .multipay-card-cvv', function() {
            if ($('#enable-multipayment').is(':checked') && !window.PagarMeMultipaymentCache.isLocked) {
                console.log('[MULTI FIX V3] Campo de cartão alterado');
                updateCacheProtected();
            }
        });
        
        console.log('[MULTI FIX V3] Hooks instalados');
    }
    
    /**
     * FUNÇÃO DE TESTE para o console
     */
    window.testMultipaymentFixV3 = function() {
        console.log('═══════════════════════════════════════');
        console.log('TESTE CORREÇÃO MULTIPAGAMENTO V3');
        console.log('═══════════════════════════════════════\n');
        
        const isChecked = $('#enable-multipayment').is(':checked');
        console.log('Checkbox:', isChecked ? 'MARCADO' : 'DESMARCADO');
        console.log('Cache bloqueado:', window.PagarMeMultipaymentCache.isLocked ? 'SIM' : 'NÃO');
        
        if (window.PagarMeMultipaymentCache.data && window.PagarMeMultipaymentCache.data.methods) {
            console.log('Cache:', window.PagarMeMultipaymentCache.data.methods.length, 'métodos\n');
            window.PagarMeMultipaymentCache.data.methods.forEach(function(m, i) {
                console.log(`  [${i+1}] ${m.method}: R$ ${m.amount.toFixed(2)}`);
            });
        } else {
            console.log('Cache: VAZIO\n');
        }
        
        // Forçar atualização
        console.log('\nForçando atualização...');
        updateCacheProtected();
        
        // Tentar injetar
        console.log('\nExecutando injeção...');
        const result = injectMultipaymentFieldsFixed();
        console.log(`\nResultado: ${result ? 'SUCESSO' : 'FALHA'}\n`);
        
        // Verificar campos
        const $form = $('form.checkout, form#order_review');
        const multiMethodCount = $form.find('input[name^="multi_method_"]').length;
        const multiAmountCount = $form.find('input[name^="multi_amount_"]').length;
        const flagsCount = $form.find('input[name="pagarme_multipayment"], input[name="is_multipayment"]').length;
        
        console.log('CAMPOS INJETADOS:');
        console.log(`  multi_method_*: ${multiMethodCount}`);
        console.log(`  multi_amount_*: ${multiAmountCount}`);
        console.log(`  flags: ${flagsCount}\n`);
        
        console.log('═══════════════════════════════════════');
    };
    
    /**
     * DESBLOQUEAR cache manualmente (use com cuidado!)
     */
    window.unlockMultipaymentCache = function() {
        window.PagarMeMultipaymentCache.isLocked = false;
        console.log('[MULTI FIX V3] Cache DESBLOQUEADO');
    };
    
    /**
     * FORÇAR recaptura do DOM
     */
    window.forceRecaptureMultipayment = function() {
        console.log('[MULTI FIX V3] Forçando recaptura...');
        window.PagarMeMultipaymentCache.isLocked = false;
        updateCacheProtected();
        
        if (window.PagarMeMultipaymentCache.data && window.PagarMeMultipaymentCache.data.methods) {
            console.log('Cache atualizado:', window.PagarMeMultipaymentCache.data.methods.length, 'métodos');
        } else {
            console.log('DOM vazio - não foi possível recapturar');
        }
    };
    
    /**
     * DEBUG do cache
     */
    window.debugMultipaymentCacheV3 = function() {
        console.log('═══════════════════════════════════════');
        console.log('DEBUG CACHE MULTIPAGAMENTO V3');
        console.log('═══════════════════════════════════════\n');
        console.log('Objeto completo:', window.PagarMeMultipaymentCache);
        console.log('═══════════════════════════════════════');
    };
    
    /**
     * INICIALIZAÇÃO
     */
    $(document).ready(function() {
        setTimeout(function() {
            installHooks();
            
            console.log('[MULTI FIX V3] Correção instalada com sucesso!');
            console.log('Use testMultipaymentFixV3() no console para testar');
            console.log('Use debugMultipaymentCacheV3() para ver o cache');
            console.log('Use forceRecaptureMultipayment() para recapturar dados');
            console.log('Use unlockMultipaymentCache() para desbloquear o cache');
        }, 1500);
    });
    
})(jQuery);