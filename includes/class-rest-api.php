<?php
/**
 * API REST para integração Railway
 */

namespace PagarMeGateway;

if (!defined('ABSPATH')) {
    exit;
}

class Rest_API {
    
    private $logger;
    private $namespace = 'pagarme-gateway/v1';
    
    public function __construct($logger) {
        $this->logger = $logger;
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    public function register_routes() {
        // Buscar pedidos pendentes de integração
        register_rest_route($this->namespace, '/orders/pending', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_pending_orders'),
            'permission_callback' => array($this, 'check_api_permission'),
            'args' => array(
                'limit' => array(
                    'default' => 50,
                    'sanitize_callback' => 'absint'
                ),
                'offset' => array(
                    'default' => 0,
                    'sanitize_callback' => 'absint'
                )
            )
        ));
        
        // Buscar dados de um pedido específico
        register_rest_route($this->namespace, '/orders/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_order_data'),
            'permission_callback' => array($this, 'check_api_permission'),
            'args' => array(
                'id' => array(
                    'validate_callback' => function($param, $request, $key) {
                        return is_numeric($param);
                    }
                )
            )
        ));
        
        // Marcar pedido como processado pela integração
        register_rest_route($this->namespace, '/orders/(?P<id>\d+)/processed', array(
            'methods' => 'POST',
            'callback' => array($this, 'mark_order_processed'),
            'permission_callback' => array($this, 'check_api_permission'),
            'args' => array(
                'id' => array(
                    'validate_callback' => function($param, $request, $key) {
                        return is_numeric($param);
                    }
                ),
                'sankhya_order_id' => array(
                    'required' => false,
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'status' => array(
                    'default' => 'processed',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'error_message' => array(
                    'required' => false,
                    'sanitize_callback' => 'sanitize_text_field'
                )
            )
        ));
        
        // Buscar lote de pedidos com filtros
        register_rest_route($this->namespace, '/orders/batch', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_orders_batch'),
            'permission_callback' => array($this, 'check_api_permission'),
            'args' => array(
                'start_date' => array(
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'end_date' => array(
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'status' => array(
                    'default' => 'any',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'processed' => array(
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'limit' => array(
                    'default' => 100,
                    'sanitize_callback' => 'absint'
                )
            )
        ));
        
        // Status da integração
        register_rest_route($this->namespace, '/status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_integration_status'),
            'permission_callback' => array($this, 'check_api_permission')
        ));
        
        // Reprocessar pedido com erro
        register_rest_route($this->namespace, '/orders/(?P<id>\d+)/retry', array(
            'methods' => 'POST',
            'callback' => array($this, 'retry_order_integration'),
            'permission_callback' => array($this, 'check_api_permission'),
            'args' => array(
                'id' => array(
                    'validate_callback' => function($param, $request, $key) {
                        return is_numeric($param);
                    }
                )
            )
        ));
    }
    
    public function check_api_permission($request) {
        $api_key = $request->get_header('X-API-Key');
        
        if (empty($api_key)) {
            return new \WP_Error('rest_forbidden', 'Chave API obrigatória', array('status' => 401));
        }
        
        $stored_key = get_option('pagarme_gateway_api_key', '');
        
        if (empty($stored_key) || !hash_equals($stored_key, $api_key)) {
            $this->logger->warning('Tentativa de acesso com chave API inválida', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
            return new \WP_Error('rest_forbidden', 'Chave API inválida', array('status' => 401));
        }
        
        return true;
    }
    
    public function get_pending_orders($request) {
        $limit = $request->get_param('limit');
        $offset = $request->get_param('offset');
        
        // Buscar pedidos pagos não processados pela integração
        $args = array(
            'status' => array('processing', 'completed'),
            'payment_method' => 'pagarme_gateway',
            'limit' => $limit,
            'offset' => $offset,
            'orderby' => 'date',
            'order' => 'ASC',
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_pagarme_order_id',
                    'compare' => 'EXISTS'
                ),
                array(
                    'key' => '_integration_processed',
                    'compare' => 'NOT EXISTS'
                )
            )
        );
        
        $orders = wc_get_orders($args);
        $formatted_orders = array();
        
        foreach ($orders as $order) {
            $integration_data = $order->get_meta('_integration_data');
            
            if ($integration_data) {
                $formatted_orders[] = $this->format_order_for_api($order, $integration_data);
            }
        }
        
        $this->logger->info('API: Pedidos pendentes solicitados', [
            'count' => count($formatted_orders),
            'limit' => $limit,
            'offset' => $offset
        ]);
        
        return rest_ensure_response(array(
            'success' => true,
            'orders' => $formatted_orders,
            'total' => count($formatted_orders),
            'has_more' => count($formatted_orders) === $limit,
            'pagination' => array(
                'limit' => $limit,
                'offset' => $offset,
                'next_offset' => count($formatted_orders) === $limit ? $offset + $limit : null
            )
        ));
    }
    
    public function get_order_data($request) {
        $order_id = $request['id'];
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new \WP_Error('order_not_found', 'Pedido não encontrado', array('status' => 404));
        }
        
        $integration_data = $order->get_meta('_integration_data');
        
        if (!$integration_data) {
            return new \WP_Error('no_integration_data', 'Dados de integração não encontrados para este pedido', array('status' => 404));
        }
        
        $formatted_order = $this->format_order_for_api($order, $integration_data);
        
        $this->logger->info('API: Dados do pedido solicitados', [
            'wc_order_id' => $order_id,
            'pagarme_order_id' => $integration_data['pagarme_order_id'] ?? null
        ]);
        
        return rest_ensure_response(array(
            'success' => true,
            'order' => $formatted_order
        ));
    }
    
    public function mark_order_processed($request) {
        $order_id = $request['id'];
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new \WP_Error('order_not_found', 'Pedido não encontrado', array('status' => 404));
        }
        
        $body = $request->get_json_params();
        $sankhya_order_id = $body['sankhya_order_id'] ?? null;
        $status = $body['status'] ?? 'processed';
        $error_message = $body['error_message'] ?? null;
        
        // Marcar como processado
        $order->update_meta_data('_integration_processed', true);
        $order->update_meta_data('_integration_processed_at', current_time('mysql'));
        $order->update_meta_data('_integration_status', $status);
        
        if ($sankhya_order_id) {
            $order->update_meta_data('_sankhya_order_id', $sankhya_order_id);
            $order->add_order_note("Integrado com Sankhya - ID: {$sankhya_order_id}");
        }
        
        if ($error_message) {
            $order->update_meta_data('_integration_error', $error_message);
            $order->add_order_note("Erro na integração: {$error_message}");
        }
        
        $order->save();
        
        // Atualizar tabela de integração
        $this->update_integration_queue_status($order_id, $status, $sankhya_order_id, $error_message);
        
        $this->logger->info('API: Pedido marcado como processado', [
            'wc_order_id' => $order_id,
            'sankhya_order_id' => $sankhya_order_id,
            'status' => $status,
            'has_error' => !empty($error_message)
        ]);
        
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Pedido marcado como processado',
            'data' => array(
                'wc_order_id' => $order_id,
                'sankhya_order_id' => $sankhya_order_id,
                'status' => $status,
                'processed_at' => current_time('mysql')
            )
        ));
    }
    
    public function get_orders_batch($request) {
        $start_date = $request->get_param('start_date');
        $end_date = $request->get_param('end_date');
        $status = $request->get_param('status');
        $processed = $request->get_param('processed');
        $limit = $request->get_param('limit');
        
        $args = array(
            'payment_method' => 'pagarme_gateway',
            'limit' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => array()
        );
        
        // Filtro por data
        if ($start_date && $end_date) {
            $args['date_created'] = $start_date . '...' . $end_date;
        }
        
        // Filtro por status
        if ($status && $status !== 'any') {
            $args['status'] = $status;
        }
        
        // Filtro por processamento
        if ($processed !== null) {
            if ($processed === 'true') {
                $args['meta_query'][] = array(
                    'key' => '_integration_processed',
                    'value' => true,
                    'compare' => '='
                );
            } else {
                $args['meta_query'][] = array(
                    'key' => '_integration_processed',
                    'compare' => 'NOT EXISTS'
                );
            }
        }
        
        // Garantir que tem dados de integração
        $args['meta_query'][] = array(
            'key' => '_pagarme_order_id',
            'compare' => 'EXISTS'
        );
        
        $orders = wc_get_orders($args);
        $formatted_orders = array();
        
        foreach ($orders as $order) {
            $integration_data = $order->get_meta('_integration_data');
            if ($integration_data) {
                $formatted_orders[] = $this->format_order_for_api($order, $integration_data);
            }
        }
        
        $this->logger->info('API: Lote de pedidos solicitado', [
            'count' => count($formatted_orders),
            'filters' => compact('start_date', 'end_date', 'status', 'processed', 'limit')
        ]);
        
        return rest_ensure_response(array(
            'success' => true,
            'orders' => $formatted_orders,
            'total' => count($formatted_orders),
            'filters_applied' => array(
                'start_date' => $start_date,
                'end_date' => $end_date,
                'status' => $status,
                'processed' => $processed,
                'limit' => $limit
            )
        ));
    }
    
    public function get_integration_status($request) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pagarme_integration_queue';
        
        // Estatísticas da fila de integração
        $stats = $wpdb->get_results("
            SELECT 
                status,
                COUNT(*) as count
            FROM $table_name 
            GROUP BY status
        ", ARRAY_A);
        
        $status_counts = array(
            'pending' => 0,
            'processed' => 0,
            'failed' => 0,
            'error' => 0
        );
        
        foreach ($stats as $stat) {
            $status_counts[$stat['status']] = intval($stat['count']);
        }
        
        // Estatísticas dos últimos 30 dias
        $thirty_days_ago = date('Y-m-d H:i:s', strtotime('-30 days'));
        $recent_stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(*) as total_orders,
                SUM(CASE WHEN status = 'processed' THEN 1 ELSE 0 END) as processed_orders,
                SUM(CASE WHEN status IN ('failed', 'error') THEN 1 ELSE 0 END) as failed_orders
            FROM $table_name 
            WHERE created_at >= %s
        ", $thirty_days_ago), ARRAY_A);
        
        $success_rate = 0;
        if ($recent_stats['total_orders'] > 0) {
            $success_rate = round(($recent_stats['processed_orders'] / $recent_stats['total_orders']) * 100, 2);
        }
        
        // Últimas transações
        $recent_orders = $wpdb->get_results($wpdb->prepare("
            SELECT 
                wc_order_id,
                pagarme_order_id,
                status,
                created_at,
                processed_at
            FROM $table_name 
            ORDER BY created_at DESC 
            LIMIT %d
        ", 10), ARRAY_A);
        
        return rest_ensure_response(array(
            'success' => true,
            'status' => array(
                'queue_counts' => $status_counts,
                'last_30_days' => array(
                    'total_orders' => intval($recent_stats['total_orders']),
                    'processed_orders' => intval($recent_stats['processed_orders']),
                    'failed_orders' => intval($recent_stats['failed_orders']),
                    'success_rate' => $success_rate
                ),
                'recent_transactions' => $recent_orders,
                'system_info' => array(
                    'plugin_version' => PAGARME_GATEWAY_VERSION,
                    'wordpress_version' => get_bloginfo('version'),
                    'woocommerce_version' => WC()->version,
                    'timezone' => wp_timezone_string(),
                    'current_time' => current_time('mysql')
                )
            )
        ));
    }
    
    public function retry_order_integration($request) {
        $order_id = $request['id'];
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new \WP_Error('order_not_found', 'Pedido não encontrado', array('status' => 404));
        }
        
        // Remover marcação de processado para permitir reprocessamento
        $order->delete_meta_data('_integration_processed');
        $order->delete_meta_data('_integration_error');
        $order->update_meta_data('_integration_retry_at', current_time('mysql'));
        $order->save();
        
        // Resetar status na fila
        global $wpdb;
        $table_name = $wpdb->prefix . 'pagarme_integration_queue';
        
        $wpdb->update(
            $table_name,
            array(
                'status' => 'pending',
                'attempts' => 0
            ),
            array('wc_order_id' => $order_id),
            array('%s', '%d'),
            array('%d')
        );
        
        $order->add_order_note('Pedido marcado para reprocessamento na integração');
        
        $this->logger->info('API: Pedido marcado para retry', [
            'wc_order_id' => $order_id
        ]);
        
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Pedido marcado para reprocessamento',
            'data' => array(
                'wc_order_id' => $order_id,
                'retry_at' => current_time('mysql')
            )
        ));
    }
    
    private function format_order_for_api($order, $integration_data) {
        return array(
            'wc_order_id' => $order->get_id(),
            'wc_order_number' => $order->get_order_number(),
            'wc_order_key' => $order->get_order_key(),
            'pagarme_order_id' => $integration_data['pagarme_order_id'],
            'pagarme_status' => $order->get_meta('_pagarme_status'),
            'sankhya_data' => array(
                'company_code' => $integration_data['company_code'],
                'negotiation_type' => $integration_data['negotiation_type'],
                'customer' => $integration_data['customer_data'],
                'order_data' => array(
                    'total' => $integration_data['order_data']['total'],
                    'date' => $integration_data['order_data']['date'],
                    'payment_method' => $integration_data['order_data']['payment_method']
                ),
                'items' => $integration_data['items']
            ),
            'billing_address' => array(
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'company' => $order->get_billing_company(),
                'address_1' => $order->get_billing_address_1(),
                'address_2' => $order->get_billing_address_2(),
                'city' => $order->get_billing_city(),
                'state' => $order->get_billing_state(),
                'postcode' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country(),
                'phone' => $order->get_billing_phone(),
                'email' => $order->get_billing_email()
            ),
            'shipping_address' => array(
                'first_name' => $order->get_shipping_first_name(),
                'last_name' => $order->get_shipping_last_name(),
                'company' => $order->get_shipping_company(),
                'address_1' => $order->get_shipping_address_1(),
                'address_2' => $order->get_shipping_address_2(),
                'city' => $order->get_shipping_city(),
                'state' => $order->get_shipping_state(),
                'postcode' => $order->get_shipping_postcode(),
                'country' => $order->get_shipping_country()
            ),
            'status' => $order->get_status(),
            'currency' => $order->get_currency(),
            'created_at' => $integration_data['created_at'],
            'processed' => $order->get_meta('_integration_processed') ? true : false,
            'processed_at' => $order->get_meta('_integration_processed_at'),
            'sankhya_order_id' => $order->get_meta('_sankhya_order_id'),
            'integration_status' => $order->get_meta('_integration_status'),
            'integration_error' => $order->get_meta('_integration_error'),
            'last_updated' => $integration_data['last_updated'] ?? $integration_data['created_at']
        );
    }
    
    private function update_integration_queue_status($wc_order_id, $status, $sankhya_order_id = null, $error_message = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pagarme_integration_queue';
        
        $update_data = array(
            'status' => $status,
            'processed_at' => current_time('mysql')
        );
        
        if ($error_message) {
            $update_data['error_message'] = $error_message;
        }
        
        $wpdb->update(
            $table_name,
            $update_data,
            array('wc_order_id' => $wc_order_id),
            array('%s', '%s'),
            array('%d')
        );
    }
}