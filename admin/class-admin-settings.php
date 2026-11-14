<?php
/**
 * Painel Administrativo Completo - COM MAPEAMENTOS DE TIPO DE NEGOCIAÇÃO
 * 
 * IMPLEMENTAÇÕES:
 * - Mapeamento por método de pagamento (Cartão/Boleto/PIX)
 * - Mapeamento por número de parcelas (1x até 12x)
 * - Teste de conexão corrigido
 * - Interface intuitiva para configuração
 */

namespace PagarMeGateway;

if (!defined('ABSPATH')) {
    exit;
}

class Admin_Settings {
    
    private $logger;
    
    public function __construct() {
        $this->logger = new Logger();
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_test_pagarme_connection', array($this, 'test_pagarme_connection'));
        add_action('wp_ajax_save_negotiation_mapping', array($this, 'save_negotiation_mapping'));
        add_action('wp_ajax_get_negotiation_mapping', array($this, 'get_negotiation_mapping'));
        add_action('wp_ajax_delete_negotiation_mapping', array($this, 'delete_negotiation_mapping'));
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Gateway Pagar.me',
            'Gateway Pagar.me',
            'manage_woocommerce',
            'pagarme-gateway-settings',
            array($this, 'admin_page')
        );
    }
    
    public function register_settings() {
        register_setting('pagarme_gateway_settings', 'pagarme_gateway_negotiation_mappings');
    }
    
    public function admin_page() {
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'connection';
        ?>
        <div class="wrap pagarme-gateway-admin">
            <h1>
                <span class="dashicons dashicons-admin-settings"></span>
                Configurações Gateway Pagar.me
            </h1>
            
            <nav class="nav-tab-wrapper">
                <a href="?page=pagarme-gateway-settings&tab=connection" 
                   class="nav-tab <?php echo $current_tab === 'connection' ? 'nav-tab-active' : ''; ?>">
                    🔌 Conexão
                </a>
                <a href="?page=pagarme-gateway-settings&tab=negotiation" 
                   class="nav-tab <?php echo $current_tab === 'negotiation' ? 'nav-tab-active' : ''; ?>">
                    📋 Tipos de Negociação
                </a>
                <a href="?page=pagarme-gateway-settings&tab=logs" 
                   class="nav-tab <?php echo $current_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    Logs
                </a>
            </nav>
            
            <div class="tab-content">
                <?php
                switch ($current_tab) {
                    case 'connection':
                        $this->render_connection_tab();
                        break;
                    case 'negotiation':
                        $this->render_negotiation_tab();
                        break;
                    case 'logs':
                        $this->render_logs_tab();
                        break;
                }
                ?>
            </div>
        </div>
        
        <style>
        .pagarme-gateway-admin {
            margin: 20px 20px 0 0;
        }
        
        .pagarme-gateway-admin h1 {
            margin-bottom: 20px;
        }
        
        .pagarme-gateway-admin h1 .dashicons {
            font-size: 32px;
            width: 32px;
            height: 32px;
            vertical-align: middle;
        }
        
        .tab-content {
            background: white;
            padding: 20px;
            border: 1px solid #ccd0d4;
            border-top: none;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        
        .section-card {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
        }
        
        .section-card h3 {
            margin-top: 0;
            padding-bottom: 10px;
            border-bottom: 2px solid #2271b1;
        }
        
        .form-table th {
            font-weight: 600;
        }
        
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }
        
        .status-ok { background: #46b450; }
        .status-error { background: #dc3232; }
        .status-warning { background: #f0a020; }
        
        .mapping-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .mapping-table th,
        .mapping-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .mapping-table th {
            background: #f0f0f0;
            font-weight: 600;
        }
        
        .mapping-table tr:hover {
            background: #f9f9f9;
        }
        
        .button-delete {
            color: #dc3232;
            border-color: #dc3232;
        }
        
        .button-delete:hover {
            background: #dc3232;
            color: white;
        }
        
        .notice-inline {
            padding: 12px;
            margin: 15px 0;
            border-left: 4px solid #2271b1;
            background: #f0f6fc;
        }
        
        .test-result {
            margin-top: 15px;
            padding: 15px;
            border-radius: 4px;
            display: none;
        }
        
        .test-result.success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        
        .test-result.error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        .code-box {
            background: #f5f5f5;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: monospace;
            font-size: 13px;
        }
        </style>
        <?php
    }
    
    /**
     * ABA DE CONEXÃO (CORRIGIDA)
     */
    private function render_connection_tab() {
        $gateway = WC()->payment_gateways()->payment_gateways()['pagarme_gateway'] ?? null;
        $api_key = $gateway ? $gateway->get_option('api_key') : '';
        $environment = $gateway ? $gateway->get_option('environment', 'sandbox') : 'sandbox';
        ?>
        
        <div class="section-card">
            <h3>🔌 Teste de Conexão com Pagar.me</h3>
            
            <div class="notice-inline">
                <p><strong>ℹ️ Informação:</strong> Esta página testa a conexão com a API do Pagar.me usando as credenciais configuradas no gateway de pagamento.</p>
                <p>Configure a API Key em: <strong>WooCommerce → Configurações → Pagamentos → Pagar.me</strong></p>
            </div>
            
            <table class="form-table">
                <tr>
                    <th>API Key Configurada:</th>
                    <td>
                        <?php if ($api_key): ?>
                            <code><?php echo substr($api_key, 0, 15) . '...' . substr($api_key, -5); ?></code>
                            <span class="status-indicator status-ok"></span>
                            <span style="color: #46b450;">Configurada</span>
                        <?php else: ?>
                            <span class="status-indicator status-error"></span>
                            <span style="color: #dc3232;">Não configurada</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Ambiente:</th>
                    <td>
                        <strong><?php echo $environment === 'sandbox' ? 'Sandbox (Testes)' : 'Produção'; ?></strong>
                    </td>
                </tr>
                <tr>
                    <th>Endpoint da API:</th>
                    <td><code class="code-box">https://api.pagar.me/core/v5</code></td>
                </tr>
            </table>
            
            <p>
                <button type="button" id="test-connection-btn" class="button button-primary button-large" <?php echo !$api_key ? 'disabled' : ''; ?>>
                    🔍 Testar Conexão Agora
                </button>
            </p>
            
            <div id="test-result" class="test-result"></div>
        </div>
        
        <div class="section-card">
            <h3>📊 Informações do Sistema</h3>
            <table class="form-table">
                <tr>
                    <th>WordPress:</th>
                    <td><?php echo get_bloginfo('version'); ?></td>
                </tr>
                <tr>
                    <th>WooCommerce:</th>
                    <td><?php echo defined('WC_VERSION') ? WC_VERSION : 'Não instalado'; ?></td>
                </tr>
                <tr>
                    <th>PHP:</th>
                    <td><?php echo PHP_VERSION; ?></td>
                </tr>
                <tr>
                    <th>Plugin:</th>
                    <td><?php echo PAGARME_GATEWAY_VERSION; ?></td>
                </tr>
            </table>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#test-connection-btn').on('click', function() {
                var $btn = $(this);
                var $result = $('#test-result');
                var originalText = $btn.html();
                
                $btn.prop('disabled', true).html('⏳ Testando conexão...');
                $result.hide().removeClass('success error');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'test_pagarme_connection',
                        nonce: '<?php echo wp_create_nonce('pagarme_admin_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $result.addClass('success').html('' + response.data.message).fadeIn();
                        } else {
                            $result.addClass('error').html('❌ ' + response.data.message).fadeIn();
                        }
                    },
                    error: function(xhr) {
                        $result.addClass('error').html('❌ Erro na requisição: ' + xhr.statusText).fadeIn();
                    },
                    complete: function() {
                        $btn.prop('disabled', false).html(originalText);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * ABA DE TIPOS DE NEGOCIAÇÃO
     */
    private function render_negotiation_tab() {
        $mappings = get_option('pagarme_gateway_negotiation_mappings', array());
        ?>
        
        <div class="section-card">
            <h3>📋 Configurar Tipos de Negociação por Método e Parcelas</h3>
            
            <div class="notice-inline">
                <p><strong>ℹ️ Como funciona:</strong></p>
                <ul style="margin: 10px 0 0 20px;">
                    <li>Configure um código de tipo de negociação para cada <strong>método de pagamento</strong> e <strong>número de parcelas</strong></li>
                    <li>Exemplo: Cartão 1x = Tipo 763, Cartão 3x = Tipo 764, Boleto = Tipo 765</li>
                    <li>Estes códigos serão enviados para o Sankhya durante a integração</li>
                </ul>
            </div>
            
            <form id="add-mapping-form" style="margin-top: 20px;">
                <table class="form-table">
                    <tr>
                        <th><label for="payment_method">Método de Pagamento:</label></th>
                        <td>
                            <select id="payment_method" name="payment_method" required style="width: 300px;">
                                <option value="">Selecione...</option>
                                <option value="credit_card">💳 Cartão de Crédito</option>
                                <option value="boleto">📄 Boleto Bancário</option>
                                <option value="pix">PIX</option>
                            </select>
                        </td>
                    </tr>
                    <tr id="installments-row" style="display: none;">
                        <th><label for="installments">Número de Parcelas:</label></th>
                        <td>
                            <select id="installments" name="installments" style="width: 300px;">
                                <option value="1">1x (À vista)</option>
                                <option value="2">2x</option>
                                <option value="3">3x</option>
                                <option value="4">4x</option>
                                <option value="5">5x</option>
                                <option value="6">6x</option>
                                <option value="7">7x</option>
                                <option value="8">8x</option>
                                <option value="9">9x</option>
                                <option value="10">10x</option>
                                <option value="11">11x</option>
                                <option value="12">12x</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="negotiation_type">Tipo de Negociação (Sankhya):</label></th>
                        <td>
                            <input type="text" id="negotiation_type" name="negotiation_type" 
                                   placeholder="Ex: 763" required style="width: 300px;" />
                            <p class="description">Código do tipo de negociação no sistema Sankhya</p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" class="button button-primary">
                        ➕ Adicionar Mapeamento
                    </button>
                </p>
            </form>
        </div>
        
        <div class="section-card">
            <h3>📊 Mapeamentos Configurados</h3>
            
            <?php if (empty($mappings)): ?>
                <p style="color: #666; font-style: italic;">Nenhum mapeamento configurado ainda.</p>
            <?php else: ?>
                <table class="mapping-table">
                    <thead>
                        <tr>
                            <th>Método de Pagamento</th>
                            <th>Parcelas</th>
                            <th>Tipo de Negociação</th>
                            <th style="width: 100px;">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="mappings-tbody">
                        <?php foreach ($mappings as $key => $mapping): ?>
                            <tr data-key="<?php echo esc_attr($key); ?>">
                                <td>
                                    <?php 
                                    $icons = array(
                                        'credit_card' => '💳 Cartão de Crédito',
                                        'boleto' => '📄 Boleto',
                                        'pix' => 'PIX'
                                    );
                                    echo $icons[$mapping['payment_method']] ?? $mapping['payment_method'];
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    if ($mapping['payment_method'] === 'credit_card') {
                                        echo $mapping['installments'] . 'x';
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td><code><?php echo esc_html($mapping['negotiation_type']); ?></code></td>
                                <td>
                                    <button type="button" class="button button-small button-delete delete-mapping" 
                                            data-key="<?php echo esc_attr($key); ?>">
                                        🗑️ Remover
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Mostrar/ocultar campo de parcelas
            $('#payment_method').on('change', function() {
                if ($(this).val() === 'credit_card') {
                    $('#installments-row').show();
                    $('#installments').prop('required', true);
                } else {
                    $('#installments-row').hide();
                    $('#installments').prop('required', false);
                }
            });
            
            // Adicionar mapeamento
            $('#add-mapping-form').on('submit', function(e) {
                e.preventDefault();
                
                var $form = $(this);
                var paymentMethod = $('#payment_method').val();
                var installments = paymentMethod === 'credit_card' ? $('#installments').val() : '0';
                var negotiationType = $('#negotiation_type').val();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'save_negotiation_mapping',
                        nonce: '<?php echo wp_create_nonce('pagarme_admin_nonce'); ?>',
                        payment_method: paymentMethod,
                        installments: installments,
                        negotiation_type: negotiationType
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Erro: ' + response.data.message);
                        }
                    },
                    error: function() {
                        alert('Erro ao salvar mapeamento');
                    }
                });
            });
            
            // Remover mapeamento
            $(document).on('click', '.delete-mapping', function() {
                if (!confirm('Tem certeza que deseja remover este mapeamento?')) {
                    return;
                }
                
                var key = $(this).data('key');
                var $row = $(this).closest('tr');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'delete_negotiation_mapping',
                        nonce: '<?php echo wp_create_nonce('pagarme_admin_nonce'); ?>',
                        key: key
                    },
                    success: function(response) {
                        if (response.success) {
                            $row.fadeOut(function() {
                                $(this).remove();
                                if ($('#mappings-tbody tr').length === 0) {
                                    location.reload();
                                }
                            });
                        } else {
                            alert('Erro: ' + response.data.message);
                        }
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * ABA DE LOGS
     */
    private function render_logs_tab() {
        $log_file = WP_CONTENT_DIR . '/uploads/pagarme-gateway-logs/pagarme-gateway-' . date('Y-m-d') . '.log';
        $logs_exist = file_exists($log_file);
        $log_content = $logs_exist ? file_get_contents($log_file) : '';
        $log_lines = $logs_exist ? array_slice(explode("\n", $log_content), -50) : array();
        ?>
        
        <div class="section-card">
            <h3>Logs do Sistema</h3>
            
            <p>
                <strong>Arquivo de log de hoje:</strong> 
                <code><?php echo basename($log_file); ?></code>
            </p>
            
            <?php if ($logs_exist): ?>
                <div style="background: #f5f5f5; padding: 15px; border: 1px solid #ddd; border-radius: 4px; max-height: 500px; overflow-y: scroll; font-family: monospace; font-size: 12px; white-space: pre-wrap;">
<?php echo esc_html(implode("\n", array_reverse($log_lines))); ?>
                </div>
                
                <p style="margin-top: 15px;">
                    <a href="<?php echo WP_CONTENT_URL . '/uploads/pagarme-gateway-logs/pagarme-gateway-' . date('Y-m-d') . '.log'; ?>" 
                       class="button" download>
                        Baixar Log Completo
                    </a>
                </p>
            <?php else: ?>
                <div class="notice-inline">
                    <p>Nenhum log registrado hoje.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * AJAX: TESTAR CONEXÃO
     */
    public function test_pagarme_connection() {
        check_ajax_referer('pagarme_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permissão negada'));
        }
        
        // Obter gateway
        $gateway = WC()->payment_gateways()->payment_gateways()['pagarme_gateway'] ?? null;
        
        if (!$gateway) {
            wp_send_json_error(array('message' => 'Gateway não encontrado'));
        }
        
        $api_key = $gateway->get_option('api_key');
        
        if (empty($api_key)) {
            wp_send_json_error(array('message' => 'API Key não configurada'));
        }
        
        // Fazer requisição de teste
        $auth = base64_encode($api_key . ':');
        
        $response = wp_remote_get('https://api.pagar.me/core/v5/customers', array(
            'timeout' => 10,
            'headers' => array(
                'Authorization' => 'Basic ' . $auth,
                'Content-Type' => 'application/json'
            )
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error(array(
                'message' => 'Erro de conexão: ' . $response->get_error_message()
            ));
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code === 200) {
            wp_send_json_success(array(
                'message' => 'Conexão estabelecida com sucesso! API Key válida e funcionando.'
            ));
        } else if ($status_code === 401) {
            wp_send_json_error(array(
                'message' => 'Autenticação falhou. Verifique se a API Key está correta.'
            ));
        } else {
            $body = wp_remote_retrieve_body($response);
            wp_send_json_error(array(
                'message' => 'Erro na API: ' . $status_code . ' - ' . $body
            ));
        }
    }
    
    /**
     * AJAX: SALVAR MAPEAMENTO
     */
    public function save_negotiation_mapping() {
        check_ajax_referer('pagarme_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permissão negada'));
        }
        
        $payment_method = sanitize_text_field($_POST['payment_method']);
        $installments = intval($_POST['installments']);
        $negotiation_type = sanitize_text_field($_POST['negotiation_type']);
        
        if (empty($payment_method) || empty($negotiation_type)) {
            wp_send_json_error(array('message' => 'Dados incompletos'));
        }
        
        $mappings = get_option('pagarme_gateway_negotiation_mappings', array());
        
        // Criar chave única
        $key = $payment_method . '_' . $installments;
        
        $mappings[$key] = array(
            'payment_method' => $payment_method,
            'installments' => $installments,
            'negotiation_type' => $negotiation_type
        );
        
        update_option('pagarme_gateway_negotiation_mappings', $mappings);
        
        wp_send_json_success(array('message' => 'Mapeamento salvo com sucesso!'));
    }
    
    /**
     * AJAX: DELETAR MAPEAMENTO
     */
    public function delete_negotiation_mapping() {
        check_ajax_referer('pagarme_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permissão negada'));
        }
        
        $key = sanitize_text_field($_POST['key']);
        $mappings = get_option('pagarme_gateway_negotiation_mappings', array());
        
        if (isset($mappings[$key])) {
            unset($mappings[$key]);
            update_option('pagarme_gateway_negotiation_mappings', $mappings);
            wp_send_json_success(array('message' => 'Mapeamento removido'));
        } else {
            wp_send_json_error(array('message' => 'Mapeamento não encontrado'));
        }
    }
    
    /**
     * ENQUEUE SCRIPTS
     */
    public function enqueue_admin_scripts($hook) {
        if ('woocommerce_page_pagarme-gateway-settings' !== $hook) {
            return;
        }
        
        wp_enqueue_style('pagarme-admin', PAGARME_GATEWAY_URL . 'assets/css/admin-style.css', array(), PAGARME_GATEWAY_VERSION);
    }
}