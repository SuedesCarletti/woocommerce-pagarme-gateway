<?php
/**
 * Cliente da API Pagar.me
 */

namespace PagarMeGateway;

if (!defined('ABSPATH')) {
    exit;
}

class API_Client {
    
    private $api_key;
    private $environment;
    private $logger;
    private $base_url;
    
    public function __construct($api_key, $environment, $logger) {
        $this->api_key = $api_key;
        $this->environment = $environment;
        $this->logger = $logger;
        
        // Definir URL base conforme ambiente
        $this->base_url = ($environment === 'production') 
            ? 'https://api.pagar.me/core/v5' 
            : 'https://api.pagar.me/core/v5';
    }
    
    /**
     * Criar pedido no Pagar.me
     */
    public function create_order($order_data) {
        $endpoint = '/orders';
        
        $this->logger->info('Criando pedido no Pagar.me', [
            'wc_order_id' => $order_data['metadata']['wc_order_id'] ?? null,
            'payment_method' => $order_data['payments'][0]['payment_method'] ?? 'unknown'
        ]);
        
        try {
            $response = $this->make_request('POST', $endpoint, $order_data);
            
            $this->logger->info('Pedido criado com sucesso no Pagar.me', [
                'pagarme_order_id' => $response['id'] ?? null,
                'status' => $response['status'] ?? 'unknown'
            ]);
            
            return $response;
            
        } catch (\Exception $e) {
            $this->logger->error('Erro ao criar pedido no Pagar.me', [
                'error' => $e->getMessage(),
                'wc_order_id' => $order_data['metadata']['wc_order_id'] ?? null
            ]);
            throw $e;
        }
    }
    
    /**
     * Buscar pedido no Pagar.me
     */
    public function get_order($order_id) {
        $endpoint = "/orders/{$order_id}";
        
        try {
            $response = $this->make_request('GET', $endpoint);
            
            $this->logger->debug('Pedido consultado no Pagar.me', [
                'pagarme_order_id' => $order_id,
                'status' => $response['status'] ?? 'unknown'
            ]);
            
            return $response;
            
        } catch (\Exception $e) {
            $this->logger->error('Erro ao consultar pedido no Pagar.me', [
                'error' => $e->getMessage(),
                'pagarme_order_id' => $order_id
            ]);
            throw $e;
        }
    }
    
    /**
     * Cancelar pedido no Pagar.me
     */
    public function cancel_order($order_id) {
        $endpoint = "/orders/{$order_id}";
        
        try {
            $response = $this->make_request('DELETE', $endpoint);
            
            $this->logger->info('Pedido cancelado no Pagar.me', [
                'pagarme_order_id' => $order_id
            ]);
            
            return $response;
            
        } catch (\Exception $e) {
            $this->logger->error('Erro ao cancelar pedido no Pagar.me', [
                'error' => $e->getMessage(),
                'pagarme_order_id' => $order_id
            ]);
            throw $e;
        }
    }
    
    /**
     * Fazer requisição HTTP para API Pagar.me
     */
    private function make_request($method, $endpoint, $body = null) {
        $url = $this->base_url . $endpoint;
        
        $args = array(
            'method' => $method,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($this->api_key . ':')
            ),
            'timeout' => 30,
            'sslverify' => true
        );
        
        if ($body && in_array($method, array('POST', 'PUT', 'PATCH'))) {
            $args['body'] = json_encode($body);
        }
        
        // Log da requisição (sem dados sensíveis)
        $this->logger->debug('Requisição para Pagar.me', [
            'method' => $method,
            'endpoint' => $endpoint,
            'has_body' => !empty($body)
        ]);
        
        // Fazer requisição
        $response = wp_remote_request($url, $args);
        
        // Verificar erros de conexão
        if (is_wp_error($response)) {
            throw new \Exception('Erro de conexão: ' . $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        // Log da resposta
        $this->logger->debug('Resposta do Pagar.me', [
            'status_code' => $status_code,
            'has_data' => !empty($data)
        ]);
        
        // Verificar erros HTTP
        if ($status_code < 200 || $status_code >= 300) {
            $error_message = $this->extract_error_message($data);
            throw new \Exception("Erro {$status_code}: {$error_message}");
        }
        
        return $data;
    }
    
    /**
     * Extrair mensagem de erro da resposta
     */
    private function extract_error_message($data) {
        if (!$data) {
            return 'Resposta vazia do servidor';
        }
        
        // Formato padrão de erro do Pagar.me
        if (isset($data['message'])) {
            return $data['message'];
        }
        
        // Erros em array
        if (isset($data['errors']) && is_array($data['errors'])) {
            $errors = array();
            foreach ($data['errors'] as $error) {
                if (is_string($error)) {
                    $errors[] = $error;
                } elseif (isset($error['message'])) {
                    $errors[] = $error['message'];
                }
            }
            return implode(', ', $errors);
        }
        
        return 'Erro desconhecido';
    }
    
    /**
     * Validar conexão com Pagar.me
     */
    public function test_connection() {
        try {
            // Fazer uma requisição simples para testar
            $endpoint = '/orders?page=1&size=1';
            $this->make_request('GET', $endpoint);
            
            $this->logger->info('Teste de conexão com Pagar.me: OK');
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Teste de conexão com Pagar.me falhou', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}