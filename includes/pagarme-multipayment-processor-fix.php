<?php
/**
 * CORREÇÃO PHP - PROCESSAMENTO DE MULTIPAGAMENTO
 * 
 * PROBLEMA: O PHP só aceita um formato de nomenclatura de campos
 * SOLUÇÃO: Aceitar múltiplos formatos para maior flexibilidade
 * 
 * ARQUIVO: pagarme-multipayment-processor-fix.php
 * VERSÃO: 1.0.0
 * DATA: 2025-11-07
 * 
 * Este arquivo deve ser incluído no gateway principal
 */

namespace PagarMeGateway;

class MultipaymentProcessorFix {
    
    /**
     * Extrair métodos de pagamento do POST com suporte a múltiplos formatos
     * 
     * @return array Array de métodos [tipo => valor]
     */
    public static function extract_payment_methods_from_post() {
        $payment_methods = array();
        $logger = new \WC_Logger();
        
        $logger->info('[MultipaymentFix] Iniciando extração de métodos', array(
            'source' => 'pagarme-gateway'
        ));
        
        // FORMATO 1: Numerado (esperado originalmente)
        // pagarme_multipayment_method_0_type = credit_card
        // pagarme_multipayment_method_0_amount = 363.09
        
        for ($i = 0; $i < 10; $i++) {
            $type_key = "pagarme_multipayment_method_{$i}_type";
            $amount_key = "pagarme_multipayment_method_{$i}_amount";
            
            if (isset($_POST[$type_key]) && isset($_POST[$amount_key])) {
                $method = sanitize_text_field($_POST[$type_key]);
                $amount = floatval($_POST[$amount_key]);
                
                if ($amount > 0) {
                    $payment_methods[$method] = $amount;
                    
                    $logger->info('[MultipaymentFix] Método encontrado (formato numerado)', array(
                        'index' => $i,
                        'method' => $method,
                        'amount' => $amount,
                        'source' => 'pagarme-gateway'
                    ));
                    
                    // Extrair dados do cartão se existirem
                    if (in_array($method, array('credit_card', 'debit_card', 'voucher'))) {
                        $card_data = self::extract_card_data_for_method($i, $method);
                        if (!empty($card_data)) {
                            // Salvar dados do cartão no POST para uso posterior
                            $_POST["pagarme_card_{$method}_data"] = $card_data;
                        }
                    }
                }
            }
        }
        
        // FORMATO 2: Por nome do método (formato antigo)
        // multi_method_credit_card = yes
        // multi_amount_credit_card = 363.09
        
        if (empty($payment_methods)) {
            $logger->info('[MultipaymentFix] Formato numerado vazio, tentando formato por nome', array(
                'source' => 'pagarme-gateway'
            ));
            
            $possible_methods = array(
                'credit_card', 'debit_card', 'pix', 'boleto', 
                'voucher', 'bank_transfer', 'cash', 'safetypay'
            );
            
            foreach ($possible_methods as $method) {
                $method_key = "multi_method_{$method}";
                $amount_key = "multi_amount_{$method}";
                
                if (isset($_POST[$method_key]) && $_POST[$method_key] === 'yes') {
                    $amount = isset($_POST[$amount_key]) ? floatval($_POST[$amount_key]) : 0;
                    
                    if ($amount > 0) {
                        $payment_methods[$method] = $amount;
                        
                        $logger->info('[MultipaymentFix] Método encontrado (formato por nome)', array(
                            'method' => $method,
                            'amount' => $amount,
                            'source' => 'pagarme-gateway'
                        ));
                        
                        // Extrair dados do cartão formato antigo
                        if (in_array($method, array('credit_card', 'debit_card', 'voucher'))) {
                            $card_data = self::extract_card_data_legacy($method);
                            if (!empty($card_data)) {
                                $_POST["pagarme_card_{$method}_data"] = $card_data;
                            }
                        }
                    }
                }
            }
        }
        
        // FORMATO 3: Fallback - Tentar detectar automaticamente
        
        if (empty($payment_methods)) {
            $logger->info('[MultipaymentFix] Tentando detecção automática', array(
                'source' => 'pagarme-gateway'
            ));
            
            foreach ($_POST as $key => $value) {
                // Detectar padrões de campos de método
                if (preg_match('/^(multi_method_|pagarme_multipayment_method_\d+_type)/', $key)) {
                    $logger->info('[MultipaymentFix] Campo detectado', array(
                        'key' => $key,
                        'value' => $value,
                        'source' => 'pagarme-gateway'
                    ));
                }
            }
        }
        
        // LOG FINAL
        
        $logger->info('[MultipaymentFix] Extração concluída', array(
            'methods_found' => count($payment_methods),
            'methods' => $payment_methods,
            'source' => 'pagarme-gateway'
        ));
        
        return $payment_methods;
    }
    
    /**
     * Extrair dados do cartão para um método (formato numerado)
     */
    private static function extract_card_data_for_method($index, $method) {
        $card_data = array();
        
        // Campos possíveis do cartão
        $card_fields = array(
            'card_number' => 'number',
            'card_holder' => 'holder_name',
            'card_expiry' => 'exp_month_year',
            'card_cvv' => 'cvv',
            'card_document' => 'holder_document',
            'card_installments' => 'installments'
        );
        
        foreach ($card_fields as $post_suffix => $card_key) {
            $field_name = "pagarme_multipayment_method_{$index}_{$post_suffix}";
            if (isset($_POST[$field_name]) && !empty($_POST[$field_name])) {
                $value = sanitize_text_field($_POST[$field_name]);
                
                // Processar validade (MMYY para MM e YY separados)
                if ($card_key === 'exp_month_year' && strlen($value) === 4) {
                    $card_data['exp_month'] = substr($value, 0, 2);
                    $card_data['exp_year'] = substr($value, 2, 2);
                } else {
                    $card_data[$card_key] = $value;
                }
            }
        }
        
        return $card_data;
    }
    
    /**
     * Extrair dados do cartão formato antigo
     */
    private static function extract_card_data_legacy($method) {
        $card_data = array();
        
        // Mapeamento de campos
        $field_mappings = array(
            "multi_card_{$method}_number_raw" => 'number',
            "multi_card_{$method}_holder" => 'holder_name',
            "multi_card_{$method}_expiry" => 'exp_month_year',
            "multi_card_{$method}_cvv" => 'cvv',
            "multi_card_{$method}_document" => 'holder_document',
            "multi_card_{$method}_installments" => 'installments'
        );
        
        foreach ($field_mappings as $post_field => $card_key) {
            if (isset($_POST[$post_field]) && !empty($_POST[$post_field])) {
                $value = sanitize_text_field($_POST[$post_field]);
                
                // Processar validade
                if ($card_key === 'exp_month_year' && strlen($value) === 4) {
                    $card_data['exp_month'] = substr($value, 0, 2);
                    $card_data['exp_year'] = substr($value, 2, 2);
                } else {
                    $card_data[$card_key] = $value;
                }
            }
        }
        
        return $card_data;
    }
    
    /**
     * Hook para substituir a extração original no gateway
     */
    public static function override_gateway_extraction() {
        // Esta função seria chamada no gateway principal
        // para substituir a lógica de extração original
        
        add_filter('pagarme_extract_multipayment_methods', function($methods) {
            // Se já encontrou métodos, retornar
            if (!empty($methods)) {
                return $methods;
            }
            
            // Caso contrário, usar nossa extração melhorada
            return self::extract_payment_methods_from_post();
        }, 10, 1);
    }
    
    /**
     * Função de diagnóstico para debug
     */
    public static function debug_post_data() {
        $logger = new \WC_Logger();
        
        $logger->info('[MultipaymentFix] === DEBUG POST DATA ===', array(
            'source' => 'pagarme-gateway'
        ));
        
        // Listar todos os campos relacionados a Pagar.me
        foreach ($_POST as $key => $value) {
            if (stripos($key, 'pagarme') !== false || 
                stripos($key, 'multi_') !== false || 
                stripos($key, 'multipay') !== false) {
                
                // Mascarar dados sensíveis
                if (stripos($key, 'number') !== false && strlen($value) > 4) {
                    $value = str_repeat('*', strlen($value) - 4) . substr($value, -4);
                } elseif (stripos($key, 'cvv') !== false) {
                    $value = '***';
                }
                
                $logger->info('[MultipaymentFix] Campo POST', array(
                    'key' => $key,
                    'value' => $value,
                    'source' => 'pagarme-gateway'
                ));
            }
        }
        
        $logger->info('[MultipaymentFix] === FIM DEBUG ===', array(
            'source' => 'pagarme-gateway'
        ));
    }
}

// Ativar o override automaticamente
add_action('init', array('\PagarMeGateway\MultipaymentProcessorFix', 'override_gateway_extraction'));