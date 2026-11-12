/**
 * CORREÇÃO DE ALINHAMENTO DE CAMPOS - MULTIPAGAMENTO
 * 
 * PROBLEMA: Incompatibilidade de nomenclatura entre JS e PHP
 * - JS envia: multi_method_X, multi_amount_X
 * - PHP espera: pagarme_multipayment_method_N_type/amount
 * 
 * SOLUÇÃO: Modificar injeção para usar nomenclatura esperada pelo PHP
 * 
 * ARQUIVO: pagarme-multipayment-field-alignment-fix.js
 * VERSÃO: 1.0.0
 * DATA: 2025-11-07
 */

(function($) {
    'use strict';

    // Logger
    function log(message, data) {
        console.log(`[Field Alignment Fix] ${message}`, data || '');
    }

    window.PagarmeFieldAlignmentFix = {
        
        /**
         * Inicialização
         */
        init: function() {
            log('Inicializando correção de alinhamento de campos');
            this.overrideInjectionFunction();
        },

        /**
         * Sobrescrever função de injeção para usar nomenclatura correta
         */
        overrideInjectionFunction: function() {
            // Sobrescrever a função do PagarmeMultipaymentFix
            if (window.PagarmeMultipaymentFix && window.PagarmeMultipaymentFix.injectMultipaymentFields) {
                
                window.PagarmeMultipaymentFix.injectMultipaymentFields = function() {
                    try {
                        const $form = $('form.checkout');
                        const isMultipayment = $('#enable-multipayment').is(':checked');

                        if (!isMultipayment) {
                            log('⚠️ Não é multipagamento - não injetar');
                            return false;
                        }

                        log('💉 Injetando campos com nomenclatura correta');

                        // REMOVER TODOS OS CAMPOS ANTIGOS
                        $form.find('.pagarme-multipay-hidden').remove();
                        $form.find('.pagarme-injected-field').remove();
                        $form.find('input[name^="multi_method_"]').remove();
                        $form.find('input[name^="multi_amount_"]').remove();
                        $form.find('input[name^="multi_card_"]').remove();
                        $form.find('input[name^="pagarme_multipayment_method_"]').remove();
                        $form.find('input[name="pagarme_multipayment"]').remove();
                        $form.find('input[name="is_multipayment"]').remove();

                        log('🧹 Campos antigos removidos');

                        // Flag principal de multipagamento
                        $form.append('<input type="hidden" name="pagarme_multipayment" value="yes" class="pagarme-multipay-hidden" />');
                        $form.append('<input type="hidden" name="is_multipayment" value="1" class="pagarme-multipay-hidden" />');

                        // Sincronizar dados
                        if (window.PagarMeGatewayCheckout && typeof window.PagarMeGatewayCheckout.syncMultipaymentDataFromDOM === 'function') {
                            window.PagarMeGatewayCheckout.syncMultipaymentDataFromDOM();
                        }

                        const multipaymentData = window.PagarMeGatewayCheckout ? 
                            window.PagarMeGatewayCheckout.multipaymentData : {};

                        // Contador para índice dos métodos
                        let methodIndex = 0;
                        let totalInjected = 0;

                        log('📊 multipaymentData:', multipaymentData);

                        // Processar cada método selecionado
                        $('.multipay-checkbox:checked').each(function() {
                            const method = $(this).val();
                            const amountInput = $(`.multipay-amount[data-method="${method}"]`);
                            const amount = parseFloat(amountInput.val() || 0);

                            log(`🔍 Processando método ${methodIndex}: ${method}, valor: ${amount}`);

                            if (amount > 0) {
                                // USAR NOMENCLATURA ESPERADA PELO PHP
                                // Campo type
                                $form.append(`<input type="hidden" 
                                    name="pagarme_multipayment_method_${methodIndex}_type" 
                                    value="${method}" 
                                    class="pagarme-multipay-hidden" />`);
                                totalInjected++;

                                // Campo amount
                                $form.append(`<input type="hidden" 
                                    name="pagarme_multipayment_method_${methodIndex}_amount" 
                                    value="${amount.toFixed(2)}" 
                                    class="pagarme-multipay-hidden" />`);
                                totalInjected++;

                                log(`✅ Método ${methodIndex} (${method}): R$ ${amount.toFixed(2)}`);

                                // Se for método com cartão, adicionar dados do cartão
                                if (['credit_card', 'debit_card', 'voucher'].includes(method)) {
                                    const cardData = multipaymentData[method];

                                    if (cardData && cardData.card) {
                                        const card = cardData.card;
                                        
                                        // Adicionar dados do cartão com nomenclatura indexada
                                        // Número do cartão
                                        if (card.number) {
                                            const cardNumberClean = card.number.replace(/\D/g, '');
                                            $form.append(`<input type="hidden" 
                                                name="pagarme_multipayment_method_${methodIndex}_card_number" 
                                                value="${cardNumberClean}" 
                                                class="pagarme-multipay-hidden" />`);
                                            totalInjected++;
                                        }

                                        // Titular
                                        if (card.holder) {
                                            $form.append(`<input type="hidden" 
                                                name="pagarme_multipayment_method_${methodIndex}_card_holder" 
                                                value="${card.holder}" 
                                                class="pagarme-multipay-hidden" />`);
                                            totalInjected++;
                                        }

                                        // Validade
                                        if (card.expiry) {
                                            const [mm, yy] = card.expiry.split('/');
                                            $form.append(`<input type="hidden" 
                                                name="pagarme_multipayment_method_${methodIndex}_card_expiry" 
                                                value="${mm}${yy}" 
                                                class="pagarme-multipay-hidden" />`);
                                            totalInjected++;
                                        }

                                        // CVV
                                        if (card.cvv) {
                                            $form.append(`<input type="hidden" 
                                                name="pagarme_multipayment_method_${methodIndex}_card_cvv" 
                                                value="${card.cvv}" 
                                                class="pagarme-multipay-hidden" />`);
                                            totalInjected++;
                                        }

                                        // Documento
                                        if (card.document) {
                                            const documentClean = card.document.replace(/\D/g, '');
                                            $form.append(`<input type="hidden" 
                                                name="pagarme_multipayment_method_${methodIndex}_card_document" 
                                                value="${documentClean}" 
                                                class="pagarme-multipay-hidden" />`);
                                            totalInjected++;
                                        }

                                        // Parcelas
                                        if (method === 'credit_card' && card.installments) {
                                            $form.append(`<input type="hidden" 
                                                name="pagarme_multipayment_method_${methodIndex}_card_installments" 
                                                value="${card.installments || 1}" 
                                                class="pagarme-multipay-hidden" />`);
                                            totalInjected++;
                                        }

                                        log(`✅ Dados do cartão para método ${methodIndex} injetados`);
                                    }
                                }

                                // Incrementar índice para próximo método
                                methodIndex++;
                            }
                        });

                        // Adicionar contador total de métodos
                        $form.append(`<input type="hidden" 
                            name="pagarme_multipayment_methods_count" 
                            value="${methodIndex}" 
                            class="pagarme-multipay-hidden" />`);
                        totalInjected++;

                        log(`✅ Total injetado: ${totalInjected} campos`);
                        log(`✅ Total de métodos: ${methodIndex}`);
                        
                        return methodIndex > 0;

                    } catch (error) {
                        log('❌ Erro ao injetar campos', error);
                        return false;
                    }
                };
                
                log('✅ Função de injeção sobrescrita com nomenclatura correta');
            }

            // Também criar uma versão alternativa caso seja necessária
            window.injectMultipaymentFieldsAligned = this.injectAlignedFields;
        },

        /**
         * Função alternativa de injeção com nomenclatura alinhada
         */
        injectAlignedFields: function() {
            // Chamar a função sobrescrita
            if (window.PagarmeMultipaymentFix && window.PagarmeMultipaymentFix.injectMultipaymentFields) {
                return window.PagarmeMultipaymentFix.injectMultipaymentFields();
            }
            return false;
        },

        /**
         * Função de teste para verificar campos
         */
        testFieldAlignment: function() {
            log('======== TESTE DE ALINHAMENTO ========');
            
            // Verificar campos esperados pelo PHP
            const expectedFields = [
                'pagarme_multipayment',
                'pagarme_multipayment_method_0_type',
                'pagarme_multipayment_method_0_amount',
                'pagarme_multipayment_method_1_type',
                'pagarme_multipayment_method_1_amount'
            ];

            expectedFields.forEach(fieldName => {
                const $field = $(`input[name="${fieldName}"]`);
                if ($field.length) {
                    log(`✅ Campo ${fieldName}: ${$field.val()}`);
                } else {
                    log(`❌ Campo ${fieldName}: NÃO ENCONTRADO`);
                }
            });

            // Listar todos os campos relacionados
            log('📋 Todos os campos Pagar.me:');
            $('input[name*="pagarme"]').each(function() {
                log(`  - ${$(this).attr('name')}: ${$(this).val()}`);
            });

            log('=====================================');
        }
    };

    // Inicializar quando o documento estiver pronto
    $(document).ready(function() {
        // Aguardar um pouco para garantir que outros scripts carregaram
        setTimeout(function() {
            PagarmeFieldAlignmentFix.init();
        }, 100);
    });

    // Expor função de teste
    window.testFieldAlignment = PagarmeFieldAlignmentFix.testFieldAlignment;

})(jQuery);