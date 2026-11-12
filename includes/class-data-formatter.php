<?php
/**
 * Formatador de dados para integração
 * 
 * ARQUIVO CORRIGIDO - 31/10/2025
 * Adicionado método format_amount() que estava faltando
 */

namespace PagarMeGateway;

if (!defined('ABSPATH')) {
    exit;
}

class Data_Formatter {
    
    /**
     * MÉTODO CRÍTICO ADICIONADO - Formatar valor para API Pagarme
     * 
     * A API do Pagarme espera valores em CENTAVOS (int32)
     * Exemplo: R$ 100,00 = 10000 centavos
     * 
     * ESTE MÉTODO ESTAVA FALTANDO E CAUSAVA O ERRO:
     * "Call to undefined method PagarMeGateway\Data_Formatter::format_amount()"
     * 
     * @param float|string $amount Valor em reais (ex: 100.00)
     * @return int Valor em centavos (ex: 10000)
     */
    public function format_amount($amount) {
        // Converter para float se vier como string
        $amount = floatval($amount);
        
        // Multiplicar por 100 para converter reais em centavos
        // Usar round para evitar problemas com ponto flutuante
        $cents = round($amount * 100);
        
        // Retornar como inteiro
        return (int) $cents;
    }
    
    /**
     * MÉTODO AUXILIAR - Converter centavos para reais
     * 
     * Converte centavos de volta para reais (útil para logs e exibição)
     * 
     * @param int $cents Valor em centavos (ex: 10000)
     * @return float Valor em reais (ex: 100.00)
     */
    public function format_from_cents($cents) {
        $cents = intval($cents);
        return $cents / 100;
    }
    
    /**
     * Formatar pedido completo para integração
     */
    public function format_order_for_integration($wc_order, $pagarme_response, $config = array()) {
        $company_code = $config['company_code'] ?? '10';
        $negotiation_type = $config['negotiation_type'] ?? '763';
        
        return array(
            'wc_order_id' => $wc_order->get_id(),
            'pagarme_order_id' => $pagarme_response['id'],
            'company_code' => $company_code,
            'negotiation_type' => $negotiation_type,
            'customer_data' => $this->format_customer_data($wc_order),
            'order_data' => $this->format_order_data($wc_order),
            'items' => $this->format_items_for_sankhya($wc_order),
            'payment_data' => $this->format_payment_data($wc_order, $pagarme_response),
            'addresses' => $this->format_addresses($wc_order),
            'created_at' => current_time('mysql'),
            'processed' => false
        );
    }
    
    /**
     * Formatar dados do cliente
     */
    private function format_customer_data($wc_order) {
        $customer_data = array(
            'name' => trim($wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name()),
            'email' => $wc_order->get_billing_email(),
            'phone' => $this->format_phone($wc_order->get_billing_phone()),
            'document' => $this->get_customer_document($wc_order),
            'company' => $wc_order->get_billing_company(),
            'customer_id' => $wc_order->get_customer_id(),
            'is_guest' => $wc_order->get_customer_id() === 0
        );
        
        // Adicionar dados adicionais se cliente logado
        if ($wc_order->get_customer_id()) {
            $user = get_user_by('id', $wc_order->get_customer_id());
            if ($user) {
                $customer_data['username'] = $user->user_login;
                $customer_data['registration_date'] = $user->user_registered;
                
                // Pegar tipo de negociação específico do cliente se houver
                $customer_negotiation_type = get_user_meta($wc_order->get_customer_id(), '_sankhya_negotiation_type', true);
                if (!empty($customer_negotiation_type)) {
                    $customer_data['custom_negotiation_type'] = $customer_negotiation_type;
                }
            }
        }
        
        return $customer_data;
    }
    
    /**
     * Formatar dados do pedido
     */
    private function format_order_data($wc_order) {
        return array(
            'total' => floatval($wc_order->get_total()),
            'subtotal' => floatval($wc_order->get_subtotal()),
            'tax_total' => floatval($wc_order->get_total_tax()),
            'shipping_total' => floatval($wc_order->get_shipping_total()),
            'discount_total' => floatval($wc_order->get_discount_total()),
            'date' => $wc_order->get_date_created()->format('Y-m-d H:i:s'),
            'status' => $wc_order->get_status(),
            'currency' => $wc_order->get_currency(),
            'payment_method' => $wc_order->get_payment_method(),
            'payment_method_title' => $wc_order->get_payment_method_title(),
            'customer_note' => $wc_order->get_customer_note(),
            'order_key' => $wc_order->get_order_key()
        );
    }
    
    /**
     * Formatar items para integração Sankhya
     */
    private function format_items_for_sankhya($wc_order) {
        $items = array();
        
        foreach ($wc_order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            
            if (!$product) {
                continue;
            }
            
            // Código do produto no Sankhya (buscar em meta_data)
            $sankhya_product_code = get_post_meta($product->get_id(), '_sankhya_product_code', true);
            if (empty($sankhya_product_code)) {
                $sankhya_product_code = $product->get_sku() ?: 'PROD_' . $product->get_id();
            }
            
            $items[] = array(
                'wc_item_id' => $item_id,
                'type' => 'product',
                'product_id' => $product->get_id(),
                'product_name' => $item->get_name(),
                'sankhya_product_code' => $sankhya_product_code,
                'sku' => $product->get_sku(),
                'quantity' => $item->get_quantity(),
                'unit_price' => floatval($item->get_subtotal() / $item->get_quantity()),
                'total_price' => floatval($item->get_total()),
                'tax_total' => floatval($item->get_total_tax()),
                'categories' => $this->get_product_categories($product),
                'attributes' => $this->get_product_attributes($product)
            );
        }
        
        // Adicionar frete como item se houver
        if ($wc_order->get_shipping_total() > 0) {
            $shipping_methods = $wc_order->get_shipping_methods();
            $shipping_method = !empty($shipping_methods) ? reset($shipping_methods) : null;
            
            $items[] = array(
                'wc_item_id' => 'shipping',
                'type' => 'shipping',
                'product_name' => $shipping_method ? $shipping_method->get_name() : 'Frete',
                'sankhya_product_code' => 'FRETE',
                'quantity' => 1,
                'unit_price' => floatval($wc_order->get_shipping_total()),
                'total_price' => floatval($wc_order->get_shipping_total()),
                'tax_total' => floatval($wc_order->get_shipping_tax()),
                'shipping_method' => $shipping_method ? $shipping_method->get_method_id() : null
            );
        }
        
        return $items;
    }
    
    /**
     * Formatar dados de pagamento
     */
    private function format_payment_data($wc_order, $pagarme_response) {
        $payment_data = array(
            'pagarme_order_id' => $pagarme_response['id'],
            'pagarme_status' => $pagarme_response['status'],
            'amount' => isset($pagarme_response['amount']) ? $pagarme_response['amount'] / 100 : $wc_order->get_total(),
            'currency' => $pagarme_response['currency'] ?? 'BRL',
            'payment_method' => 'credit_card',
            'created_at' => $pagarme_response['created_at'] ?? current_time('mysql')
        );
        
        // Dados das transações/cobranças
        if (isset($pagarme_response['charges']) && !empty($pagarme_response['charges'])) {
            $charge = $pagarme_response['charges'][0];
            
            $payment_data['charge_id'] = $charge['id'];
            $payment_data['charge_status'] = $charge['status'];
            $payment_data['charge_amount'] = $charge['amount'] / 100;
            
            // Dados do cartão (sem informações sensíveis)
            if (isset($charge['payment_method']['credit_card'])) {
                $card_data = $charge['payment_method']['credit_card'];
                $payment_data['card_data'] = array(
                    'brand' => $card_data['brand'] ?? null,
                    'first_digits' => $card_data['first_digits'] ?? null,
                    'last_digits' => $card_data['last_digits'] ?? null,
                    'holder_name' => $card_data['holder_name'] ?? null
                );
            }
            
            // Dados da transação
            if (isset($charge['last_transaction'])) {
                $transaction = $charge['last_transaction'];
                $payment_data['transaction'] = array(
                    'id' => $transaction['id'] ?? null,
                    'status' => $transaction['status'] ?? null,
                    'authorization_code' => $transaction['authorization_code'] ?? null,
                    'nsu' => $transaction['nsu'] ?? null,
                    'tid' => $transaction['tid'] ?? null
                );
                
                // Dados da resposta do gateway
                if (isset($transaction['gateway_response'])) {
                    $gateway_response = $transaction['gateway_response'];
                    $payment_data['gateway_response'] = array(
                        'code' => $gateway_response['code'] ?? null,
                        'message' => $gateway_response['message'] ?? null
                    );
                }
            }
        }
        
        return $payment_data;
    }
    
    /**
     * Formatar endereços
     */
    private function format_addresses($wc_order) {
        return array(
            'billing' => array(
                'first_name' => $wc_order->get_billing_first_name(),
                'last_name' => $wc_order->get_billing_last_name(),
                'company' => $wc_order->get_billing_company(),
                'address_1' => $wc_order->get_billing_address_1(),
                'address_2' => $wc_order->get_billing_address_2(),
                'city' => $wc_order->get_billing_city(),
                'state' => $wc_order->get_billing_state(),
                'postcode' => $wc_order->get_billing_postcode(),
                'country' => $wc_order->get_billing_country(),
                'phone' => $this->format_phone($wc_order->get_billing_phone()),
                'email' => $wc_order->get_billing_email()
            ),
            'shipping' => array(
                'first_name' => $wc_order->get_shipping_first_name(),
                'last_name' => $wc_order->get_shipping_last_name(),
                'company' => $wc_order->get_shipping_company(),
                'address_1' => $wc_order->get_shipping_address_1(),
                'address_2' => $wc_order->get_shipping_address_2(),
                'city' => $wc_order->get_shipping_city(),
                'state' => $wc_order->get_shipping_state(),
                'postcode' => $wc_order->get_shipping_postcode(),
                'country' => $wc_order->get_shipping_country()
            )
        );
    }
    
    /**
     * Obter documento do cliente (CPF/CNPJ)
     */
    private function get_customer_document($wc_order) {
        // Tentar pegar CPF/CNPJ dos meta campos mais comuns
        $document_fields = array(
            '_billing_cpf',
            '_billing_cnpj', 
            'billing_cpf',
            'billing_cnpj',
            '_billing_document',
            'billing_document'
        );
        
        foreach ($document_fields as $field) {
            $document = $wc_order->get_meta($field);
            if (!empty($document)) {
                return $this->format_document($document);
            }
        }
        
        return '';
    }
    
    /**
     * Formatar documento (CPF/CNPJ)
     */
    private function format_document($document) {
        // Remove tudo que não é número
        $document = preg_replace('/\D/', '', $document);
        
        // Validação básica de CPF/CNPJ
        if (strlen($document) === 11) {
            // CPF
            return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $document);
        } elseif (strlen($document) === 14) {
            // CNPJ
            return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $document);
        }
        
        return $document;
    }
    
    /**
     * Formatar telefone
     */
    private function format_phone($phone) {
        if (empty($phone)) {
            return '';
        }
        
        // Remove tudo que não é número
        $phone = preg_replace('/\D/', '', $phone);
        
        // Formatação baseada no tamanho
        if (strlen($phone) === 11) {
            // Celular com 9 dígitos
            return preg_replace('/(\d{2})(\d{5})(\d{4})/', '($1) $2-$3', $phone);
        } elseif (strlen($phone) === 10) {
            // Telefone fixo
            return preg_replace('/(\d{2})(\d{4})(\d{4})/', '($1) $2-$3', $phone);
        }
        
        return $phone;
    }
    
    /**
     * Obter categorias do produto
     */
    private function get_product_categories($product) {
        $categories = array();
        $terms = get_the_terms($product->get_id(), 'product_cat');
        
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $categories[] = array(
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug
                );
            }
        }
        
        return $categories;
    }
    
    /**
     * Obter atributos do produto
     */
    private function get_product_attributes($product) {
        $attributes = array();
        
        foreach ($product->get_attributes() as $attribute_name => $attribute) {
            if ($attribute->get_variation()) {
                continue; // Skip variation attributes
            }
            
            $attribute_data = array(
                'name' => $attribute_name,
                'options' => array()
            );
            
            if ($attribute->is_taxonomy()) {
                $terms = get_terms(array(
                    'taxonomy' => $attribute->get_name(),
                    'include' => $attribute->get_options()
                ));
                
                foreach ($terms as $term) {
                    $attribute_data['options'][] = $term->name;
                }
            } else {
                $attribute_data['options'] = $attribute->get_options();
            }
            
            $attributes[] = $attribute_data;
        }
        
        return $attributes;
    }
    
    /**
     * Formatar dados especificamente para o formato esperado pelo Sankhya
     */
    public function format_for_sankhya_api($integration_data) {
        return array(
            'cabecalho' => array(
                'CODEMP' => $integration_data['company_code'],
                'CODTIPVENDA' => $integration_data['negotiation_type'],
                'DTNEG' => date('d/m/Y', strtotime($integration_data['order_data']['date'])),
                'CODPARC' => $this->get_or_create_customer_code($integration_data['customer_data']),
                'VLRNOTA' => $integration_data['order_data']['total'],
                'OBSERVACAO' => 'Pedido WooCommerce #' . $integration_data['wc_order_id'] . ' - Pagar.me: ' . $integration_data['pagarme_order_id']
            ),
            'itens' => $this->format_items_for_sankhya_api($integration_data['items'])
        );
    }
    
    /**
     * Formatar itens para API Sankhya
     */
    private function format_items_for_sankhya_api($items) {
        $sankhya_items = array();
        
        foreach ($items as $item) {
            // Pular itens que não são produtos físicos para o Sankhya
            if (isset($item['type']) && in_array($item['type'], ['fee', 'shipping'])) {
                continue;
            }
            
            $sankhya_items[] = array(
                'CODPROD' => $item['sankhya_product_code'],
                'QTDNEG' => $item['quantity'],
                'VLRUNIT' => $item['unit_price'],
                'VLRTOT' => $item['total_price'],
                'CODVOL' => 'UN', // Unidade padrão
                'CODLOCALORIG' => 0 // Local de estoque padrão
            );
        }
        
        return $sankhya_items;
    }
    
    /**
     * Obter ou criar código do cliente
     */
    private function get_or_create_customer_code($customer_data) {
        // Esta função deveria idealmente consultar o Sankhya para buscar
        // o código do cliente existente ou criar um novo
        // Por enquanto, retorna um código baseado no email
        return 'WC_' . substr(md5($customer_data['email']), 0, 8);
    }
}