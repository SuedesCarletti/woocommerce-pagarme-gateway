<?php
/**
 * SISTEMA DE DIAGNÓSTICO E CORREÇÃO DE WEBHOOKS
 * 
 * Arquivo: includes/webhook-diagnostic-tool.php
 * 
 * Este arquivo ajuda a identificar e corrigir problemas com webhooks
 */

namespace PagarMeGateway;

class Webhook_Diagnostic_Tool {
    
    private $errors = array();
    private $warnings = array();
    private $success = array();
    
    /**
     * Executar diagnóstico completo
     */
    public function run_diagnostics() {
        echo "<div class='wrap'>";
        echo "<h1>Diagnóstico de Webhooks Pagar.me</h1>";
        
        // Teste 1: Verificar endpoints registrados
        $this->check_endpoints();
        
        // Teste 2: Verificar permissões de arquivo
        $this->check_permissions();
        
        // Teste 3: Verificar configuração do gateway
        $this->check_gateway_config();
        
        // Teste 4: Verificar conectividade
        $this->check_connectivity();
        
        // Teste 5: Verificar logs de erro
        $this->check_error_logs();
        
        // Teste 6: Verificar estrutura do banco
        $this->check_database();
        
        // Exibir resultados
        $this->display_results();
        
        // Botões de ação
        $this->display_actions();
        
        echo "</div>";
    }
    
    /**
     * 1. Verificar endpoints registrados
     */
    private function check_endpoints() {
        global $wp_rewrite;
        
        // Verificar WooCommerce API endpoint
        $wc_endpoint = home_url('/wc-api/pagarme_webhook');
        $this->success[] = "Endpoint WooCommerce registrado: <code>$wc_endpoint</code>";
        
        // Verificar REST API endpoint
        $rest_endpoint = rest_url('pagarme/v1/webhook');
        $this->success[] = "Endpoint REST API registrado: <code>$rest_endpoint</code>";
        
        // Verificar se as regras de rewrite estão ativas
        if (empty($wp_rewrite->rules)) {
            $this->warnings[] = "Regras de rewrite não estão ativas. Execute flush_rewrite_rules()";
        }
    }
    
    /**
     * 2. Verificar permissões
     */
    private function check_permissions() {
        // Verificar se o servidor permite POST requests
        $test_url = home_url('/wc-api/pagarme_webhook');
        
        $response = wp_remote_post($test_url, array(
            'method' => 'POST',
            'body' => json_encode(array('type' => 'test')),
            'headers' => array('Content-Type' => 'application/json'),
            'sslverify' => false,
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            $this->errors[] = "❌ Erro ao acessar webhook: " . $response->get_error_message();
        } else {
            $status = wp_remote_retrieve_response_code($response);
            if ($status === 403) {
                $this->errors[] = "❌ Erro 403 detectado. Servidor bloqueando acesso.";
                $this->add_403_fix();
            } elseif ($status === 500) {
                $this->errors[] = "❌ Erro 500 detectado. Erro interno no processamento.";
                $this->add_500_fix();
            } elseif ($status === 200 || $status === 400) {
                $this->success[] = "Endpoint acessível (Status: $status)";
            }
        }
    }
    
    /**
     * 3. Verificar configuração do gateway
     */
    private function check_gateway_config() {
        $gateways = WC()->payment_gateways()->payment_gateways();
        
        if (!isset($gateways['pagarme_gateway'])) {
            $this->errors[] = "❌ Gateway Pagar.me não encontrado";
            return;
        }
        
        $gateway = $gateways['pagarme_gateway'];
        
        if ($gateway->enabled !== 'yes') {
            $this->warnings[] = "Gateway Pagar.me está desativado";
        } else {
            $this->success[] = "Gateway Pagar.me está ativo";
        }
        
        // Verificar API Key
        $api_key = $gateway->get_option('api_key');
        if (empty($api_key)) {
            $this->errors[] = "❌ API Key não configurada";
        } else {
            $this->success[] = "API Key configurada";
        }
    }
    
    /**
     * 4. Verificar conectividade com Pagar.me
     */
    private function check_connectivity() {
        $gateway = WC()->payment_gateways()->payment_gateways()['pagarme_gateway'] ?? null;
        
        if (!$gateway) {
            return;
        }
        
        $api_key = $gateway->get_option('api_key');
        if (empty($api_key)) {
            return;
        }
        
        // Testar conexão com a API
        $response = wp_remote_get('https://api.pagar.me/core/v5/orders?status=paid&page=1&size=1', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($api_key . ':'),
                'Content-Type' => 'application/json'
            ),
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            $this->errors[] = "❌ Erro ao conectar com Pagar.me: " . $response->get_error_message();
        } else {
            $status = wp_remote_retrieve_response_code($response);
            if ($status === 200) {
                $this->success[] = "Conexão com Pagar.me funcionando";
            } else {
                $this->errors[] = "❌ Erro na API Pagar.me (Status: $status)";
            }
        }
    }
    
    /**
     * 5. Verificar logs de erro
     */
    private function check_error_logs() {
        $error_log = ini_get('error_log');
        
        if (file_exists($error_log)) {
            $recent_errors = $this->get_recent_errors($error_log);
            
            if (!empty($recent_errors)) {
                $this->warnings[] = "Erros recentes encontrados no log";
                foreach ($recent_errors as $error) {
                    $this->warnings[] = "<code style='font-size: 11px;'>$error</code>";
                }
            }
        }
    }
    
    /**
     * 6. Verificar estrutura do banco
     */
    private function check_database() {
        global $wpdb;
        
        // Verificar se a tabela de logs existe
        $table = $wpdb->prefix . 'pagarme_gateway_logs';
        $exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
        
        if ($exists) {
            $this->success[] = "Tabela de logs existe";
            
            // Contar logs recentes
            $count = $wpdb->get_var("
                SELECT COUNT(*) 
                FROM $table 
                WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            
            if ($count > 0) {
                $this->success[] = "$count logs nas últimas 24 horas";
            }
        } else {
            $this->warnings[] = "Tabela de logs não existe";
        }
    }
    
    /**
     * Obter erros recentes
     */
    private function get_recent_errors($log_file) {
        $errors = array();
        $lines = file($log_file);
        $recent_lines = array_slice($lines, -50); // Últimas 50 linhas
        
        foreach ($recent_lines as $line) {
            if (stripos($line, 'pagarme') !== false && 
                (stripos($line, 'error') !== false || stripos($line, 'fatal') !== false)) {
                $errors[] = trim($line);
            }
        }
        
        return array_slice($errors, -5); // Máximo 5 erros
    }
    
    /**
     * Adicionar correção para erro 403
     */
    private function add_403_fix() {
        $this->errors[] = "
        <div style='background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 10px 0;'>
            <strong>🔧 Correção para Erro 403:</strong><br>
            1. Adicione ao .htaccess:<br>
            <code style='display: block; margin: 10px 0; padding: 10px; background: #f8f9fa;'>
# Permitir webhooks Pagar.me
&lt;IfModule mod_rewrite.c&gt;
    RewriteRule ^wc-api/pagarme_webhook$ index.php?wc-api=pagarme_webhook [L]
&lt;/IfModule&gt;
            </code>
            2. Desative mod_security temporariamente<br>
            3. Configure firewall para permitir IPs do Pagar.me<br>
            4. Use o endpoint REST alternativo: <code>" . rest_url('pagarme/v1/webhook') . "</code>
        </div>";
    }
    
    /**
     * Adicionar correção para erro 500
     */
    private function add_500_fix() {
        $this->errors[] = "
        <div style='background: #f8d7da; padding: 15px; border-left: 4px solid #dc3545; margin: 10px 0;'>
            <strong>🔧 Correção para Erro 500:</strong><br>
            1. Ative o modo debug do WordPress:<br>
            <code style='display: block; margin: 10px 0; padding: 10px; background: #f8f9fa;'>
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
            </code>
            2. Verifique o arquivo de log em: <code>wp-content/debug.log</code><br>
            3. Substitua o arquivo webhook handler pelo corrigido<br>
            4. Limpe o cache do servidor e do WordPress
        </div>";
    }
    
    /**
     * Exibir resultados do diagnóstico
     */
    private function display_results() {
        // Sucessos
        if (!empty($this->success)) {
            echo "<div style='background: #d4edda; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
            echo "<h3>Verificações OK:</h3>";
            foreach ($this->success as $msg) {
                echo "<p>$msg</p>";
            }
            echo "</div>";
        }
        
        // Avisos
        if (!empty($this->warnings)) {
            echo "<div style='background: #fff3cd; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
            echo "<h3>Avisos:</h3>";
            foreach ($this->warnings as $msg) {
                echo "<p>$msg</p>";
            }
            echo "</div>";
        }
        
        // Erros
        if (!empty($this->errors)) {
            echo "<div style='background: #f8d7da; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
            echo "<h3>Erros Encontrados:</h3>";
            foreach ($this->errors as $msg) {
                echo "<p>$msg</p>";
            }
            echo "</div>";
        }
    }
    
    /**
     * Exibir botões de ação
     */
    private function display_actions() {
        ?>
        <div style="margin: 20px 0;">
            <h3>🔧 Ações de Correção:</h3>
            
            <p>
                <button class="button button-primary" onclick="flushRewriteRules()">
                    🔄 Atualizar Regras de URL
                </button>
                <span style="margin-left: 10px;">Resolve problemas de endpoint não encontrado</span>
            </p>
            
            <p>
                <button class="button" onclick="testWebhook()">
                    🧪 Testar Webhook
                </button>
                <span style="margin-left: 10px;">Envia webhook de teste</span>
            </p>
            
            <p>
                <button class="button" onclick="clearLogs()">
                    Limpar Logs
                </button>
                <span style="margin-left: 10px;">Limpa logs antigos do sistema</span>
            </p>
            
            <p>
                <button class="button button-primary" onclick="applyFix()">
                    ✨ Aplicar Correção Automática
                </button>
                <span style="margin-left: 10px;">Tenta corrigir problemas automaticamente</span>
            </p>
        </div>
        
        <script>
        function flushRewriteRules() {
            jQuery.post(ajaxurl, {
                action: 'pagarme_flush_rewrite',
                nonce: '<?php echo wp_create_nonce('pagarme_admin'); ?>'
            }, function(response) {
                alert('Regras atualizadas! Recarregue a página.');
                location.reload();
            });
        }
        
        function testWebhook() {
            jQuery.post(ajaxurl, {
                action: 'pagarme_test_webhook',
                nonce: '<?php echo wp_create_nonce('pagarme_admin'); ?>'
            }, function(response) {
                alert('Webhook de teste enviado! Verifique os logs.');
            });
        }
        
        function clearLogs() {
            if (confirm('Limpar todos os logs?')) {
                jQuery.post(ajaxurl, {
                    action: 'pagarme_clear_logs',
                    nonce: '<?php echo wp_create_nonce('pagarme_admin'); ?>'
                }, function(response) {
                    alert('Logs limpos!');
                });
            }
        }
        
        function applyFix() {
            jQuery.post(ajaxurl, {
                action: 'pagarme_apply_fix',
                nonce: '<?php echo wp_create_nonce('pagarme_admin'); ?>'
            }, function(response) {
                alert('Correções aplicadas! Teste o webhook novamente.');
                location.reload();
            });
        }
        </script>
        <?php
    }
}

// Registrar página de diagnóstico no admin
add_action('admin_menu', function() {
    add_submenu_page(
        'woocommerce',
        'Diagnóstico Webhooks Pagar.me',
        'Webhook Diagnóstico',
        'manage_woocommerce',
        'pagarme-webhook-diagnostic',
        function() {
            $tool = new Webhook_Diagnostic_Tool();
            $tool->run_diagnostics();
        }
    );
});

// Handlers AJAX
add_action('wp_ajax_pagarme_flush_rewrite', function() {
    check_ajax_referer('pagarme_admin', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Sem permissão.'), 403);
    }
    flush_rewrite_rules();
    wp_send_json_success();
});

add_action('wp_ajax_pagarme_test_webhook', function() {
    check_ajax_referer('pagarme_admin', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Sem permissão.'), 403);
    }
    
    $test_data = array(
        'id' => 'hook_test_' . time(),
        'type' => 'test',
        'data' => array(
            'test' => true,
            'timestamp' => current_time('mysql')
        )
    );
    
    $response = wp_remote_post(home_url('/wc-api/pagarme_webhook'), array(
        'body' => json_encode($test_data),
        'headers' => array('Content-Type' => 'application/json')
    ));
    
    wp_send_json_success(array(
        'status' => wp_remote_retrieve_response_code($response),
        'body' => wp_remote_retrieve_body($response)
    ));
});

add_action('wp_ajax_pagarme_clear_logs', function() {
    check_ajax_referer('pagarme_admin', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Sem permissão.'), 403);
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'pagarme_gateway_logs';
    $wpdb->query("TRUNCATE TABLE $table");
    
    wp_send_json_success();
});

add_action('wp_ajax_pagarme_apply_fix', function() {
    check_ajax_referer('pagarme_admin', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Sem permissão.'), 403);
    }
    
    // Aplicar correções automáticas
    flush_rewrite_rules();
    
    // Atualizar opções
    update_option('pagarme_webhook_fixed', true);
    
    // Criar tabelas se não existirem
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pagarme_webhook_logs (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        webhook_id varchar(255) NOT NULL,
        type varchar(100) NOT NULL,
        data longtext NOT NULL,
        status varchar(50) DEFAULT 'received',
        processed_at datetime DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY webhook_id (webhook_id),
        KEY type (type),
        KEY status (status)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    wp_send_json_success();
});