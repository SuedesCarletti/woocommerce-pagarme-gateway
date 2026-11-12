<?php
/**
 * Plugin Name: Gateway Pagar.me para WooCommerce
 * Plugin URI: https://github.com/SuedesCarletti/pagarme-woocommerce-gateway
 * Description: Gateway Pagar.me com todos os métodos de pagamento, multipagamentos e antifraude
 * Version: 2.0.0
 * Author: Suedes Carletti
 * E-mail: suedes.carletti.tp@gmail.com
 * Requires at least: 5.0
 * Tested up to: 6.4
 * WC requires at least: 4.0
 * WC tested up to: 8.5
 * Requires PHP: 7.4
 * License: Todos os direitos reservados / All rights reserved
 * Text Domain: pagarme-gateway
 * Domain Path: /languages
 */

// Prevenir acesso direto
if (!defined('ABSPATH')) {
    exit;
}

// Definir constantes do plugin
define('PAGARME_GATEWAY_VERSION', '2.0.0');
define('PAGARME_GATEWAY_PATH', plugin_dir_path(__FILE__));
define('PAGARME_GATEWAY_URL', plugin_dir_url(__FILE__));
define('PAGARME_GATEWAY_BASENAME', plugin_basename(__FILE__));

// Carregar o novo sistema de webhook
require_once PAGARME_GATEWAY_PATH . 'includes/webhook-handler-fixed.php';
require_once PAGARME_GATEWAY_PATH . 'includes/webhook-diagnostic-tool.php';

// Desativar o handler antigo se existir
remove_action('woocommerce_api_pagarme_webhook', array($old_handler, 'handle_webhook'));

// Log de inicialização
error_log('========================================');
error_log('Pagar.me Gateway: Iniciando carregamento do plugin');
error_log('========================================');

// Verificar se WooCommerce está ativo
function pagarme_gateway_check_woocommerce() {
    if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>Gateway Pagar.me:</strong> WooCommerce deve estar ativo para usar este plugin.</p></div>';
        });
        return false;
    }
    return true;
}

if (!pagarme_gateway_check_woocommerce()) {
    return;
}

/**
 * Inicializar o plugin
 */
function init_pagarme_gateway() {
    error_log('Pagar.me Gateway: Função init_pagarme_gateway() chamada');
    
    // Verificar se WooCommerce está disponível
    if (!class_exists('WC_Payment_Gateway')) {
        error_log('Pagar.me Gateway: ERRO - WC_Payment_Gateway não encontrada');
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>Gateway Pagar.me:</strong> WooCommerce não está disponível.</p></div>';
        });
        return;
    }
    
    error_log('Pagar.me Gateway: WooCommerce detectado ');
    
    try {
        // Lista de arquivos para carregar
        $files_to_load = array(
            'includes/class-logger.php',
            'includes/class-data-formatter.php',
            'includes/class-api-client.php',
            'includes/class-rest-api.php',
            'includes/pagarme-gateway-complete-system.php',
            'includes/webhook-diagnostico.php'
        );
        
        // Carregar cada arquivo
        foreach ($files_to_load as $file) {
            $file_path = PAGARME_GATEWAY_PATH . $file;
            
            if (file_exists($file_path)) {
                require_once $file_path;
                error_log('Pagar.me Gateway: Carregado - ' . basename($file));
            } else {
                error_log('Pagar.me Gateway: NÃO ENCONTRADO - ' . $file);
            }
        }
        
        // Carregar e instanciar a classe admin
        if (is_admin()) {
            $admin_file = PAGARME_GATEWAY_PATH . 'admin/class-admin-settings.php';
            if (file_exists($admin_file)) {
                require_once $admin_file;
                error_log('Pagar.me Gateway: Admin carregado');
                
                // Instanciar a classe
                new \PagarMeGateway\Admin_Settings();
                error_log('Pagar.me Gateway: Admin_Settings INSTANCIADA');
            } else {
                error_log('Pagar.me Gateway: Arquivo admin não encontrado: ' . $admin_file);
            }
        }
        
        // Verificar se a classe principal existe
        if (!class_exists('PagarMeGateway\PagarMe_Gateway_Complete')) {
            error_log('Pagar.me Gateway: ERRO CRÍTICO - Classe PagarMe_Gateway_Complete não encontrada!');
            
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>Gateway Pagar.me:</strong> Erro ao carregar classe principal. ';
                echo 'Verifique se todos os arquivos estão na pasta includes/';
                echo '</p></div>';
            });
            return;
        }
        
        error_log('Pagar.me Gateway: Classe principal encontrada');
        
        // Registrar o gateway no WooCommerce
        add_filter('woocommerce_payment_gateways', 'pagarme_add_gateway_class');
        
        error_log('Pagar.me Gateway: Gateway registrado no WooCommerce');
        error_log('Pagar.me Gateway: Plugin inicializado com SUCESSO!');
        error_log('========================================');
        
    } catch (\Exception $e) {
        error_log('Pagar.me Gateway: EXCEÇÃO CAPTURADA: ' . $e->getMessage());
        error_log('Pagar.me Gateway: Stack trace: ' . $e->getTraceAsString());
        
        add_action('admin_notices', function() use ($e) {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>Gateway Pagar.me:</strong> Erro ao inicializar - ' . esc_html($e->getMessage());
            echo '</p></div>';
        });
    }
}

/**
 * Adicionar classe do gateway ao WooCommerce
 */
function pagarme_add_gateway_class($gateways) {
    error_log('Pagar.me Gateway: Função pagarme_add_gateway_class() chamada');
    error_log('Pagar.me Gateway: Gateways antes: ' . implode(', ', $gateways));
    
    $gateways[] = 'PagarMeGateway\PagarMe_Gateway_Complete';
    
    error_log('Pagar.me Gateway: Gateway PagarMe_Gateway_Complete adicionado à lista');
    error_log('Pagar.me Gateway: Gateways depois: ' . implode(', ', $gateways));
    
    return $gateways;
}

// Hook para inicializar quando plugins estiverem carregados
add_action('plugins_loaded', 'init_pagarme_gateway', 11);

// Log quando WooCommerce carrega os gateways
add_action('woocommerce_payment_gateways', function($gateways) {
    error_log('Pagar.me Gateway: WooCommerce está carregando gateways');
    error_log('Pagar.me Gateway: Gateways disponíveis: ' . print_r($gateways, true));
    return $gateways;
}, 999);

// HOOKS PARA INJEÇÃO DE BOLETO/PIX EM EMAILS

add_action('woocommerce_email_after_order_table', 'pagarme_add_boleto_to_email', 10, 4);

function pagarme_add_boleto_to_email($order, $sent_to_admin, $plain_text, $email) {
    if ($sent_to_admin) {
        return;
    }
    
    if ($order->get_payment_method() !== 'pagarme_gateway') {
        return;
    }
    
    // Evitar duplicidade se já estiver na página thankyou
    if (is_wc_endpoint_url('order-received')) {
        return;
    }
    
    $boleto_url = $order->get_meta('_pagarme_boleto_url');
    $boleto_pdf = $order->get_meta('_pagarme_boleto_pdf');
    $boleto_line = $order->get_meta('_pagarme_boleto_line');
    
    $pix_qr_code_url = $order->get_meta('_pagarme_pix_qr_code_url');
    $pix_line = $order->get_meta('_pagarme_pix_line');

    if (!$boleto_url && !$pix_line) {
        return;
    }
    
    if ($boleto_url) {
        ?>
        <div style="background: #FFF5F0; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #F05A01;">
            <h2 style="color: #333; font-size: 1.4em; margin: 0 0 15px 0;">Seu Boleto Bancário</h2>
            <p style="color: #666; margin-bottom: 15px;">Clique nos botões abaixo para visualizar ou baixar seu boleto:</p>
            
            <table style="margin: 15px 0;">
                <tr>
                    <td>
                        <a href="<?php echo esc_url($boleto_url); ?>" 
                           style="background: #F05A01; color: white; padding: 12px 20px; text-decoration: none; border-radius: 6px; display: inline-block; margin-right: 10px; font-weight: 600;">
                            📄 Ver Boleto
                        </a>
                        <a href="<?php echo esc_url($boleto_pdf); ?>" 
                           style="background: #28a745; color: white; padding: 12px 20px; text-decoration: none; border-radius: 6px; display: inline-block; font-weight: 600;">
                            📥 Baixar PDF
                        </a>
                    </td>
                </tr>
            </table>
            
            <?php if ($boleto_line): ?>
            <div style="background: white; padding: 15px; margin-top: 15px; border-radius: 6px; border: 1px dashed #FFB380;">
                <p style="margin: 0 0 8px 0; font-weight: 600; color: #333;">Linha Digitável:</p>
                <p style="font-family: monospace; font-size: 14px; word-break: break-all; margin: 0; color: #1f2937;">
                    <?php echo esc_html($boleto_line); ?>
                </p>
            </div>
            <?php endif; ?>
            
            <p style="color: #D84E01; margin-top: 15px; font-weight: 600;">
                ⏰ Atenção: Pague até a data de vencimento.
            </p>
        </div>
        <?php
    }
    
    if ($pix_qr_code_url) {
        ?>
        <div style="background: #E8F5E9; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #28a745;">
            <h2 style="color: #333; font-size: 1.4em; margin: 0 0 15px 0;">Pagamento via PIX</h2>
            <p style="text-align: center;">
                <img src="<?php echo esc_url($pix_qr_code_url); ?>" alt="QR Code PIX" style="max-width: 200px; height: auto;" />
            </p>
            <?php if ($pix_line): ?>
            <div style="background: white; padding: 15px; margin-top: 15px; border-radius: 6px; border: 1px dashed #81C784;">
                <p style="margin: 0 0 8px 0; font-weight: 600; color: #333;">Código PIX (Copia e Cola):</p>
                <p style="font-family: monospace; font-size: 12px; word-break: break-all; margin: 0; color: #1f2937;">
                    <?php echo esc_html($pix_line); ?>
                </p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

/**
 * Função de ativação do plugin
 */
function pagarme_gateway_activate() {
    error_log('Pagar.me Gateway: ATIVAÇÃO DO PLUGIN iniciada');
    
    // Verificar se WooCommerce está ativo
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            '<h1>Erro de Dependência</h1>' .
            '<p>Este plugin requer o WooCommerce para funcionar.</p>' .
            '<p><a href="' . admin_url('plugin-install.php?s=woocommerce&tab=search&type=term') . '">Instalar WooCommerce</a> | ' .
            '<a href="' . admin_url('plugins.php') . '">Voltar para Plugins</a></p>',
            'Erro',
            array('back_link' => true)
        );
    }
    
    global $wpdb;
    
    // Criar tabela de integração
    $table_name = $wpdb->prefix . 'pagarme_gateway_integration';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        order_id bigint(20) NOT NULL,
        pagarme_order_id varchar(100) NOT NULL,
        status varchar(50) NOT NULL,
        payment_method varchar(50) NOT NULL,
        amount decimal(10,2) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY order_id (order_id),
        KEY pagarme_order_id (pagarme_order_id),
        KEY status (status)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Criar tabela de logs
    $logs_table = $wpdb->prefix . 'pagarme_gateway_logs';
    
    $sql_logs = "CREATE TABLE IF NOT EXISTS $logs_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        level varchar(20) NOT NULL,
        message text NOT NULL,
        context text,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY level (level),
        KEY created_at (created_at)
    ) $charset_collate;";
    
    dbDelta($sql_logs);
    
    // Gerar chave API
    $api_key = get_option('pagarme_gateway_api_key');
    if (!$api_key) {
        $api_key = wp_generate_password(32, false);
        update_option('pagarme_gateway_api_key', $api_key);
        error_log('Pagar.me Gateway: Chave API gerada');
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
    
    error_log('Pagar.me Gateway: ATIVAÇÃO concluída com SUCESSO!');
}

/**
 * Função de desativação do plugin
 */
function pagarme_gateway_deactivate() {
    error_log('Pagar.me Gateway: Desativando plugin');
    
    // Limpar jobs agendados
    wp_clear_scheduled_hook('pagarme_gateway_cleanup');
    
    // Flush rewrite rules
    flush_rewrite_rules();
    
    error_log('Pagar.me Gateway: Plugin desativado');
}

// Registrar hooks de ativação/desativação
register_activation_hook(__FILE__, 'pagarme_gateway_activate');
register_deactivation_hook(__FILE__, 'pagarme_gateway_deactivate');

// Adicionar links de ação na página de plugins
add_filter('plugin_action_links_' . PAGARME_GATEWAY_BASENAME, function($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=pagarme_gateway') . '">Configurações</a>';
    array_unshift($links, $settings_link);
    return $links;
});

// Adicionar meta links na página de plugins
add_filter('plugin_row_meta', function($links, $file) {
    if ($file == PAGARME_GATEWAY_BASENAME) {
        $links[] = '<a href="https://docs.pagar.me" target="_blank">Documentação</a>';
        $links[] = '<a href="mailto:pessoa3@example.com">📧 Suporte</a>';
    }
    return $links;
}, 10, 2);

/**
 * REMOVIDO: FALLBACK GLOBAL (PARA EVITAR DUPLICAÇÃO) 
 */
// add_action('woocommerce_thankyou', 'pagarme_force_display_boleto_pix', 30, 1);

// function pagarme_force_display_boleto_pix($order_id) { ... } 


// CUSTOMIZAR TÍTULO DO MÉTODO DE PAGAMENTO

add_filter('woocommerce_gateway_title', 'pagarme_customize_payment_method_title', 10, 2);

function pagarme_customize_payment_method_title($title, $gateway_id) {
    // Só aplicar para nosso gateway
    if (!in_array($gateway_id, array('pagarme_gateway', 'pagarme', 'pagarme_complete'))) {
        return $title;
    }
    
    // Pegar o pedido atual
    global $wp;
    $order_id = 0;
    
    if (is_wc_endpoint_url('order-received')) {
        $order_id = absint($wp->query_vars['order-received']);
    } elseif (is_wc_endpoint_url('view-order')) {
        $order_id = absint($wp->query_vars['view-order']);
    } elseif (isset($_GET['order_id'])) {
        $order_id = absint($_GET['order_id']);
    }
    
    if (!$order_id) {
        return $title;
    }
    
    $order = wc_get_order($order_id);
    if (!$order) {
        return $title;
    }
    
    // Verificar qual método foi usado baseado nos meta dados
    $boleto_url = $order->get_meta('_pagarme_boleto_url', true);
    $pix_qr_code = $order->get_meta('_pagarme_pix_qr_code_url', true);
    $payment_methods = $order->get_meta('_pagarme_payment_methods', true);
    
    // Se for multipagamento
    if ($payment_methods) {
        $methods_json = json_decode($payment_methods, true);
        if (is_array($methods_json) && count($methods_json) > 1) {
            $method_names = array();
            foreach ($methods_json as $method => $amount) {
                $method_names[] = pagarme_get_method_name($method);
            }
            return 'Multipagamento: ' . implode(' + ', $method_names);
        }
    }
    
    // Pagamento único
    if ($boleto_url) {
        return '💰 Boleto Bancário';
    }
    
    if ($pix_qr_code) {
        return '💚 PIX';
    }
    
    // Tentar identificar pelo meta
    $payment_method = $order->get_meta('_pagarme_payment_method', true);
    if ($payment_method) {
        return pagarme_get_method_name($payment_method);
    }
    
    return $title;
}

/**
 * Helper: Converter código do método para nome amigável
 */
function pagarme_get_method_name($method) {
    $names = array(
        'credit_card' => '💳 Cartão de Crédito',
        'debit_card' => '💳 Cartão de Débito',
        'boleto' => '💰 Boleto Bancário',
        'pix' => '💚 PIX',
        'voucher' => '🎫 Voucher',
    );
    
    return isset($names[$method]) ? $names[$method] : ucfirst(str_replace('_', ' ', $method));
}
