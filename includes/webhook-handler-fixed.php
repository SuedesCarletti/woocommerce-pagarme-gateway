<?php
/**
 * SISTEMA COMPLETO DE WEBHOOK HANDLER PARA PAGARME
 * 
 * Arquivo: includes/webhook-handler-fixed.php
 * 
 * Este arquivo resolve os problemas de erro 403 e 500 implementando
 * a estrutura correta para processar webhooks do Pagar.me
 */

namespace PagarMeGateway;

use WC_Order;
use WC_Logger;

/**
 * Classe Principal de Processamento de Webhooks
 */
class PagarMe_Webhook_System {
    
    private $logger;
    private $gateway;
    
    public function __construct() {
        $this->logger = wc_get_logger();
        $this->init_hooks();
    }
    
    /**
     * Inicializar hooks do WordPress/WooCommerce
     */
    private function init_hooks() {
        // Registrar endpoint do webhook no WooCommerce
        add_action('woocommerce_api_pagarme_webhook', array($this, 'process_webhook'));
        
        // Adicionar endpoint alternativo via REST API
        add_action('rest_api_init', array($this, 'register_rest_endpoint'));
    }
    
    /**
     * Registrar endpoint REST como alternativa
     */
    public function register_rest_endpoint() {
        register_rest_route('pagarme/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'process_webhook_rest'),
            'permission_callback' => '__return_true'
        ));
    }
    
    /**
     * PROCESSO PRINCIPAL DO WEBHOOK
     */
    public function process_webhook() {
        // Log inicial
        $this->log('info', '=== WEBHOOK RECEBIDO ===');
        
        // Obter dados do webhook
        $input = file_get_contents('php://input');
        $webhook_data = json_decode($input, true);
        
        // Validação inicial
        if (!$this->validate_webhook($webhook_data)) {
            $this->send_response(400, 'Invalid webhook data');
            return;
        }
        
        // Log do tipo de webhook
        $this->log('info', 'Webhook Type: ' . $webhook_data['type']);
        $this->log('debug', 'Webhook Data: ' . json_encode($webhook_data));
        
        try {
            // Processar baseado no tipo
            $result = $this->route_webhook($webhook_data);
            
            // Responder sucesso
            $this->send_response(200, $result['message'] ?? 'Webhook processed successfully');
            
        } catch (\Exception $e) {
            $this->log('error', 'Webhook Exception: ' . $e->getMessage());
            $this->log('error', 'Stack Trace: ' . $e->getTraceAsString());
            $this->send_response(500, 'Internal server error');
        }
    }
    
    /**
     * Processar webhook via REST API
     */
    public function process_webhook_rest(\WP_REST_Request $request) {
        $webhook_data = $request->get_json_params();
        
        if (!$this->validate_webhook($webhook_data)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => 'Invalid webhook data'
            ), 400);
        }
        
        try {
            $result = $this->route_webhook($webhook_data);
            return new \WP_REST_Response(array(
                'success' => true,
                'message' => $result['message'] ?? 'Webhook processed'
            ), 200);
            
        } catch (\Exception $e) {
            $this->log('error', 'REST Webhook Error: ' . $e->getMessage());
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => 'Internal error'
            ), 500);
        }
    }
    
    /**
     * ROTEAMENTO DE WEBHOOKS
     */
    private function route_webhook($webhook_data) {
        $type = $webhook_data['type'];
        $data = $webhook_data['data'] ?? array();
        
        // Mapear tipo para handler
        $handlers = array(
            // Handlers de Charge
            'charge.paid' => 'handle_charge_paid',
            'charge.payment_failed' => 'handle_charge_failed',
            'charge.pending' => 'handle_charge_pending',
            'charge.canceled' => 'handle_charge_canceled',
            'charge.underpaid' => 'handle_charge_underpaid',
            'charge.overpaid' => 'handle_charge_overpaid',
            
            // Handlers de Order
            'order.paid' => 'handle_order_paid',
            'order.payment_failed' => 'handle_order_failed',
            'order.canceled' => 'handle_order_canceled',
            'order.created' => 'handle_order_created',
            'order.closed' => 'handle_order_closed',
            
            // Handlers de Invoice
            'invoice.created' => 'handle_invoice_created',
            'invoice.canceled' => 'handle_invoice_canceled',
            'invoice.paid' => 'handle_invoice_paid',
            'invoice.payment_failed' => 'handle_invoice_failed',
            
            // Handler de Teste
            'test' => 'handle_test'
        );
        
        // Verificar se existe handler
        if (!isset($handlers[$type])) {
            $this->log('warning', 'Webhook type not implemented: ' . $type);
            return array(
                'code' => 200,
                'message' => 'Webhook type not implemented: ' . $type
            );
        }
        
        // Chamar handler específico
        $method = $handlers[$type];
        if (method_exists($this, $method)) {
            return $this->$method($data, $webhook_data);
        }
        
        return array(
            'code' => 200,
            'message' => 'Handler not found for: ' . $type
        );
    }
    
    /**
     * HANDLERS DE CHARGE
     */
    
    /**
     * Handler: charge.paid
     */
    private function handle_charge_paid($charge_data, $webhook_data) {
        $this->log('info', 'Processing charge.paid');
        
        // Buscar pedido WooCommerce
        $order = $this->find_wc_order($charge_data);
        
        if (!$order) {
            $this->log('error', 'Order not found for charge: ' . json_encode($charge_data));
            return array(
                'code' => 200,
                'message' => 'Order not found'
            );
        }
        
        // Log do pedido encontrado
        $this->log('info', sprintf(
            'Order found: #%d (Status: %s)',
            $order->get_id(),
            $order->get_status()
        ));
        
        // Salvar charge_id no pedido
        if (isset($charge_data['id'])) {
            $order->update_meta_data('_pagarme_charge_id', $charge_data['id']);
        }
        
        // Verificar se já foi processado
        if (in_array($order->get_status(), array('processing', 'completed'))) {
            $this->log('info', 'Order already processed');
            return array(
                'code' => 200,
                'message' => 'Order already processed'
            );
        }
        
        // Atualizar status do pedido
        $order->payment_complete($charge_data['id'] ?? '');
        $order->add_order_note(sprintf(
            'Pagamento confirmado via webhook Pagar.me (Charge ID: %s)',
            $charge_data['id'] ?? 'N/A'
        ));
        
        // Atualizar dados adicionais
        if (isset($charge_data['paid_at'])) {
            $order->update_meta_data('_pagarme_paid_at', $charge_data['paid_at']);
        }
        
        if (isset($charge_data['payment_method'])) {
            $order->update_meta_data('_pagarme_payment_method_webhook', $charge_data['payment_method']);
        }
        
        $order->save();
        
        $this->log('info', 'Charge paid processed successfully');
        
        return array(
            'code' => 200,
            'message' => 'Charge paid processed'
        );
    }
    
    /**
     * Handler: charge.payment_failed
     */
    private function handle_charge_failed($charge_data, $webhook_data) {
        $this->log('info', 'Processing charge.payment_failed');
        
        $order = $this->find_wc_order($charge_data);
        
        if (!$order) {
            return array(
                'code' => 200,
                'message' => 'Order not found'
            );
        }
        
        // Construir mensagem de erro
        $error_message = 'Pagamento recusado';
        
        if (isset($charge_data['last_transaction']['gateway_response'])) {
            $response = $charge_data['last_transaction']['gateway_response'];
            if (isset($response['errors']) && is_array($response['errors'])) {
                $errors = array_map(function($error) {
                    return $error['message'] ?? 'Erro desconhecido';
                }, $response['errors']);
                $error_message .= ': ' . implode(', ', $errors);
            }
        }
        
        // Atualizar status
        $order->update_status('failed', $error_message);
        $order->save();
        
        $this->log('info', 'Charge failed processed');
        
        return array(
            'code' => 200,
            'message' => 'Charge failed processed'
        );
    }
    
    /**
     * Handler: charge.pending
     */
    private function handle_charge_pending($charge_data, $webhook_data) {
        $this->log('info', 'Processing charge.pending');
        
        $order = $this->find_wc_order($charge_data);
        
        if (!$order) {
            return array(
                'code' => 200,
                'message' => 'Order not found'
            );
        }
        
        // Manter como on-hold
        if (!in_array($order->get_status(), array('on-hold', 'pending'))) {
            $order->update_status('on-hold', 'Aguardando confirmação de pagamento');
            $order->save();
        }
        
        $this->log('info', 'Charge pending processed');
        
        return array(
            'code' => 200,
            'message' => 'Charge pending processed'
        );
    }
    
    /**
     * Handler: charge.canceled
     */
    private function handle_charge_canceled($charge_data, $webhook_data) {
        $this->log('info', 'Processing charge.canceled');
        
        $order = $this->find_wc_order($charge_data);
        
        if (!$order) {
            return array(
                'code' => 200,
                'message' => 'Order not found'
            );
        }
        
        $order->update_status('cancelled', 'Cobrança cancelada via webhook Pagar.me');
        $order->save();
        
        $this->log('info', 'Charge canceled processed');
        
        return array(
            'code' => 200,
            'message' => 'Charge canceled processed'
        );
    }
    
    /**
     * Handler: charge.underpaid
     */
    private function handle_charge_underpaid($charge_data, $webhook_data) {
        $this->log('info', 'Processing charge.underpaid');
        
        $order = $this->find_wc_order($charge_data);
        
        if (!$order) {
            return array(
                'code' => 200,
                'message' => 'Order not found'
            );
        }
        
        // Adicionar nota sobre pagamento parcial
        $paid_amount = isset($charge_data['paid_amount']) ? $charge_data['paid_amount'] / 100 : 0;
        $order->add_order_note(sprintf(
            'Pagamento parcial recebido: R$ %.2f (Total esperado: R$ %.2f)',
            $paid_amount,
            $order->get_total()
        ));
        
        $order->update_status('on-hold', 'Pagamento parcial recebido');
        $order->save();
        
        $this->log('info', 'Charge underpaid processed');
        
        return array(
            'code' => 200,
            'message' => 'Charge underpaid processed'
        );
    }
    
    /**
     * Handler: charge.overpaid
     */
    private function handle_charge_overpaid($charge_data, $webhook_data) {
        $this->log('info', 'Processing charge.overpaid');
        
        $order = $this->find_wc_order($charge_data);
        
        if (!$order) {
            return array(
                'code' => 200,
                'message' => 'Order not found'
            );
        }
        
        // Processar pagamento e adicionar nota
        $paid_amount = isset($charge_data['paid_amount']) ? $charge_data['paid_amount'] / 100 : 0;
        
        $order->payment_complete($charge_data['id'] ?? '');
        $order->add_order_note(sprintf(
            'Pagamento maior que o esperado recebido: R$ %.2f (Total esperado: R$ %.2f)',
            $paid_amount,
            $order->get_total()
        ));
        
        $order->save();
        
        $this->log('info', 'Charge overpaid processed');
        
        return array(
            'code' => 200,
            'message' => 'Charge overpaid processed'
        );
    }
    
    /**
     * HANDLERS DE ORDER
     */
    
    /**
     * Handler: order.paid
     */
    private function handle_order_paid($order_data, $webhook_data) {
        $this->log('info', 'Processing order.paid');
        
        $order = $this->find_wc_order_from_order_data($order_data);
        
        if (!$order) {
            $this->log('error', 'Order not found for order.paid');
            return array(
                'code' => 200,
                'message' => 'Order not found'
            );
        }
        
        // Verificar se já foi processado
        if (in_array($order->get_status(), array('processing', 'completed'))) {
            $this->log('info', 'Order already processed');
            return array(
                'code' => 200,
                'message' => 'Order already processed'
            );
        }
        
        // Atualizar status
        $order->payment_complete($order_data['id'] ?? '');
        $order->add_order_note('Pedido pago confirmado via webhook Pagar.me');
        $order->save();
        
        $this->log('info', 'Order paid processed');
        
        return array(
            'code' => 200,
            'message' => 'Order paid processed'
        );
    }
    
    /**
     * Handler: order.payment_failed
     */
    private function handle_order_failed($order_data, $webhook_data) {
        $this->log('info', 'Processing order.payment_failed');
        
        $order = $this->find_wc_order_from_order_data($order_data);
        
        if (!$order) {
            return array(
                'code' => 200,
                'message' => 'Order not found'
            );
        }
        
        $order->update_status('failed', 'Pagamento do pedido falhou via webhook');
        $order->save();
        
        $this->log('info', 'Order payment_failed processed');
        
        return array(
            'code' => 200,
            'message' => 'Order payment_failed processed'
        );
    }
    
    /**
     * Handler: order.canceled
     */
    private function handle_order_canceled($order_data, $webhook_data) {
        $this->log('info', 'Processing order.canceled');
        
        $order = $this->find_wc_order_from_order_data($order_data);
        
        if (!$order) {
            return array(
                'code' => 200,
                'message' => 'Order not found'
            );
        }
        
        $order->update_status('cancelled', 'Pedido cancelado via webhook Pagar.me');
        $order->save();
        
        $this->log('info', 'Order canceled processed');
        
        return array(
            'code' => 200,
            'message' => 'Order canceled processed'
        );
    }
    
    /**
     * Handler: order.created
     */
    private function handle_order_created($order_data, $webhook_data) {
        $this->log('info', 'Processing order.created');
        
        // Apenas registrar, pois o pedido já deve existir
        $this->log('info', 'Order created webhook received for ID: ' . ($order_data['id'] ?? 'N/A'));
        
        return array(
            'code' => 200,
            'message' => 'Order created acknowledged'
        );
    }
    
    /**
     * Handler: order.closed
     */
    private function handle_order_closed($order_data, $webhook_data) {
        $this->log('info', 'Processing order.closed');
        
        $order = $this->find_wc_order_from_order_data($order_data);
        
        if (!$order) {
            return array(
                'code' => 200,
                'message' => 'Order not found'
            );
        }
        
        // Adicionar nota
        $order->add_order_note('Pedido fechado no Pagar.me');
        
        // Se ainda estiver pendente, cancelar
        if (in_array($order->get_status(), array('pending', 'on-hold'))) {
            $order->update_status('cancelled', 'Pedido fechado sem pagamento');
        }
        
        $order->save();
        
        $this->log('info', 'Order closed processed');
        
        return array(
            'code' => 200,
            'message' => 'Order closed processed'
        );
    }
    
    /**
     * HANDLERS DE INVOICE
     */
    
    /**
     * Handler: invoice.created
     */
    private function handle_invoice_created($invoice_data, $webhook_data) {
        $this->log('info', 'Processing invoice.created');
        
        // Registrar criação da invoice
        $this->log('info', 'Invoice created: ' . ($invoice_data['id'] ?? 'N/A'));
        
        return array(
            'code' => 200,
            'message' => 'Invoice created acknowledged'
        );
    }
    
    /**
     * Handler: invoice.paid
     */
    private function handle_invoice_paid($invoice_data, $webhook_data) {
        $this->log('info', 'Processing invoice.paid');
        
        // Buscar pedido relacionado
        if (isset($invoice_data['order_id'])) {
            $order = $this->find_wc_order_by_pagarme_id($invoice_data['order_id']);
            if ($order) {
                $order->add_order_note('Fatura paga no Pagar.me (Invoice ID: ' . $invoice_data['id'] . ')');
                if ($order->get_status() === 'on-hold') {
                    $order->payment_complete();
                }
                $order->save();
            }
        }
        
        $this->log('info', 'Invoice paid processed');
        
        return array(
            'code' => 200,
            'message' => 'Invoice paid processed'
        );
    }
    
    /**
     * Handler: invoice.canceled
     */
    private function handle_invoice_canceled($invoice_data, $webhook_data) {
        $this->log('info', 'Processing invoice.canceled');
        
        $this->log('info', 'Invoice canceled: ' . ($invoice_data['id'] ?? 'N/A'));
        
        return array(
            'code' => 200,
            'message' => 'Invoice canceled acknowledged'
        );
    }
    
    /**
     * Handler: invoice.payment_failed
     */
    private function handle_invoice_failed($invoice_data, $webhook_data) {
        $this->log('info', 'Processing invoice.payment_failed');
        
        if (isset($invoice_data['order_id'])) {
            $order = $this->find_wc_order_by_pagarme_id($invoice_data['order_id']);
            if ($order) {
                $order->add_order_note('Pagamento da fatura falhou (Invoice ID: ' . $invoice_data['id'] . ')');
                $order->save();
            }
        }
        
        $this->log('info', 'Invoice payment_failed processed');
        
        return array(
            'code' => 200,
            'message' => 'Invoice payment_failed processed'
        );
    }
    
    /**
     * Handler: test webhook
     */
    private function handle_test($data, $webhook_data) {
        $this->log('info', 'Test webhook received');
        
        return array(
            'code' => 200,
            'message' => 'Test webhook processed successfully'
        );
    }
    
    /**
     * MÉTODOS AUXILIARES
     */
    
    /**
     * Buscar pedido WooCommerce pelos dados da charge
     */
    private function find_wc_order($charge_data) {
        // Estratégias de busca em ordem de prioridade
        
        // 1. Buscar pelo order_id direto
        if (isset($charge_data['order_id'])) {
            $order = $this->find_wc_order_by_pagarme_id($charge_data['order_id']);
            if ($order) {
                return $order;
            }
        }
        
        // 2. Buscar pelos metadados
        if (isset($charge_data['metadata'])) {
            // Tentar pelo wc_order_id
            if (isset($charge_data['metadata']['wc_order_id'])) {
                $order = wc_get_order($charge_data['metadata']['wc_order_id']);
                if ($order) {
                    return $order;
                }
            }
            
            // Tentar pelo order_key
            if (isset($charge_data['metadata']['wc_order_key'])) {
                $order_id = wc_get_order_id_by_order_key($charge_data['metadata']['wc_order_key']);
                if ($order_id) {
                    return wc_get_order($order_id);
                }
            }
        }
        
        // 3. Buscar pelo código da charge
        if (isset($charge_data['code'])) {
            // Remover prefixos se houver
            $code = preg_replace('/^(CH_|ch_)/', '', $charge_data['code']);
            $order = wc_get_order($code);
            if ($order) {
                return $order;
            }
        }
        
        // 4. Buscar pelo charge_id salvo anteriormente
        if (isset($charge_data['id'])) {
            $orders = wc_get_orders(array(
                'meta_key' => '_pagarme_charge_id',
                'meta_value' => $charge_data['id'],
                'limit' => 1
            ));
            
            if (!empty($orders)) {
                return $orders[0];
            }
        }
        
        return null;
    }
    
    /**
     * Buscar pedido WooCommerce pelos dados do order
     */
    private function find_wc_order_from_order_data($order_data) {
        // 1. Buscar pelo ID do Pagar.me
        if (isset($order_data['id'])) {
            $order = $this->find_wc_order_by_pagarme_id($order_data['id']);
            if ($order) {
                return $order;
            }
        }
        
        // 2. Buscar pelos metadados
        if (isset($order_data['metadata'])) {
            if (isset($order_data['metadata']['wc_order_id'])) {
                $order = wc_get_order($order_data['metadata']['wc_order_id']);
                if ($order) {
                    return $order;
                }
            }
        }
        
        // 3. Buscar pelo código
        if (isset($order_data['code'])) {
            $order = wc_get_order($order_data['code']);
            if ($order) {
                return $order;
            }
        }
        
        return null;
    }
    
    /**
     * Buscar pedido pelo ID do Pagar.me
     */
    private function find_wc_order_by_pagarme_id($pagarme_id) {
        $orders = wc_get_orders(array(
            'meta_key' => '_pagarme_order_id',
            'meta_value' => $pagarme_id,
            'limit' => 1
        ));
        
        if (!empty($orders)) {
            return $orders[0];
        }
        
        return null;
    }
    
    /**
     * Validar dados do webhook
     */
    private function validate_webhook($webhook_data) {
        if (empty($webhook_data)) {
            $this->log('error', 'Empty webhook data');
            return false;
        }
        
        if (!isset($webhook_data['type'])) {
            $this->log('error', 'Missing webhook type');
            return false;
        }
        
        // Validar assinatura se configurado
        if ($this->should_validate_signature()) {
            return $this->validate_signature($webhook_data);
        }
        
        return true;
    }
    
    /**
     * Verificar se deve validar assinatura
     */
    private function should_validate_signature() {
        $gateway = $this->get_gateway();
        // Falha fechada: valida a assinatura, a menos que a opção seja explicitamente 'no'
        return ! $gateway || $gateway->get_option('validate_webhook_signature', 'yes') !== 'no';
    }
    
    /**
     * Validar assinatura do webhook
     */
    private function validate_signature($webhook_data) {
        $signature = $_SERVER['HTTP_X_HUB_SIGNATURE'] ?? '';
        
        if (empty($signature)) {
            $this->log('error', 'Assinatura não fornecida (X-Hub-Signature ausente)');
            return false;
        }

        $gateway = $this->get_gateway();
        $secret = $gateway ? $gateway->get_option('webhook_secret_key') : '';

        if (empty($secret)) {
            $this->log('warning', 'Secret Key do Webhook não configurada. Validação ignorada.');
            return true;
        }

        $body = file_get_contents('php://input');
        $expected_signature = 'sha1=' . hash_hmac('sha1', $body, $secret);

        if (!hash_equals($expected_signature, $signature)) {
            $this->log('error', 'Assinatura HMAC inválida', array(
                'received' => $signature,
                'expected' => $expected_signature
            ));
            return false;
        }

        $this->log('info', 'Assinatura HMAC validada com sucesso ');
        return true;
    }
    
    /**
     * Obter instância do gateway
     */
    private function get_gateway() {
        if (!$this->gateway) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            $this->gateway = isset($gateways['pagarme_gateway']) ? $gateways['pagarme_gateway'] : null;
        }
        return $this->gateway;
    }
    
    /**
     * Enviar resposta HTTP
     */
    private function send_response($status_code, $message) {
        http_response_code($status_code);
        echo json_encode(array(
            'success' => $status_code === 200,
            'message' => $message,
            'timestamp' => current_time('Y-m-d H:i:s')
        ));
        exit;
    }
    
    /**
     * Registrar log
     */
    private function log($level, $message, $context = array()) {
        $this->logger->log($level, '[PagarMe Webhook] ' . $message, array(
            'source' => 'pagarme-gateway',
            'context' => $context
        ));
    }
}

// Inicializar o sistema quando o plugin carregar
add_action('init', function() {
    new PagarMe_Webhook_System();
});
