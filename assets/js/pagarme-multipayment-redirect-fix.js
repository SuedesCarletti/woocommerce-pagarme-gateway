/**
 * FIX: Loading Infinito em Multipayment com Boleto
 * 
 * PROBLEMA:
 * - Pedido multipayment com boleto processa corretamente no backend
 * - Boleto é gerado com sucesso
 * - Frontend fica em loading infinito e não redireciona
 * - Usuário precisa dar F5 para ver o pedido finalizado
 * 
 * SOLUÇÃO:
 * - Interceptar resposta AJAX do checkout
 * - Detectar sucesso no processamento
 * - Forçar redirect para página de confirmação
 * 
 * Versão: 1.0.0
 * Data: 03/12/2025
 */

(function($) {
    'use strict';

    // Flag para evitar múltiplos redirects
    let redirecting = false;
    
    /**
     * Log com prefixo
     */
    function log(message, data) {
        console.log('[Redirect Fix]', message, data || '');
    }
    
    log('Módulo carregado');
    
    /**
     * Detectar se é multipayment
     */
    function isMultipayment() {
        return $('#enable-multipayment').is(':checked') || 
               $('input[name="pagarme_multipayment"]').val() === 'yes';
    }
    
    /**
     * Detectar se tem boleto selecionado
     */
    function hasBoletoSelected() {
        const methods = [];
        
        // Verificar checkboxes de métodos
        $('input[name^="multi_method_"]:checked').each(function() {
            const method = $(this).attr('name').replace('multi_method_', '');
            methods.push(method);
        });
        
        return methods.includes('boleto');
    }
    
    /**
     * Forçar redirect para página de confirmação
     */
    function forceRedirect(redirectUrl) {
        if (redirecting) {
            log('⚠️ Redirect já em andamento, ignorando');
            return;
        }
        
        redirecting = true;
        
        log('✅ Forçando redirect para:', redirectUrl);
        
        // Remover overlay de loading se existir
        $('.woocommerce-checkout').unblock();
        $('.blockUI').fadeOut();
        
        // Aguardar um momento para garantir que o pedido foi salvo
        setTimeout(function() {
            window.location.href = redirectUrl;
        }, 500);
    }
    
    /**
     * Interceptar resposta AJAX do checkout
     */
    $(document).ajaxComplete(function(event, xhr, settings) {
        // Verificar se é o endpoint de checkout
        if (!settings.url || settings.url.indexOf('wc-ajax=checkout') === -1) {
            return;
        }
        
        // Verificar se é multipayment
        if (!isMultipayment()) {
            log('⏭️ Não é multipayment, ignorando');
            return;
        }
        
        log('📡 Resposta AJAX recebida do checkout');
        
        try {
            // Parse da resposta
            let response;
            
            if (typeof xhr.responseJSON !== 'undefined') {
                response = xhr.responseJSON;
            } else if (typeof xhr.responseText !== 'undefined') {
                try {
                    response = JSON.parse(xhr.responseText);
                } catch (e) {
                    log('⚠️ Erro ao fazer parse do responseText', e);
                    return;
                }
            }
            
            if (!response) {
                log('⚠️ Resposta vazia ou inválida');
                return;
            }
            
            log('📦 Resposta parsed:', response);
            
            // Verificar se houve sucesso
            if (response.result === 'success' && response.redirect) {
                log('✅ Sucesso detectado! Redirect URL:', response.redirect);
                
                // Verificar se tem boleto
                const withBoleto = hasBoletoSelected();
                
                log('🎫 Tem boleto?', withBoleto);
                
                // SEMPRE forçar redirect em caso de sucesso
                // O WooCommerce deveria fazer isso automaticamente, mas está falhando
                forceRedirect(response.redirect);
                
            } else if (response.result === 'failure') {
                log('❌ Falha no processamento');
                
                // Desbloquear interface para permitir correção
                $('.woocommerce-checkout').unblock();
                $('.blockUI').fadeOut();
                
                // Scroll para o topo para mostrar erros
                $('html, body').animate({
                    scrollTop: $('.woocommerce-error, .woocommerce-message').offset().top - 100
                }, 500);
            }
            
        } catch (e) {
            log('❌ Erro ao processar resposta AJAX:', e);
        }
    });
    
    /**
     * Fallback: Monitorar mudanças de status no WooCommerce
     */
    $(document.body).on('checkout_error', function() {
        log('⚠️ Erro no checkout detectado');
        
        // Garantir que interface seja desbloqueada
        $('.woocommerce-checkout').unblock();
        $('.blockUI').fadeOut();
    });
    
    /**
     * Fallback 2: Timeout de segurança
     * Se após 30 segundos ainda estiver em loading, mostrar mensagem
     */
    let checkoutStartTime = null;
    
    $(document).on('submit', 'form.checkout', function() {
        if (isMultipayment()) {
            checkoutStartTime = Date.now();
            log('⏱️ Iniciando timer de segurança (30s)');
            
            setTimeout(function() {
                if (checkoutStartTime && !redirecting) {
                    const elapsed = (Date.now() - checkoutStartTime) / 1000;
                    
                    if (elapsed >= 29) {
                        log('⚠️ Timeout de 30s atingido');
                        
                        // Desbloquear interface
                        $('.woocommerce-checkout').unblock();
                        $('.blockUI').fadeOut();
                        
                        // Mostrar mensagem amigável
                        alert('O processamento está demorando mais que o esperado. Vamos verificar seu pedido...');
                        
                        // Tentar redirecionar para "Minha Conta > Pedidos"
                        if (typeof wc_checkout_params !== 'undefined' && wc_checkout_params.wc_ajax_url) {
                            const baseUrl = wc_checkout_params.wc_ajax_url.split('?')[0];
                            const accountUrl = baseUrl.replace('/wp-admin/admin-ajax.php', '/minha-conta/pedidos/');
                            window.location.href = accountUrl;
                        }
                    }
                }
            }, 30000);
        }
    });
    
    /**
     * Limpar timer quando redirect acontecer
     */
    $(window).on('beforeunload', function() {
        checkoutStartTime = null;
    });
    
    log('✅ Interceptador instalado com sucesso');
    
})(jQuery);