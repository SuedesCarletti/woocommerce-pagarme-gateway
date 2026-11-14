<?php
/**
 * Plugin Name: Diagnóstico Webhook Pagar.me
 * Description: Diagnóstico completo do webhook - Acesse via Admin
 * Version: 1.0
 * Author: Suedes Carletti
 */

// Prevenir acesso direto
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adicionar página no menu Admin
 */
add_action('admin_menu', function() {
    add_menu_page(
        'Diagnóstico Webhook',
        'Webhook Debug',
        'manage_options',
        'webhook-diagnostico',
        'webhook_diagnostico_page',
        'dashicons-search',
        99
    );
});

/**
 * Página de diagnóstico
 */
function webhook_diagnostico_page() {
    // Verificar permissões
    if (!current_user_can('manage_options')) {
        wp_die('Acesso negado');
    }
    
    ?>
    <div class="wrap">
        <h1>🔍 Diagnóstico Webhook Pagar.me</h1>
        
        <style>
            .diag-section { background: white; padding: 20px; margin: 20px 0; border-left: 4px solid #0073aa; }
            .diag-ok { color: #46b450; font-weight: bold; }
            .diag-error { color: #dc3232; font-weight: bold; }
            .diag-warning { color: #ffb900; font-weight: bold; }
            .diag-info { color: #0073aa; }
            .diag-code { background: #f0f0f0; padding: 10px; margin: 10px 0; overflow-x: auto; }
        </style>
        
        <?php
        $results = array();
        
        // TESTE 1: WordPress
        echo '<div class="diag-section">';
        echo '<h2>1⃣ WordPress</h2>';
        
        echo '<p class="diag-ok">WordPress carregado: ' . get_bloginfo('version') . '</p>';
        echo '<p class="diag-info">ABSPATH: ' . ABSPATH . '</p>';
        echo '<p class="diag-info">PHP: ' . PHP_VERSION . '</p>';
        echo '<p class="diag-info">Site URL: ' . home_url() . '</p>';
        $results['wordpress'] = true;
        
        echo '</div>';
        
        // TESTE 2: WooCommerce
        echo '<div class="diag-section">';
        echo '<h2>2⃣ WooCommerce</h2>';
        
        if (class_exists('WooCommerce')) {
            echo '<p class="diag-ok">WooCommerce: ' . WC()->version . '</p>';
            $results['woocommerce'] = true;
            
            if (function_exists('wc_get_order')) {
                echo '<p class="diag-ok">wc_get_order() disponível</p>';
                $results['wc_functions'] = true;
            } else {
                echo '<p class="diag-error">wc_get_order() NÃO disponível</p>';
                $results['wc_functions'] = false;
            }
        } else {
            echo '<p class="diag-error">WooCommerce NÃO instalado</p>';
            $results['woocommerce'] = false;
        }
        
        echo '</div>';
        
        // TESTE 3: Plugin Pagar.me
        echo '<div class="diag-section">';
        echo '<h2>3⃣ Plugin Pagar.me</h2>';
        
        if (defined('PAGARME_GATEWAY_VERSION')) {
            echo '<p class="diag-ok">Plugin carregado: v' . PAGARME_GATEWAY_VERSION . '</p>';
            echo '<p class="diag-info">Path: ' . PAGARME_GATEWAY_PATH . '</p>';
            $results['plugin'] = true;
        } else {
            echo '<p class="diag-error">Plugin NÃO carregado</p>';
            $results['plugin'] = false;
        }
        
        // Verificar arquivos
        $files = array(
            'includes/class-logger.php',
            'includes/pagarme-gateway-complete-system.php'
        );
        
        echo '<h3>Arquivos:</h3>';
        $plugin_path = WP_PLUGIN_DIR . '/pagarme-woocommerce-gateway/';
        
        foreach ($files as $file) {
            if (file_exists($plugin_path . $file)) {
                echo '<p class="diag-ok">' . $file . '</p>';
            } else {
                echo '<p class="diag-error">' . $file . ' - NÃO ENCONTRADO</p>';
            }
        }
        
        echo '</div>';
        
        // TESTE 4: Classes
        echo '<div class="diag-section">';
        echo '<h2>4⃣ Classes PHP</h2>';
        
        $classes = array(
            'PagarMeGateway\\Logger' => 'Logger',
            'PagarMeGateway\\PagarMe_Gateway_Complete' => 'Gateway',
            'PagarMeGateway\\PagarMe_Webhook_Handler' => 'Webhook Handler',
        );
        
        foreach ($classes as $class => $name) {
            if (class_exists($class)) {
                echo '<p class="diag-ok">' . $name . '</p>';
                $results['class_' . $name] = true;
            } else {
                echo '<p class="diag-error">' . $name . ' (' . $class . ')</p>';
                $results['class_' . $name] = false;
            }
        }
        
        echo '</div>';
        
        // TESTE 5: Hooks
        echo '<div class="diag-section">';
        echo '<h2>5⃣ Hooks WooCommerce</h2>';
        
        global $wp_filter;
        
        if (isset($wp_filter['woocommerce_api_pagarme_webhook'])) {
            echo '<p class="diag-ok">Hook woocommerce_api_pagarme_webhook REGISTRADO</p>';
            
            $callbacks = $wp_filter['woocommerce_api_pagarme_webhook']->callbacks;
            echo '<h4>Callbacks:</h4><div class="diag-code">';
            foreach ($callbacks as $priority => $cbs) {
                echo "Prioridade $priority:<br>";
                foreach ($cbs as $cb) {
                    if (is_array($cb['function'])) {
                        echo "&nbsp;&nbsp;→ " . get_class($cb['function'][0]) . "::" . $cb['function'][1] . "<br>";
                    }
                }
            }
            echo '</div>';
            $results['hook'] = true;
        } else {
            echo '<p class="diag-error">Hook NÃO registrado!</p>';
            echo '<p class="diag-warning">Webhook não funcionará!</p>';
            $results['hook'] = false;
        }
        
        echo '</div>';
        
        // TESTE 6: URL e Permalinks
        echo '<div class="diag-section">';
        echo '<h2>6⃣ URL do Webhook</h2>';
        
        $webhook_url = home_url('/wc-api/pagarme_webhook');
        echo '<p class="diag-info">URL: <strong>' . $webhook_url . '</strong></p>';
        
        $permalink = get_option('permalink_structure');
        if (empty($permalink)) {
            echo '<p class="diag-error">PERMALINKS NO MODO PADRÃO!</p>';
            echo '<p class="diag-warning">ESTA É A CAUSA MAIS COMUM DE ERRO 500!</p>';
            echo '<p class="diag-info">Vá em: Configurações → Permalinks → Escolha qualquer opção exceto "Padrão"</p>';
            $results['permalinks'] = false;
        } else {
            echo '<p class="diag-ok">Permalinks: ' . $permalink . '</p>';
            $results['permalinks'] = true;
        }
        
        echo '</div>';
        
        // TESTE 7: Testar Acesso ao Webhook
        echo '<div class="diag-section">';
        echo '<h2>7⃣ Teste de Acesso</h2>';
        
        $test_data = json_encode(array(
            'id' => 'hook_test_' . time(),
            'type' => 'test',
            'data' => array('test' => true)
        ));
        
        $response = wp_remote_post($webhook_url, array(
            'body' => $test_data,
            'headers' => array('Content-Type' => 'application/json'),
            'timeout' => 10,
            'sslverify' => false
        ));
        
        if (is_wp_error($response)) {
            echo '<p class="diag-error">Erro: ' . $response->get_error_message() . '</p>';
            $results['access'] = false;
        } else {
            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            echo '<p class="diag-info">HTTP Status: <strong>' . $code . '</strong></p>';
            
            if ($code == 200) {
                echo '<p class="diag-ok">Webhook acessível!</p>';
                $results['access'] = true;
            } elseif ($code == 500) {
                echo '<p class="diag-error">ERRO 500 - Erro interno</p>';
                $results['access'] = false;
            } elseif ($code == 403) {
                echo '<p class="diag-error">ERRO 403 - Acesso negado</p>';
                echo '<p class="diag-info">Servidor LiteSpeed pode estar bloqueando</p>';
                $results['access'] = false;
            }
            
            echo '<h4>Resposta:</h4>';
            echo '<div class="diag-code"><pre>' . esc_html(substr($body, 0, 1000)) . '</pre></div>';
        }
        
        echo '</div>';
        
        // TESTE 8: Debug.log
        echo '<div class="diag-section">';
        echo '<h2>8⃣ Logs de Erro</h2>';
        
        $debug_file = WP_CONTENT_DIR . '/debug.log';
        if (file_exists($debug_file)) {
            $lines = file($debug_file);
            $recent = array_slice($lines, -30);
            
            $relevant = array_filter($recent, function($line) {
                return stripos($line, 'pagar') !== false || 
                       stripos($line, 'webhook') !== false ||
                       stripos($line, 'fatal') !== false;
            });
            
            if (!empty($relevant)) {
                echo '<h4>Erros recentes:</h4>';
                echo '<div class="diag-code"><pre>' . esc_html(implode('', $relevant)) . '</pre></div>';
            } else {
                echo '<p class="diag-ok">Sem erros recentes relacionados</p>';
            }
        } else {
            echo '<p class="diag-info">ℹdebug.log não encontrado</p>';
            echo '<p>Para ativar, adicione no wp-config.php:</p>';
            echo '<div class="diag-code"><pre>define(\'WP_DEBUG\', true);
define(\'WP_DEBUG_LOG\', true);
define(\'WP_DEBUG_DISPLAY\', false);</pre></div>';
        }
        
        echo '</div>';
        
        // RESUMO
        echo '<div class="diag-section">';
        echo '<h2>RESUMO</h2>';
        
        $total = count($results);
        $passed = count(array_filter($results));
        $failed = $total - $passed;
        
        echo '<p><strong>Total:</strong> ' . $total . ' testes</p>';
        echo '<p><strong>Passaram:</strong> <span class="diag-ok">' . $passed . '</span></p>';
        echo '<p><strong>Falharam:</strong> <span class="diag-error">' . $failed . '</span></p>';
        
        if ($failed == 0) {
            echo '<p class="diag-ok">TUDO OK! Webhook deveria funcionar.</p>';
        } else {
            echo '<p class="diag-error">Problemas encontrados - veja acima</p>';
        }
        
        // SOLUÇÕES
        echo '<h2>SOLUÇÕES RÁPIDAS</h2>';
        
        if (isset($results['permalinks']) && !$results['permalinks']) {
            echo '<div style="background: #dc3232; color: white; padding: 15px; margin: 10px 0;">';
            echo '<h3>URGENTE: Corrigir Permalinks</h3>';
            echo '<p>1. Vá em: <a href="' . admin_url('options-permalink.php') . '" style="color: white; text-decoration: underline;">Configurações → Permalinks</a></p>';
            echo '<p>2. Escolha QUALQUER opção exceto "Padrão"</p>';
            echo '<p>3. Clique em "Salvar alterações"</p>';
            echo '<p><strong>Isso resolve 80% dos erros 500 em webhooks!</strong></p>';
            echo '</div>';
        }
        
        if (isset($results['hook']) && !$results['hook']) {
            echo '<div style="background: #ffb900; color: black; padding: 15px; margin: 10px 0;">';
            echo '<h3>Hook do Webhook Não Registrado</h3>';
            echo '<p>O webhook não está sendo inicializado. Verifique o arquivo:</p>';
            echo '<code>includes/pagarme-gateway-complete-system.php</code>';
            echo '<p>Deve conter:</p>';
            echo '<div class="diag-code"><pre>new PagarMeGateway\PagarMe_Webhook_Handler();</pre></div>';
            echo '</div>';
        }
        
        if (isset($results['class_Webhook Handler']) && !$results['class_Webhook Handler']) {
            echo '<div style="background: #dc3232; color: white; padding: 15px; margin: 10px 0;">';
            echo '<h3>Classe Webhook Handler Não Encontrada</h3>';
            echo '<p>Substitua o arquivo: <code>includes/webhook-handler-fixed.php</code></p>';
            echo '<p>Reinstale o plugin a partir do repositório.</p>';
            echo '</div>';
        }
        
        echo '</div>';
        ?>
        
        <div class="diag-section">
            <h2>🔄 Ações</h2>
            <p>
                <a href="<?php echo admin_url('admin.php?page=webhook-diagnostico'); ?>" class="button button-primary">
                    🔄 Executar Novamente
                </a>
                <a href="<?php echo admin_url('options-permalink.php'); ?>" class="button">
                    ⚙️ Configurar Permalinks
                </a>
            </p>
        </div>
        
    </div>
    <?php
}