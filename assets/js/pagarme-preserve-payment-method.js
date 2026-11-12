/**
 * CORREÇÃO V2: PRESERVAR MÉTODO DE PAGAMENTO SELECIONADO
 * 
 * VERSÃO: 2.0.0 - CAPTURA ROBUSTA
 * DATA: 2025-11-27
 * 
 * MELHORIAS:
 * - Captura via MÚLTIPLOS seletores
 * - Detecção via MutationObserver
 * - Verificação contínua do DOM
 * - Restauração mais agressiva
 */

(function($) {
    'use strict';
    
    console.log('[Preserve Payment V2] Carregando correção aprimorada...');
    
    // VARIÁVEIS GLOBAIS
    
    let userSelectedPaymentMethod = null;
    let isUserInteracting = false;
    let currentStep = 1;
    let captureAttempts = 0;
    
    /**
     * CAPTURAR método selecionado (VERSÃO ROBUSTA)
     */
    function captureUserSelection() {
        let captured = null;
        
        // Método 1: Campo hidden #pagarme-payment-type
        const $paymentTypeField = $('#pagarme-payment-type');
        if ($paymentTypeField.length > 0 && $paymentTypeField.val()) {
            captured = $paymentTypeField.val();
            console.log('[Preserve V2] Capturado via #pagarme-payment-type:', captured);
        }
        
        // Método 2: Botão ativo .pagarme-payment-button.active
        if (!captured) {
            const $activeButton = $('.pagarme-payment-button.active');
            if ($activeButton.length > 0) {
                captured = $activeButton.attr('data-type') || $activeButton.data('type');
                console.log('[Preserve V2] Capturado via botão ativo:', captured);
            }
        }
        
        // Método 3: Input radio selecionado
        if (!captured) {
            const $selectedRadio = $('input[name="pagarme_payment_type"]:checked');
            if ($selectedRadio.length > 0) {
                captured = $selectedRadio.val();
                console.log('[Preserve V2] Capturado via radio:', captured);
            }
        }
        
        // Método 4: Verificar tabs ativos
        if (!captured) {
            const $activeTab = $('.payment-method-tab.active, .payment-tab.active');
            if ($activeTab.length > 0) {
                captured = $activeTab.attr('data-method') || $activeTab.data('method');
                console.log('[Preserve V2] Capturado via tab:', captured);
            }
        }
        
        // Método 5: Verificar classes no container
        if (!captured) {
            const $container = $('#pagarme-payment-form, .pagarme-payment-form');
            if ($container.length > 0) {
                const classes = $container.attr('class') || '';
                const match = classes.match(/payment-method-(\w+)/);
                if (match) {
                    captured = match[1];
                    console.log('[Preserve V2] Capturado via classe do container:', captured);
                }
            }
        }
        
        if (captured && captured !== 'credit_card') {
            userSelectedPaymentMethod = captured;
            captureAttempts = 0;
            console.log('[Preserve V2] Método salvo:', captured);
            return true;
        } else if (captured === 'credit_card' || captured === 'debit_card') {
            userSelectedPaymentMethod = captured;
            captureAttempts = 0;
            console.log('[Preserve V2] Cartão salvo:', captured);
            return true;
        }
        
        captureAttempts++;
        return false;
    }
    
    /**
     * RESTAURAR método selecionado
     */
    function restoreUserSelection() {
        if (!userSelectedPaymentMethod) {
            return; // Não restaurar se não há seleção
        }
        
        const currentMethod = getCurrentPaymentMethod();
        
        if (currentMethod !== userSelectedPaymentMethod) {
            console.log(`[Preserve V2] Restaurando: ${currentMethod} → ${userSelectedPaymentMethod}`);
            
            // Método 1: Clicar no botão correto
            const $targetButton = $(`.pagarme-payment-button[data-type="${userSelectedPaymentMethod}"]`);
            if ($targetButton.length > 0) {
                console.log('[Preserve V2] Clicando no botão:', userSelectedPaymentMethod);
                $targetButton.trigger('click');
                return true;
            }
            
            // Método 2: Selecionar radio
            const $targetRadio = $(`input[name="pagarme_payment_type"][value="${userSelectedPaymentMethod}"]`);
            if ($targetRadio.length > 0) {
                console.log('[Preserve V2] Marcando radio:', userSelectedPaymentMethod);
                $targetRadio.prop('checked', true).trigger('change');
                return true;
            }
            
            // Método 3: Atualizar campo hidden
            const $paymentTypeField = $('#pagarme-payment-type');
            if ($paymentTypeField.length > 0) {
                console.log('[Preserve V2] Atualizando campo hidden:', userSelectedPaymentMethod);
                $paymentTypeField.val(userSelectedPaymentMethod).trigger('change');
                return true;
            }
            
            // Método 4: Clicar em tab
            const $targetTab = $(`.payment-method-tab[data-method="${userSelectedPaymentMethod}"], .payment-tab[data-method="${userSelectedPaymentMethod}"]`);
            if ($targetTab.length > 0) {
                console.log('[Preserve V2] Clicando na tab:', userSelectedPaymentMethod);
                $targetTab.trigger('click');
                return true;
            }
            
            console.log('[Preserve V2] Não foi possível restaurar - elementos não encontrados');
        }
        
        return false;
    }
    
    /**
     * OBTER método atual
     */
    function getCurrentPaymentMethod() {
        // Verificar campo hidden
        const $paymentTypeField = $('#pagarme-payment-type');
        if ($paymentTypeField.length > 0 && $paymentTypeField.val()) {
            return $paymentTypeField.val();
        }
        
        // Verificar botão ativo
        const $activeButton = $('.pagarme-payment-button.active');
        if ($activeButton.length > 0) {
            return $activeButton.attr('data-type') || $activeButton.data('type');
        }
        
        return null;
    }
    
    /**
     * PROTEGER selectFirstPaymentMethod
     */
    function protectPaymentSelection() {
        if (window.PagarMeGatewayCheckout && window.PagarMeGatewayCheckout.selectFirstPaymentMethod) {
            const originalSelectFirst = window.PagarMeGatewayCheckout.selectFirstPaymentMethod;
            
            window.PagarMeGatewayCheckout.selectFirstPaymentMethod = function() {
                // Se temos seleção do usuário E não estamos no Step 3
                if (userSelectedPaymentMethod && currentStep > 3) {
                    console.log('[Preserve V2] BLOQUEANDO selectFirstPaymentMethod() - preservando:', userSelectedPaymentMethod);
                    
                    // Agendar restauração
                    setTimeout(function() {
                        restoreUserSelection();
                    }, 100);
                    
                    return; // NÃO executar função original
                }
                
                // Caso contrário, permitir
                console.log('[Preserve V2] Permitindo selectFirstPaymentMethod()');
                originalSelectFirst.apply(this, arguments);
            };
            
            console.log('[Preserve V2] selectFirstPaymentMethod() protegida');
        }
    }
    
    /**
     * OBSERVADOR DE MUTAÇÕES (detecta mudanças no DOM)
     */
    function startDOMObserver() {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                // Se o método mudou
                if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                    const $target = $(mutation.target);
                    
                    // Se um botão foi ativado
                    if ($target.hasClass('pagarme-payment-button') && $target.hasClass('active')) {
                        const method = $target.attr('data-type') || $target.data('type');
                        
                        if (method && currentStep === 3) {
                            console.log('[Preserve V2] Observer detectou seleção:', method);
                            userSelectedPaymentMethod = method;
                        }
                    }
                }
            });
        });
        
        // Observar mudanças nos botões de pagamento
        const $paymentContainer = $('#pagarme-payment-form, .pagarme-payment-form, #payment');
        if ($paymentContainer.length > 0) {
            observer.observe($paymentContainer[0], {
                attributes: true,
                subtree: true,
                attributeFilter: ['class']
            });
            
            console.log('[Preserve V2] Observer iniciado');
        }
    }
    
    /**
     * INSTALAR hooks
     */
    function installHooks() {
        console.log('[Preserve V2] Instalando hooks...');
        
        // Hook 1: Cliques DIRETOS em botões de método
        $(document).on('click', '.pagarme-payment-button, .payment-method-button, .payment-tab, .payment-method-tab', function(e) {
            isUserInteracting = true;
            
            const $this = $(this);
            const method = $this.attr('data-type') || $this.data('type') || 
                          $this.attr('data-method') || $this.data('method');
            
            if (method) {
                console.log('[Preserve V2] Clique detectado no botão:', method);
                userSelectedPaymentMethod = method;
            }
            
            setTimeout(function() {
                isUserInteracting = false;
            }, 500);
        });
        
        // Hook 2: Mudanças em radios
        $(document).on('change', 'input[name="pagarme_payment_type"]', function() {
            if (isUserInteracting) {
                const method = $(this).val();
                console.log('[Preserve V2] Radio alterado:', method);
                userSelectedPaymentMethod = method;
            }
        });
        
        // Hook 3: Mudanças no campo hidden (só se for interação do usuário)
        $(document).on('change', '#pagarme-payment-type', function() {
            const newValue = $(this).val();
            
            // Se mudou para credit_card E temos outra seleção salva E não é interação do usuário
            if (newValue === 'credit_card' && 
                userSelectedPaymentMethod && 
                userSelectedPaymentMethod !== 'credit_card' && 
                !isUserInteracting &&
                currentStep > 3) {
                
                console.log('[Preserve V2] Detectada mudança suspeita para credit_card');
                
                setTimeout(function() {
                    restoreUserSelection();
                }, 200);
            }
        });
        
        // Hook 4: ANTES de validar Step 3 (captura final)
        $(document).on('msc:before:validate:step', function(e, data) {
            if (data && data.step === 3) {
                console.log('[Preserve V2] Capturando antes de validar Step 3');
                captureUserSelection();
            }
        });
        
        // Hook 5: AO SAIR do Step 3
        $(document).on('msc_before_next_step', function(e, data) {
            if (currentStep === 3) {
                console.log('[Preserve V2] Saindo do Step 3 - capturando');
                captureUserSelection();
            }
        });
        
        // Hook 6: Após updated_checkout (com delay)
        $(document.body).on('updated_checkout', function() {
            setTimeout(function() {
                if (userSelectedPaymentMethod && currentStep > 3) {
                    console.log('[Preserve V2] updated_checkout - tentando restaurar');
                    restoreUserSelection();
                }
            }, 300);
        });
        
        // Hook 7: Após renderização de campos
        $(document).on('pagarme_fields_rendered', function() {
            setTimeout(function() {
                if (userSelectedPaymentMethod && currentStep > 3) {
                    console.log('[Preserve V2] Campos renderizados - restaurando');
                    restoreUserSelection();
                }
            }, 200);
        });
        
        console.log('[Preserve V2] Hooks instalados');
    }
    
    /**
     * VERIFICAÇÃO CONTÍNUA (fallback)
     */
    function startContinuousCheck() {
        setInterval(function() {
            // Detectar step atual
            const $activeStep = $('.msc-step.active, .step-indicator.active, [data-step].active');
            if ($activeStep.length > 0) {
                const stepAttr = $activeStep.attr('data-step') || $activeStep.index() + 1;
                if (stepAttr && parseInt(stepAttr) !== currentStep) {
                    const oldStep = currentStep;
                    currentStep = parseInt(stepAttr);
                    
                    if (oldStep === 3 && currentStep === 4) {
                        console.log('[Preserve V2] Detectada navegação 3 → 4 via polling');
                        captureUserSelection();
                    }
                }
            }
            
            // Se estamos no Step 4 e temos seleção não-credit_card
            if (currentStep === 4 && userSelectedPaymentMethod && userSelectedPaymentMethod !== 'credit_card') {
                const currentMethod = getCurrentPaymentMethod();
                
                if (currentMethod && currentMethod !== userSelectedPaymentMethod) {
                    console.log('[Preserve V2] Polling detectou divergência - restaurando');
                    restoreUserSelection();
                }
            }
        }, 1000); // Verificar a cada 1 segundo
    }
    
    /**
     * FUNÇÕES DE TESTE
     */
    window.testPreservePaymentV2 = function() {
        console.log('═══════════════════════════════════════');
        console.log('TESTE PRESERVAÇÃO V2');
        console.log('═══════════════════════════════════════\n');
        
        console.log('Step atual:', currentStep);
        console.log('Método salvo:', userSelectedPaymentMethod || 'NENHUM');
        console.log('Método atual no DOM:', getCurrentPaymentMethod() || 'NENHUM');
        console.log('Tentativas de captura:', captureAttempts);
        
        console.log('\nComandos disponíveis:');
        console.log('  captureUserPaymentSelectionV2() - Forçar captura');
        console.log('  forceRestorePaymentSelectionV2() - Forçar restauração');
        console.log('  clearSavedPaymentSelectionV2() - Limpar seleção');
        
        console.log('\n═══════════════════════════════════════');
    };
    
    window.captureUserPaymentSelectionV2 = function() {
        console.log('Forçando captura V2...');
        isUserInteracting = true;
        const result = captureUserSelection();
        isUserInteracting = false;
        
        if (result) {
            console.log('Captura bem-sucedida:', userSelectedPaymentMethod);
        } else {
            console.log('Captura falhou - método não encontrado');
        }
    };
    
    window.forceRestorePaymentSelectionV2 = function() {
        console.log('Forçando restauração V2...');
        const result = restoreUserSelection();
        
        if (result) {
            console.log('Restauração bem-sucedida');
        } else {
            console.log('Restauração falhou');
        }
    };
    
    window.clearSavedPaymentSelectionV2 = function() {
        userSelectedPaymentMethod = null;
        console.log('Seleção limpa');
    };
    
    /**
     * INICIALIZAÇÃO
     */
    $(document).ready(function() {
        setTimeout(function() {
            protectPaymentSelection();
            installHooks();
            startDOMObserver();
            startContinuousCheck();
            
            console.log('[Preserve V2] Correção aprimorada instalada com sucesso!');
            console.log('Use testPreservePaymentV2() no console para testar');
        }, 2000);
    });
    
})(jQuery);
