(function($) {
    'use strict';

    console.log('Correção de Multipagamento - Carregada');
    
    /**
     * Função para injetar campos de multipagamento de forma robusta
     * Compatível com Multistep Checkout Pro v4
     */
    
// === helpers ===
function $checkoutForms(){
  // Aggregate all possible forms used by Woo + Multistep + Order-Pay
  return jQuery('form.checkout, form#order_review, form[name="checkout"], form[id*="checkout"]');
}

    // CORREÇÃO CRÍTICA: Expor globalmente para evitar ReferenceError nas funções de Hardening
    if (typeof window.$checkoutForms === 'undefined') {
        window.$checkoutForms = $checkoutForms;
    }

function injectMultipaymentFieldsRobust() {
        const $form = $checkoutForms();
        
        // Verificar se é multipagamento
        const isMultipayment = $('#enable-multipayment').is(':checked');
        
        if (!isMultipayment) {
            console.log('Multipagamento não ativado');
            return false;
        }
        
        console.log('Multipagamento ativado - injetando campos');
        
        // Remover campos anteriores
        $form.find('.pagarme-multipay-hidden').remove();
        
        // CRÍTICO: Adicionar flag de multipagamento MÚLTIPLAS VEZES
        // para garantir que pelo menos uma chegue no PHP
        $form.append('<input type="hidden" name="pagarme_multipayment" value="yes" class="pagarme-multipay-hidden" />');
        $form.append('<input type="hidden" name="pagarme_multipayment" value="yes" />'); // Sem classe para não ser removida
        $form.append('<input type="hidden" name="is_multipayment" value="1" />'); // Backup alternativo
        
        // Contar métodos adicionados
        let methodsCount = 0;
        
        // Coletar métodos selecionados
        $('.multipay-checkbox:checked').each(function() {
            const method = jQuery(this).val();
            const $amountInput = $(`.multipay-amount[data-method=\"${method}\"]`);
            const amount = parseFloat($amountInput.val() || 0);
            
            if (amount > 0) {
                methodsCount++;
                
                // Adicionar campos do método
                $form.append(`<input type="hidden" name="multi_method_${method}" value="yes" class="pagarme-multipay-hidden" />`);
                $form.append(`<input type="hidden" name="multi_amount_${method}" value="${amount.toFixed(2)}" class="pagarme-multipay-hidden" />`);
                
                console.log(`Método ${method}: R$ ${amount.toFixed(2)}`);
                
                // Se for cartão, adicionar dados do cartão
                if (['credit_card', 'debit_card', 'voucher'].includes(method)) {
                    const cardData = window.PagarMeGatewayCheckout.multipaymentData[method];
                    if (cardData && cardData.card) {
                        const card = cardData.card;
                        
                        // Número do cartão
                        const cardNumberClean = (card.number || '').replace(/\D/g, '');
                        $form.append(`<input type="hidden" name="multi_card_${method}_number_raw" value="${cardNumberClean}" class="pagarme-multipay-hidden" />`);
                        
                        // Titular
                        $form.append(`<input type="hidden" name="multi_card_${method}_holder" value="${card.holder || ''}" class="pagarme-multipay-hidden" />`);
                        
                        // Validade
                        const expiry = card.expiry || '';
                        const expiryClean = expiry.replace(/\D/g, '');
                        if (expiryClean.length >= 4) {
                            const mm = expiryClean.substring(0, 2);
                            const yy = expiryClean.substring(2, 4);
                            const yyyy = '20' + yy;
                            
                            $form.append(`<input type="hidden" name="multi_card_${method}_exp_month" value="${mm}" class="pagarme-multipay-hidden" />`);
                            $form.append(`<input type="hidden" name="multi_card_${method}_exp_year" value="${yyyy}" class="pagarme-multipay-hidden" />`);
                            $form.append(`<input type="hidden" name="multi_card_${method}_expiration" value="${mm}${yy}" class="pagarme-multipay-hidden" />`);
                        }
                        
                        // CVV
                        $form.append(`<input type="hidden" name="multi_card_${method}_cvv" value="${card.cvv || ''}" class="pagarme-multipay-hidden" />`);
                        
                        // Documento
                        const documentClean = (card.document || '').replace(/\D/g, '');
                        $form.append(`<input type="hidden" name="multi_card_${method}_document" value="${documentClean}" class="pagarme-multipay-hidden" />`);
                        
                        // Parcelas (apenas para crédito)
                        if (method === 'credit_card') {
                            $form.append(`<input type="hidden" name="multi_card_${method}_installments" value="${card.installments || 1}" class="pagarme-multipay-hidden" />`);
                        }
                        
                        console.log(`Dados do cartão ${method} injetados`);
                    }
                }
            }
        });

    function injectSinglePaymentFieldsEarly() {
        
        if (singlePaymentFieldsInjected) {
            console.log('[Single Early] Campos já injetados');
            return true;
        }
        
        const $form = $('form.checkout, form#order_review');
        const paymentMethod = $('input[name="payment_method"]:checked').val();
        
        if (paymentMethod !== 'pagarme_gateway') {
            return false;
        }
        
        if ($('input[name="pagarme_multipayment"]').val() === 'yes') {
            return false;
        }
        
        const paymentType = $('input[name="pagarme_payment_type"]:checked').val() || $('#pagarme-payment-type').val() || $('.pagarme-payment-button.active').attr('data-type') || 'credit_card';
        
        console.log('[Single Early] Iniciando injeção antecipada');
        
        if (!['credit_card', 'debit_card', 'voucher'].includes(paymentType)) {
            singlePaymentFieldsInjected = true;
            return true;
        }
        
        $form.find('.pagarme-single-early-hidden').remove();
        
        const cardNumber = $('#pagarme-card-number').val() || '';
        const cardNumberClean = cardNumber.replace(/\D/g, '');
        const holderName = $('#pagarme-card-holder-name').val() || '';
        const expiryRaw = $('#pagarme-card-expiry').val() || '';
        const cvv = $('#pagarme-card-cvv').val() || '';
        const cardDocument = $('#pagarme-card-document').val() || '';
        const cardDocumentClean = cardDocument.replace(/\D/g, '');
        const installments = $('#pagarme-installments').val() || '1';
        
        if (!cardNumberClean || !holderName || !expiryRaw || !cvv) {
            console.warn('[Single Early] Campos não preenchidos');
            return false;
        }
        
        const expiryDigits = expiryRaw.replace(/\D/g, '');
        if (expiryDigits.length < 4) {
            return false;
        }
        
        const expMonth = expiryDigits.substring(0, 2);
        const expYear = expiryDigits.substring(2, 4);
        const expYearFull = '20' + expYear;
        
        const fields = [
            { name: 'pagarme_card_number_raw', value: cardNumberClean },
            { name: 'pagarme_card_number', value: cardNumber },
            { name: 'pagarme_card_holder', value: holderName },
            { name: 'pagarme_card_holder_name', value: holderName },
            { name: 'pagarme_card_exp_month', value: expMonth },
            { name: 'pagarme_card_exp_year', value: expYearFull },
            { name: 'pagarme_card_expiration', value: expMonth + expYear },
            { name: 'pagarme_card_expiry', value: expiryRaw },
            { name: 'pagarme_card_cvv', value: cvv },
            { name: 'pagarme_card_document', value: cardDocumentClean },
            { name: 'pagarme_holder_document', value: cardDocumentClean }
        ];
        
        if (paymentType === 'credit_card') {
            fields.push({ name: 'pagarme_installments', value: installments });
        }
        
        let injectedCount = 0;
        fields.forEach(field => {
            if (field.value) {
                $form.append(
                    `<input type="hidden" name="${field.name}" value="${field.value}" class="pagarme-single-early-hidden" />`
                );
                injectedCount++;
            }
        });
        
        console.log(`[Single Early] ${injectedCount} campos injetados`);
        singlePaymentFieldsInjected = true;
        
        return true;
    }
    
    $(document).ready(function() {
        
        $(document).on('change', 'input[name="payment_method"]', function() {
            singlePaymentFieldsInjected = false;
            setTimeout(injectSinglePaymentFieldsEarly, 100);
        });
        
        $(document).on('change', 'input[name="pagarme_payment_type"]', function() {
            singlePaymentFieldsInjected = false;
            setTimeout(injectSinglePaymentFieldsEarly, 100);
        });
        
        $(document).on('blur', '#pagarme-card-number, #pagarme-card-holder-name, #pagarme-card-expiry, #pagarme-card-cvv', function() {
            setTimeout(injectSinglePaymentFieldsEarly, 100);
        });
        
        $(document).on('submit', 'form.checkout', function(e) {
            const paymentMethod = $('input[name="payment_method"]:checked').val();
            if (paymentMethod === 'pagarme_gateway') {
                injectSinglePaymentFieldsEarly();
            }
        });
        
        $(document).on('msc_before_next_step', function() {
            const paymentMethod = $('input[name="payment_method"]:checked').val();
            if (paymentMethod === 'pagarme_gateway') {
                injectSinglePaymentFieldsEarly();
            }
        });
        
        $(document).on('click', '#place_order, button[name="woocommerce_checkout_place_order"]', function(e) {
            const paymentMethod = $('input[name="payment_method"]:checked').val();
            if (paymentMethod === 'pagarme_gateway') {
                injectSinglePaymentFieldsEarly();
            }
        });
        
        console.log('[Single Early] Listeners instalados');
    });        
        
        console.log(`Total de métodos injetados: ${methodsCount}`);
        
        // Verificar se os campos foram realmente adicionados
        const injectedCount = $form.find('.pagarme-multipay-hidden').length;
        const multipaymentFlag = $form.find('input[name="pagarme_multipayment"]').length;
        
        console.log(`Campos injetados: ${injectedCount}`);
        console.log(`Flags de multipagamento: ${multipaymentFlag}`);
        
        return methodsCount > 0;
    }
    
    /**
     * Injetar em MÚLTIPLOS pontos para garantir compatibilidade com Multistep
     */
    
    // 1. Antes do submit do formulário
    $(document).on('submit', 'form.checkout', function(e) {
        const paymentMethod = $('input[name="payment_method"]:checked').val();
        if (paymentMethod === 'pagarme_gateway') {
            console.log('Submit do formulário - injetando campos');
            injectMultipaymentFieldsRobust();
        }
    });
    
    // 2. Ao clicar no botão de finalizar
    $(document).on('click', '#place_order, .msc-place-order, button[name="woocommerce_checkout_place_order"]', function() {
        const paymentMethod = $('input[name="payment_method"]:checked').val();
        if (paymentMethod === 'pagarme_gateway') {
            console.log('Botão finalizar clicado - injetando campos');
            injectMultipaymentFieldsRobust();
        }
    });
    
    // 3. Hook do WooCommerce
    $(document).on('checkout_place_order_pagarme_gateway', function() {
        console.log('Hook WooCommerce - injetando campos');
        injectMultipaymentFieldsRobust();
        return true;
    });
    
    // 4. Antes do AJAX
    $(document).ajaxSend(function(event, jqxhr, settings) {
        if (settings.url && settings.url.includes('wc-ajax=checkout')) {
            const paymentMethod = $('input[name="payment_method"]:checked').val();
            if (paymentMethod === 'pagarme_gateway') {
                console.log('AJAX detectado - injetando campos');
                injectMultipaymentFieldsRobust();
            }
        }
    });
    
    // 5. Específico para Multistep - Ao mudar de step
    $(document).on('msc:step:changed', function(e, data) {
        if (data && data.newStep === 4) {
            console.log('Mudou para Step 4 - preparando injeção');
            setTimeout(function() {
                const paymentMethod = $('input[name="payment_method"]:checked').val();
                if (paymentMethod === 'pagarme_gateway') {
                    injectMultipaymentFieldsRobust();
                }
            }, 100);
        }
    });
    
    // 6. Específico para Multistep - Antes de validar step 3
    $(document).on('msc:before:validate:step', function(e, data) {
        if (data && data.step === 3) {
            console.log('Validando Step 3 - injetando campos');
            const paymentMethod = $('input[name="payment_method"]:checked').val();
            if (paymentMethod === 'pagarme_gateway') {
                injectMultipaymentFieldsRobust();
            }
        }
    });
    
    // 7. Função global para testes
    window.testMultipaymentInjection = function() {
        console.log('TESTE DE INJEÇÃO DE MULTIPAGAMENTO');
        console.log('=====================================');
        
        injectMultipaymentFieldsRobust();
        
        // Verificar resultado
        const $form = $checkoutForms();
        const multipaymentFlags = $form.find('input[name="pagarme_multipayment"]').length;
        const multiMethodFields = $form.find('input[name^="multi_method_"]').length;
        const multiAmountFields = $form.find('input[name^="multi_amount_"]').length;
        
        console.log('Resultado:');
        console.log(`  - Flags de multipagamento: ${multipaymentFlags}`);
        console.log(`  - Campos multi_method_: ${multiMethodFields}`);
        console.log(`  - Campos multi_amount_: ${multiAmountFields}`);
        
        // Listar todos os campos injetados
        console.log('Campos injetados:');
        $form.find('.pagarme-multipay-hidden').each(function() {
            const name = $(this).attr('name');
            const value = $(this).val();
            console.log(`  - ${name}: ${value}`);
        });
        
        console.log('=====================================');
        
        return {
            multipaymentFlags: multipaymentFlags,
            multiMethodFields: multiMethodFields,
            multiAmountFields: multiAmountFields
        };
    };
    
    console.log('Correção de multipagamento instalada');
    console.log('Use testMultipaymentInjection() no console para testar');

    // ======= DOC VALIDATORS & SANDBOX HELPERS (added) =======
    function onlyDigits(str) {
        return (str || '').toString().replace(/\D/g, '');
    }
    function isValidCPF(cpfRaw) {
        const cpf = onlyDigits(cpfRaw);
        if (cpf.length != 11) return false;
        if (/^(\d)\1+$/.test(cpf)) return false;
        let sum = 0;
        for (let i=0;i<9;i++) sum += parseInt(cpf.charAt(i),10) * (10-i);
        let d1 = 11 - (sum % 11); if (d1 >= 10) d1 = 0;
        sum = 0;
        for (let i=0;i<10;i++) sum += parseInt(cpf.charAt(i),10) * (11-i);
        let d2 = 11 - (sum % 11); if (d2 >= 10) d2 = 0;
        return d1 === parseInt(cpf.charAt(9),10) && d2 === parseInt(cpf.charAt(10),10);
    }
    function isValidCNPJ(cnpjRaw) {
        const cnpj = onlyDigits(cnpjRaw);
        if (cnpj.length != 14) return false;
        if (/^(\d)\1+$/.test(cnpj)) return false;
        const calcDV = (base) => {
            let length = base.length;
            let pos = length - 7;
            let sum = 0;
            for (let i = length; i >= 1; i--) {
                sum += parseInt(base.charAt(length - i), 10) * pos--;
                if (pos < 2) pos = 9;
            }
            let dv = sum % 11;
            return (dv < 2) ? 0 : (11 - dv);
        };
        const base12 = cnpj.substring(0,12);
        const dv1 = calcDV(base12);
        const dv2 = calcDV(base12 + String(dv1));
        return cnpj === (base12 + String(dv1) + String(dv2));
    }
    function isValidDocumentCPFOrCNPJ(docRaw) {
        const d = onlyDigits(docRaw);
        if (d.length === 11) return isValidCPF(d);
        if (d.length === 14) return isValidCNPJ(d);
        return false;
    }
    function isSandboxEnv() {
        try {
            return (typeof pagarme_params !== 'undefined' && pagarme_params.environment === 'test') ||
                   (typeof window.PagarMeGatewayConfig !== 'undefined' && window.PagarMeGatewayConfig.environment === 'test');
        } catch(e){ return false; }
    }
    function isAllowedTestDocument(docRaw) {
        if (!isSandboxEnv()) return false;  // in production, never allow
        const d = onlyDigits(docRaw);
        const whitelist = [
            '00000000000','11111111111','22222222222','33333333333','44444444444',
            '55555555555','66666666666','77777777777','88888888888','99999999999',
            '12345678909',
            '00000000000000','11111111000191'
        ];
        return whitelist.includes(d);
    }
        function normalizeExpiry(input) {
    // aceita "MM/AA", "MM-AA", "MMYY", "MMYYYY"
    const d = (input || '').toString().replace(/[^\d]/g, '').slice(0, 6);
    if (d.length < 4) return null;
    const mm = d.slice(0, 2);
    // pega 2 últimos dígitos do ano
    const yy = (d.length === 4 ? d.slice(2, 4) : d.slice(-2));
    const yyyy = '20' + yy;
    const mmyy = mm + yy;

    const m = parseInt(mm, 10);
    if (m < 1 || m > 12) return null;

    // checagem de vencimento no fim do mês
    const now = new Date();
    const exp = new Date(parseInt(yyyy, 10), m - 1, 1);
    const cur = new Date(now.getFullYear(), now.getMonth(), 1);
    if (exp < cur) return null;

    return { mm, yy, yyyy, mmyy };
    }
    // ======= END validators (added) =======


    
    console.log('Pagar.me v3.0.0 COMPLETA carregado');
    
    var PagarMeGatewayCheckout = {
        
        // Propriedades básicas
        isMultistep: false,
        fieldsRendered: false,
        isRendering: false,
        selectedPaymentType: null,
        debugMode: true,
        
        // Multipagamento
        multipaymentEnabled: false,
        multipaymentMode: false,
        multipaymentData: {},
        allowedCombinations: [],
        orderTotal: 0,
        
        /**
         * INICIALIZAÇÃO
         */
        init: function() {
            this.log('💳 Inicializando v3.0.0...');
            
            // Detectar se está no multistep
            this.isMultistep = $('.msc-checkout-wrapper').length > 0;
            
            // Carregar configurações do PHP
            this.loadConfiguration();
            
            // Renderizar campos
            this.forceRenderFields();
            
            this.multipaymentMode = false;
            $('#enable-multipayment').prop('checked', false);
            $('#multipayment-interface').hide();
            $('.payment-methods-list').show();
            
            // Bind de eventos
            this.bindEvents();
            
            // Validações
            this.initValidation();
            
            // REMOVER ESTA PARTE:
            // if (this.isMultistep) {
            //     this.initMultistepCompat();
            // }
            
            this.log('Inicializado');
            
            // Expor globalmente para o multistep acessar
            window.PagarMeGatewayCheckout = this;
        },
        
        /**
         * Carregar configurações do PHP
         */
        loadConfiguration: function() {
            // Multipagamento habilitado?
            if (typeof pagarme_params !== 'undefined') {
                this.multipaymentEnabled = pagarme_params.multipayment_enabled || false;
                this.log('Multipagamento habilitado:', this.multipaymentEnabled);
                
                // Carregar combinações permitidas
                if (pagarme_params.multipayment_combinations) {
                    this.allowedCombinations = pagarme_params.multipayment_combinations;
                    this.log('Combinações permitidas:', this.allowedCombinations);
                }
            }
            
            // Fallback para window.PagarMeGatewayConfig
            if (typeof window.PagarMeGatewayConfig !== 'undefined') {
                if (!this.multipaymentEnabled && window.PagarMeGatewayConfig.multipayment) {
                    this.multipaymentEnabled = window.PagarMeGatewayConfig.multipayment.enabled;
                    this.allowedCombinations = window.PagarMeGatewayConfig.multipayment.combinations || [];
                }
            }
        },
        
        /**
         * RENDERIZAÇÃO DE CAMPOS
         */
        
        /**
         * Obter métodos habilitados do PHP
         */
        getEnabledMethods: function() {
            let methods = ['credit_card', 'pix', 'boleto']; // Padrão
            
            if (typeof pagarme_params !== 'undefined' && pagarme_params.enabled_methods) {
                const raw = pagarme_params.enabled_methods;
                
                if (Array.isArray(raw)) {
                    methods = raw;
                } else if (typeof raw === 'object') {
                    methods = Object.keys(raw);
                }
            } else if (typeof window.PagarMeGatewayConfig !== 'undefined' && 
                       window.PagarMeGatewayConfig.methods) {
                methods = Object.keys(window.PagarMeGatewayConfig.methods);
            }
            
            this.log('Métodos habilitados:', methods);
            return methods;
        },
        
        /**
         * Forçar renderização dos campos
         */
        forceRenderFields: function() {
            if (this.isRendering) {
                this.log('⚠️ Já está renderizando');
                return;
            }
            
            this.isRendering = true;
            this.log('🎨 Renderizando campos...');
            
            const $paymentBox = $('.payment_box.payment_method_pagarme_gateway');
            
            if ($paymentBox.length === 0) {
                this.log('❌ Payment box não encontrado');
                this.isRendering = false;
                return;
            }
            
            // Verificar se já existem campos
            const $existing = $('input[name="pagarme_payment_type"]');
            if ($existing.length > 0) {
                this.log('Campos já existem');
                this.isRendering = false;
                return;
            }
            
            // Obter métodos e renderizar
            const methods = this.getEnabledMethods();
            const html = this.generateFieldsHTML(methods);
            $paymentBox.html(html);
            
            this.fieldsRendered = true;
            this.log('Campos renderizados');
            
            // Selecionar primeiro método
            setTimeout(() => {
                this.selectFirstPaymentMethod();
                this.isRendering = false;
            }, 100);
        },
        
        /**
         * Gerar HTML completo dos campos
         */
        generateFieldsHTML: function(methods) {
            let html = '<div id="pagarme-payment-methods" style="margin: 20px 0;">';
            
            // Botão de multipagamento
            if (this.multipaymentEnabled) {
                html += this.getMultipaymentToggleHTML();
            }
            
            // Título
            html += '<h3 style="margin-bottom: 15px; font-size: 18px; color: #333;">Escolha a forma de pagamento:</h3>';
            
            // Lista de métodos (pagamento único)
            html += '<div class="payment-methods-list" style="margin-bottom: 20px;">';
            methods.forEach(method => {
                html += this.getPaymentOptionHTML(method, this.getMethodLabel(method));
            });
            html += '</div>';
            
            // Interface de multipagamento
            if (this.multipaymentEnabled) {
                html += this.getMultipaymentInterfaceHTML(methods);
            }
            
            // Campos do cartão (pagamento único)
            html += this.getCardFieldsHTML();
            
            // Info do PIX
            html += this.getPixInfoHTML();
            
            // Info do Boleto
            html += this.getBoletoInfoHTML();
            
            html += '</div>';
            
            return html;
        },
        
        /**
         * MULTIPAGAMENTO - UX MELHORADA
         */
        
        /**
         * HTML do botão toggle de multipagamento
         */
        getMultipaymentToggleHTML: function() {
            return `
                <div style="margin-bottom: 20px; padding: 15px; background: #e7f3ff; border: 2px solid #0073aa; border-radius: 8px;">
                    <label style="display: flex; align-items: center; cursor: pointer; font-weight: 600;">
                        <input type="checkbox" id="enable-multipayment" style="margin-right: 10px; width: 20px; height: 20px;" />
                        <span style="font-size: 16px;">💰 Pagar com múltiplos métodos</span>
                    </label>
                    <p style="margin: 8px 0 0 30px; font-size: 13px; color: #666;">
                        Divida o pagamento entre diferentes meios (cartão, PIX, boleto, etc)
                    </p>
                </div>
            `;
        },
        
        /**
         * HTML da interface de multipagamento
         */
        getMultipaymentInterfaceHTML: function(methods) {
            let html = `
                <div id="multipayment-interface" style="display: none; margin-bottom: 20px; padding: 20px; background: #f8f9fa; border-radius: 8px; border: 2px solid #0073aa;">
                    <h4 style="margin: 0 0 15px 0; color: #0073aa;">💰 Divida o pagamento:</h4>
                    <div id="multipayment-methods">
            `;
            
            // Renderizar cada método
            methods.forEach(method => {
                html += `
                    <div class="multipay-method" data-method="${method}" 
                         style="margin-bottom: 15px; padding: 12px; background: white; border-radius: 6px; border: 1px solid #ddd; transition: all 0.3s;">
                        
                        <label style="display: flex; align-items: center; margin-bottom: 8px; cursor: pointer;">
                            <input type="checkbox" class="multipay-checkbox" value="${method}" 
                                   style="margin-right: 10px; width: 18px; height: 18px;" />
                            <span class="multipay-label" style="font-weight: 600; font-size: 15px; transition: opacity 0.3s;">
                                ${this.getMethodLabel(method)}
                            </span>
                        </label>
                        
                        <div class="multipay-amount-input" style="display: none; margin-left: 28px;">
                            <label style="display: block; margin-bottom: 5px; font-size: 13px; color: #666;">
                                Valor para este método:
                            </label>
                            <input type="number" class="multipay-amount" data-method="${method}" 
                                   placeholder="R$ 0,00" step="0.01" min="0" 
                                   style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" />
                        </div>
                        
                        <!-- Container para campos do cartão (será inserido dinamicamente) -->
                        <div class="multipay-card-container" data-method="${method}"></div>
                    </div>
                `;
            });
            
            html += `
                    </div>
                    
                    <div id="multipay-summary" style="margin-top: 15px; padding: 12px; background: #e7f3ff; border-radius: 6px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                            <span>Total do pedido:</span>
                            <strong id="multipay-total">R$ 0,00</strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                            <span>Valor distribuído:</span>
                            <span id="multipay-distributed">R$ 0,00</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; padding-top: 8px; border-top: 1px solid #0073aa;">
                            <span><strong>Faltando:</strong></span>
                            <strong id="multipay-remaining" style="color: #d63638;">R$ 0,00</strong>
                        </div>
                    </div>
                </div>
            `;
            
            return html;
        },
        
        /**
        * VERSÃO MELHORADA - Toggle com inicialização correta
        */
        toggleMultipaymentMode: function(enabled) {
            this.multipaymentMode = enabled;
            this.log('Modo multipagamento:', enabled);
            
            if (enabled) {
                // Esconder seleção única
                $('.payment-methods-list').hide();
                $('#card-fields, #pix-info, #boleto-info').hide();
                
                // Mostrar interface multipagamento
                $('#multipayment-interface').show();
                $('.multipay-method').show();
                
                // Limpar seleção única
                $('input[name="pagarme_payment_type"]').prop('checked', false);
                
                // CORREÇÃO: Garantir que todos métodos estejam disponíveis inicialmente
                this.clearMultipaymentData();
                
                // CORREÇÃO: Mostrar dica útil
                this.showMultipayHint('💡 Selecione o primeiro método de pagamento para começar');
                
                // Atualizar total
                this.updateMultipaymentTotal();
                
            } else {
                // Voltar para seleção única
                $('.payment-methods-list').show();
                $('#multipayment-interface').hide();
                
                // Limpar dados
                this.clearMultipaymentData();
                
                // Selecionar primeiro método
                this.selectFirstPaymentMethod();
            }
        },
                
        /**
        * VERSÃO CORRIGIDA - Limpar dados garantindo estado inicial correto
        */
        clearMultipaymentData: function() {
            this.multipaymentData = {};
            
            // CORREÇÃO: Resetar TODOS os checkboxes para estado inicial
            $('.multipay-checkbox').prop('checked', false).prop('disabled', false);
            $('.multipay-amount').val('');
            $('.multipay-amount-input').hide();
            
            // CORREÇÃO: Resetar estilos para estado inicial (todos disponíveis)
            $('.multipay-method').each(function() {
                const $method = $(this);
                const method = $method.data('method');
                
                // Todos visíveis e clicáveis
                $method.css({
                    'border-color': '#ddd',
                    'opacity': '1',
                    'pointer-events': 'auto',
                    'background-color': '#ffffff'
                });
            });
            
            // Resetar labels
            $('.multipay-label').css({
                'opacity': '1',
                'text-decoration': 'none'
            });
            
            // Remover campos de cartão
            $('.multipay-card-fields').remove();
            
            this.log('Dados de multipagamento limpos');
        },
                
        /**
         * Toggle de método no multipagamento com validação UX
         */
        handleMultipayMethodToggle: function(method, isChecked) {
            const $methodDiv = $(`.multipay-method[data-method="${method}"]`);
            const $checkbox = $(`.multipay-checkbox[value="${method}"]`);
            
            if (isChecked) {
                // Verificar se combinação é permitida
                let selectedMethods = this.getSelectedMultipayMethods();

                // Garante que o método atual entra só uma vez
                if (isChecked && !selectedMethods.includes(method)) {
                    selectedMethods.push(method);
                }
                
                if (!this.isCombinationAllowed(selectedMethods)) {
                    // Combinação não permitida - desmarcar
                    $checkbox.prop('checked', false);
                    
                    // Mostrar feedback visual temporário
                    $methodDiv.css('background-color', '#ffe0e0');
                    setTimeout(() => {
                        $methodDiv.css('background-color', '');
                    }, 500);
                    
                    // Mostrar mensagem sutil (não é erro agressivo)
                    this.showMultipayHint('Esta combinação não está disponível para este pedido');
                    return;
                }
                
                // Combinação permitida
                // Mostrar campo de valor
                $methodDiv.find('.multipay-amount-input').slideDown(300);
                $methodDiv.css('border-color', '#0073aa');
                
                // Se for cartão, mostrar campos
                if (this.isCardMethod(method)) {
                    this.showMultipayCardFields(method);
                }
                
  // Atualizar disponibilidade dos outros métodos
                this.updateMethodsAvailability();
                
            } else {
                // Desmarcar
                $methodDiv.find('.multipay-amount-input').slideUp(300);
                $methodDiv.css('border-color', '#ddd');
                delete this.multipaymentData[method];
                
                // Esconder campos do cartão
                if (this.isCardMethod(method)) {
                    this.hideMultipayCardFields(method);
                }
                
  // Atualizar disponibilidade dos outros métodos
                this.updateMethodsAvailability();
            }
            
            // Recalcular distribuição
            this.calculateMultipaymentDistribution();

            this.syncMultipaymentDataFromDOM();
        },

        /**
        * Monta this.multipaymentData a partir do que está na tela
        * para o multistep conseguir validar.
        */
        syncMultipaymentDataFromDOM: function() {
            const data = {};
            const self = this;

            $('.multipay-checkbox:checked').each(function() {
                const method = $(this).val();
                const amount = parseFloat($(`.multipay-amount[data-method="${method}"]`).val()) || 0;

                // só guarda se tiver valor > 0
                if (amount > 0) {
                    data[method] = {
                        type: method,     // credit_card, pix, boleto...
                        value: amount
                    };

                    // se for cartão, guarda também os campos (opcional, mas útil p/ debug)
                    if (self.isCardMethod(method)) {
                        data[method].card = {
                            number: $(`.multipay-card-number[data-method="${method}"]`).val() || '',
                            expiry: $(`.multipay-card-expiry[data-method="${method}"]`).val() || '',
                            cvv:    $(`.multipay-card-cvv[data-method="${method}"]`).val() || '',
                            holder: $(`.multipay-card-holder[data-method="${method}"]`).val() || '',
                            document: $(`.multipay-card-document[data-method="${method}"]`).val() || '',
                            installments: $(`.multipay-installments[data-method="${method}"]`).val() || '1'
                        };
                    }
                }
            });

            this.multipaymentData = data;
            this.log('🔁 multipaymentData sincronizado do DOM:', this.multipaymentData);
        },

        /**
        * VERSÃO CORRIGIDA - Atualizar disponibilidade visual dos métodos
        */
        updateMethodsAvailability: function() {
            const selectedMethods = this.getSelectedMultipayMethods();
            
            // CORREÇÃO: Se nenhum selecionado, TODOS disponíveis (não bloquear nada!)
            if (selectedMethods.length === 0) {
                $('.multipay-method').each(function() {
                    const $method = $(this);
                    const method = $method.data('method');
                    const isConfigured = self.isMethodConfigured(method);
                    
                    // Todos clicáveis, mas configurados ficam mais destacados
                    $method.css({
                        'opacity': isConfigured ? '1' : '0.7',
                        'pointer-events': 'auto',
                        'border-color': isConfigured ? '#0073aa' : '#ddd'
                    });
                    
                    $method.find('.multipay-checkbox').prop('disabled', false);
                    $method.find('.multipay-label').css('opacity', isConfigured ? '1' : '0.7');
                });
                return;
            }
            
            // Guardar referência do self
            const self = this;
            
            // Testar cada método não selecionado
            $('.multipay-method').each(function() {
                const $method = $(this);
                const method = $method.data('method');
                const $checkbox = $method.find('.multipay-checkbox');
                const $label = $method.find('.multipay-label');
                
                // Se já está selecionado, manter habilitado
                if ($checkbox.is(':checked')) {
                    $method.css({
                        'opacity': '1',
                        'pointer-events': 'auto',
                        'border-color': '#28a745' // Verde para selecionado
                    });
                    $checkbox.prop('disabled', false);
                    $label.css('opacity', '1');
                    return;
                }
                
                // CORREÇÃO: Testar se seria permitido com este método
                const testCombination = [...selectedMethods, method];
                const wouldBeAllowed = self.isCombinationAllowed(testCombination);
                
                if (wouldBeAllowed) {
                    // Seria permitido - deixar disponível
                    $method.css({
                        'opacity': '1',
                        'pointer-events': 'auto',
                        'border-color': '#0073aa'
                    });
                    $checkbox.prop('disabled', false);
                    $label.css('opacity', '1');
                } else {
                    // Não seria permitido - ESMAECER (mas não desabilitar completamente)
                    $method.css({
                        'opacity': '0.4',
                        'pointer-events': 'none',
                        'border-color': '#ddd'
                    });
                    $checkbox.prop('disabled', true);
                    $label.css({
                        'opacity': '0.5',
                        'text-decoration': 'line-through'
                    });
                }
            });
        },

        /**
        * NOVA FUNÇÃO - Verificar se método está entre os habilitados
        */
        isMethodConfigured: function(method) {
            // Obter métodos habilitados do pagarme_params
            if (typeof pagarme_params !== 'undefined' && pagarme_params.enabled_methods) {
                const enabledMethods = Array.isArray(pagarme_params.enabled_methods) 
                    ? pagarme_params.enabled_methods 
                    : Object.keys(pagarme_params.enabled_methods);
                
                return enabledMethods.includes(method);
            }
            
            // Fallback: considerar todos configurados se não houver info
            return true;
        },

        /**
        * VERSÃO MELHORADA - Dicas mais amigáveis
        */
        showMultipayHint: function(message, type = 'info') {
            // Remover hint anterior
            $('.multipay-hint').remove();
            
            // Cores por tipo
            const colors = {
                'info': { bg: '#e7f3ff', border: '#0073aa', text: '#0073aa' },
                'warning': { bg: '#fff3cd', border: '#ffc107', text: '#856404' },
                'error': { bg: '#ffe0e0', border: '#dc3545', text: '#721c24' }
            };
            
            const color = colors[type] || colors['info'];
            
            // Criar hint
            const $hint = $('<div class="multipay-hint"></div>');
            $hint.css({
                'padding': '12px 15px',
                'background': color.bg,
                'border-left': '4px solid ' + color.border,
                'margin-bottom': '15px',
                'font-size': '14px',
                'color': color.text,
                'border-radius': '4px',
                'animation': 'slideDown 0.3s ease'
            }).text(message);
            
            // Inserir antes dos métodos
            $('#multipayment-methods').prepend($hint);
            
            // Auto-remover após 4 segundos (apenas para info)
            if (type === 'info') {
                setTimeout(() => {
                    $hint.fadeOut(300, function() {
                        $(this).remove();
                    });
                }, 4000);
            }
        },

        /**
        * VERSÃO CORRIGIDA - Verificar se combinação é permitida
        */
        isCombinationAllowed: function(methods) {
            // CORREÇÃO: Se é um único método, SEMPRE permitir (primeira seleção)
            if (methods.length === 1) {
                return true;
            }
            
            // Se não há métodos, retornar true (estado inicial)
            if (methods.length === 0) {
                return true;
            }
            
            // CORREÇÃO: Se não há combinações configuradas no admin, permitir qualquer
            if (!this.allowedCombinations || this.allowedCombinations.length === 0) {
                console.warn('Nenhuma combinação configurada - permitindo todas');
                return true;
            }
            
            // Mapear para abreviações
            const abbrs = methods.map(m => this.mapMethodToAbbr(m)).sort();
            
            // Gerar permutações
            const permutations = this.generatePermutations(abbrs);
            
            // Verificar se alguma está nas combinações permitidas
            const isAllowed = permutations.some(combo => 
                this.allowedCombinations.includes(combo)
            );
            
            if (!isAllowed) {
                console.log('Combinação não permitida:', methods, '(testado:', permutations, ')');
            }
            
            return isAllowed;
        },

        /**
         * Mapear método para abreviação
         * * =================================
         * CORREÇÃO APLICADA
         * A lógica original abreviava 'credit_card' para 'credit', 
         * causando a validação de 'credit_credit' (o que gerava o erro).
         * * A lógica corrigida abaixo foi modificada para SEMPRE retornar 
         * o nome completo do método (ex: 'credit_card').
         * * Isso fará com que a validação procure por 'credit_card_credit_card'
         * nas suas combinações permitidas.
         */
        mapMethodToAbbr: function(method) {
            
            // Lógica antiga (com o bug de abreviação)
            const map = {
                'credit_card': 'credit',
                'debit_card': 'debit',
                'pix': 'pix',
                'boleto': 'boleto',
                'voucher': 'voucher'
            };
            return map[method] || method;

            // Lógica Corrigida: Retorna o nome original do método
            return method;
        },
        
        /**
         * Gerar permutações de combinações
         */
        generatePermutations: function(abbrs) {
            const combinations = [];
            
            if (abbrs.length === 2) {
                combinations.push(abbrs[0] + '_' + abbrs[1]);
                combinations.push(abbrs[1] + '_' + abbrs[0]);
            } else if (abbrs.length === 3) {
                // 6 permutações possíveis
                combinations.push(abbrs[0] + '_' + abbrs[1] + '_' + abbrs[2]);
                combinations.push(abbrs[0] + '_' + abbrs[2] + '_' + abbrs[1]);
                combinations.push(abbrs[1] + '_' + abbrs[0] + '_' + abbrs[2]);
                combinations.push(abbrs[1] + '_' + abbrs[2] + '_' + abbrs[0]);
                combinations.push(abbrs[2] + '_' + abbrs[0] + '_' + abbrs[1]);
                combinations.push(abbrs[2] + '_' + abbrs[1] + '_' + abbrs[0]);
            }
            
            return combinations;
        },
        
        /**
         * Obter métodos selecionados
         */
        getSelectedMultipayMethods: function() {
            const selected = [];
            $('.multipay-checkbox:checked').each(function() {
                selected.push($(this).val());
            });
            return selected;
        },
        
        /**
         * CAMPOS DO CARTÃO NO MULTIPAGAMENTO
         */
        
        /**
         * Verificar se é método de cartão
         */
        isCardMethod: function(method) {
            return ['credit_card', 'debit_card', 'voucher'].includes(method);
        },
        
        /**
         * Mostrar campos do cartão
         */
        showMultipayCardFields: function(method) {
            const $container = $(`.multipay-card-container[data-method="${method}"]`);
            
            // Se já existe, apenas mostrar
            if ($container.find('.multipay-card-fields').length > 0) {
                $container.find('.multipay-card-fields').slideDown(300);
                return;
            }
            
            // Criar campos
            const html = this.getMultipayCardFieldsHTML(method);
            $container.html(html);
            $container.find('.multipay-card-fields').hide().slideDown(300);
            
            // Bind formatação
            this.bindMultipayCardFormatting(method);
        },
        
        /**
         * Esconder campos do cartão
         */
        hideMultipayCardFields: function(method) {
            $(`.multipay-card-container[data-method="${method}"] .multipay-card-fields`).slideUp(300, function() {
                $(this).find('input, select').val('');
            });
        },
        
        /**
         * HTML dos campos do cartão
         */
        getMultipayCardFieldsHTML: function(method) {
            const methodLabel = this.getMethodLabel(method);
            const showInstallments = method === 'credit_card';

            // monta opções de parcelas exatamente como no pagamento único
            let installmentsOptions = '';
            if (showInstallments) {
                // tenta ler do pagarme_params.installments.max
                const installmentsConf = (typeof pagarme_params !== 'undefined' && pagarme_params.installments)
                    ? pagarme_params.installments
                    : null;

                const maxInstallments = (installmentsConf && installmentsConf.max)
                    ? installmentsConf.max
                    : 12; // fallback seguro

                // OBS: se você tiver tabela de parcelas com juros no pagarme_params,
                // aqui dá pra montar label tipo "3x de R$ 120,00" etc.
                // por enquanto replica o mesmo padrão "Nx sem juros"
                for (let i = 1; i <= maxInstallments; i++) {
                    installmentsOptions += `<option value="${i}">${i}x sem juros</option>`;
                }
            }

            return `
                <div class="multipay-card-fields" style="margin-top: 10px; padding: 15px; background: #f8f9fa; border-radius: 6px; border: 1px solid #0073aa;">
                    
                    <h5 style="margin: 0 0 12px 0; font-size: 14px; color: #0073aa;">
                        💳 Dados do ${methodLabel}
                    </h5>
                    
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; margin-bottom: 4px; font-size: 13px; font-weight: 500;">
                            Número do Cartão *
                        </label>
                        <input type="text" 
                            class="multipay-card-number" 
                            data-method="${method}"
                            placeholder="0000 0000 0000 0000" 
                            maxlength="19"
                            style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" />
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-bottom: 12px;">
                        <div style="flex: 1;">
                            <label style="display: block; margin-bottom: 4px; font-size: 13px; font-weight: 500;">
                                Validade *
                            </label>
                            <input type="text" 
                                class="multipay-card-expiry" 
                                data-method="${method}"
                                placeholder="MM/AA" 
                                maxlength="5"
                                style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" />
                        </div>
                        <div style="flex: 1;">
                            <label style="display: block; margin-bottom: 4px; font-size: 13px; font-weight: 500;">
                                CVV *
                            </label>
                            <input type="text" 
                                class="multipay-card-cvv" 
                                data-method="${method}"
                                placeholder="000" 
                                maxlength="4"
                                style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" />
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; margin-bottom: 4px; font-size: 13px; font-weight: 500;">
                            Nome no Cartão *
                        </label>
                        <input type="text" 
                            class="multipay-card-holder" 
                            data-method="${method}"
                            placeholder="Nome como está no cartão" 
                            style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; text-transform: uppercase;" />
                    </div>
                    
                    
                    
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; margin-bottom: 4px; font-size: 13px; font-weight: 500;">
                            CPF/CNPJ do Titular *
                        </label>
                        <input type="text" 
                            class="multipay-card-document" 
                            data-method="${method}"
                            placeholder="000.000.000-00 ou 00.000.000/0000-00"
                            maxlength="18"
                            style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" />
                    </div>
        ${showInstallments ? `
                        <div style="margin-bottom: 0;">
                            <label style="display: block; margin-bottom: 4px; font-size: 13px; font-weight: 500;">
                                Parcelas
                            </label>
                            <select class="multipay-installments" 
                                    data-method="${method}"
                                    style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                ${installmentsOptions}
                            </select>
                        </div>
                    ` : ''}
                </div>
            `;
        },
        
        /**
         * Bind formatação dos campos do cartão
         */
        bindMultipayCardFormatting: function(method) {
            const self = this;
            
            // Número do cartão
            $(document).off('input', `.multipay-card-number[data-method="${method}"]`)
                       .on('input', `.multipay-card-number[data-method="${method}"]`, function() {
                var val = $(this).val().replace(/\s/g, '');
                var formatted = val.match(/.{1,4}/g);
                $(this).val(formatted ? formatted.join(' ') : '');
            });
            
            // Validade
            $(document).off('input', `.multipay-card-expiry[data-method="${method}"]`)
                       .on('input', `.multipay-card-expiry[data-method="${method}"]`, function() {
                var val = $(this).val().replace(/\D/g, '');
                if (val.length >= 2) {
                    $(this).val(val.substring(0, 2) + '/' + val.substring(2, 4));
                } else {
                    $(this).val(val);
                }
            });
            
            // CVV
            $(document).off('input', `.multipay-card-cvv[data-method="${method}"]`)
                       .on('input', `.multipay-card-cvv[data-method="${method}"]`, function() {
                $(this).val($(this).val().replace(/\D/g, '').substring(0, 4));
            });
            
            // Nome (uppercase automático)
            $(document).off('input', `.multipay-card-holder[data-method="${method}"]`)
                       .on('input', `.multipay-card-holder[data-method="${method}"]`, function() {
                $(this).val($(this).val().toUpperCase());
            });
        },
        
        /**
         * CÁLCULO E DISTRIBUIÇÃO DE VALORES
         */
        
        /**
         * Atualizar total do pedido
         */
        updateMultipaymentTotal: function() {
            let total = 0;
            
            // Tentar pegar do WooCommerce
            if (typeof wc_checkout_params !== 'undefined') {
                const totalText = $('.order-total .woocommerce-Price-amount').text();
                total = parseFloat(totalText.replace(/[^\d,]/g, '').replace(',', '.')) || 0;
            }
            
            this.orderTotal = total;
            $('#multipay-total').text('R$ ' + total.toFixed(2).replace('.', ','));
            
            this.calculateMultipaymentDistribution();
        },
        
        /**
         * Calcular distribuição com auto-completar
         */
        calculateMultipaymentDistribution: function() {
            // pega os métodos que realmente estão marcados
            const selectedMethods = this.getSelectedMultipayMethods(); // ex: ["credit_card","boleto"]

            let distributed = 0;
            const methodValues = {};

            // soma só o que está digitado nos métodos marcados
            selectedMethods.forEach(method => {
                const $input = $(`.multipay-amount[data-method="${method}"]`);
                const rawVal = parseFloat($input.val()) || 0;
                methodValues[method] = rawVal;
                distributed += rawVal;
            });

            const remaining = this.orderTotal - distributed;

            // atualiza painel resumo
            $('#multipay-distributed').text(
                'R$ ' + distributed.toFixed(2).replace('.', ',')
            );

            $('#multipay-remaining')
                .text('R$ ' + Math.abs(remaining).toFixed(2).replace('.', ','))
                .css('color', (Math.abs(remaining) < 0.01) ? '#46b450' : '#d63638');

            // IMPORTANTE:
            // AQUI NÃO TEM mais auto-preenchimento do segundo método.
            // Isso agora acontece só no finalizeMultipaymentDistribution().

            this.syncMultipaymentDataFromDOM();
        },
                
        finalizeMultipaymentDistribution: function() {
            const selectedMethods = this.getSelectedMultipayMethods(); // ex: ["credit_card","boleto"]
            if (selectedMethods.length !== 2) {
                // só queremos autocompletar automático quando tiver exatamente 2 métodos ativos
                this.calculateMultipaymentDistribution();
                return;
            }

            // pega valores atuais digitados
            let distributed = 0;
            const methodValues = {};
            const methodInputs = {};

            selectedMethods.forEach(method => {
                const $input = $(`.multipay-amount[data-method="${method}"]`);
                const rawVal = parseFloat($input.val()) || 0;
                methodValues[method] = rawVal;
                methodInputs[method] = $input;
                distributed += rawVal;
            });

            // identifica qual método já tem valor (>0) e qual está vazio
            const filled = [];
            const empty = [];

            selectedMethods.forEach(method => {
                if (methodValues[method] > 0) {
                    filled.push(method);
                } else {
                    empty.push(method);
                }
            });

            // regra de auto completar:
            // - exatamente 1 método preenchido
            // - exatamente 1 método vazio
            // - ainda tem saldo a distribuir
            if (filled.length === 1 && empty.length === 1) {
                const onlyFilledMethod = filled[0];
                const onlyEmptyMethod = empty[0];

                const remaining = this.orderTotal - methodValues[onlyFilledMethod];

                if (remaining > 0.01) {
                    // preenche o campo vazio com o restante do pedido
                    methodInputs[onlyEmptyMethod].val(remaining.toFixed(2));

                    // highlight visual rápido
                    methodInputs[onlyEmptyMethod].css('background-color', '#e7f3ff');
                    setTimeout(() => {
                        methodInputs[onlyEmptyMethod].css('background-color', '');
                    }, 1000);
                }
            }

            // depois de ajustar, recalcula o resumo com os dois valores finais
            this.calculateMultipaymentDistribution();

            this.syncMultipaymentDataFromDOM();
        },

        /**
         * VALIDAÇÃO
         */
        
        /**
         * Validar multipagamento
         */
        validateMultipayment: function() {
            const selectedMethods = [];
            let totalDistributed = 0;
            
            $('.multipay-checkbox:checked').each(function() {
                const method = $(this).val();
                const amount = parseFloat($(`.multipay-amount[data-method="${method}"]`).val()) || 0;
                if (amount > 0) {
                    selectedMethods.push({method, amount});
                    totalDistributed += amount;
                }
            });
            
            // Verificar se selecionou métodos
            if (selectedMethods.length === 0) {
                this.showError('Selecione pelo menos um método de pagamento');
                return false;
            }
            
            // Verificar total
            const diff = Math.abs(totalDistributed - this.orderTotal);
            if (diff > 0.01) {
                this.showError(`A soma dos valores (R$ ${totalDistributed.toFixed(2)}) deve ser igual ao total do pedido (R$ ${this.orderTotal.toFixed(2)})`);
                return false;
            }
            
            // Validar dados dos cartões
            for (let i=0;i<selectedMethods.length;i++) {
                const {method} = selectedMethods[i];
                if (this.isCardMethod(method)) {
                    if (!this.validateMultipayCardData(method)) {
                        return false;
                    }
                }
            }
    
            const $form = $checkoutForms();
            $form.find('.pagarme-multipay-hidden').remove(); 

            // CORREÇÃO CRÍTICA: Adicionar campo sinalizador para o PHP
            $form.append(`<input type="hidden" name="pagarme_multipayment" value="yes" class="pagarme-multipay-hidden" />`);

                selectedMethods.forEach(({method, amount}) => {
                // 4.1. Hidden fields for PHP processing logic
                $form.append(`<input type="hidden" name="multi_method_${method}" value="yes" class="pagarme-multipay-hidden" />`);
                $form.append(`<input type="hidden" name="multi_amount_${method}" value="${amount.toFixed(2)}" class="pagarme-multipay-hidden" />`);

                // 4.2. Hidden fields for CARD DATA (read by get_card_data_from_post in PHP)
                if (this.isCardMethod(method)) {
                    // Sync data before reading
                    this.syncMultipaymentDataFromDOM();
                    const cardData = this.multipaymentData[method].card;
                    
                    // Card data uses the new multi_card_X prefix
                    $form.append(`<input type="hidden" name="multi_card_${method}_number_raw" value="${onlyDigits(cardData.number)}" class="pagarme-multipay-hidden" />`);
                    $form.append(`<input type="hidden" name="multi_card_${method}_holder" value="${cardData.holder}" class="pagarme-multipay-hidden" />`);
                    const norm = normalizeExpiry(cardData.expiry);
                    if (!norm) {
                    this.showError(`Data de validade inválida ou incompleta (${methodLabel || method}).`);
                    return false;
                    }
                    $form.append(`<input type="hidden" name="multi_card_${method}_exp_month" value="${norm.mm}" class="pagarme-multipay-hidden" />`);
                    $form.append(`<input type="hidden" name="multi_card_${method}_exp_year"  value="${norm.yyyy}" class="pagarme-multipay-hidden" />`);
                    $form.append(`<input type="hidden" name="multi_card_${method}_expiration" value="${norm.mmyy}" class="pagarme-multipay-hidden" />`);

                    $form.append(`<input type="hidden" name="multi_card_${method}_cvv" value="${cardData.cvv}" class="pagarme-multipay-hidden" />`);
                    $form.append(`<input type="hidden" name="multi_card_${method}_document" value="${onlyDigits(cardData.document)}" class="pagarme-multipay-hidden" />`);
                    
                    if (method === 'credit_card') {
                        $form.append(`<input type="hidden" name="multi_card_${method}_installments" value="${cardData.installments}" class="pagarme-multipay-hidden" />`);
                    }
                }
            });
            
            // Armazenar dados validados
            this.multipaymentData = selectedMethods;
            this.log('Multipagamento válido:', this.multipaymentData);
            
            return true;
        },
        
        /**
         * Validar dados do cartão no multipagamento
         */
        validateMultipayCardData: function(method) {
            const cardNumber = $(`.multipay-card-number[data-method="${method}"]`).val();
            const expiry = $(`.multipay-card-expiry[data-method="${method}"]`).val();
            const cvv = $(`.multipay-card-cvv[data-method="${method}"]`).val();
            const holder = $(`.multipay-card-holder[data-method="${method}"]`).val();
            
            const methodLabel = this.getMethodLabel(method);
            
            if (!cardNumber || cardNumber.replace(/\s/g, '').length < 13) {
                this.showError(`Número do cartão inválido (${methodLabel})`);
                $(`.multipay-card-number[data-method="${method}"]`).focus();
                return false;
            }
            
            if (!expiry || expiry.length !== 5) {
                this.showError(`Validade inválida (${methodLabel})`);
                $(`.multipay-card-expiry[data-method="${method}"]`).focus();
                return false;
            }
            
            if (!cvv || cvv.length < 3) {
                this.showError(`CVV inválido (${methodLabel})`);
                $(`.multipay-card-cvv[data-method="${method}"]`).focus();
                return false;
            }
            
            if (!holder || holder.trim().length < 3) {
                this.showError(`Nome do titular inválido (${methodLabel})`);
                $(`.multipay-card-holder[data-method="${method}"]`).focus();
                return false;
            }
            

            // Documento CPF/CNPJ (added)
            const documentVal = $(`.multipay-card-document[data-method="${method}"]`).val();
            const docIsValid2 = isValidDocumentCPFOrCNPJ(documentVal) || isAllowedTestDocument(documentVal);
            if (!docIsValid2) {
                this.showError(`CPF/CNPJ inválido (${methodLabel})`);
                $(`.multipay-card-document[data-method="${method}"]`).focus();
                return false;
            }

            return true;
        },
        
        /**
         * Validar campos de pagamento (geral)
         */
        validatePaymentFields: function() {
            this.log('🔍 Validando campos...');
            
            // Limpar erros
            $('.woocommerce-error').remove();
            
            // Se está em modo multipagamento
            if (this.multipaymentMode) {
                return this.validateMultipayment();
            }
            
            // Validação de pagamento único
            const selectedMethod = $('input[name="pagarme_payment_type"]:checked').val();
            
            if (!selectedMethod) {
                this.showError('Por favor, selecione um método de pagamento');
                return false;
            }
            
            // Validar cartão se necessário
            if (this.isCardMethod(selectedMethod)) {
                return this.validateSingleCardPayment();
            }
            
            return true;
        },
        
        /**
         * Validar pagamento único com cartão
         */
        validateSingleCardPayment: function() {
            const cardNumber = $('#pagarme-card-number').val().replace(/\s/g,'');
            if (!cardNumber || cardNumber.length < 13) {
                this.showError('Número do cartão inválido');
                $('#pagarme-card-number').focus();
                return false;
            }

            const holderName = $('#pagarme-card-holder-name').val();
            if (!holderName || holderName.trim().length < 3) {
                this.showError('Nome do titular inválido');
                $('#pagarme-card-holder-name').focus();
                return false;
            }

            const expiryRaw = $('#pagarme-card-expiry').val(); // "MM/AA"
            const norm = normalizeExpiry(expiryRaw);
            if (!norm) {
                this.showError('Data de validade inválida ou vencida (use MM/AA)');
                $('#pagarme-card-expiry').focus();
                return false;
            }

            const cvv = $('#pagarme-card-cvv').val();
            if (!cvv || cvv.length < 3) {
                this.showError('CVV inválido');
                $('#pagarme-card-cvv').focus();
                return false;
            }

            const doc = $('#pagarme-card-document').val();
            const docIsValid = isValidDocumentCPFOrCNPJ(doc) || isAllowedTestDocument(doc);
            if (!docIsValid) {
                this.showError('CPF/CNPJ inválido para o titular do cartão');
                $('#pagarme-card-document').focus();
                return false;
            }

            // garante que o hidden com número limpo está atualizado
            $('#pagarme-card-number-raw').val(cardNumber);
            return true;
        },

        /**
         * MÉTODOS AUXILIARES
         */
        
        /**
         * Labels dos métodos
         */
        getMethodLabel: function(method) {
            const labels = {
                'credit_card': '💳 Cartão de Crédito',
                'debit_card': '💳 Cartão de Débito',
                'pix': 'PIX',
                'boleto': '📄 Boleto Bancário',
                'voucher': '🎫 Voucher',
                'cash': '💵 Dinheiro',
                'safetypay': '🔒 SafetyPay'
            };
            return labels[method] || method;
        },
        
        /**
         * HTML de opção de pagamento único
         */
        getPaymentOptionHTML: function(type, label) {
            return `
                <label class="payment-method-option" style="display: block; padding: 12px; margin-bottom: 10px; border: 2px solid #ddd; border-radius: 8px; cursor: pointer; transition: all 0.3s;">
                    <input type="radio" name="pagarme_payment_type" value="${type}" style="margin-right: 10px; width: 18px; height: 18px; vertical-align: middle;" />
                    <span style="font-size: 16px; font-weight: 500;">${label}</span>
                </label>
            `;
        },
        
        /**
         * HTML dos campos do cartão (pagamento único)
         */
        getCardFieldsHTML: function() {
            const maxInstallments = (typeof pagarme_params !== 'undefined' && pagarme_params.installments) 
                ? pagarme_params.installments.max 
                : 12;
            
            let installmentsOptions = '';
            for (let i = 1; i <= maxInstallments; i++) {
                installmentsOptions += `<option value="${i}">${i}x sem juros</option>`;
            }
            
            return `
                <div id="card-fields" style="display:none; margin-top: 20px; padding: 20px; background: #f8f9fa; border-radius: 8px;">
                    <h4 style="margin: 0 0 15px 0; font-size: 16px; color: #333;">💳 Dados do Cartão</h4>
                    
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 600;">
                            Número do Cartão <span style="color: red;">*</span>
                        </label>
                        <input type="text" id="pagarme-card-number" placeholder="0000 0000 0000 0000" maxlength="19" 
                            style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px;" required />
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 600;">
                            Nome no Cartão <span style="color: red;">*</span>
                        </label>
                        <input type="text" id="pagarme-card-holder-name" placeholder="Nome como está no cartão" 
                            style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px; text-transform: uppercase;" required />
                    </div>
                    
                    
                    
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 600;">
                            CPF ou CNPJ do Titular <span style="color: red;">*</span>
                        </label>
                        <input type="text" id="pagarme-card-document" 
                            placeholder="Digite o CPF ou CNPJ"
                            maxlength="18"
                            style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px;" required />
                    </div>
            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                        <div style="flex: 1;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 600;">
                                Validade <span style="color: red;">*</span>
                            </label>
                            <input type="text" id="pagarme-card-expiry" placeholder="MM/AA" maxlength="5" 
                                style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px;" required />
                        </div>
                        <div style="flex: 1;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 600;">
                                CVV <span style="color: red;">*</span>
                            </label>
                            <input type="text" id="pagarme-card-cvv" placeholder="123" maxlength="4" 
                                style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px;" required />
                        </div>
                    </div>
                    
                    <div id="installments-field" style="margin-bottom: 0;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 600;">
                            Parcelas <span style="color: red;">*</span>
                        </label>
                        <select id="pagarme-installments" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px;">
                            ${installmentsOptions}
                        </select>
                    </div>
                </div>
            `;
        },
        
        /**
         * HTML info PIX
         */
        getPixInfoHTML: function() {
            return `
                <div id="pix-info" style="display:none; padding: 15px; background: #e7f3ff; border-radius: 8px; margin-top: 15px;">
                    <p style="margin: 5px 0;"><strong>Pagamento instantneo via PIX</strong></p>
                    <p style="margin: 5px 0;">📱 O QR Code será gerado após a confirmação do pedido</p>
                </div>
            `;
        },
        
        /**
         * HTML info Boleto
         */
        getBoletoInfoHTML: function() {
            return `
                <div id="boleto-info" style="display:none; padding: 15px; background: #fff3cd; border-radius: 8px; margin-top: 15px;">
                    <p style="margin: 5px 0;"><strong>📄 O boleto será gerado após a confirmação do pedido</strong></p>
                    <p style="margin: 5px 0;">⏰ Prazo de pagamento: 3 dias úteis</p>
                </div>
            `;
        },
        
        /**
         * Selecionar primeiro método
         */
        selectFirstPaymentMethod: function() {
            const $firstRadio = $('input[name="pagarme_payment_type"]:first');
            if ($firstRadio.length > 0) {
                // MARCAR VISUALMENTE O RADIO BUTTON
                $firstRadio.prop('checked', true);
                
                // CORREÇÃO CRÍTICA: Chamar o manipulador de visualização/estado diretamente
                // (para mostrar os campos do cartão) SEM disparar o evento 'change' do jQuery.
                // O 'change' está hookado em outros scripts para VALIDAÇÃO/INJEÇÃO, que deve ser adiada.
                this.handlePaymentMethodChange($firstRadio.val());
                
                this.log('Método selecionado por padrão:', $firstRadio.val());
            }
        },
        
        /**
         * Trocar método de pagamento (único)
         */
        handlePaymentMethodChange: function(method) {
            this.selectedPaymentType = method;
            this.log('Método selecionado:', method);
            
            // Atualizar estilos
            $('.payment-method-option').css({
                'border-color': '#ddd',
                'background-color': 'transparent'
            });
            
            $('input[name="pagarme_payment_type"]:checked')
                .closest('.payment-method-option')
                .css({
                    'border-color': '#0073aa',
                    'background-color': '#f0f7ff'
                });
            
            // Mostrar/esconder campos
            $('#card-fields, #pix-info, #boleto-info').hide();

            // CORREÇÃO: Limpar campos do cartão ao trocar de método
            // Isso previne que dados de cartão "fantasmas" sejam enviados
            // quando o usuário troca de "Cartão" para "Boleto" ou "PIX".
            if (!this.isCardMethod(method)) {
                $('#pagarme-card-number').val('');
                $('#pagarme-card-holder-name').val('');
                $('#pagarme-card-expiry').val('');
                $('#pagarme-card-cvv').val('');
                $('#pagarme-card-document').val('');
                $('#pagarme-installments').val('1');
                this.log(' Campos do cartão limpos.');
            }
            
            if (this.isCardMethod(method)) {
                $('#card-fields').show();
                
                if (method === 'debit_card') {
                    $('#installments-field').hide();
                } else {
                    $('#installments-field').show();
                }
            } else if (method === 'pix') {
                $('#pix-info').show();
            } else if (method === 'boleto') {
                $('#boleto-info').show();
            }
        },
        
        /**
         * Mostrar erro
         */
        showError: function(message) {
            $('.woocommerce-error').remove();
            
            const errorHtml = `
                <div class="woocommerce-error" role="alert" style="padding: 15px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 20px; color: #721c24;">
                    ${message}
                </div>
            `;
            
            $('.payment_box.payment_method_pagarme_gateway').prepend(errorHtml);
            
            $('html, body').animate({
                scrollTop: $('.woocommerce-error').offset().top - 100
            }, 500);
        },
        
        /**
         * Log de debug
         */
        log: function(message, data) {
            if (!this.debugMode) return;
            if (data) {
                console.log('Pagar.me:', message, data);
            } else {
                console.log('Pagar.me:', message);
            }
        },
        
        /**
         * EVENTOS
         */
        bindEvents: function() {
            const self = this;
            
            // Seleção de método de pagamento único
            $(document).on('change', 'input[name="pagarme_payment_type"]', function() {
                self.handlePaymentMethodChange($(this).val());
            });
            
            // Toggle multipagamento
            $(document).on('change', '#enable-multipayment', function() {
                self.toggleMultipaymentMode($(this).is(':checked'));
            });
            
            // Checkbox de método multipagamento
            $(document).on('change', '.multipay-checkbox', function() {
                const method = $(this).val();
                const isChecked = $(this).is(':checked');
                self.handleMultipayMethodToggle(method, isChecked);
            });
            
            // Enquanto digita → só atualiza números (sem auto-preencher outro método)
            $(document).on('input', '.multipay-amount', function() {
                self.calculateMultipaymentDistribution();
            });

            // Quando termina de digitar (sai do campo) → faz o auto-preenchimento do restante
            $(document).on('blur', '.multipay-amount', function() {
                self.finalizeMultipaymentDistribution();
            });

            
            // Formatação do cartão (pagamento único)
            $(document).on('input', '#pagarme-card-number', function() {
                const val = $(this).val().replace(/\s/g, '');
                const formatted = val.match(/.{1,4}/g);
                $(this).val(formatted ? formatted.join(' ') : '');
            });
            
            $(document).on('input', '#pagarme-card-expiry', function() {
                const val = $(this).val().replace(/\D/g, '');
                if (val.length >= 2) {
                    $(this).val(val.substring(0, 2) + '/' + val.substring(2, 4));
                } else {
                    $(this).val(val);
                }
            });
            
            $(document).on('input', '#pagarme-card-cvv', function() {
                $(this).val($(this).val().replace(/\D/g, ''));
            });
            
            $(document).on('input', '#pagarme-card-holder-name', function() {
                $(this).val($(this).val().toUpperCase());
            });

            // Mask CPF/CNPJ (added)
            function formatCpfCnpj(raw) {
                const d = onlyDigits(raw).slice(0,14);
                if (d.length <= 11) { // CPF
                    let out = d;
                    if (d.length > 9) {
                        out = d.replace(/(\d{3})(\d{3})(\d{3})(\d{0,2})/, function(_,a,b,c,e){ 
                            return a + '.' + b + '.' + c + (e ? '-' + e : '');
                        });
                    } else if (d.length > 6) {
                        out = d.replace(/(\d{3})(\d{3})(\d{0,3})/, function(_,a,b,c){ 
                            return a + '.' + b + (c ? '.' + c : '');
                        });
                    } else if (d.length > 3) {
                        out = d.replace(/(\d{3})(\d{0,3})/, function(_,a,b){ 
                            return a + (b ? '.' + b : '');
                        });
                    }
                    return out;
                } else { // CNPJ
                    let out = d;
                    if (d.length > 12) {
                        out = d.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{0,2})/, function(_,a,b,c,d4,e){
                            return a + '.' + b + '.' + c + '/' + d4 + (e ? '-' + e : '');
                        });
                    } else if (d.length > 8) {
                        out = d.replace(/(\d{2})(\d{3})(\d{3})(\d{0,4})/, function(_,a,b,c,d4){
                            return a + '.' + b + '.' + c + (d4 ? '/' + d4 : '');
                        });
                    } else if (d.length > 5) {
                        out = d.replace(/(\d{2})(\d{3})(\d{0,3})/, function(_,a,b,c){
                            return a + '.' + b + (c ? '.' + c : '');
                        });
                    } else if (d.length > 2) {
                        out = d.replace(/(\d{2})(\d{0,3})/, function(_,a,b){
                            return a + (b ? '.' + b : '');
                        });
                    }
                    return out;
                }
            }

            $(document).off('input', '#pagarme-card-document')
                       .on('input', '#pagarme-card-document', function(){
                $(this).val(formatCpfCnpj($(this).val()));
            });

            $(document).off('input', '.multipay-card-document')
                       .on('input', '.multipay-card-document', function(){
                $(this).val(formatCpfCnpj($(this).val()));
            });
        },

        /**
        * Injetar campos hidden para pagamento único com cartão
        */
        injectSinglePaymentHiddenFields: function() {
            const self = this;
            const selectedMethod = self.selectedPaymentType || $('input[name="pagarme_payment_type"]:checked').val() || 'credit_card';
            
            // Log para debug
            self.log('💉 Injetando campos hidden para pagamento único');
            self.log('Método selecionado:', selectedMethod);
            
            // Limpar campos anteriores
            $('form.checkout').find('.pagarme-single-payment-hidden').remove();
            
            // Se não for método de cartão, não precisa injetar campos
            if (!self.isCardMethod(selectedMethod)) {
                self.log('Método não requer campos de cartão');
                return true; // Retornar true para não travar o processo
            }
            
            // Coletar dados dos campos
            const cardNumber = $('#pagarme-card-number').val() || '';
            const cardNumberClean = (cardNumber || '').replace(/\D/g,'');
            const holderName = $('#pagarme-card-holder-name').val() || '';
            const expiryRaw = $('#pagarme-card-expiry').val() || '';
            const cvv = $('#pagarme-card-cvv').val() || '';
            const cardDocument = $('#pagarme-card-document').val() || '';
            const cardDocumentClean = (cardDocument || '').replace(/\D/g,'');
            const installments = $('#pagarme-installments').val() || '1';
            
            // CORREÇÃO DE DEBUG: LOGAR INPUTS ANTES DA VALIDAÇÃO
            self.log('📋 DADOS VISÍVEIS ANTES DA INJEÇÃO:', {
                cardNumber: cardNumberClean ? cardNumberClean.substring(0, 6) + '***' + cardNumberClean.slice(-4) : 'VAZIO',
                holderName: holderName || 'VAZIO',
                expiryRaw: expiryRaw || 'VAZIO',
                cvv: cvv ? '***' : 'VAZIO',
                document: cardDocumentClean || 'VAZIO'
            });

            
            // Função auxiliar para normalizar validade
            function normalizeExpiry(rawExpiry) {
                if (!rawExpiry) return null;
                // ... (restante da função normalizeExpiry)
            }
            
            // Validar e normalizar validade
            const norm = normalizeExpiry(expiryRaw);
            
            // Validações básicas (são redundantes com validateCardFieldsBeforeSubmit, mas garantem erro no log)
            if (!norm) {
                self.log('❌ Data de validade inválida:', expiryRaw);
                // Não mostrar erro ao usuário aqui, apenas forçar o log
                // return false; // NÃO DESCOMENTAR: Deixamos o validateCardFieldsBeforeSubmit fazer o retorno falso
            }
            
            if (cardNumberClean.length < 13 || !holderName || !cvv || !norm) {
                self.log('❌ CAMPOS CRÍTICOS INCOMPLETOS. INJEÇÃO PARCIAL/ABORTADA NO JS.');
                // Permitir que a injeção falhe/retorne false para que o PHP também falhe, mas com o log detalhado acima.
                // Se a função não retornar 'true', o processo de checkout falhará, mas o log de DEBUG
                // irá mostrar o que o JS estava lendo no momento da falha.
                // return false; // NÃO DEVE RETORNAR FALSE AQUI, pois o log no PHP já fará isso.
            }
            
            const $form = $checkoutForms();
            
            // CORREÇÃO PRINCIPAL: Enviar campos em MÚLTIPLOS FORMATOS
            
            // 1. NÚMERO DO CARTÃO - enviar em ambos os formatos
            if (cardNumberClean) {
                $form.append(`<input type="hidden" name="pagarme_card_number_raw" value="${cardNumberClean}" class="pagarme-single-payment-hidden" />`);
            }
            if (cardNumber) {
                $form.append(`<input type="hidden" name="pagarme_card_number" value="${cardNumber}" class="pagarme-single-payment-hidden" />`);
            }
            
            // 2. NOME DO TITULAR - CRÍTICO: enviar em AMBOS os formatos
            if (holderName) {
                $form.append(`<input type="hidden" name="pagarme_card_holder" value="${holderName}" class="pagarme-single-payment-hidden" />`);
                $form.append(`<input type="hidden" name="pagarme_card_holder_name" value="${holderName}" class="pagarme-single-payment-hidden" />`);
            }
            
            // 3. VALIDADE - enviar em TODOS os formatos possíveis
            if (norm) {
                $form.append(`<input type="hidden" name="pagarme_card_exp_month" value="${norm.mm}" class="pagarme-single-payment-hidden" />`);
                $form.append(`<input type="hidden" name="pagarme_card_exp_year" value="${norm.yyyy}" class="pagarme-single-payment-hidden" />`);
                $form.append(`<input type="hidden" name="pagarme_card_expiration" value="${norm.mmyy}" class="pagarme-single-payment-hidden" />`);
                $form.append(`<input type="hidden" name="pagarme_card_expiry" value="${expiryRaw}" class="pagarme-single-payment-hidden" />`);
            }
            
            // 4. CVV
            if (cvv) {
                $form.append(`<input type="hidden" name="pagarme_card_cvv" value="${cvv}" class="pagarme-single-payment-hidden" />`);
            }
            
            // 5. DOCUMENTO (CPF/CNPJ)
            if (cardDocumentClean) {
                $form.append(`<input type="hidden" name="pagarme_card_document" value="${cardDocumentClean}" class="pagarme-single-payment-hidden" />`);
                $form.append(`<input type="hidden" name="pagarme_holder_document" value="${cardDocumentClean}" class="pagarme-single-payment-hidden" />`);
            }
            
            // 6. PARCELAS (apenas para crédito)
            if (selectedMethod === 'credit_card') {
                $form.append(`<input type="hidden" name="pagarme_installments" value="${installments}" class="pagarme-single-payment-hidden" />`);
            }
            
            // Log de sucesso com resumo dos dados
            self.log('Campos hidden injetados com sucesso!');
            
            // Contar quantos campos críticos foram injetados (para fins de debug)
            const injected_count_check = $form.find('input[name="pagarme_card_number_raw"]').length;
            return injected_count_check > 0;
        },

        /**
        * FUNÇÃO ADICIONAL: Validar campos antes de enviar
        * Adicionar esta função para melhor validação
        */
        validateCardFieldsBeforeSubmit: function() {
            const self = this;
            
            self.log('🔍 Validando campos do cartão antes do envio...');
            
            // Verificar se os campos existem no DOM
            const fields = {
                number: $('#pagarme-card-number'),
                holder: $('#pagarme-card-holder-name'),
                expiry: $('#pagarme-card-expiry'),
                cvv: $('#pagarme-card-cvv')
            };
            
            // Verificar existência dos campos
            for (const [name, $field] of Object.entries(fields)) {
                if (!$field.length) {
                    self.log(`❌ Campo ${name} não encontrado no DOM`);
                    return false;
                }
                
                if (!$field.val() || $field.val().trim() === '') {
                    self.log(`❌ Campo ${name} está vazio`);
                    $field.focus();
                    self.showError(`Por favor, preencha o campo: ${name}`);
                    return false;
                }
            }
            
            self.log('Todos os campos validados com sucesso');
            return true;
        },

        /**
        * Inicializar validação
        */
        initValidation: function() {
            const self = this;
            
            // Hook do WooCommerce para processar pagamento
            $(document).on('checkout_place_order_pagarme_gateway', function() {
                self.log('🎯 Evento checkout_place_order_pagarme_gateway disparado');
                
                const sub = self.selectedPaymentType || $('input[name="pagarme_payment_type"]:checked').val() || 'credit_card';
                
                // Atualizar campos hidden do método
                $('#pagarme-selected-method').val(sub);
                $('#pagarme-payment-type-hidden').val(sub);
                
                // Limpar campos antigos
                $('form.checkout').find('.pagarme-multipay-hidden').remove();
                $('form.checkout').find('.pagarme-single-payment-hidden').remove();
                
                // Se não for multipagamento, injetar campos de pagamento único
                if (!self.multipaymentMode) {
                    // Validar campos primeiro
                    if (self.isCardMethod(sub)) {
                        if (!self.validateCardFieldsBeforeSubmit()) {
                            return false;
                        }
                    }
                    
                    // Injetar campos
                    const result = self.injectSinglePaymentHiddenFields();
                    if (!result) {
                        self.log('❌ Falha ao injetar campos hidden');
                        return false;
                    }
                }
                
                // Permitir continuação
                self.log('Validação concluída, prosseguindo com checkout');
                return true;
            });
        },
        
        /**
         * MÉTODO DE VALIDAÇÃO PARA O MULTISTEP
         */
        canGoForward: function() {
            const wcMethod = $('input[name="payment_method"]:checked').val() || '';
            if (wcMethod !== 'pagarme_gateway') {
                return { ok: true };
            }
            if (this.multipaymentMode) {
                const valid = this.validateMultipayment();
                return { ok: valid, error: valid ? null : 'Verifique os dados de pagamento múltiplo' };
            }
            
            // Obter tipo de pagamento corretamente
            const paymentType = $('input[name="pagarme_payment_type"]:checked').val() || $('#pagarme-payment-type').val() || $('.pagarme-payment-button.active').attr('data-type') || 'credit_card';
            
            if (this.isCardMethod(paymentType)) {
                const validationResult = this.validateSinglePaymentForward();
                return validationResult;
            }
            
            return { ok: true };
        },

        /**
        * Validar pagamento único
        */
        validateSinglePaymentForward: function() {
            this.log('💳 Validando pagamento único');
            
            // Obter tipo de pagamento selecionado
            const paymentType = $('input[name="pagarme_payment_type"]:checked').val();
            
            if (!paymentType) {
                return {
                    ok: false,
                    error: 'Selecione um método de pagamento'
                };
            }
            
            this.log('📌 Tipo selecionado:', paymentType);
            
            // Validações específicas por tipo
            switch (paymentType) {
                case 'credit_card':
                    return this.validateCreditCardFields();
                    
                case 'debit_card':
                    return this.validateDebitCardFields();
                    
                case 'pix':
                case 'boleto':
                    // PIX e Boleto não precisam validação adicional
                    return { ok: true };
                    
                default:
                    return { ok: true };
            }
        },

        /**
        * Validar multipagamento
        */
        validateMultipayment: function() {
            const selectedMethods = [];
            let totalDistributed = 0;
            
            // Verificar se há métodos adicionados
            if (Object.keys(this.multipaymentData).length === 0) {
                return {
                    ok: false,
                    error: 'Adicione pelo menos um método de pagamento'
                };
            }
            
            // Calcular total dos métodos
            let totalAdded = 0;
            for (const methodId in this.multipaymentData) {
                const method = this.multipaymentData[methodId];
                totalAdded += parseFloat(method.value) || 0;
            }
            
            const orderTotal = this.orderTotal;
            const difference = Math.abs(totalAdded - orderTotal);
            
            this.log('💵 Total do pedido:', orderTotal);
            this.log('💵 Total dos métodos:', totalAdded);
            this.log('💵 Diferença:', difference);
            
            // Validar total (permitir diferença de 0.01 por arredondamento)
            if (difference > 0.01) {
                if (totalAdded < orderTotal) {
                    return {
                        ok: false,
                        error: `Faltam R$ ${(orderTotal - totalAdded).toFixed(2)} para completar o valor total`
                    };
                } else {
                    return {
                        ok: false,
                        error: `O total dos métodos excede em R$ ${(totalAdded - orderTotal).toFixed(2)} o valor do pedido`
                    };
                }
            }
            
            // Validar dados do cartão para cada método que seja cartão
            for (const methodId in this.multipaymentData) {
                const method = this.multipaymentData[methodId];
                
                if (method.type === 'credit_card' || method.type === 'debit_card') {
                    const validation = this.validateCardFieldsForMethod(methodId);
                    if (!validation.ok) {
                        return validation;
                    }
                }
            }
            
            return { ok: true };
        },

        /**
        * Validar campos do cartão de crédito
        */
        validateCreditCardFields: function() {
            const errors = [];
            
            // Número do cartão
            const cardNumber = $('#pagarme-card-number').val().replace(/\s/g, '');
            if (!cardNumber || cardNumber.length < 13) {
                errors.push('Número do cartão inválido');
            }
            
            // Nome no cartão
            const cardHolder = $('#pagarme-card-holder-name').val().trim();
            if (!cardHolder || cardHolder.length < 3) {
                errors.push('Nome no cartão inválido');
            }
            
            // Validade
            const cardExpiry = $('#pagarme-card-expiry').val();
            if (!cardExpiry || !/^\d{2}\/\d{2}$/.test(cardExpiry)) {
                errors.push('Validade do cartão inválida (MM/AA)');
            } else {
                // Validar se não está vencido
                const [month, year] = cardExpiry.split('/');
                const expDate = new Date(2000 + parseInt(year), parseInt(month) - 1);
                const now = new Date();
                if (expDate < now) {
                    errors.push('Cartão vencido');
                }
            }
            
            // CVV
            const cardCvv = $('#pagarme-card-cvv').val();
            if (!cardCvv || cardCvv.length < 3) {
                errors.push('CVV inválido');
            }
            
            // Parcelas (se houver)
            const installments = $('#pagarme-installments').val();
            if ($('#pagarme-installments').is(':visible') && !installments) {
                errors.push('Selecione o número de parcelas');
            }
            
            if (errors.length > 0) {
                return {
                    ok: false,
                    error: errors.join(', ')
                };
            }
            
            return { ok: true };
        },

        /**
        * Validar campos do cartão de débito
        */
        validateDebitCardFields: function() {
            // Mesma validação do crédito, exceto parcelas
            const errors = [];
            
            const cardNumber = $('#pagarme-card-number').val().replace(/\s/g, '');
            if (!cardNumber || cardNumber.length < 13) {
                errors.push('Número do cartão inválido');
            }
            
            const cardHolder = $('#pagarme-card-holder-name').val().trim();
            if (!cardHolder || cardHolder.length < 3) {
                errors.push('Nome no cartão inválido');
            }
            
            const cardExpiry = $('#pagarme-card-expiry').val();
            if (!cardExpiry || !/^\d{2}\/\d{2}$/.test(cardExpiry)) {
                errors.push('Validade do cartão inválida');
            }
            
            const cardCvv = $('#pagarme-card-cvv').val();
            if (!cardCvv || cardCvv.length < 3) {
                errors.push('CVV inválido');
            }
            
            if (errors.length > 0) {
                return {
                    ok: false,
                    error: errors.join(', ')
                };
            }
            
            return { ok: true };
        },

        /**
        * Validar campos do cartão para um método específico no multipagamento
        */
        validateCardFieldsForMethod: function(methodId) {
            const selectorId = `#multipay-${methodId}`;
            const errors = [];

            // 1) tenta pelo padrão antigo (IDs)
            let $numberEl   = $(`${selectorId}-card-number`);
            let $holderEl   = $(`${selectorId}-card-holder`);
            let $expiryEl   = $(`${selectorId}-card-expiry`);
            let $cvvEl      = $(`${selectorId}-card-cvv`);

            // 2) se não existir nesse padrão, tenta pelo padrão do seu HTML (data-method)
            if (!$numberEl.length && !$holderEl.length && !$expiryEl.length && !$cvvEl.length) {
                $numberEl = $(`.multipay-card-number[data-method="${methodId}"]`);
                $holderEl = $(`.multipay-card-holder[data-method="${methodId}"]`);
                $expiryEl = $(`.multipay-card-expiry[data-method="${methodId}"]`);
                $cvvEl    = $(`.multipay-card-cvv[data-method="${methodId}"]`);
            }

            // 3) Se ainda assim não tem campos de cartão, não vamos travar o step.
            // Isso acontece quando o método está marcado mas o formulário daquele
            // método é renderizado em outro lugar / salvo / tokenizado.
            if (
                (!$numberEl || !$numberEl.length) &&
                (!$holderEl || !$holderEl.length) &&
                (!$expiryEl || !$expiryEl.length) &&
                (!$cvvEl || !$cvvEl.length)
            ) {
                this.log('⚠️ Campos de cartão não encontrados para', methodId, '— ignorando validação de cartão.');
                return { ok: true };
            }

            // ----- a partir daqui é a mesma validação de antes, só que defensiva -----

            const rawNumber = ($numberEl.val() || '').toString().replace(/\s/g, '');
            if (!rawNumber || rawNumber.length < 13) {
                errors.push(`Número do cartão inválido para ${methodId}`);
            }

            const cardHolder = ($holderEl.val() || '').toString().trim();
            if (!cardHolder || cardHolder.length < 3) {
                errors.push(`Nome no cartão inválido para ${methodId}`);
            }

            const cardExpiry = ($expiryEl.val() || '').toString();
            if (!cardExpiry || !/^\d{2}\/\d{2}$/.test(cardExpiry)) {
                errors.push(`Validade do cartão inválida (MM/AA) para ${methodId}`);
            } else {
                const [month, year] = cardExpiry.split('/');
                const expDate = new Date(2000 + parseInt(year, 10), parseInt(month, 10) - 1, 1);
                const now = new Date();
                // expira no fim do mês, então vamos só comparar ano/mês
                if (
                    expDate.getFullYear() < now.getFullYear() ||
                    (expDate.getFullYear() === now.getFullYear() && expDate.getMonth() < now.getMonth())
                ) {
                    errors.push(`Cartão vencido para ${methodId}`);
                }
            }

            const cardCvv = ($cvvEl.val() || '').toString().trim();
            if (!cardCvv || cardCvv.length < 3) {
                errors.push(`CVV inválido para ${methodId}`);
            }

            if (errors.length > 0) {
                return {
                    ok: false,
                    error: errors.join(', ')
                };
            }

            return { ok: true };
        },

    };
    
    // Expor globalmente
    window.PagarMeGatewayCheckout = PagarMeGatewayCheckout;
    
    /**
     * INICIALIZAÇÃO
     */
    $(document).ready(function() {
        console.log('DOM Ready');
        setTimeout(function() {
            PagarMeGatewayCheckout.init();
        }, 500);
    });
    
    // Re-renderizar após WooCommerce atualizar
    let updatedCheckoutCount = 0;
    
    $(document.body).on('updated_checkout', function() {
        updatedCheckoutCount++;
        console.log('WooCommerce updated_checkout #' + updatedCheckoutCount);
        
        setTimeout(function() {
            const $radios = $('input[name="pagarme_payment_type"]');
            
            if ($radios.length === 0) {
                console.log('Campos limpos - re-renderizando...');
                $('.payment_box.payment_method_pagarme_gateway').empty();
                PagarMeGatewayCheckout.forceRenderFields();
            } else {
                console.log('Campos existem (' + $radios.length + ')');
            }
        }, 500);
    });
    
})(jQuery);

(function($) {
    'use strict';
    
    console.log('Iniciando correção urgente de injeção de campos Pagar.me');
    
    // Função para injetar campos (simplificada e robusta)
    function injectPagarmeFields() {
        console.log('Tentando injetar campos Pagar.me...');
        
        // CORREÇÃO CRÍTICA: Se modo multipagamento ativo, ignorar injeção de pagamento único
        const isMultipaymentActive = $('#enable-multipayment').is(':checked');
        if (isMultipaymentActive) {
             console.log('Modo multipagamento ativo, ignorando injeção de pagamento único.');
             // Retornar true para evitar que o processo falhe na validação dos campos visíveis (que estão escondidos).
             return true; 
        }

        // Verificar se é pagamento Pagar.me
        const isPaymentPagarme = $('input[name="payment_method"]:checked').val() === 'pagarme_gateway';
        if (!isPaymentPagarme) {
            console.log('Não é pagamento Pagar.me, ignorando');
            return;
        }
        
        // Obter método de pagamento selecionado
        const paymentType = $('input[name="pagarme_payment_type"]:checked').val() || 
                           $('#pagarme-selected-method').val() || 
                           'credit_card';
        
        console.log('Método de pagamento:', paymentType);
        
        // Se não for cartão, não precisa injetar campos
        if (!['credit_card', 'debit_card', 'voucher'].includes(paymentType)) {
            console.log('Método não requer campos de cartão');
            return;
        }
        
        // Limpar campos anteriores
        $('.pagarme-injected-field').remove();
        
        // Coletar dados dos campos visíveis
        const fields = {
            number: $('#pagarme-card-number').val() || '',
            holder: $('#pagarme-card-holder-name').val() || '',
            expiry: $('#pagarme-card-expiry').val() || '',
            cvv: $('#pagarme-card-cvv').val() || '',
            document: $('#pagarme-card-document').val() || '',
            installments: $('#pagarme-installments').val() || '1'
        };
        
        console.log('Dados coletados:', {
            number: fields.number ? fields.number.substring(0,6) + '****' : 'vazio',
            holder: fields.holder || 'vazio',
            expiry: fields.expiry || 'vazio',
            cvv: fields.cvv ? '***' : 'vazio',
            document: fields.document ? '***' : 'vazio'
        });
        
        // Validar campos mínimos
        if (!fields.number || !fields.holder || !fields.expiry || !fields.cvv) {
            console.error('Campos obrigatórios não preenchidos');
            return;
        }
        
        // Limpar e formatar dados
        const cardNumberClean = fields.number.replace(/\D/g, '');
        const documentClean = fields.document.replace(/\D/g, '');
        
        // Processar validade
        const expiryParts = fields.expiry.replace(/\D/g, '');
        let expMonth = '', expYear = '';
        
        if (expiryParts.length >= 4) {
            expMonth = expiryParts.substring(0, 2);
            expYear = expiryParts.substring(2, 4);
            
            // Converter para ano completo se necessário
            if (expYear.length === 2) {
                expYear = '20' + expYear;
            }
        }
        
        const $form = $checkoutForms();
        
        // Injetar campos em TODOS os formatos possíveis
        const hiddenFields = [
            // Número do cartão
            { name: 'pagarme_card_number', value: fields.number },
            { name: 'pagarme_card_number_raw', value: cardNumberClean },
            
            // Nome do titular - MÚLTIPLOS FORMATOS
            { name: 'pagarme_card_holder', value: fields.holder },
            { name: 'pagarme_card_holder_name', value: fields.holder },
            
            // Validade - TODOS OS FORMATOS
            { name: 'pagarme_card_expiry', value: fields.expiry },
            { name: 'pagarme_card_exp_month', value: expMonth },
            { name: 'pagarme_card_exp_year', value: expYear },
            { name: 'pagarme_card_expiration', value: expMonth + expYear.substring(2) },
            
            // CVV
            { name: 'pagarme_card_cvv', value: fields.cvv },
            
            // Documento
            { name: 'pagarme_card_document', value: documentClean },
            { name: 'pagarme_holder_document', value: documentClean },
            
            // Parcelas
            { name: 'pagarme_installments', value: fields.installments }
        ];
        
        // Adicionar cada campo
        hiddenFields.forEach(field => {
            if (field.value) {
                $form.append(`<input type="hidden" name="${field.name}" value="${field.value}" class="pagarme-injected-field" />`);
            }
        });
        
        console.log('Campos injetados com sucesso:', hiddenFields.length);
        
        // Verificar se foram realmente adicionados
        const addedFields = $('.pagarme-injected-field').length;
        console.log('Campos verificados no DOM:', addedFields);
        
        return true;
    }
    
    // MÚLTIPLOS PONTOS DE CAPTURA
    
    // 1. Interceptar submit do formulário
    $(document).on('submit', 'form.checkout', function(e) {
        const isPaymentPagarme = $('input[name="payment_method"]:checked').val() === 'pagarme_gateway';
        
        if (isPaymentPagarme) {
            console.log('Submit interceptado - injetando campos Pagar.me');
            
            // Tentar injetar campos
            const injected = injectPagarmeFields();
            
            if (!injected) {
                console.log('Campos não foram injetados, mas permitindo continuação');
            }
        }
    });
    
    // 2. Hook do WooCommerce checkout_place_order
    $(document).on('checkout_place_order_pagarme_gateway', function() {
        console.log('checkout_place_order_pagarme_gateway - injetando campos');
        injectPagarmeFields();
        return true;
    });
    
    // 3. Antes do AJAX do checkout
    $(document).ajaxSend(function(event, jqxhr, settings) {
        if (settings.url && settings.url.includes('wc-ajax=checkout')) {
            const isPaymentPagarme = $('input[name="payment_method"]:checked').val() === 'pagarme_gateway';
            
            if (isPaymentPagarme) {
                console.log('AJAX checkout detectado - injetando campos');
                injectPagarmeFields();
            }
        }
    });
    
    // 4. Ao clicar no botão de finalizar (específico para Multistep)
    $(document).on('click', '#place_order, .msc-place-order, button[name="woocommerce_checkout_place_order"]', function() {
        const isPaymentPagarme = $('input[name="payment_method"]:checked').val() === 'pagarme_gateway';
        
        if (isPaymentPagarme) {
            console.log('Botão finalizar clicado - injetando campos');
            injectPagarmeFields();
        }
    });
    
    // 5. Ao navegar para o Step 4 (específico para Multistep)
    $(document).on('msc:step:changed', function(e, data) {
        if (data && data.newStep === 4) {
            console.log('Navegou para Step 4 - preparando campos');
            
            setTimeout(function() {
                const isPaymentPagarme = $('input[name="payment_method"]:checked').val() === 'pagarme_gateway';
                if (isPaymentPagarme) {
                    injectPagarmeFields();
                }
            }, 100);
        }
    });
    
    // FUNÇÕES DE TESTE E DEBUG
    
    // Tornar função de teste global
    window.testPagarmeFields = function() {
        console.log('TESTE DE CAMPOS PAGARME');
        console.log('========================');
        
        // Campos visíveis
        const visible = {
            'Número': $('#pagarme-card-number').val() || 'vazio',
            'Titular': $('#pagarme-card-holder-name').val() || 'vazio',
            'Validade': $('#pagarme-card-expiry').val() || 'vazio',
            'CVV': $('#pagarme-card-cvv').val() || 'vazio',
            'Documento': $('#pagarme-card-document').val() || 'vazio',
            'Parcelas': $('#pagarme-installments').val() || 'vazio'
        };
        console.log('Campos Visíveis:', visible);
        
        // Campos hidden
        const hidden = {};
        $('input[type="hidden"]').each(function() {
            const name = $(this).attr('name');
            if (name && name.includes('pagarme')) {
                hidden[name] = $(this).val();
            }
        });
        console.log('Campos Hidden:', hidden);
        
        // Método selecionado
        const payment = $('input[name="payment_method"]:checked').val();
        const type = $('input[name="pagarme_payment_type"]:checked').val();
        console.log('Gateway:', payment);
        console.log('Tipo:', type);
        
        // Tentar injetar
        console.log('Tentando injetar campos...');
        injectPagarmeFields();
        
        // Verificar após injeção
        const afterCount = $('.pagarme-injected-field').length;
        console.log('Campos injetados:', afterCount);
        
        console.log('========================');
    };
    
    // Função para forçar injeção manual
    window.forcePagarmeInject = function() {
        console.log('Forçando injeção manual...');
        return injectPagarmeFields();
    };
    
    // Log de inicialização
    console.log('Correção de injeção Pagar.me carregada');
    console.log('Use testPagarmeFields() para testar');
    console.log('Use forcePagarmeInject() para forçar injeção');

// === HARDENING v2: guarantee multipayment hidden fields on ALL forms ===
(function ensureMultipaymentHardened(){
  function hardenOnce(){
    const $form = $checkoutForms();
    const isMultipayment = jQuery('#enable-multipayment').is(':checked');
    const gw = jQuery('input[name="payment_method"]:checked').val();
    if (!isMultipayment || gw !== 'pagarme_gateway') return;

    if ($form.find('input[name^="multi_method_"]').length === 0) {
      jQuery('.multipay-checkbox:checked').each(function(){
        const method = jQuery(this).val();
        const amount = parseFloat(jQuery(`.multipay-amount[data-method="${method}"]`).val() || 0);
        if (amount > 0) {
          $form.append(`<input type="hidden" name="multi_method_${method}" value="yes" class="pagarme-multipay-hidden" />`);
          $form.append(`<input type="hidden" name="multi_amount_${method}" value="${amount.toFixed(2)}" class="pagarme-multipay-hidden" />`);
          $form.append(`<input type="hidden" name="pagarme_multipayment_methods[]" value="${method}" class="pagarme-multipay-hidden" />`);
          $form.append(`<input type="hidden" name="pagarme_multipayment_amounts[${method}]" value="${amount.toFixed(2)}" class="pagarme-multipay-hidden" />`);
        }
      });
    }
    if ($form.find('input[name="pagarme_multipayment"]').length === 0) {
      $form.append('<input type="hidden" name="pagarme_multipayment" value="yes" class="pagarme-multipay-hidden" />');
    }
  }
  jQuery(document).on('checkout_place_order_pagarme_gateway', hardenOnce);
  jQuery(document).on('submit', 'form.checkout, form#order_review', hardenOnce);
  jQuery(document).ajaxSend(function(evt, jqxhr, settings){
    if (settings && settings.url && settings.url.indexOf('wc-ajax=checkout') !== -1) {
      hardenOnce();
    }
  });
})();

    
})(jQuery);

// CORREÇÃO ADICIONAL PARA O CHECKOUT.JS EXISTENTE

// Garantir que o objeto PagarmeCheckout tenha a função correta
if (window.PagarmeCheckout) {
    // Substituir ou adicionar a função de injeção
    window.PagarmeCheckout.injectFieldsBeforeSubmit = function() {
        console.log('PagarmeCheckout.injectFieldsBeforeSubmit chamado');
        
        // Chamar a função de injeção robusta
        if (window.forcePagarmeInject) {
            return window.forcePagarmeInject();
        }
        
        return false;
    };
    
    // Garantir que seja chamado no momento certo
    const originalValidate = window.PagarMeGatewayCheckout.validatePaymentFields;
    window.PagarMeGatewayCheckout.validatePaymentFields = function() {
        console.log('Validando e injetando campos...');
        
        // Injetar campos primeiro
        this.injectFieldsBeforeSubmit();
        
        // Depois validar (se a função original existir)
        if (originalValidate) {
            return originalValidate.apply(this, arguments);
        }
        
        return true;
    };
}

console.log('Correção urgente aplicada - campos devem ser injetados agora');
// CORREÇÃO DEFINITIVA V6.0 - SINGLE PAYMENT + MULTIPAGAMENTO
// INSTALADO EM: $(date +%Y-%m-%d)
// VERSÃO: 6.0

(function($) {
    'use strict';
    
    console.log('[CORREÇÃO DEFINITIVA V6.0] Carregando...');
    
    /**
     * Detectar se é REALMENTE multipagamento
     */
    function isRealMultipayment() {
        const checkboxChecked = $('#enable-multipayment').is(':checked');
        const selectedMethods = $('.multipayment-method.selected').length;
        
        const storageKey = 'pagarme_multipay_protected_v5';
        const savedData = sessionStorage.getItem(storageKey);
        let hasSavedMethods = false;
        
        if (savedData) {
            try {
                const parsed = JSON.parse(savedData);
                hasSavedMethods = parsed.methods && parsed.methods.length > 1;
            } catch (e) {
                // Ignorar erro
            }
        }
        
        const isMulti = checkboxChecked && (selectedMethods > 1 || hasSavedMethods);
        
        console.log('[Detecção V6]', {
            checkboxChecked,
            selectedMethods,
            hasSavedMethods,
            resultado: isMulti ? 'MULTIPAGAMENTO' : 'SINGLE PAYMENT'
        });
        
        return isMulti;
    }
    
    /**
     * Injetar campos para SINGLE PAYMENT
     */
    function injectSinglePaymentFields() {
        console.log('[Single V6] Injetando campos de pagamento único');
        
        const $form = $('form.checkout');
        const paymentMethod = $('input[name="payment_method"]:checked').val();
        
        if (paymentMethod !== 'pagarme_gateway') {
            console.log('[Single V6] Não é Pagar.me');
            return false;
        }
        
        const paymentType = $('input[name="pagarme_payment_type"]:checked').val() || $('#pagarme-payment-type').val() || $('.pagarme-payment-button.active').attr('data-type') || 'credit_card';
        console.log('[Single V6] Método:', paymentType);
        
        if (!['credit_card', 'debit_card', 'voucher'].includes(paymentType)) {
            console.log('[Single V6] Método não requer cartão');
            return true;
        }
        
        $form.find('.pagarme-single-payment-hidden').remove();
        
        const cardNumber = $('#pagarme-card-number').val() || '';
        const cardNumberClean = cardNumber.replace(/\D/g, '');
        const holderName = $('#pagarme-card-holder-name').val() || '';
        const expiryRaw = $('#pagarme-card-expiry').val() || '';
        const cvv = $('#pagarme-card-cvv').val() || '';
        const cardDocument = $('#pagarme-card-document').val() || '';
        const cardDocumentClean = cardDocument.replace(/\D/g, '');
        const installments = $('#pagarme-installments').val() || '1';
        
        console.log('[Single V6] Dados:', {
            number: cardNumberClean ? cardNumberClean.substring(0, 6) + '****' : 'vazio',
            holder: holderName || 'vazio',
            expiry: expiryRaw || 'vazio',
            cvv: cvv ? '***' : 'vazio'
        });
        
        if (cardNumberClean.length < 13 || !holderName || !cvv || !expiryRaw) {
            console.error('[Single V6] Campos inválidos');
            return false;
        }
        
        const expiryDigits = expiryRaw.replace(/\D/g, '');
        if (expiryDigits.length < 4) {
            console.error('[Single V6] Validade inválida');
            return false;
        }
        
        const expMonth = expiryDigits.substring(0, 2);
        const expYear = expiryDigits.substring(2, 4);
        const expYearFull = '20' + expYear;
        
        const fields = [
            { name: 'pagarme_card_number_raw', value: cardNumberClean },
            { name: 'pagarme_card_number', value: cardNumber },
            { name: 'pagarme_card_holder', value: holderName },
            { name: 'pagarme_card_holder_name', value: holderName },
            { name: 'pagarme_card_exp_month', value: expMonth },
            { name: 'pagarme_card_exp_year', value: expYearFull },
            { name: 'pagarme_card_expiration', value: expMonth + expYear },
            { name: 'pagarme_card_expiry', value: expiryRaw },
            { name: 'pagarme_card_cvv', value: cvv },
            { name: 'pagarme_card_document', value: cardDocumentClean },
            { name: 'pagarme_holder_document', value: cardDocumentClean }
        ];
        
        if (paymentType === 'credit_card') {
            fields.push({ name: 'pagarme_installments', value: installments });
        }
        
        let injectedCount = 0;
        fields.forEach(field => {
            if (field.value) {
                $form.append(
                    `<input type="hidden" name="${field.name}" value="${field.value}" class="pagarme-single-payment-hidden" />`
                );
                injectedCount++;
            }
        });
        
        console.log(`[Single V6] ${injectedCount} campos injetados`);
        
        const verificacao = $form.find('.pagarme-single-payment-hidden').length;
        console.log(`[Single V6] Verificação: ${verificacao} campos no DOM`);
        
        return verificacao > 0;
    }
    
    /**
     * Injetar campos para MULTIPAGAMENTO
     */
    function injectMultipaymentFields() {
        console.log('[Multi V6] Injetando campos de multipagamento');
        
        if (window.protectedInjectFields && typeof window.protectedInjectFields === 'function') {
            console.log('[Multi V6] Usando proteção v5');
            return window.protectedInjectFields();
        }
        
        console.log('[Multi V6] Proteção v5 não encontrada, usando fallback');
        
        const $form = $('form.checkout');
        $form.find('.pagarme-multipay-hidden').remove();
        
        $form.append('<input type="hidden" name="pagarme_multipayment" value="yes" class="pagarme-multipay-hidden" />');
        $form.append('<input type="hidden" name="is_multipayment" value="1" class="pagarme-multipay-hidden" />');
        
        const storageKey = 'pagarme_multipay_protected_v5';
        const savedData = sessionStorage.getItem(storageKey);
        
        if (!savedData) {
            console.error('[Multi V6] Nenhum dado no sessionStorage');
            return false;
        }
        
        try {
            const data = JSON.parse(savedData);
            
            if (!data.methods || data.methods.length === 0) {
                console.error('[Multi V6] Array de métodos vazio');
                return false;
            }
            
            data.methods.forEach(method => {
                const methodType = method.method;
                const amount = method.amount;
                
                $form.append(`<input type="hidden" name="multi_method_${methodType}" value="yes" class="pagarme-multipay-hidden" />`);
                $form.append(`<input type="hidden" name="multi_amount_${methodType}" value="${amount}" class="pagarme-multipay-hidden" />`);
                
                if (method.card) {
                    const card = method.card;
                    const prefix = `multi_card_${methodType}_`;
                    
                    $form.append(`<input type="hidden" name="${prefix}number_raw" value="${card.number}" class="pagarme-multipay-hidden" />`);
                    $form.append(`<input type="hidden" name="${prefix}holder" value="${card.holder}" class="pagarme-multipay-hidden" />`);
                    $form.append(`<input type="hidden" name="${prefix}cvv" value="${card.cvv}" class="pagarme-multipay-hidden" />`);
                    
                    if (card.expiry) {
                        const digits = card.expiry.replace(/\D/g, '');
                        const mm = digits.substring(0, 2);
                        const yy = digits.substring(2, 4);
                        
                        $form.append(`<input type="hidden" name="${prefix}exp_month" value="${mm}" class="pagarme-multipay-hidden" />`);
                        $form.append(`<input type="hidden" name="${prefix}exp_year" value="20${yy}" class="pagarme-multipay-hidden" />`);
                        $form.append(`<input type="hidden" name="${prefix}expiration" value="${mm}${yy}" class="pagarme-multipay-hidden" />`);
                    }
                    
                    if (card.document) {
                        $form.append(`<input type="hidden" name="${prefix}document" value="${card.document.replace(/\D/g, '')}" class="pagarme-multipay-hidden" />`);
                    }
                    
                    if (methodType === 'credit_card' && card.installments) {
                        $form.append(`<input type="hidden" name="${prefix}installments" value="${card.installments}" class="pagarme-multipay-hidden" />`);
                    }
                }
            });
            
            const injectedCount = $form.find('.pagarme-multipay-hidden').length;
            console.log(`[Multi V6] ${injectedCount} campos injetados`);
            
            return injectedCount > 0;
            
        } catch (e) {
            console.error('[Multi V6] Erro ao processar dados:', e);
            return false;
        }
    }
    
    /**
     * Função principal: detectar e injetar
     */
    function injectFieldsSmart() {
        console.log('[Smart Inject V6] Iniciando injeção inteligente');
        
        const paymentMethod = $('input[name="payment_method"]:checked').val();
        if (paymentMethod !== 'pagarme_gateway') {
            console.log('⏭[Smart Inject V6] Não é Pagar.me, ignorando');
            return true;
        }
        
        const isMulti = isRealMultipayment();
        
        if (isMulti) {
            console.log('[Smart Inject V6] Modo: MULTIPAGAMENTO');
            return injectMultipaymentFields();
        } else {
            console.log('1⃣ [Smart Inject V6] Modo: SINGLE PAYMENT');
            return injectSinglePaymentFields();
        }
    }
    
    /**
     * Instalar em múltiplos pontos
     */
    function installHooks() {
        console.log('[Hooks V6] Instalando interceptadores');
        
        $(document).off('submit.pagarmefix').on('submit.pagarmefix', 'form.checkout', function(e) {
            console.log('[Hook V6] Submit do formulário');
            injectFieldsSmart();
        });
        
        $(document).off('click.pagarmefix').on('click.pagarmefix', '#place_order, .msc-place-order, button[name="woocommerce_checkout_place_order"]', function() {
            console.log('[Hook V6] Botão finalizar');
            setTimeout(injectFieldsSmart, 50);
        });
        
        $(document).off('checkout_place_order_pagarme_gateway.pagarmefix')
                   .on('checkout_place_order_pagarme_gateway.pagarmefix', function() {
            console.log('[Hook V6] WooCommerce checkout_place_order');
            injectFieldsSmart();
            return true;
        });
        
        $(document).ajaxSend(function(event, jqxhr, settings) {
            if (settings.url && settings.url.includes('wc-ajax=checkout')) {
                const paymentMethod = $('input[name="payment_method"]:checked').val();
                if (paymentMethod === 'pagarme_gateway') {
                    console.log('[Hook V6] AJAX checkout');
                    injectFieldsSmart();
                }
            }
        });
        
        $(document).off('msc:step:changed.pagarmefix').on('msc:step:changed.pagarmefix', function(e, data) {
            if (data && data.newStep === 4) {
                console.log('[Hook V6] Multistep: Step 4');
                setTimeout(injectFieldsSmart, 100);
            }
        });
        
        $(document).off('msc:before:validate:step.pagarmefix').on('msc:before:validate:step.pagarmefix', function(e, data) {
            if (data && data.step === 3) {
                console.log('[Hook V6] Multistep: Validando Step 3');
                injectFieldsSmart();
            }
        });
        
        console.log('[Hooks V6] Todos os interceptadores instalados');
    }
    
    /**
     * Sobrescrever função problemática antiga
     */
    function overrideOldFunction() {
        console.log('[Override V6] Sobrescrevendo função antiga');
        
        if (window.injectPagarmeFields) {
            window.injectPagarmeFields_OLD = window.injectPagarmeFields;
            window.injectPagarmeFields = injectFieldsSmart;
            console.log('[Override V6] injectPagarmeFields substituída');
        }
        
        if (window.PagarMeGatewayCheckout && window.PagarMeGatewayCheckout.injectSinglePaymentHiddenFields) {
            window.PagarMeGatewayCheckout.injectSinglePaymentHiddenFields_OLD = 
                window.PagarMeGatewayCheckout.injectSinglePaymentHiddenFields;
            
            window.PagarMeGatewayCheckout.injectSinglePaymentHiddenFields = function() {
                console.log('[Override V6] Chamada redirecionada');
                return injectFieldsSmart();
            };
            
            console.log('[Override V6] PagarMeGatewayCheckout.injectSinglePaymentHiddenFields substituída');
        }
    }
    
    /**
     * Função de teste global
     */
    window.testPagarmeInjectV6 = function() {
        console.clear();
        console.log('TESTE DA CORREÇÃO V6.0');
        console.log('═══════════════════════════════════════════');
        
        const isMulti = isRealMultipayment();
        console.log(`\nModo detectado: ${isMulti ? 'MULTIPAGAMENTO' : 'SINGLE PAYMENT'}\n`);
        
        console.log('Tentando injetar campos...\n');
        const result = injectFieldsSmart();
        
        console.log(`\nResultado: ${result ? 'SUCESSO' : 'FALHA'}\n`);
        
        const $form = $('form.checkout');
        const singleFields = $form.find('.pagarme-single-payment-hidden').length;
        const multiFields = $form.find('.pagarme-multipay-hidden').length;
        
        console.log('Campos injetados:');
        console.log(`  - Single payment: ${singleFields}`);
        console.log(`  - Multipayment: ${multiFields}`);
        
        if (singleFields > 0) {
            console.log('\nCampos single payment:');
            $form.find('.pagarme-single-payment-hidden').each(function() {
                const name = $(this).attr('name');
                const value = $(this).val();
                const display = name.includes('number') || name.includes('cvv') 
                    ? value.substring(0, 4) + '***' 
                    : value;
                console.log(`  ${name}: ${display}`);
            });
        }
        
        if (multiFields > 0) {
            console.log('\nCampos multipayment:');
            $form.find('.pagarme-multipay-hidden').each(function() {
                const name = $(this).attr('name');
                const value = $(this).val();
                console.log(`  ${name}: ${value}`);
            });
        }
        
        console.log('\n═══════════════════════════════════════════');
        
        return {
            mode: isMulti ? 'multi' : 'single',
            result: result,
            singleFields: singleFields,
            multiFields: multiFields
        };
    };
    
    /**
     * Inicialização
     */
    $(document).ready(function() {
        setTimeout(function() {
            overrideOldFunction();
            installHooks();
            console.log('[CORREÇÃO DEFINITIVA V6.0] Instalada com sucesso!');
            console.log('Use testPagarmeInjectV6() no console para testar');
        }, 500);
    });
    
})(jQuery);

// FIM DA CORREÇÃO DEFINITIVA V6.0
