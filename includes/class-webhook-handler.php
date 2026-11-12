<?php
/**
 * WEBHOOK HANDLER - VERSÃO HÍBRIDA MELHORADA
 * 
 * COMBINAÇÃO DO MELHOR DE AMBOS OS PLUGINS:
 * 
 * DO PLUGIN OFICIAL:
 * Prevenção de duplicação de webhooks
 * Validação de origem (WooCommerce vs outras plataformas)
 * Busca direta por code (performance)
 * Sistema de hooks extensível
 * Sanitização de nomes de eventos
 * Distinção produtos físicos vs digitais
 * 
 * DO NOSSO PLUGIN:
 * Singleton pattern
 * Múltiplas estratégias de busca (fallback robusto)
 * Logs detalhados em português
 * Error handling completo
 * Resposta JSON estruturada
 * Documentação em português
 * 
 * INSTALAÇÃO:
 * 1. Backup do arquivo atual: includes/class-webhook-handler.php
 * 2. Substituir pelo conteúdo deste arquivo
 * 3. Testar com test-webhook.php
 * 4. Validar no painel Pagar.me
 */

namespace PagarMeGateway;

// Prevenir acesso direto
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe principal do Webhook Handler
 * Versão híbrida melhorada baseada no plugin oficial + nossas melhorias
 */
class Webhook_Handler {
    
    /**
     * Instância do logger
     * @var Logger
     */
    private $logger;
    
    /**
     * Instância da configuração
     * @var Config
     */
    private $config;
    
    /**
     * Instância única (Singleton)
     * @var Webhook_Handler|null
     */
    private static $instance = null;
    
    /**
     * Obter instância única
     * @return Webhook_Handler
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Construtor privado (Singleton)
     */
    private function __construct() {
        // Inicializar dependências
        $this->logger = new Logger();
        $this->config = new Config();
        
        // CRITICAL: Registrar hook do WooCommerce
        add_action('woocommerce_api_pagarme_webhook', array($this, 'handle_webhook'));
        
        $this->logger->info('Webhook Handler (Híbrido) inicializado', array(
            'hook' => 'woocommerce_api_pagarme_webhook',
            'version' => 'hybrid_v2.0'
        ));
        
        error_log('Pagar.me Webhook Handler: Versão Híbrida inicializada');
    }
    
    /**
     * Handler principal do webhook
     * URL: https://seusite.com/wc-api/pagarme_webhook
     */
    public function handle_webhook() {
        error_log('========================================');
        error_log('Pagar.me Webhook: handle_webhook() CHAMADO!');
        error_log('Timestamp: ' . current_time('mysql'));
        
        // Obter corpo da requisição
        $input = file_get_contents('php://input');
        
        // MELHORIA DO PLUGIN OFICIAL: Usar objeto ao invés de array
        $webhook_data = json_decode($input, false); // false = retorna objeto
        
        // Validar dados básicos
        if (empty($webhook_data) || !isset($webhook_data->type)) {
            $this->logger->error('Webhook inválido: dados ausentes', array(
                'body_size' => strlen($input),
                'json_error' => json_last_error_msg()
            ));
            
            $this->respond_with_error(400, 'Invalid webhook data');
            return;
        }
        
        // Log do webhook recebido
        $this->logger->info('Webhook recebido do Pagar.me', array(
            'webhook_id' => $webhook_data->id ?? 'N/A',
            'type' => $webhook_data->type ?? 'DESCONHECIDO',
            'has_data' => isset($webhook_data->data) ? 'sim' : 'não',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'N/A',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'N/A'
        ));
        
        // MELHORIA DO PLUGIN OFICIAL: Sanitizar nome do evento
        $event = $this->sanitize_event_name($webhook_data->type);
        
        // MELHORIA DO PLUGIN OFICIAL: Verificar se já foi processado
        if ($this->was_webhook_processed($event, $webhook_data->id, $webhook_data->data->code ?? null)) {
            $this->logger->info('ℹWebhook já processado anteriormente', array(
                'webhook_id' => $webhook_data->id,
                'event' => $event
            ));
            
            $this->respond_with_success('Webhook already processed');
            return;
        }
        
        // MELHORIA DO PLUGIN OFICIAL: Validar se pedido é do WooCommerce
        if (!$this->is_woocommerce_order($webhook_data)) {
            $this->logger->info('ℹWebhook ignorado: não é pedido do WooCommerce', array(
                'webhook_id' => $webhook_data->id,
                'platform' => $this->get_platform_from_metadata($webhook_data)
            ));
            
            $this->respond_with_success('Webhook received but not from WooCommerce');
            return;
        }
        
        try {
            // NOVO: Hook antes de processar (extensibilidade)
            do_action('pagarme_gateway_before_webhook', $webhook_data);
            
            // Processar webhook
            $this->process_webhook($event, $webhook_data);
            
            // MELHORIA DO PLUGIN OFICIAL: Marcar como processado
            $this->mark_webhook_as_processed($event, $webhook_data->id, $webhook_data->data->code ?? null);
            
            // NOVO: Hook após processar (extensibilidade)
            do_action('pagarme_gateway_after_webhook', $webhook_data);
            
            // Responder com sucesso
            $this->respond_with_success('Webhook processed successfully', array(
                'event' => $event,
                'webhook_id' => $webhook_data->id
            ));
            
        } catch (\Exception $e) {
            $this->logger->error('Erro ao processar webhook', array(
                'error' => $e->getMessage(),
                'type' => $webhook_data->type,
                'webhook_id' => $webhook_data->id ?? 'N/A',
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ));
            
            $this->respond_with_error(500, 'Internal server error', array(
                'error' => $e->getMessage()
            ));
        }
        
        error_log('========================================');
    }
    
    /**
     * MELHORIA DO PLUGIN OFICIAL: Processar webhook baseado no tipo de evento
     */
    private function process_webhook($event, $webhook_data) {
        // Eventos de charge (boleto, PIX, etc)
        if (strpos($event, 'charge') !== false) {
            $this->process_charge_event($event, $webhook_data);
            return;
        }
        
        // Eventos de order
        $this->process_order_event($event, $webhook_data);
    }
    
    /**
     * Processar eventos de charge
     */
    private function process_charge_event($event, $webhook_data) {
        $this->logger->info("Processando evento de charge: {$event}");
        
        // Buscar pedido
        $order = $this->get_order_from_webhook_data($webhook_data);
        
        if (!$order) {
            $this->logger->warning('Pedido não encontrado para charge', array(
                'charge_id' => $webhook_data->data->id ?? 'N/A',
                'order_code' => $webhook_data->data->code ?? 'N/A'
            ));
            return;
        }
        
        // NOVO: Hook específico para charges (extensibilidade)
        do_action("pagarme_gateway_{$event}", $order, $webhook_data);
        do_action("pagarme_gateway_notes_{$event}", $order, $webhook_data);
        
        // Processar conforme tipo
        switch ($event) {
            case 'charge_paid':
                $this->handle_charge_paid($order, $webhook_data);
                break;
                
            case 'charge_payment_failed':
            case 'charge_failed':
                $this->handle_charge_failed($order, $webhook_data);
                break;
                
            case 'charge_pending':
                $this->handle_charge_pending($order, $webhook_data);
                break;
                
            default:
                $this->logger->info("ℹEvento de charge não implementado: {$event}");
        }
    }
    
    /**
     * Processar eventos de order
     */
    private function process_order_event($event, $webhook_data) {
        $this->logger->info("Processando evento de order: {$event}");
        
        // Buscar pedido
        $order = $this->get_order_from_webhook_data($webhook_data);
        
        if (!$order) {
            $this->logger->warning('Pedido não encontrado para order', array(
                'order_id' => $webhook_data->data->id ?? 'N/A',
                'order_code' => $webhook_data->data->code ?? 'N/A'
            ));
            return;
        }
        
        // NOVO: Hook específico para orders (extensibilidade)
        do_action("pagarme_gateway_{$event}", $order, $webhook_data);
        
        // Processar conforme tipo
        switch ($event) {
            case 'order_paid':
                $this->handle_order_paid($order, $webhook_data);
                break;
                
            case 'order_payment_failed':
                $this->handle_order_failed($order, $webhook_data);
                break;
                
            case 'order_canceled':
            case 'order_cancelled':
                $this->handle_order_canceled($order, $webhook_data);
                break;
                
            default:
                $this->logger->info("ℹEvento de order não implementado: {$event}");
        }
    }
    
    /**
     * MELHORIA DO PLUGIN OFICIAL: Buscar pedido com estratégias múltiplas
     */
    private function get_order_from_webhook_data($webhook_data) {
        // ESTRATÉGIA 1: Busca direta por code (ID do WooCommerce) - MAIS RÁPIDO
        if (isset($webhook_data->data->code)) {
            $order = wc_get_order($webhook_data->data->code);
            if ($order) {
                $this->logger->info('Pedido encontrado por code', array(
                    'order_id' => $order->get_id(),
                    'strategy' => 'direct_code'
                ));
                return $order;
            }
        }
        
        // ESTRATÉGIA 2: Busca por charge_id (se disponível)
        if (isset($webhook_data->data->id) && strpos($webhook_data->data->id, 'ch_') === 0) {
            $orders = wc_get_orders(array(
                'meta_key' => '_pagarme_charge_id',
                'meta_value' => $webhook_data->data->id,
                'limit' => 1
            ));
            
            if (!empty($orders)) {
                $this->logger->info('Pedido encontrado por charge_id', array(
                    'order_id' => $orders[0]->get_id(),
                    'strategy' => 'charge_id'
                ));
                return $orders[0];
            }
        }
        
        // ESTRATÉGIA 3: Busca por pagarme_order_id
        if (isset($webhook_data->data->order_id)) {
            $orders = wc_get_orders(array(
                'meta_key' => '_pagarme_order_id',
                'meta_value' => $webhook_data->data->order_id,
                'limit' => 1
            ));
            
            if (!empty($orders)) {
                $this->logger->info('Pedido encontrado por pagarme_order_id', array(
                    'order_id' => $orders[0]->get_id(),
                    'strategy' => 'pagarme_order_id'
                ));
                return $orders[0];
            }
        }
        
        // ESTRATÉGIA 4: Busca por metadata (se disponível)
        if (isset($webhook_data->data->metadata->wc_order_id)) {
            $order = wc_get_order($webhook_data->data->metadata->wc_order_id);
            if ($order) {
                $this->logger->info('Pedido encontrado por metadata', array(
                    'order_id' => $order->get_id(),
                    'strategy' => 'metadata'
                ));
                return $order;
            }
        }
        
        $this->logger->warning('Pedido não encontrado por nenhuma estratégia', array(
            'strategies_tried' => 'code, charge_id, pagarme_order_id, metadata'
        ));
        
        return null;
    }
    
    /**
     * Handler: charge.paid
     */
    private function handle_charge_paid($order, $webhook_data) {
        $current_status = $order->get_status();
        
        // Verificar se já está pago
        if (in_array($current_status, array('processing', 'completed'))) {
            $this->logger->info('ℹPedido já confirmado como pago', array(
                'order_id' => $order->get_id(),
                'current_status' => $current_status
            ));
            return;
        }
        
        // Salvar charge_id no pedido
        if (isset($webhook_data->data->id)) {
            $order->update_meta_data('_pagarme_charge_id', $webhook_data->data->id);
        }
        
        // Marcar como pago
        $order->payment_complete($webhook_data->data->id ?? '');
        $order->add_order_note(
            'Pagar.me: Cobrança confirmada via webhook - Charge ID: ' . ($webhook_data->data->id ?? 'N/A')
        );
        
        // MELHORIA DO PLUGIN OFICIAL: Distinguir produtos físicos vs digitais
        if (!$this->order_needs_processing($order)) {
            $order->set_status('completed');
        }
        
        $order->save();
        
        // Log estruturado da mudança
        $this->log_status_change($order, $current_status, $order->get_status(), 'charge.paid');
    }
    
    /**
     * Handler: order.paid
     */
    private function handle_order_paid($order, $webhook_data) {
        $current_status = $order->get_status();
        
        // Verificar se já está pago
        if (in_array($current_status, array('processing', 'completed'))) {
            $this->logger->info('ℹPedido já confirmado como pago', array(
                'order_id' => $order->get_id(),
                'current_status' => $current_status
            ));
            return;
        }
        
        // Marcar como pago
        $order->payment_complete($webhook_data->data->id ?? '');
        $order->add_order_note(
            'Pagar.me: Pagamento confirmado via webhook - Order ID: ' . ($webhook_data->data->id ?? 'N/A')
        );
        
        // MELHORIA DO PLUGIN OFICIAL: Distinguir produtos físicos vs digitais
        if (!$this->order_needs_processing($order)) {
            $order->set_status('completed');
        }
        
        $order->save();
        
        // Log estruturado
        $this->log_status_change($order, $current_status, $order->get_status(), 'order.paid');
    }
    
    /**
     * Handler: charge.payment_failed / charge.failed
     */
    private function handle_charge_failed($order, $webhook_data) {
        $current_status = $order->get_status();
        
        if ($current_status === 'failed') {
            return; // Já está como falho
        }
        
        // Extrair mensagem de erro
        $error_message = 'Pagar.me: Cobrança reprovada';
        
        if (isset($webhook_data->data->last_transaction->gateway_response->errors[0]->message)) {
            $error_message .= ' - ' . $webhook_data->data->last_transaction->gateway_response->errors[0]->message;
        }
        
        $order->update_status('failed', $error_message);
        $order->save();
        
        $this->log_status_change($order, $current_status, 'failed', 'charge.failed');
    }
    
    /**
     * Handler: order.payment_failed
     */
    private function handle_order_failed($order, $webhook_data) {
        $current_status = $order->get_status();
        
        if ($current_status === 'failed') {
            return;
        }
        
        $order->update_status('failed', 'Pagar.me: Pagamento falhou');
        $order->save();
        
        $this->log_status_change($order, $current_status, 'failed', 'order.payment_failed');
    }
    
    /**
     * Handler: order.canceled
     */
    private function handle_order_canceled($order, $webhook_data) {
        $current_status = $order->get_status();
        
        if (in_array($current_status, array('cancelled', 'canceled'))) {
            return;
        }
        
        $order->update_status('cancelled', 'Pagar.me: Pedido cancelado');
        $order->save();
        
        $this->log_status_change($order, $current_status, 'cancelled', 'order.canceled');
    }
    
    /**
     * Handler: charge.pending
     */
    private function handle_charge_pending($order, $webhook_data) {
        $current_status = $order->get_status();
        
        if ($current_status !== 'on-hold') {
            $order->update_status('on-hold', 'Pagar.me: Cobrança pendente');
            $order->save();
            
            $this->log_status_change($order, $current_status, 'on-hold', 'charge.pending');
        }
    }
    
    /**
     * MELHORIA DO PLUGIN OFICIAL: Verificar se pedido precisa processamento
     * Produtos físicos precisam de envio, então ficam em "processing"
     * Produtos digitais/serviços podem ir direto para "completed"
     */
    private function order_needs_processing($order) {
        $needs_processing = false;
        
        if (count($order->get_items()) > 0) {
            foreach ($order->get_items() as $item) {
                if ($item->is_type('line_item')) {
                    $product = $item->get_product();
                    
                    if (!$product) {
                        continue;
                    }
                    
                    // Se for produto físico (não downloadable e não virtual), precisa processing
                    if (!$product->is_downloadable() && !$product->is_virtual()) {
                        $needs_processing = true;
                        break;
                    }
                }
            }
        }
        
        return $needs_processing;
    }
    
    /**
     * MELHORIA DO PLUGIN OFICIAL: Sanitizar nome do evento
     */
    private function sanitize_event_name($event) {
        return str_replace('.', '_', strtolower($event));
    }
    
    /**
     * MELHORIA DO PLUGIN OFICIAL: Verificar se webhook já foi processado
     */
    private function was_webhook_processed($event, $webhook_id, $code) {
        if (!$code) {
            return false;
        }

        // Tenta encontrar o pedido pelo código (que pode ser o ID ou o código do Pagar.me)
        $order_id = 0;
        if (is_numeric($code)) {
            $order_id = intval($code);
        } else {
            // Busca pedido pelo meta_data do código do Pagar.me se não for numérico
            $orders = wc_get_orders(array(
                'meta_key' => '_pagarme_order_id',
                'meta_value' => $code,
                'limit' => 1
            ));
            if (!empty($orders)) {
                $order_id = $orders[0]->get_id();
            }
        }

        if (!$order_id) {
            return false;
        }

        $meta_key = "webhook_{$event}_{$webhook_id}";
        $value = get_post_meta($order_id, $meta_key, true);

        return !empty($value);
    }

    /**
     * MELHORIA DO PLUGIN OFICIAL: Marcar webhook como processado
     */
    private function mark_webhook_as_processed($event, $webhook_id, $code) {
        if (!$code) {
            return;
        }

        // Tenta encontrar o pedido pelo código
        $order_id = 0;
        if (is_numeric($code)) {
            $order_id = intval($code);
        } else {
            $orders = wc_get_orders(array(
                'meta_key' => '_pagarme_order_id',
                'meta_value' => $code,
                'limit' => 1
            ));
            if (!empty($orders)) {
                $order_id = $orders[0]->get_id();
            }
        }

        if (!$order_id) {
            return;
        }

        $meta_key = "webhook_{$event}_{$webhook_id}";
        update_post_meta($order_id, $meta_key, true);

        $this->logger->info('Webhook marcado como processado', array(
            'order_id' => $order_id,
            'event' => $event,
            'webhook_id' => $webhook_id
        ));
    }
    
    /**
     * MELHORIA DO PLUGIN OFICIAL: Validar se pedido é do WooCommerce
     */
    private function is_woocommerce_order($webhook_data) {
        // Verificar se pedido existe
        if (!isset($webhook_data->data->code)) {
            return false;
        }
        
        $order = wc_get_order($webhook_data->data->code);
        
        if (!$order) {
            return false;
        }
        
        // Verificar metadata
        $platform = $this->get_platform_from_metadata($webhook_data);
        
        // Se tem metadata de plataforma, verificar se é WooCommerce
        if (!empty($platform)) {
            return stripos($platform, 'woocommerce') !== false;
        }
        
        // Se não tem metadata, assumir que é válido (pedido existe no WooCommerce)
        return true;
    }
    
    /**
     * MELHORIA DO PLUGIN OFICIAL: Extrair plataforma dos metadados
     */
    private function get_platform_from_metadata($webhook_data) {
        if (!isset($webhook_data->data->order->metadata)) {
            return '';
        }
        
        $metadata = $webhook_data->data->order->metadata;
        
        if (property_exists($metadata, 'platformVersion')) {
            return $metadata->platformVersion;
        }
        
        return '';
    }
    
    /**
     * MELHORIA: Log estruturado de mudança de status
     */
    private function log_status_change($order, $previous_status, $new_status, $event) {
        $this->logger->info('Status do pedido atualizado', array(
            'order_id' => $order->get_id(),
            'previous_status' => $previous_status,
            'new_status' => $new_status,
            'webhook_event' => $event,
            'needs_processing' => $this->order_needs_processing($order) ? 'sim' : 'não'
        ));
    }
    
    /**
     * MELHORIA: Responder com sucesso
     */
    private function respond_with_success($message, $data = array()) {
        http_response_code(200);
        header('Content-Type: application/json');
        
        echo json_encode(array_merge(array(
            'success' => true,
            'message' => $message,
            'timestamp' => current_time('mysql')
        ), $data));
        
        exit;
    }
    
    /**
     * MELHORIA: Responder com erro
     */
    private function respond_with_error($code, $message, $data = array()) {
        http_response_code($code);
        header('Content-Type: application/json');
        
        echo json_encode(array_merge(array(
            'success' => false,
            'error' => $message,
            'timestamp' => current_time('mysql')
        ), $data));
        
        exit;
    }
}

// CRITICAL: Inicializar a classe quando o WordPress carregar
add_action('plugins_loaded', function() {
    if (class_exists('WC_Payment_Gateway')) {
        Webhook_Handler::get_instance();
        error_log('Pagar.me: Webhook_Handler (Híbrido) inicializado com sucesso');
    } else {
        error_log('Pagar.me: ERRO - WC_Payment_Gateway não encontrada');
    }
}, 11);
