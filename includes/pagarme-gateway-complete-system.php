<?php
/**
 * SISTEMA COMPLETO DE GATEWAY PAGARME
 * 
 * Este arquivo contém todas as alterações necessárias para:
 * Todos os métodos de pagamento disponíveis na API Pagar.me
 * Sistema de multipagamentos configurável
 * Sistema de antifraude integrado
 * API REST para integração com multistep
 * Configurações flexíveis no admin
 * 
 * @package PagarMeGateway
 * @version 2.0.0
 */

// PARTE 1: CLASSE PRINCIPAL DO GATEWAY COM TODOS OS MÉTODOS

namespace PagarMeGateway;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe Principal do Gateway Pagar.me
 * Gerencia todos os métodos de pagamento disponíveis
 */
class PagarMe_Gateway_Complete extends \WC_Payment_Gateway {
    
    // Propriedades do Gateway
    private $api_key;
    private $environment;
    private $logger;
    private $data_formatter;

    /**
     * ADICIONAR ESTA LINHA
     * Flag para controlar renderização única do boleto
     */
    private static $boleto_rendered = false;
    
    // Controle de métodos de pagamento
    private $enabled_methods = array();
    private $multipayment_enabled = false;
    private $multipayment_combinations = array();
    private $antifraud_enabled = false;

    /**
     * Construtor
     */
    public function __construct() {
        $this->id = 'pagarme_gateway';
        $this->icon = '';
        $this->has_fields = true;
        $this->method_title = 'Gateway Pagar.me Completo';
        $this->method_description = 'Gateway com todos os métodos de pagamento Pagar.me';
        
        $this->supports = array(
            'products',
            'refunds',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes'
        );
        
        $this->init_form_fields();
        $this->init_settings();
        
        // Configurações básicas
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->api_key = $this->get_option('api_key');
        $this->environment = $this->get_option('environment', 'sandbox');
        
        // Configurações de multipagamento
        $this->multipayment_enabled = $this->get_option('enable_multipayment') === 'yes';
        $this->multipayment_combinations = $this->get_option('multipayment_combinations', array());
        
        // Configurações de antifraude
        $this->antifraud_enabled = $this->get_option('enable_antifraud') === 'yes';
        
        // Inicializar componentes
        $this->logger = new Logger();
        $this->data_formatter = new Data_Formatter();
        
        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, 
                array($this, 'process_admin_options'));
        
        // HOOK ATIVADO - Exibir boleto/PIX na thank you page
        add_action('woocommerce_thankyou_' . $this->id, 
                array($this, 'thankyou_page'));

        // ADICIONAR ESTA LINHA
        // Resetar flag do boleto no footer
        add_action('wp_footer', array($this, 'reset_boleto_flag'), 999);                
        
        // CRÍTICO: PRIORIDADE 20 para carregar DEPOIS do Multistep
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'), 20);

        // NOVO: Carregar funcionalidade de segunda via do boleto
        if (file_exists(PAGARME_GATEWAY_PATH . 'includes/class-boleto-frontend-display.php')) {
            require_once PAGARME_GATEWAY_PATH . 'includes/class-boleto-frontend-display.php';
        }

    }

    /**
     * Carregar scripts e estilos do checkout
     * Este método é chamado pelo hook wp_enqueue_scripts
     */
    public function payment_scripts() {
        // Só carregar no checkout ou na página de adicionar método de pagamento
        if (!is_checkout() && !is_add_payment_method_page()) {
            return;
        }
        
        // Verificar se o gateway está habilitado
        if ($this->enabled !== 'yes') {
            return;
        }
        
        // SCRIPTS DO PAGAR.ME (ORIGINAIS)
        
        // Script de integração com multistep
        wp_enqueue_script(
            'pagarme-multistep-integration',
            plugins_url('assets/js/pagarme-multistep-integration.js', dirname(__FILE__)),
            array('jquery'),
            PAGARME_GATEWAY_VERSION,
            true
        );
        
        // Script principal do checkout
        wp_enqueue_script(
            'pagarme-checkout',
            plugins_url('assets/js/checkout.js', dirname(__FILE__)),
            array('jquery', 'wc-checkout'),
            PAGARME_GATEWAY_VERSION,
            true
        );

        // CORREÇÃO COMPLETA DE SINGLEPAYMENT

        // Correção Single Payment
        wp_enqueue_script(
            'pagarme-singlepayment-fix',
            plugins_url('assets/js/pagarme-singlepayment-fix.js', dirname(__FILE__)),
            array('jquery', 'pagarme-checkout'), // Depende do jQuery e do checkout.js
            '1.0.0',
            true
        );

        // Correção Boleto/PIX - Limpeza de Campos
        wp_enqueue_script(
            'pagarme-boleto-pix-fix',
            plugins_url('assets/js/pagarme-boleto-pix-fix.js', dirname(__FILE__)),
            array('jquery', 'pagarme-singlepayment-fix'),
            '1.0.0',
            true
        );
 
        // Correção de injeção de campos multipagamento
        wp_enqueue_script(
            'pagarme-multipayment-fix',
            plugins_url('assets/js/pagarme-multipayment-fix.js', dirname(__FILE__)),
            array('jquery', 'pagarme-checkout', 'pagarme-singlepayment-fix', 'pagarme-boleto-pix-fix'),
            '1.0.0',
            true
        );

        wp_enqueue_script(
            'pagarme-preserve-payment',
            plugins_url('assets/js/pagarme-preserve-payment-method.js', dirname(__FILE__)),
            array('jquery', 'pagarme-checkout', 'pagarme-singlepayment-fix'),
            '1.0.0',
            true
        );
        
        // CSS

        // wp_enqueue_style(
        //     'pagarme-checkout-style',
        //     plugins_url('assets/css/checkout.css', dirname(__FILE__)),
        //     array(),
        //     PAGARME_GATEWAY_VERSION
        // );
        
        // PASSAR CONFIGURAÇÕES PARA JAVASCRIPT
        
        wp_localize_script('pagarme-checkout', 'pagarme_params', array(
            // URLs e autenticação
            'ajax_url' => admin_url('admin-ajax.php'),
            'api_url' => rest_url('pagarme/v1'),
            'checkout_url' => wc_get_checkout_url(),
            'nonce' => wp_create_nonce('pagarme_checkout'),
            
            // Métodos habilitados
            'enabled_methods' => $this->get_enabled_payment_methods(),
            
            // MULTIPAGAMENTO
            'multipayment_enabled' => $this->is_multipayment_enabled(),
            'multipayment_combinations' => $this->get_option('multipayment_combinations', array()),
            'multipayment_min_value' => floatval($this->get_option('multipayment_min_value', 100)),
            'multipayment_max_methods' => $this->get_option('multipayment_max_methods', '2'),
            
            // Antifraude
            'antifraud_enabled' => $this->is_antifraud_enabled(),
            
            // Ambiente
            'environment' => $this->environment,
            
            // CONFIGURAÇÕES DE PARCELAS
            'installments' => array(
                'max' => intval($this->get_option('credit_card_max_installments', 12)),
                'min_value' => floatval($this->get_option('credit_card_min_installment_value', 5)),
                'interest_rate' => floatval($this->get_option('credit_card_interest_rate', 0))
            ),
            
            // CONFIGURAÇÕES DE PIX
            'pix' => array(
                'enabled' => $this->get_option('enable_pix') === 'yes',
                'discount' => floatval($this->get_option('pix_discount', 0)),
                'expiration' => intval($this->get_option('pix_expiration', 30))
            ),
            
            // CONFIGURAÇÕES DE BOLETO
            'boleto' => array(
                'enabled' => $this->get_option('enable_boleto') === 'yes',
                'discount' => floatval($this->get_option('boleto_discount', 0)),
                'days_to_expire' => intval($this->get_option('boleto_days_to_expire', 3))
            ),
            
            // NOVO: TOTAL DO PEDIDO
            'order_total' => WC()->cart ? WC()->cart->get_total('raw') : 0,
            
            // Traduções
            'i18n' => array(
                'invalid_card' => __('Número de cartão inválido', 'pagarme-gateway'),
                'invalid_cvv' => __('CVV inválido', 'pagarme-gateway'),
                'invalid_expiry' => __('Data de validade inválida', 'pagarme-gateway'),
                'invalid_cpf' => __('CPF inválido', 'pagarme-gateway'),
                'processing' => __('Processando pagamento...', 'pagarme-gateway'),
                'error' => __('Erro ao processar pagamento', 'pagarme-gateway'),
            )
        ));
        
        // NOVO: PASSAR CONFIGURAÇÕES PARA O VALIDADOR
        wp_localize_script('pagarme-checkout-validator', 'pagarme_validator_params', array(
            'order_total' => WC()->cart ? WC()->cart->get_total('raw') : 0,
            'debug_mode' => defined('WP_DEBUG') && WP_DEBUG,
        ));
        
        // SCRIPT DE VALIDAÇÃO DE CARTÃO (ORIGINAL)
        
        if ($this->get_option('enable_credit_card') === 'yes' || 
            $this->get_option('enable_debit_card') === 'yes') {
            wp_enqueue_script(
                'pagarme-card-validator',
                plugins_url('assets/js/card-validator.js', dirname(__FILE__)),
                array('jquery'),
                PAGARME_GATEWAY_VERSION,
                true
            );
        }
        
        // NOVO: ESTILOS CSS INLINE PARA VALIDAÇÕES
        $this->add_validator_inline_styles();
    }

/*
    /**
    * Adicionar estilos inline para o validador
    *
    * @return void
    */
    private function add_validator_inline_styles() {
        wp_add_inline_style('pagarme-checkout-style', '
            ESTILOS DO VALIDADOR PAGAR.ME
            ============================================ */
            
            /* Campo com erro */
            .pagarme-field-error {
                border: 2px solid #dc3545 !important;
                background-color: #fff5f5 !important;
                animation: pagarme-shake 0.3s;
            }
            
            /* Valor inválido no multipagamento */
            .invalid-amount {
                border: 2px solid #ffc107 !important;
                background-color: #fffbf0 !important;
            }
            
            /* Animação de shake */
            @keyframes pagarme-shake {
                0%, 100% { transform: translateX(0); }
                25% { transform: translateX(-10px); }
                75% { transform: translateX(10px); }
            }
            
            /* Mensagem de erro customizada */
            .pagarme-error-message {
                background: #dc3545;
                color: #fff;
                padding: 15px 20px;
                border-radius: 8px;
                margin-bottom: 20px;
                font-size: 15px;
                line-height: 1.6;
                box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
                animation: pagarme-slideDown 0.3s ease;
            }
            
            @keyframes pagarme-slideDown {
                from {
                    opacity: 0;
                    transform: translateY(-20px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            
            /* Indicador de saldo do multipagamento */
            #multipayment-balance-indicator {
                position: fixed;
                bottom: 20px;
                right: 20px;
                z-index: 9999;
                padding: 15px 20px;
                border-radius: 8px;
                font-weight: bold;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                font-size: 16px;
                transition: all 0.3s ease;
            }
            
            /* Responsivo */
            @media (max-width: 768px) {
                #multipayment-balance-indicator {
                    bottom: 10px;
                    right: 10px;
                    font-size: 14px;
                    padding: 12px 16px;
                }
            }
        ');
    }
    
    /**
     * Definir campos de configuração do admin
     */
    public function init_form_fields() {
        $this->form_fields = array(
            // ===== CONFIGURAÇÕES BÁSICAS =====
            'enabled' => array(
                'title' => 'Ativar/Desativar',
                'type' => 'checkbox',
                'label' => 'Ativar Gateway Pagar.me',
                'default' => 'no'
            ),
            'title' => array(
                'title' => 'Título',
                'type' => 'text',
                'description' => 'Título exibido no checkout',
                'default' => 'Pagamento Online',
                'desc_tip' => true
            ),
            'description' => array(
                'title' => 'Descrição',
                'type' => 'textarea',
                'description' => 'Descrição exibida no checkout',
                'default' => 'Escolha sua forma de pagamento'
            ),
            
            // ===== API =====
            'api_section' => array(
                'title' => 'Configurações da API',
                'type' => 'title'
            ),
            'api_key' => array(
                'title' => 'Chave de API',
                'type' => 'password',
                'description' => 'Sua chave secreta da API Pagar.me',
                'desc_tip' => true
            ),
            'environment' => array(
                'title' => 'Ambiente',
                'type' => 'select',
                'options' => array(
                    'sandbox' => 'Sandbox (Testes)',
                    'production' => 'Produção'
                ),
                'default' => 'sandbox'
            ),
            
            // ===== MÉTODOS DE PAGAMENTO =====
            'payment_methods_section' => array(
                'title' => '💳 Métodos de Pagamento Disponíveis',
                'type' => 'title',
                'description' => 'Configure quais métodos estarão disponíveis no checkout'
            ),
            
            // Cartão de Crédito
            'enable_credit_card' => array(
                'title' => 'Cartão de Crédito',
                'type' => 'checkbox',
                'label' => 'Habilitar pagamento com Cartão de Crédito',
                'default' => 'yes'
            ),
            'credit_card_max_installments' => array(
                'title' => '└─ Máximo de Parcelas',
                'type' => 'number',
                'default' => '12',
                'custom_attributes' => array('min' => '1', 'max' => '24')
            ),
            'credit_card_min_installment_value' => array(
                'title' => '└─ Valor Mínimo da Parcela',
                'type' => 'price',
                'default' => '5'
            ),
            'credit_card_interest_rate' => array(
                'title' => '└─ Taxa de Juros (%)',
                'type' => 'decimal',
                'default' => '0',
                'description' => 'Taxa mensal para parcelamento com juros'
            ),
            
            // Cartão de Débito
            'enable_debit_card' => array(
                'title' => 'Cartão de Débito',
                'type' => 'checkbox',
                'label' => 'Habilitar pagamento com Cartão de Débito',
                'default' => 'no'
            ),
            'debit_card_brands' => array(
                'title' => '└─ Bandeiras Aceitas',
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select',
                'options' => array(
                    'visa' => 'Visa',
                    'mastercard' => 'Mastercard',
                    'elo' => 'Elo',
                    'maestro' => 'Maestro'
                ),
                'default' => array('visa', 'mastercard')
            ),
            
            // PIX
            'enable_pix' => array(
                'title' => 'PIX',
                'type' => 'checkbox',
                'label' => 'Habilitar pagamento via PIX',
                'default' => 'yes'
            ),
            'pix_discount' => array(
                'title' => '└─ Desconto PIX (%)',
                'type' => 'number',
                'default' => '0',
                'custom_attributes' => array('min' => '0', 'max' => '100')
            ),
            'pix_expiration' => array(
                'title' => '└─ Tempo de Expiração (minutos)',
                'type' => 'number',
                'default' => '30',
                'custom_attributes' => array('min' => '5', 'max' => '1440')
            ),
            
            // Boleto
            'enable_boleto' => array(
                'title' => 'Boleto Bancário',
                'type' => 'checkbox',
                'label' => 'Habilitar pagamento com Boleto',
                'default' => 'yes'
            ),
            'boleto_discount' => array(
                'title' => '└─ Desconto Boleto (%)',
                'type' => 'number',
                'default' => '0'
            ),
            'boleto_days_to_pay' => array(
                'title' => '└─ Dias para Vencimento',
                'type' => 'number',
                'default' => '3',
                'custom_attributes' => array('min' => '1', 'max' => '30')
            ),
            'boleto_bank' => array(
                'title' => '└─ Banco Emissor',
                'type' => 'select',
                'default' => '341',
                'options' => array(
                    '001' => 'Banco do Brasil',
                    '033' => 'Santander',
                    '104' => 'Caixa Econômica Federal',
                    '237' => 'Bradesco',
                    '341' => 'Itaú',
                    '745' => 'Citibank'
                )
            ),
            
            // Voucher (Vale Alimentação/Refeição)
            'enable_voucher' => array(
                'title' => 'Voucher (Vale Alimentação/Refeição)',
                'type' => 'checkbox',
                'label' => 'Habilitar pagamento com Voucher',
                'default' => 'no'
            ),
            'voucher_brands' => array(
                'title' => '└─ Bandeiras Aceitas',
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select',
                'options' => array(
                    'alelo' => 'Alelo',
                    'ticket' => 'Ticket',
                    'vr' => 'VR',
                    'sodexo' => 'Sodexo'
                ),
                'default' => array('alelo', 'vr')
            ),
            
            // Transferência Bancária
            'enable_bank_transfer' => array(
                'title' => 'Transferência Bancária',
                'type' => 'checkbox',
                'label' => 'Habilitar Transferência Bancária',
                'default' => 'no'
            ),
            'bank_transfer_banks' => array(
                'title' => '└─ Bancos Disponíveis',
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select',
                'options' => array(
                    '001' => 'Banco do Brasil',
                    '237' => 'Bradesco',
                    '341' => 'Itaú'
                ),
                'default' => array('001', '237', '341')
            ),
            
            // Cash (Dinheiro - para lojas físicas)
            'enable_cash' => array(
                'title' => 'Dinheiro (Cash)',
                'type' => 'checkbox',
                'label' => 'Habilitar pagamento em dinheiro (lojas físicas)',
                'default' => 'no'
            ),
            
            // SafetyPay
            'enable_safetypay' => array(
                'title' => 'SafetyPay',
                'type' => 'checkbox',
                'label' => 'Habilitar pagamento via SafetyPay',
                'default' => 'no'
            ),
            
            // ===== MULTIPAGAMENTOS =====
            'multipayment_section' => array(
                'title' => '💰 Configurações de Multipagamentos',
                'type' => 'title',
                'description' => 'Permite que o cliente pague com múltiplos métodos'
            ),
            'enable_multipayment' => array(
                'title' => 'Habilitar Multipagamentos',
                'type' => 'checkbox',
                'label' => 'Permitir combinação de métodos de pagamento',
                'default' => 'no'
            ),
            'multipayment_combinations' => array(
                'title' => 'Combinações Permitidas',
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select',
                'options' => array(
                    'credit_pix' => 'Cartão de Crédito + PIX',
                    'credit_boleto' => 'Cartão de Crédito + Boleto',
                    'pix_boleto' => 'PIX + Boleto',
                    'credit_pix_boleto' => 'Cartão + PIX + Boleto',
                    'debit_pix' => 'Cartão de Débito + PIX',
                    'voucher_credit' => 'Voucher + Cartão de Crédito'
                ),
                'default' => array('credit_pix', 'credit_boleto')
            ),
            'multipayment_min_value' => array(
                'title' => 'Valor Mínimo para Multipagamento',
                'type' => 'price',
                'default' => '100',
                'description' => 'Valor mínimo do pedido para habilitar multipagamento'
            ),
            'multipayment_max_methods' => array(
                'title' => 'Máximo de Métodos Simultâneos',
                'type' => 'number',
                'default' => '2',
                'custom_attributes' => array('min' => '2', 'max' => '3')
            ),
            
            // ===== ANTIFRAUDE =====
            'antifraud_section' => array(
                'title' => '🛡️ Configurações de Antifraude',
                'type' => 'title',
                'description' => 'Sistema de prevenção de fraudes'
            ),
            'enable_antifraud' => array(
                'title' => 'Habilitar Antifraude',
                'type' => 'checkbox',
                'label' => 'Ativar análise antifraude nos pedidos',
                'default' => 'no'
            ),
            'antifraud_min_value' => array(
                'title' => 'Valor Mínimo para Análise',
                'type' => 'price',
                'default' => '50',
                'description' => 'Pedidos abaixo deste valor não serão analisados'
            ),
            'antifraud_auto_cancel' => array(
                'title' => 'Cancelar Automaticamente se Reprovado',
                'type' => 'checkbox',
                'label' => 'Cancelar pedidos reprovados pelo antifraude',
                'default' => 'yes'
            ),
            'antifraud_manual_review' => array(
                'title' => 'Permitir Revisão Manual',
                'type' => 'checkbox',
                'label' => 'Permitir revisão manual de pedidos suspeitos',
                'default' => 'yes'
            ),
            
            // ===== CONFIGURAÇÕES ADICIONAIS =====
            'additional_section' => array(
                'title' => '⚙️ Configurações Adicionais',
                'type' => 'title'
            ),
            'soft_descriptor' => array(
                'title' => 'Soft Descriptor',
                'type' => 'text',
                'description' => 'Nome que aparecerá na fatura (máx. 13 caracteres)',
                'default' => substr(get_bloginfo('name'), 0, 13),
                'custom_attributes' => array('maxlength' => '13')
            ),
            'capture_method' => array(
                'title' => 'Método de Captura',
                'type' => 'select',
                'options' => array(
                    'automatic' => 'Captura Automática',
                    'manual' => 'Captura Manual'
                ),
                'default' => 'automatic',
                'description' => 'Define se a captura será automática ou manual'
            ),
            'log_level' => array(
                'title' => 'Nível de Log',
                'type' => 'select',
                'options' => array(
                    'none' => 'Desabilitado',
                    'error' => 'Apenas Erros',
                    'info' => 'Informações',
                    'debug' => 'Debug Completo'
                ),
                'default' => 'info'
            )
        );
    }
    
    /**
     * Obter métodos de pagamento habilitados
     */
    public function get_enabled_payment_methods() {
        $methods = array();
        
        // Cartão de Crédito
        if ($this->get_option('enable_credit_card') === 'yes') {
            $methods['credit_card'] = array(
                'title' => 'Cartão de Crédito',
                'icon' => '💳',
                'max_installments' => $this->get_option('credit_card_max_installments', 12),
                'min_installment_value' => $this->get_option('credit_card_min_installment_value', 5),
                'interest_rate' => $this->get_option('credit_card_interest_rate', 0)
            );
        }
        
        // Cartão de Débito
        if ($this->get_option('enable_debit_card') === 'yes') {
            $methods['debit_card'] = array(
                'title' => 'Cartão de Débito',
                'icon' => '💳',
                'brands' => $this->get_option('debit_card_brands', array('visa', 'mastercard'))
            );
        }
        
        // PIX
        if ($this->get_option('enable_pix') === 'yes') {
            $methods['pix'] = array(
                'title' => 'PIX',
                'icon' => '⚡',
                'discount' => $this->get_option('pix_discount', 0),
                'expiration' => $this->get_option('pix_expiration', 30)
            );
        }
        
        // Boleto
        if ($this->get_option('enable_boleto') === 'yes') {
            $methods['boleto'] = array(
                'title' => 'Boleto Bancário',
                'icon' => '📄',
                'discount' => $this->get_option('boleto_discount', 0),
                'days_to_pay' => $this->get_option('boleto_days_to_pay', 3),
                'bank' => $this->get_option('boleto_bank', '341')
            );
        }
        
        // Voucher
        if ($this->get_option('enable_voucher') === 'yes') {
            $methods['voucher'] = array(
                'title' => 'Vale Alimentação/Refeição',
                'icon' => '🎫',
                'brands' => $this->get_option('voucher_brands', array('alelo', 'vr'))
            );
        }
        
        // Transferência Bancária
        if ($this->get_option('enable_bank_transfer') === 'yes') {
            $methods['bank_transfer'] = array(
                'title' => 'Transferência Bancária',
                'icon' => '🏦',
                'banks' => $this->get_option('bank_transfer_banks', array('001', '237', '341'))
            );
        }
        
        // Cash
        if ($this->get_option('enable_cash') === 'yes') {
            $methods['cash'] = array(
                'title' => 'Dinheiro',
                'icon' => '💵'
            );
        }
        
        // SafetyPay
        if ($this->get_option('enable_safetypay') === 'yes') {
            $methods['safetypay'] = array(
                'title' => 'SafetyPay',
                'icon' => '🔒'
            );
        }
        
        return $methods;
    }
    
    /**
     * Verificar se multipagamento está habilitado
     */
    public function is_multipayment_enabled() {
        if (!$this->multipayment_enabled) {
            return false;
        }
        
        // Verificar se o valor do carrinho atinge o mínimo
        $min_value = floatval($this->get_option('multipayment_min_value', 100));
        $cart_total = WC()->cart ? WC()->cart->total : 0;
        
        return $cart_total >= $min_value;
    }
    
    /**
     * Obter combinações permitidas de multipagamento
     */
    public function get_multipayment_combinations() {
        if (!$this->is_multipayment_enabled()) {
            return array();
        }
        
        $combinations = $this->get_option('multipayment_combinations', array());
        $enabled_methods = $this->get_enabled_payment_methods();
        $valid_combinations = array();
        
        // Validar combinações com métodos habilitados
        foreach ($combinations as $combination) {
            $methods = explode('_', $combination);
            $all_enabled = true;
            
            foreach ($methods as $method) {
                // Mapear abreviações para nomes completos
                $method_map = array(
                    'credit' => 'credit_card',
                    'debit' => 'debit_card',
                    'pix' => 'pix',
                    'boleto' => 'boleto',
                    'voucher' => 'voucher'
                );
                
                $full_method = isset($method_map[$method]) ? $method_map[$method] : $method;
                
                if (!isset($enabled_methods[$full_method])) {
                    $all_enabled = false;
                    break;
                }
            }
            
            if ($all_enabled) {
                $valid_combinations[] = $combination;
            }
        }
        
        return $valid_combinations;
    }
    
    /**
     * Verificar se antifraude está habilitado
     */
    public function is_antifraud_enabled($order_total = 0) {
        if (!$this->antifraud_enabled) {
            return false;
        }
        
        // Verificar valor mínimo
        $min_value = floatval($this->get_option('antifraud_min_value', 50));
        
        return $order_total >= $min_value;
    }
    
    /**
     * Processar pagamento
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        $is_multipayment = isset($_POST['pagarme_multipayment']) && $_POST['pagarme_multipayment'] === 'yes';

        $payment_method = '';

        if ( ! $is_multipayment ) {
            // Lógica de fallback para pagamento único (copiada da versão de correção)
            if ( isset($_POST['pagarme_selected_method']) && ! empty($_POST['pagarme_selected_method']) ) {
                $payment_method = sanitize_text_field($_POST['pagarme_selected_method']);
            } elseif ( isset($_POST['pagarme_payment_method']) && ! empty($_POST['pagarme_payment_method']) ) {
                $payment_method = sanitize_text_field($_POST['pagarme_payment_method']);
            } elseif ( isset($_POST['pagarme_payment_type']) && ! empty($_POST['pagarme_payment_type']) ) {
                $payment_method = sanitize_text_field($_POST['pagarme_payment_type']);
            } elseif ( isset($_POST['_detected_pagarme_method']) && ! empty($_POST['_detected_pagarme_method']) ) {
                $payment_method = sanitize_text_field($_POST['_detected_pagarme_method']);
            }

            if ( empty($payment_method) ) {
                $card_number = isset($_POST['pagarme_card_number']) ? trim($_POST['pagarme_card_number']) : '';
                $payment_method = ! empty($card_number) ? 'credit_card' : 'boleto';
            }
            
            $valid_methods = array('credit_card', 'debit_card', 'pix', 'boleto', 'voucher', 'bank_transfer', 'cash', 'safetypay');

            if ( ! in_array( $payment_method, $valid_methods, true ) ) {
                wc_add_notice('Método de pagamento "' . esc_html($payment_method) . '" não é suportado.', 'error');
                return array('result' => 'fail');
            }
        }

        $this->logger->info('Processando pagamento', array(
            'order_id'       => $order_id,
            'payment_method' => $payment_method,
            'is_multipayment'=> $is_multipayment,
        ));

        try {
            if ( $is_multipayment ) {
                $result = $this->process_multipayment($order);
            } else {
                $result = $this->process_single_payment($order, $payment_method);
            }

            if ( $result['success'] ) {
                return array(
                    'result'   => 'success',
                    'redirect' => $result['redirect'],
                );
            } else {
                throw new \Exception($result['error']);
            }

        } catch (\Exception $e) {
            $this->logger->error('Erro no processamento', array(
                'order_id' => $order_id,
                'error'    => $e->getMessage(),
            ));

            wc_add_notice('Erro ao processar pagamento: ' . $e->getMessage(), 'error');
            return array('result' => 'fail');
        }
    }

    /**
     * Processar pagamento único
     */
    private function process_single_payment($order, $payment_method) {
        $payment_data = $this->prepare_payment_data($order, $payment_method);
        
        // Adicionar antifraude se habilitado
        if ($this->is_antifraud_enabled($order->get_total())) {
            $payment_data['antifraud_enabled'] = true;
            $payment_data['session_id'] = $this->get_session_id();
            $payment_data['ip'] = $_SERVER['REMOTE_ADDR'];
        }
        
        // Criar pedido na API Pagar.me
        $response = $this->create_pagarme_order($payment_data);
        
        if ($response['success']) {
            // Salvar ID do pedido Pagar.me
            $order->update_meta_data('_pagarme_order_id', $response['order_id']);
            $order->update_meta_data('_pagarme_payment_method', $payment_method);
            
            // NOVO: Processar dados do Boleto/PIX se existirem (para pagamento único)
            if ($payment_method === 'boleto' || $payment_method === 'pix') {
                $this->handle_boleto_pix_response($order, $response);
            }
            
            // Atualizar status baseado na resposta
            $this->update_order_status($order, $response);
            
            return array(
                'success' => true,
                'redirect' => $this->get_return_url($order)
            );
        }
        
        return array(
            'success' => false,
            'error' => $response['error']
        );
    }
        
    /**
     * Extrair métodos de pagamento do POST, suportando a nomenclatura antiga (multi_method_) 
     * e a nova nomenclatura indexada (pagarme_multipayment_method_N_type).
     *
     * Implementar dentro da classe PagarMe_Gateway_Complete.
     *
     * @return array Array de métodos [tipo => valor]
     */
    private function extract_multipayment_methods_robust() {
        $payment_methods = array();

        // 1. Tentar Formato Legado (Injetado pelo checkout.js original)
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'multi_method_') === 0 && $value === 'yes') {
                $method = str_replace('multi_method_', '', $key);
                $amount_key = 'multi_amount_' . $method;
                $amount = isset($_POST[$amount_key]) ? floatval($_POST[$amount_key]) : 0;
                
                if ($amount > 0) {
                    $payment_methods[$method] = $amount;
                }
            }
        }

        if (!empty($payment_methods)) {
            $this->logger->debug('Extração multi: Sucesso no formato legado', ['methods' => array_keys($payment_methods)]);
            return $payment_methods;
        }

        // 2. Tentar Formato Indexado (Injetado pelo pagarme-multipayment-field-alignment-fix.js)
        $method_count = isset($_POST['pagarme_multipayment_methods_count']) 
                        ? intval($_POST['pagarme_multipayment_methods_count']) 
                        : 0;

        if ($method_count > 0) {
            for ($i = 0; $i < $method_count; $i++) {
                $type_key = "pagarme_multipayment_method_{$i}_type";
                $amount_key = "pagarme_multipayment_method_{$i}_amount";

                if (isset($_POST[$type_key]) && isset($_POST[$amount_key])) {
                    $method = sanitize_text_field($_POST[$type_key]);
                    $amount = floatval($_POST[$amount_key]);

                    if ($amount > 0) {
                        $payment_methods[$method] = $amount;
                    }
                }
            }
        }
        
        if (!empty($payment_methods)) {
             $this->logger->debug('Extração multi: Sucesso no formato indexado', ['methods' => array_keys($payment_methods)]);
        }

        return $payment_methods;
    }

    /**
     * Processar multipagamento (Substituir a implementação existente que falhava na extração)
     */
    private function process_multipayment($order) {
        try {
            // 1. Extrair métodos usando a lógica robusta
            $payment_methods = $this->extract_multipayment_methods_robust();
            
            if (empty($payment_methods)) {
                // Log detalhado para diagnosticar a falha na extração do POST
                $this->logger->error('ERRO CRÍTICO: Nenhum método detectado no POST.', ['post_keys' => array_keys($_POST)]);
                throw new \Exception('Nenhum método de pagamento selecionado para o multipagamento');
            }
            
            $total_payment = array_sum($payment_methods);
            $order_total = $order->get_total();
            
            if (abs($total_payment - $order_total) > 0.01) {
                throw new \Exception(
                    sprintf(
                        'A soma dos pagamentos (R$ %.2f) não corresponde ao total do pedido (R$ %.2f)',
                        $total_payment,
                        $order_total
                    )
                );
            }
            
            $this->logger->info('Processando multipagamento', array(
                'order_id' => $order->get_id(),
                'methods' => $payment_methods,
            ));
            
            $response = $this->create_pagarme_multipayment_order($order, $payment_methods);
            
            if ($response['success']) {
                // Salvar dados do pedido
                $order->update_meta_data('_pagarme_order_id', $response['order_id']);
                $order->update_meta_data('_pagarme_multipayment', 'yes');
                $order->update_meta_data('_pagarme_payment_methods', json_encode($payment_methods));
                
                // CRÍTICO: Verificar e salvar Boleto/PIX (multipagamento)
                if (!empty($response['charges'])) {
                    $this->handle_multipayment_pix_boleto_links($order, $response['charges']);
                    $order->save(); // SALVAR IMEDIATAMENTE após metadados
                }
                
                $order->save(); // Salvar novamente por segurança
                
                // Adicionar nota ao pedido
                $methods_text = array();
                foreach ($payment_methods as $method => $amount) {
                    $methods_text[] = sprintf('%s: R$ %.2f', ucfirst(str_replace('_', ' ', $method)), $amount);
                }
                
                $order->add_order_note(
                    'Multipagamento processado com sucesso:<br>' . 
                    implode('<br>', $methods_text) . '<br>' . 
                    'ID Pagar.me: ' . $response['order_id']
                );
                
                $this->logger->info('Multipagamento concluído com sucesso', array(
                    'order_id' => $order->get_id(),
                    'pagarme_order_id' => $response['order_id']
                ));
                
                // CORREÇÃO: Retorno padronizado com 'success' => true
                return array(
                    'success' => true,
                    'redirect' => $this->get_return_url($order)
                );
            } else {
                // CORREÇÃO: Adicionar else e melhorar log de erro
                $this->logger->error('Falha ao processar multipagamento', array(
                    'order_id' => $order->get_id(),
                    'error' => $response['error'] ?? 'Erro desconhecido'
                ));
                
                return array(
                    'success' => false,
                    'error' => $response['error'] ?? 'Erro ao processar multipagamento'
                );
            }

            } catch (\Exception $e) {
                $this->logger->error('Exceção ao processar multipagamento', array(
                    'order_id' => $order->get_id(),
                    'exception' => $e->getMessage()
                ));
                
                return array(
                    'success' => false,
                    'error' => $e->getMessage()
                );
            }
    }

    /**
    * NOVO MÉTODO: Lidar com a resposta da API Pagar.me para Boleto/PIX (Single Payment)
    */
    private function handle_boleto_pix_response($order, $response) {
        $charge = $response['charges'][0] ?? null;
        if (!$charge) {
            $this->logger->warning('handle_boleto_pix_response: Nenhuma charge encontrada na resposta', [
                'order_id' => $order->get_id(),
                'response_keys' => array_keys($response)
            ]);
            return;
        }

        $transaction = $charge['last_transaction'] ?? null;
        if (!$transaction) {
            $this->logger->warning('handle_boleto_pix_response: Nenhuma transaction encontrada', [
                'order_id' => $order->get_id(),
                'charge_id' => $charge['id'] ?? 'N/A'
            ]);
            return;
        }
        
        // DEBUG: Log da estrutura da transação
        $this->logger->debug('handle_boleto_pix_response: Estrutura da transaction', [
            'order_id' => $order->get_id(),
            'transaction_type' => $transaction['transaction_type'] ?? $transaction['type'] ?? 'N/A',
            'has_url' => isset($transaction['url']),
            'has_qr_code' => isset($transaction['qr_code_url']),
            'transaction_keys' => array_keys($transaction)
        ]);
        
        // Garantir que a transação possui URLs específicas (Boleto ou PIX)
        $transaction_type = $transaction['transaction_type'] ?? $transaction['type'] ?? '';
        
        // CRÍTICO: Pagar.me pode retornar 'boleto' OU 'DM' (Debit Mandate)
        $boleto_types = ['boleto', 'DM', 'debit_mandate'];
        $is_boleto = in_array($transaction_type, $boleto_types, true) && isset($transaction['url']);
        $is_pix = $transaction_type === 'pix' && isset($transaction['qr_code_url']);

        if ($is_boleto) {
            $boleto_url = $transaction['url'] ?? '';
            $boleto_pdf = $transaction['pdf'] ?? '';
            $boleto_line = $transaction['line'] ?? '';
            $boleto_barcode = $transaction['barcode'] ?? $transaction['barcode_url'] ?? '';
            
            $order->update_meta_data('_pagarme_boleto_url', $boleto_url);
            $order->update_meta_data('_pagarme_boleto_pdf', $boleto_pdf);
            $order->update_meta_data('_pagarme_boleto_line', $boleto_line);
            $order->update_meta_data('_pagarme_boleto_barcode', $boleto_barcode);
            $order->update_meta_data('_pagarme_boleto_due_date', $due_date);
            
            // CRÍTICO: Salvar as alterações no banco de dados
            $order->save();
            
            // Forçar status on-hold para boletos
            $order->update_status('on-hold', 'Boleto gerado, aguardando pagamento.');
            
            $this->logger->info('Dados de Boleto salvos no pedido (Single Payment)', [
                'order_id' => $order->get_id(),
                'boleto_url' => $boleto_url,
                'boleto_pdf' => $boleto_pdf,
                'boleto_line' => $boleto_line
            ]);
            
        } elseif ($is_pix) {
            $pix_qr_code = $transaction['qr_code_url'] ?? '';
            $pix_line = $transaction['qr_code'] ?? '';
            
            $order->update_meta_data('_pagarme_pix_qr_code_url', $pix_qr_code);
            $order->update_meta_data('_pagarme_pix_line', $pix_line);
            
            // CRÍTICO: Salvar as alterações no banco de dados
            $order->save();
            
            // Forçar status on-hold para PIX
            $order->update_status('on-hold', 'PIX gerado, aguardando pagamento.');
            
            $this->logger->info('Dados de PIX salvos no pedido (Single Payment)', [
                'order_id' => $order->get_id(),
                'pix_qr_code' => $pix_qr_code,
                'pix_line' => substr($pix_line, 0, 50) . '...'
            ]);
        } else {
            $this->logger->warning('handle_boleto_pix_response: Tipo de transação não reconhecido ou dados incompletos', [
                'order_id' => $order->get_id(),
                'transaction_type' => $transaction_type,
                'is_boleto' => $is_boleto,
                'is_pix' => $is_pix
            ]);
        }
    }
    
    /**
     * NOVO MÉTODO: Lidar com a resposta da API Pagar.me para Boleto/PIX (Multipayment)
     * Salva links se encontrados em qualquer uma das charges.
     */
    private function handle_multipayment_pix_boleto_links($order, $charges) {
        $saved_links = false;
        
        $this->logger->info('[MULTI] Verificando charges para Boleto/PIX', [
            'order_id' => $order->get_id(),
            'num_charges' => count($charges)
        ]);
        
        foreach ($charges as $index => $charge) {
            $transaction = $charge['last_transaction'] ?? null;
            
            if (!$transaction) {
                $this->logger->warning("[MULTI] Charge {$index} sem transação", [
                    'charge_id' => $charge['id'] ?? 'unknown'
                ]);
                continue;
            }
            
            $transaction_type = $transaction['type'] ?? 'unknown';
            
            $this->logger->info("[MULTI] Analisando charge {$index}", [
                'type' => $transaction_type,
                'has_url' => isset($transaction['url']),
                'has_qr_code' => isset($transaction['qr_code_url']),
                'has_pdf' => isset($transaction['pdf']),
                'has_line' => isset($transaction['line'])
            ]);
            
            // DEBUG: Log completo da transação (censurado)
            $transaction_debug = array_map(function($value) {
                if (is_string($value) && strlen($value) > 50) {
                    return substr($value, 0, 50) . '...[truncated]';
                }
                return $value;
            }, $transaction);
            
            $this->logger->info("[MULTI] Estrutura da transação {$index}", [
                'transaction_keys' => array_keys($transaction),
                'transaction_sample' => $transaction_debug
            ]);
            
            // CRÍTICO: Pagar.me pode retornar 'boleto' OU 'DM' (Debit Mandate)
            $boleto_types = ['boleto', 'DM', 'debit_mandate'];
            $is_boleto = in_array($transaction_type, $boleto_types, true) && isset($transaction['url']);
            $is_pix = $transaction_type === 'pix' && isset($transaction['qr_code_url']);

            if ($is_boleto) {
                $this->logger->info('[MULTI] BOLETO ENCONTRADO!', [
                    'order_id' => $order->get_id(),
                    'boleto_url' => $transaction['url'] ?? ''
                ]);
                
                // SALVAR METADADOS
                $order->update_meta_data('_pagarme_boleto_url', $transaction['url'] ?? '');
                $order->update_meta_data('_pagarme_boleto_pdf', $transaction['pdf'] ?? '');
                $order->update_meta_data('_pagarme_boleto_line', $transaction['line'] ?? '');
                $order->update_meta_data('_pagarme_boleto_barcode', $transaction['barcode_url'] ?? '');
                
                // SALVAR DATA DE VENCIMENTO
                $days_to_pay = (int) $this->get_option('boleto_days_to_pay', 3);
                $due_date = date('Y-m-d', strtotime('+' . $days_to_pay . ' days'));
                $order->update_meta_data('_pagarme_boleto_due_date', $due_date);
                
                // SALVAR IMEDIATAMENTE
                $order->save();
                
                $this->logger->info('[MULTI] Metadados de boleto salvos com sucesso', [
                    'order_id' => $order->get_id()
                ]);
                
                $order->add_order_note('Pagar.me: Boleto gerado como parte do multipagamento.');
                $saved_links = true;
                break; 
                
            } elseif ($is_pix) {
                $this->logger->info('[MULTI] PIX ENCONTRADO!', [
                    'order_id' => $order->get_id(),
                    'pix_qr_code_url' => $transaction['qr_code_url'] ?? ''
                ]);
                
                // SALVAR METADADOS
                $order->update_meta_data('_pagarme_pix_qr_code_url', $transaction['qr_code_url'] ?? '');
                $order->update_meta_data('_pagarme_pix_line', $transaction['qr_code'] ?? '');
                
                // SALVAR IMEDIATAMENTE
                $order->save();
                
                $this->logger->info('[MULTI] Metadados de PIX salvos com sucesso', [
                    'order_id' => $order->get_id()
                ]);
                
                $order->add_order_note('Pagar.me: PIX gerado como parte do multipagamento.');
                $saved_links = true;
                break; 
            }
        }
        
        if ($saved_links) {
            $this->logger->info('[MULTI] Links de Boleto/PIX salvos no pedido', [
                'order_id' => $order->get_id()
            ]);
        } else {
            $this->logger->warning('[MULTI] Nenhum Boleto/PIX encontrado nas charges', [
                'order_id' => $order->get_id(),
                'num_charges' => count($charges)
            ]);
        }
        
        return $saved_links;
    }

    /**
    * Exibir boleto/PIX na página de "Obrigado"
    * COM CONTROLE DE DUPLICAÇÃO
    */
    public function thankyou_page($order_id) {
        // CRÍTICO: Prevenir múltiplas renderizações do mesmo boleto
        if (self::$boleto_rendered) {
            $this->logger->debug('Thankyou page: Boleto já renderizado, pulando duplicação', array(
                'order_id' => $order_id
            ));
            return;
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order || $order->get_payment_method() !== $this->id) {
            return;
        }
        
        // Verifica se é Boleto/PIX salvo por pagamento único ou multipagamento
        $boleto_url = $order->get_meta('_pagarme_boleto_url', true);
        $boleto_pdf = $order->get_meta('_pagarme_boleto_pdf', true);
        $boleto_line = $order->get_meta('_pagarme_boleto_line', true);
        
        $pix_qr_code_url = $order->get_meta('_pagarme_pix_qr_code_url', true);
        $pix_line = $order->get_meta('_pagarme_pix_line', true);
        
        if ($boleto_url) {
            $this->render_boleto_info($boleto_url, $boleto_pdf, $boleto_line, $order);
            self::$boleto_rendered = true;
            
            $this->logger->debug('Thankyou page: Boleto renderizado', array(
                'order_id' => $order_id,
                'boleto_url' => $boleto_url
            ));
        } elseif ($pix_qr_code_url) {
            $this->render_pix_info($pix_qr_code_url, $pix_line);
            self::$boleto_rendered = true;
            
            $this->logger->debug('Thankyou page: PIX renderizado', array(
                'order_id' => $order_id,
                'pix_url' => $pix_qr_code_url
            ));
        }
    }
        
    /**
    * Renderiza o bloco de informações do boleto
    */
    private function render_boleto_info($url, $pdf, $line) {
        ?>
        <section class="woocommerce-order-pagarme-boleto" style="margin: 30px 0;">
            <div style="background: linear-gradient(135deg, #F05A01 0%, #FF8C3A 100%); padding: 2px; border-radius: 12px; box-shadow: 0 10px 30px rgba(240, 90, 1, 0.2);">
                <div style="background: white; padding: 30px; border-radius: 10px;">
                    <h2 style="color: #333; font-size: 26px; margin: 0 0 20px 0; display: flex; align-items: center; gap: 12px; font-weight: 700;">
                        <span style="font-size: 36px;">🎫</span>
                        Seu Boleto Bancário
                    </h2>
                    
                    <p style="color: #666; font-size: 16px; margin-bottom: 20px; line-height: 1.6;">
                        Clique nos botões abaixo para visualizar ou baixar seu boleto:
                    </p>
                    
                    <div style="display: flex; gap: 15px; margin: 20px 0; flex-wrap: wrap;">
                        <a href="<?php echo esc_url($url); ?>" 
                        target="_blank" 
                        class="button button-primary" 
                        style="background: linear-gradient(135deg, #F05A01 0%, #FF6B1A 100%); border: none; padding: 16px 32px; font-size: 16px; font-weight: 600; text-decoration: none; color: white; border-radius: 8px; display: inline-flex; align-items: center; gap: 10px; transition: all 0.3s; box-shadow: 0 4px 12px rgba(240, 90, 1, 0.3);">
                            <span style="font-size: 20px;">📄</span> Ver Boleto Online
                        </a>
                        
                        <?php if ($pdf): ?>
                        <a href="<?php echo esc_url($pdf); ?>" 
                        target="_blank" 
                        class="button" 
                        style="background: linear-gradient(135deg, #28a745 0%, #34ce57 100%); border: none; padding: 16px 32px; font-size: 16px; font-weight: 600; text-decoration: none; color: white; border-radius: 8px; display: inline-flex; align-items: center; gap: 10px; transition: all 0.3s; box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);">
                            <span style="font-size: 20px;">📥</span> Baixar PDF
                        </a>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($line): ?>
                    <div style="background: #FFF5F0; padding: 20px; margin: 20px 0; border-radius: 10px; border-left: 4px solid #F05A01;">
                        <p style="margin: 0 0 12px 0; color: #333; font-weight: 700; font-size: 16px;">
                            <span style="font-size: 20px;">🔢</span> Linha Digitável:
                        </p>
                        <p style="font-family: 'Courier New', monospace; font-size: 15px; word-break: break-all; margin: 0; color: #1f2937; background: white; padding: 16px; border-radius: 6px; border: 1px solid #FFD4B8;">
                            <?php echo esc_html($line); ?>
                        </p>
                        <button onclick="navigator.clipboard.writeText('<?php echo esc_js($line); ?>').then(() => { this.textContent='Copiado!'; this.style.background='#28a745'; this.style.color='white'; this.style.borderColor='#28a745'; setTimeout(() => { this.textContent='Copiar Linha Digitável'; this.style.background='#FFF5F0'; this.style.color='#F05A01'; this.style.borderColor='#F05A01'; }, 2000); });" 
                                style="margin-top: 12px; background: #FFF5F0; color: #F05A01; border: 2px solid #F05A01; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; transition: all 0.3s;">
                            📋 Copiar Linha Digitável
                        </button>
                    </div>
                    <?php endif; ?>
                    
                    <div style="background: #FFF3E0; padding: 16px; margin-top: 20px; border-radius: 8px; border-left: 4px solid #FF9800;">
                        <p style="margin: 0; color: #E65100; display: flex; align-items: flex-start; gap: 10px; font-weight: 600;">
                            <span style="font-size: 22px;">⏰</span>
                            <span><strong>Importante:</strong> Pague até a data de vencimento <?php echo date('d/m/Y', strtotime('+3 days')); ?> </span>
                        </p>
                    </div>
                </div>
            </div>
        </section>
        <?php
    }

    // CORREÇÃO 3: ATUALIZAR CORES DO MÉTODO render_pix_info()

    /**
    * Renderiza o bloco de informações do PIX
    */
    private function render_pix_info($qr_code_url, $pix_line) {
        ?>
        <section class="woocommerce-order-pagarme-pix" style="margin: 30px 0;">
            <div style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); padding: 2px; border-radius: 12px; box-shadow: 0 10px 30px rgba(17, 153, 142, 0.2);">
                <div style="background: white; padding: 30px; border-radius: 10px; text-align: center;">
                    <h2 style="color: #333; font-size: 26px; margin: 0 0 20px 0; font-weight: 700;">
                        <span style="font-size: 36px;">💚</span> Pagamento via PIX
                    </h2>
                    
                    <p style="color: #666; margin-bottom: 20px; font-size: 16px;">
                        Escaneie o QR Code abaixo com o app do seu banco:
                    </p>
                    
                    <div style="background: white; padding: 20px; display: inline-block; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
                        <?php if ($qr_code_url): ?>
                            <img src="<?php echo esc_url($qr_code_url); ?>" 
                                alt="QR Code PIX" 
                                style="max-width: 250px; height: auto; display: block;" />
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($pix_line): ?>
                    <div style="margin-top: 20px; text-align: left;">
                        <p style="margin: 0 0 10px 0; color: #333; font-weight: 700; font-size: 16px;">
                            Ou copie o código PIX (Copia e Cola):
                        </p>
                        <div style="background: #E8F5E9; padding: 15px; border-radius: 8px; border: 1px dashed #81C784;">
                            <code style="word-break: break-all; font-size: 12px; color: #1f2937; display: block; line-height: 1.6;">
                                <?php echo esc_html($pix_line); ?>
                            </code>
                        </div>
                        <button onclick="navigator.clipboard.writeText('<?php echo esc_js($pix_line); ?>').then(() => { this.textContent='Copiado com sucesso!'; setTimeout(() => { this.textContent='Copiar Código PIX'; }, 2000); });" 
                                style="margin-top: 12px; background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); color: white; border: none; padding: 14px 28px; border-radius: 8px; cursor: pointer; font-size: 15px; font-weight: 600; width: 100%; transition: all 0.3s; box-shadow: 0 4px 12px rgba(17, 153, 142, 0.3);">
                            📋 Copiar Código PIX
                        </button>
                    </div>
                    <?php endif; ?>
                    
                    <p style="color: #059669; margin-top: 20px; font-weight: 600; font-size: 15px;">
                        ✅ Pagamento instantâneo. A confirmação ocorre em segundos!
                    </p>
                </div>
            </div>
        </section>
        <?php
    }
    
    /**
    * Resetar flag de renderização do boleto
    * Permite que o boleto seja renderizado novamente em outra página
    */
    public function reset_boleto_flag() {
        self::$boleto_rendered = false;
    }

    /**
     * NOVO MÉTODO: Preparar apenas objeto de pagamento individual
     * Este método retorna SOMENTE os dados do payment, sem customer e items
     * Usado para construir o array payments[] no multipagamento
     * 
     * @param WC_Order $order Pedido do WooCommerce
     * @param string $payment_method Método de pagamento
     * @param float $amount Valor deste pagamento específico
     * @return array Objeto do payment formatado para API
     */
    private function prepare_payment_object_only($order, $payment_method, $amount) {
        
        $payment_object = array(
            'payment_method' => $payment_method,
            'amount' => $this->data_formatter->format_amount($amount)
        );
        
        try {
            switch ($payment_method) {
                case 'credit_card':
                    $payment_object['credit_card'] = $this->prepare_credit_card_data($order, $payment_method);
                    break;
                case 'debit_card':
                    $payment_object['debit_card'] = $this->prepare_debit_card_data($order);
                    break;
                case 'voucher':
                    $payment_object['voucher'] = $this->prepare_voucher_data($order);
                    break;
                case 'pix':
                    $payment_object['pix'] = $this->prepare_pix_data($order);
                    break;
                case 'boleto':
                    $payment_object['boleto'] = $this->prepare_boleto_data($order);
                    break;
                case 'bank_transfer':
                    $payment_object['bank_transfer'] = $this->prepare_bank_transfer_data($order);
                    break;
                case 'cash':
                    $payment_object['cash'] = $this->prepare_cash_data($order);
                    break;
                case 'safetypay':
                    $payment_object['safety_pay'] = $this->prepare_safetypay_data($order);
                    break;
                default:
                    throw new \Exception('Método de pagamento inválido: "' . $payment_method . '"');
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Erro ao preparar dados do método', array('method' => $payment_method, 'error' => $e->getMessage()));
            throw $e;
        }
        
        return $payment_object;
    }


    /**
     * NOVO MÉTODO: Criar pedido com multipagamento
     * Monta estrutura correta conforme documentação Pagar.me
     * Endpoint: POST /core/v5/orders
     * 
     * @param WC_Order $order Pedido do WooCommerce
     * @param array $payment_methods Array [metodo => valor]
     * @return array Resposta da API
     */
    private function create_pagarme_multipayment_order($order, $payment_methods) {
        try {
            // 1. Validar que a soma dos valores está correta
            $total_payments = array_sum($payment_methods);
            $order_total = $order->get_total();
            
            if (abs($total_payments - $order_total) > 0.01) {
                throw new \Exception(
                    sprintf(
                        'Soma dos pagamentos (R$ %.2f) não corresponde ao total do pedido (R$ %.2f)',
                        $total_payments,
                        $order_total
                    )
                );
            }
            
            // 2. Construir array de payments
            $payments_array = array();
            
            foreach ($payment_methods as $method => $amount) {
                // Usar o método que retorna SOMENTE o objeto do payment
                $payment_object = $this->prepare_payment_object_only($order, $method, $amount);
                
                // Adicionar antifraude se habilitado
                if ($this->is_antifraud_enabled($amount)) {
                    $payment_object['antifraud_enabled'] = true;
                }
                
                $payments_array[] = $payment_object;
                
                $this->logger->info('Payment adicionado ao array', array(
                    'method' => $method,
                    'amount' => $amount,
                    'amount_formatted' => $this->data_formatter->format_amount($amount)
                ));
            }
            
            // 3. Montar payload completo para API
            $order_data = array(
                'code' => strval($order->get_id()),
                'customer' => $this->format_customer_data($order),
                'items' => $this->format_items_data($order),
                'payments' => $payments_array, // Array com múltiplos payments
                'closed' => true
            );
            
            // Adicionar shipping se houver
            if ($order->get_shipping_total() > 0) {
                $order_data['shipping'] = array(
                    'amount' => $this->data_formatter->format_amount($order->get_shipping_total()),
                    'description' => $order->get_shipping_method(),
                    'recipient_name' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
                    'recipient_phone' => $order->get_billing_phone(),
                    'address' => array(
                        'line_1' => $order->get_shipping_address_1(),
                        'line_2' => $order->get_shipping_address_2(),
                        'zip_code' => preg_replace('/[^0-9]/', '', $order->get_shipping_postcode()),
                        'city' => $order->get_shipping_city(),
                        'state' => $order->get_shipping_state(),
                        'country' => $order->get_shipping_country()
                    )
                );
            }
            
            $this->logger->info('Payload de multipagamento montado', array(
                'order_id' => $order->get_id(),
                'total_payments' => count($payments_array),
                'total_amount' => $order_total,
                'methods' => array_keys($payment_methods)
            ));
            
            // 4. Enviar para API Pagar.me
            $api_url = $this->get_api_url() . '/orders';
            
            $response = wp_remote_post($api_url, array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode(trim($this->api_key) . ':'),
                    'Content-Type' => 'application/json'
                ),
                'body' => wp_json_encode($order_data),
                'timeout' => 30
            ));
            
            // Log do request completo (apenas em debug)
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $this->logger->debug('Request multipagamento enviado', array(
                    'url' => $api_url,
                    'payload' => $order_data
                ));
            }
            
            // 5. Processar resposta
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                $this->logger->error('Erro ao criar pedido multipagamento', array(
                    'error' => $error_message
                ));
                
                return array(
                    'success' => false,
                    'error' => $error_message
                );
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $status_code = wp_remote_retrieve_response_code($response);
            
            // Log da resposta (apenas em debug)
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $this->logger->debug('Resposta da API recebida', array(
                    'status_code' => $status_code,
                    'body' => $body
                ));
            }
            
            if ($status_code === 200 || $status_code === 201) {
                $this->logger->info('Pedido multipagamento criado com sucesso', array(
                    'pagarme_order_id' => $body['id'],
                    'status' => $body['status'],
                    'charges_count' => count($body['charges'] ?? array())
                ));
                
                return array(
                    'success' => true,
                    'order_id' => $body['id'],
                    'status' => $body['status'],
                    'charges' => $body['charges'] ?? array()
                );
            }
            
            // Erro da API
            $error_message = $body['message'] ?? 'Erro desconhecido ao criar pedido';
            
            $this->logger->error('API retornou erro', array(
                'status_code' => $status_code,
                'message' => $error_message,
                'errors' => $body['errors'] ?? array()
            ));
            
            return array(
                'success' => false,
                'error' => $error_message
            );
            
        } catch (\Exception $e) {
            $this->logger->error('Exceção ao criar multipagamento', array(
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }

    // MÉTODOS CORRIGIDOS - ADICIONADOS EM 27/10/2025

/**
 * CORREÇÕES REAIS DO GATEWAY PAGARME
 * Baseado no código REAL enviado pelo usuário
 * 
 * PROBLEMA ENCONTRADO:
 * - Método prepare_payment_data() NÃO EXISTE mas é chamado nas linhas 698 e 765
 * - Este método é CRÍTICO pois monta o payload para API
 * 
 */

// ADICIONAR ESTES MÉTODOS NA CLASSE PagarMe_Gateway_Complete

    /**
    * MÉTODO PRINCIPAL - Preparar dados de pagamento para API
    * Este método monta o JSON que será enviado para /orders da API PagarMe
    * 
    * @param WC_Order $order Pedido do WooCommerce
    * @param string $payment_method Método de pagamento (credit_card, debit_card, pix, etc)
    * @param float|null $amount Valor (para multipagamentos)
    * @return array Dados formatados para API
    */
    private function prepare_payment_data($order, $payment_method, $amount = null) {
        $payment_amount = $amount !== null ? $amount : $order->get_total();
        
        $data = array(
            'code' => strval($order->get_id()),
            'amount' => $this->data_formatter->format_amount($payment_amount),
            'currency' => 'BRL',
            'closed' => true,
            'customer' => $this->format_customer_data($order),
            'items' => $this->format_items_data($order),
            'payments' => array()
        );
        
        $data['payments'][] = $this->prepare_payment_object_only($order, $payment_method, $payment_amount);

        // CORREÇÃO CRÍTICA: INCLUIR OBJETO SHIPPING PARA SINGLE PAYMENT
        // Se o frete existe, ele deve ser adicionado ao payload da ordem,
        // garantindo que a soma (items + shipping) iguale o 'amount' total.
        if ($order->get_shipping_total() > 0) {
            $data['shipping'] = array(
                'amount' => $this->data_formatter->format_amount($order->get_shipping_total()),
                'description' => $order->get_shipping_method(),
                'recipient_name' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
                'recipient_phone' => $order->get_billing_phone(),
                'address' => array(
                    'line_1' => $order->get_shipping_address_1(),
                    'line_2' => $order->get_shipping_address_2(),
                    'zip_code' => preg_replace('/[^0-9]/', '', $order->get_shipping_postcode()),
                    'city' => $order->get_billing_city(),
                    'state' => $order->get_billing_state(),
                    'country' => $order->get_billing_country()
                )
            );
        }
        
        return $data;
    }
    /**
     * ADAPTADO: Preparar dados para Cartão de Crédito.
     * Utiliza a nova função simplificada `get_card_data_from_post`.
     * CORRIGIDO: Lógica de parcelas para multipagamento.
     */
    private function prepare_credit_card_data($order, $payment_method_id = 'credit_card') {
        
        try {
            // Validar dados primeiro
            $this->validatePaymentData($payment_method_id);

            // CORREÇÃO: Lógica simplificada e correta para obter parcelas
            // Detecta se estamos em contexto de multipagamento (flag injetada pelo JS)
            $is_multipayment = isset($_POST['pagarme_multipayment']) || isset($_POST['is_multipayment']);
            $installments = 1;

            if ($is_multipayment) {
                // Em multipagamento, o JS envia o campo com prefixo `multi_card_{method}_installments`
                // Esta lógica corrige a condição anterior que falhava com 'credit_card'
                $installments_field = 'multi_card_' . $payment_method_id . '_installments';
                if (isset($_POST[$installments_field])) {
                    $installments = (int) $_POST[$installments_field];
                }
            } else {
                // Em pagamento único, o campo não tem prefixo
                if (isset($_POST['pagarme_installments'])) {
                    $installments = (int) $_POST['pagarme_installments'];
                }
            }
            
            // Validações de parcelas
            if ($payment_method_id === 'credit_card' || $payment_method_id === 'voucher') {
                $max_installments = (int) $this->get_option('credit_card_max_installments', 12);
                if ($installments > $max_installments) {
                    throw new \Exception('Número de parcelas excede o máximo permitido');
                }
            }
            
            // Obter dados do cartão com a nova função robusta
            $card_data = $this->get_card_data_from_post($order, $payment_method_id);
            
            $result = array(
                'statement_descriptor' => $this->get_statement_descriptor(),
                'card' => $card_data
            );
            
            if ($payment_method_id === 'credit_card') {
                $result['installments'] = $installments;
                $result['operation_type'] = $this->get_option('capture_method') === 'manual' ? 'auth_only' : 'auth_and_capture';
            }
            
            if ($payment_method_id === 'debit_card') {
                $result['recurrence'] = false;
            }
            
            return $result;
            
        } catch (\Exception $e) {
            // Log detalhado do erro
            $this->logger->error('Erro ao preparar dados do cartão:', array(
                'method' => $payment_method_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            
            // Re-lançar com mensagem mais clara
            throw new \Exception('Erro ao processar dados do cartão: ' . $e->getMessage());
        }
    }

    /**
     * ADAPTADO: Preparar dados para Cartão de Débito.
     * Utiliza a mesma lógica simplificada, sem parcelas.
     */
    private function prepare_debit_card_data($order) {
        // Unified single definition — debit has no installments
        $card_data = $this->get_card_data_from_post($order);
        return array(
            'statement_descriptor' => $this->get_statement_descriptor(),
            'card' => $card_data,
            'recurrence' => false
        );
    }

/**
 * CARTÃO DE DÉBITO - SEM installments conforme documentação
 */

/**
 * PIX - Conforme documentação
 */
private function prepare_pix_data($order) {
    $expires_in = (int) $this->get_option('pix_expiration', 30) * 60; // Converter minutos para segundos
    
    return array(
        'expires_in' => $expires_in,
        'additional_information' => array(
            array(
                'name' => 'Pedido',
                'value' => '#' . $order->get_id()
            ),
            array(
                'name' => 'Cliente',
                'value' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
            )
        )
    );
}

/**
 * BOLETO - Conforme documentação
 */
private function prepare_boleto_data($order) {
    $days_to_pay = (int) $this->get_option('boleto_days_to_pay', 3);
    $due_date = date('Y-m-d', strtotime('+' . $days_to_pay . ' days'));

    $instructions = 'Não receber após o vencimento';

    $data = array(
        'instructions' => substr($instructions, 0, 256),  // Máx 256 caracteres
        'due_at' => $due_date
    );

    // Enviar o banco apenas se explicitamente configurado, senão deixa o Pagar.me usar o padrão
    $bank = $this->get_option('boleto_bank');
    if (!empty($bank)) {
        $data['bank'] = $bank;
    }

    return $data;
}

    /**
     * ADAPTADO: Preparar dados para Voucher.
     * Utiliza a lógica simplificada, garantindo que o documento do titular seja incluído.
     */
    private function prepare_voucher_data($order) {
        $card_data = $this->get_card_data_from_post($order, 'voucher');
        $holder_document = isset($_POST['pagarme_holder_document']) 
            ? sanitize_text_field($_POST['pagarme_holder_document']) 
            : '';
        $holder_document = preg_replace('/[^0-9]/', '', $holder_document);
        
        if (empty($holder_document) || strlen($holder_document) !== 11) {
            throw new \Exception('CPF do portador do cartão é obrigatório para voucher e deve ter 11 dígitos');
        }
        
        $card_data['holder_document'] = $holder_document;
        
        return array(
            'statement_descriptor' => $this->get_statement_descriptor(),
            'card' => $card_data
        );
    }

/**
 * TRANSFERÊNCIA BANCÁRIA - COM código do banco conforme documentação
 */
private function prepare_bank_transfer_data($order) {
    // Obter banco selecionado pelo cliente
    $bank_code = isset($_POST['pagarme_bank_code']) 
        ? sanitize_text_field($_POST['pagarme_bank_code']) 
        : '';
    
    // Validar banco selecionado
    if (empty($bank_code)) {
        throw new \Exception('Selecione um banco para transferência');
    }
    
    // Validar se o banco está habilitado nas configurações
    $available_banks = $this->get_option('bank_transfer_banks', array('001', '237', '341'));
    if (!in_array($bank_code, $available_banks)) {
        throw new \Exception('Banco selecionado não está disponível');
    }
    
    // Retornar conforme documentação (apenas o código do banco)
    return array(
        'bank' => $bank_code  // Campo obrigatório
    );
}

/**
 * CASH (Dinheiro) - Conforme documentação
 */
private function prepare_cash_data($order) {
    // Cash não requer dados adicionais
    return array();
}

/**
 * SAFETYPAY - Conforme documentação
 */
private function prepare_safetypay_data($order) {
    // SafetyPay não requer dados adicionais básicos
    return array();
}

    /**
     * CORREÇÃO COMPLETA: Função para obter dados do cartão do POST (versão simplificada e robusta).
     * Esta função substitui a lógica complexa anterior. Agora ela detecta o contexto (único vs múltiplo)
     * e lê os campos com o prefixo correto, eliminando ambiguidades.
     */
    private function get_card_data_from_post($order, $payment_method_id = null) {
        $this->logger->info('Iniciando coleta de dados do cartão (v2 Corrigida)', [
            'method' => $payment_method_id,
            'post_keys' => array_keys($_POST)
        ]);

        if (!empty($_POST['pagarme_card_token'])) {
            $this->logger->info('Token de cartão encontrado. Usando token.');
            return ['card_token' => sanitize_text_field($_POST['pagarme_card_token'])];
        }

        // Detectar contexto (Single vs Multi) para definir o prefixo correto
        $is_multipayment = isset($_POST['pagarme_multipayment']) || isset($_POST['is_multipayment']);
        $prefix = 'pagarme_'; // Prefixo padrão para pagamento único

        if ($is_multipayment && $payment_method_id) {
            // Para multipagamento, o JS injeta com prefixo 'multi_card_{method}_'
            $prefix = 'multi_card_' . $payment_method_id . '_';
            $this->logger->info('Contexto de Multipagamento detectado.', ['prefix' => $prefix]);
        } else {
            $this->logger->info('Contexto de Pagamento Único detectado.', ['prefix' => $prefix]);
        }

        // Função auxiliar para buscar valor de campo usando o prefixo
        $get_field_value = function($base_name) use ($prefix) {
            $possible_fields = [
                $prefix . $base_name,
                $prefix . 'card_' . $base_name, 
                $prefix . 'card_' . $base_name . '_raw'
            ];
            foreach ($possible_fields as $field) {
                if (isset($_POST[$field]) && !empty($_POST[$field])) {
                    return sanitize_text_field($_POST[$field]);
                }
            }
            return '';
        };

        // Coletar dados usando a função auxiliar
        $card_number = preg_replace('/\D/', '', $get_field_value('number_raw') ?: $get_field_value('number'));
        $holder_name = $get_field_value('holder_name') ?: $get_field_value('holder');
        $cvv         = $get_field_value('cvv');
        $document    = preg_replace('/\D/', '', $get_field_value('document') ?: $get_field_value('holder_document'));

        // Coletar validade (MM/AA ou MM/YYYY)
        $exp_month = $get_field_value('exp_month');
        $exp_year  = $get_field_value('exp_year');

        if (empty($exp_month) || empty($exp_year)) {
            $expiry_full = $get_field_value('expiry') ?: $get_field_value('expiration');
            if ($expiry_full) {
                $expiry_digits = preg_replace('/\D/', '', $expiry_full);
                if (strlen($expiry_digits) >= 4) {
                    $exp_month = substr($expiry_digits, 0, 2);
                    $year_part = substr($expiry_digits, 2);
                    $exp_year  = strlen($year_part) === 2 ? '20' . $year_part : $year_part;
                }
            }
        }

        // Validações
        if (empty($card_number) || strlen($card_number) < 13) throw new \Exception('Número do cartão inválido.');
        if (empty($holder_name)) throw new \Exception('Nome do titular é obrigatório.');
        if (empty($cvv) || strlen($cvv) < 3) throw new \Exception('CVV inválido.');
        if (empty($exp_month) || empty($exp_year)) throw new \Exception('Data de validade inválida.');

        $this->logger->info('Dados do cartão coletados com sucesso.');

        $card_data = [
            'number'           => $card_number,
            'holder_name'      => strtoupper($holder_name),
            'exp_month'        => (int)$exp_month,
            'exp_year'         => (int)$exp_year % 100, // API v5 espera 2 dígitos para o ano
            'cvv'              => $cvv,
            'billing_address'  => $this->format_billing_address($order),
        ];

        if (!empty($document)) {
            $card_data['holder_document'] = $document;
        }

        return $card_data;
    }

    /**
    * MÉTODO ADICIONAL: Validar dados de pagamento antes de processar
    * Adicionar este método para melhor debug
    */
    private function validatePaymentData($payment_method) {
        $this->logger->info('Validando dados de pagamento', array(
            'method' => $payment_method,
            'post_data_keys' => array_keys($_POST)
        ));
        
        // Log detalhado de TODOS os campos recebidos
        $pagarme_fields = array();
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'pagarme') !== false || strpos($key, 'multi_') !== false || strpos($key, 'card') !== false) {
                $pagarme_fields[$key] = is_string($value) 
                    ? (strlen($value) > 50 ? substr($value, 0, 20) . '...' : $value)
                    : $value;
            }
        }
        
        $this->logger->info('Campos Pagar.me recebidos:', $pagarme_fields);
        
        // Se não for método de cartão, não precisa validar
        if (!in_array($payment_method, array('credit_card', 'debit_card', 'voucher'))) {
            $this->logger->info('Método não requer cartão: ' . $payment_method);
            return true;
        }
        
        // Verificar se há token (não precisa de outros campos)
        if (isset($_POST['pagarme_card_token']) && !empty($_POST['pagarme_card_token'])) {
            $this->logger->info('Usando token de cartão');
            return true;
        }
        
        // NOVO: Detectar se é multipagamento
        $is_multipayment = isset($_POST['pagarme_multipayment']) || isset($_POST['is_multipayment']);
        $method_prefix = $is_multipayment ? 'multi_card_' . $payment_method . '_' : '';
        
        $this->logger->info('Contexto de pagamento:', array(
            'is_multipayment' => $is_multipayment,
            'prefix' => $method_prefix,
            'method' => $payment_method
        ));
        
        // NOVO: Lista de possíveis nomes de campos INCLUINDO multipagamento
        $field_variants = array(
            'number' => array(
                // Multipagamento
                $method_prefix . 'number_raw',
                $method_prefix . 'number',
                'multi_card_' . $payment_method . '_number_raw',
                'multi_card_' . $payment_method . '_number',
                // Single payment
                'pagarme_card_number',
                'pagarme_card_number_raw',
                'pagarme-card-number',
                'card_number',
                'cardNumber'
            ),
            'holder' => array(
                // Multipagamento
                $method_prefix . 'holder',
                'multi_card_' . $payment_method . '_holder',
                // Single payment
                'pagarme_card_holder',
                'pagarme_card_holder_name',
                'pagarme-card-holder-name',
                'pagarme-card-holder',
                'card_holder_name',
                'cardHolderName'
            ),
            'expiry' => array(
                // Multipagamento
                $method_prefix . 'expiration',
                'multi_card_' . $payment_method . '_expiration',
                $method_prefix . 'exp_month',
                'multi_card_' . $payment_method . '_exp_month',
                // Single payment
                'pagarme_card_expiry',
                'pagarme_card_exp_month',
                'pagarme_card_expiration',
                'pagarme-card-expiry',
                'card_expiry',
                'cardExpiry'
            ),
            'cvv' => array(
                // Multipagamento
                $method_prefix . 'cvv',
                'multi_card_' . $payment_method . '_cvv',
                // Single payment
                'pagarme_card_cvv',
                'pagarme-card-cvv',
                'card_cvv',
                'cardCvv',
                'cvv'
            )
        );
        
        // Verificar se pelo menos um campo de cada tipo existe
        $found_fields = array();
        foreach ($field_variants as $type => $variants) {
            $found = false;
            foreach ($variants as $variant) {
                if (isset($_POST[$variant]) && !empty($_POST[$variant])) {
                    $found_fields[$type] = $variant;
                    $found = true;
                    $this->logger->info('Campo encontrado: ' . $type . ' => ' . $variant);
                    break;
                }
            }
            
            if (!$found && $type !== 'expiry') {
                $this->logger->warning('Campo não encontrado: ' . $type, array(
                    'procurados' => $variants,
                    'recebidos' => array_keys($_POST)
                ));
            }
        }
        
        // Verificação especial para validade (pode vir em campos separados)
        if (!isset($found_fields['expiry'])) {
            // Single payment
            if (isset($_POST['pagarme_card_exp_month']) && isset($_POST['pagarme_card_exp_year'])) {
                $found_fields['expiry'] = 'separate_fields';
            }
            // Multipagamento
            $multi_month = 'multi_card_' . $payment_method . '_exp_month';
            $multi_year = 'multi_card_' . $payment_method . '_exp_year';
            if (isset($_POST[$multi_month]) && isset($_POST[$multi_year])) {
                $found_fields['expiry'] = 'separate_fields_multi';
            }
        }
        
        // Log dos campos encontrados
        $this->logger->info('Campos encontrados:', $found_fields);
        
        // Se faltar campos críticos, retornar erro detalhado
        $missing = array();
        if (!isset($found_fields['number'])) $missing[] = 'número do cartão';
        if (!isset($found_fields['holder'])) $missing[] = 'nome do titular';
        if (!isset($found_fields['cvv'])) $missing[] = 'CVV';
        if (!isset($found_fields['expiry'])) $missing[] = 'validade';
        
        if (!empty($missing)) {
            $error_msg = 'Campos faltando: ' . implode(', ', $missing);
            $this->logger->error('Validação falhou', array(
                'missing' => $missing,
                'found' => $found_fields
            ));
            throw new \Exception($error_msg);
        }
        
        $this->logger->info('Validação concluída com sucesso');
        return true;
    }


/**
 * AUXILIAR - Formatar dados do cliente conforme documentação
 */
private function format_customer_data($order) {
    $cpf_cnpj = $order->get_meta('_billing_cpf') ?: $order->get_meta('_billing_cnpj');
    $cpf_cnpj = preg_replace('/[^0-9]/', '', $cpf_cnpj);
    
    $customer = array(
        'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
        'email' => $order->get_billing_email(),
        'type' => strlen($cpf_cnpj) === 11 ? 'individual' : 'company'
    );
    
    // Adicionar documento se disponível
    if (!empty($cpf_cnpj)) {
        $customer['document'] = $cpf_cnpj;
        $customer['document_type'] = strlen($cpf_cnpj) === 11 ? 'CPF' : 'CNPJ';
    }
    
    // Adicionar telefones
    $phone = preg_replace('/[^0-9]/', '', $order->get_billing_phone());
    if (!empty($phone)) {
        $customer['phones'] = array(
            'mobile_phone' => array(
                'country_code' => '55',
                'area_code' => substr($phone, 0, 2),
                'number' => substr($phone, 2)
            )
        );
    }
    
    // Adicionar endereço
    $customer['address'] = $this->format_billing_address($order);
    
    return $customer;
}

/**
 * AUXILIAR - Formatar endereço conforme documentação
 */
private function format_billing_address($order) {
    $address_1 = $order->get_billing_address_1();
    $address_2 = $order->get_billing_address_2();
    $number = $order->get_meta('_billing_number') ?: 'S/N';
    $neighborhood = $order->get_meta('_billing_neighborhood') ?: '';
    
    return array(
        'line_1' => $number . ', ' . $address_1,
        'line_2' => $address_2,
        'zip_code' => preg_replace('/[^0-9]/', '', $order->get_billing_postcode()),
        'city' => $order->get_billing_city(),
        'state' => $order->get_billing_state(),
        'country' => 'BR'
    );
}

    /**
    * AUXILIAR - Formatar items do pedido conforme documentação
    */
    private function format_items_data($order, $include_shipping = false) {
        $items = array();
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            
            $items[] = array(
                'code' => strval($product->get_id()),
                'description' => $item->get_name(),
                'amount' => $this->data_formatter->format_amount($item->get_total()),
                'quantity' => $item->get_quantity()
            );
        }
        
        // CORREÇÃO: Frete só é adicionado como item se explicitamente solicitado
        // Quando usar multipagamento, o frete vai no objeto 'shipping' separado
        if ($include_shipping && $order->get_shipping_total() > 0) {
            $items[] = array(
                'code' => 'shipping',
                'description' => 'Frete - ' . $order->get_shipping_method(),
                'amount' => $this->data_formatter->format_amount($order->get_shipping_total()),
                'quantity' => 1
            );
        }
        
        return $items;
    }

    /**
    * AUXILIAR - Get statement descriptor (texto na fatura)
    */
    private function get_statement_descriptor() {
        $descriptor = $this->get_option('soft_descriptor', get_bloginfo('name'));
        
        // Limpar e formatar
        $descriptor = remove_accents($descriptor);
        $descriptor = preg_replace('/[^A-Za-z0-9 ]/', '', $descriptor);
        $descriptor = substr($descriptor, 0, 13);  // Máx 13 caracteres para PSP
        $descriptor = strtoupper(trim($descriptor));
        
        return $descriptor ?: 'LOJA ONLINE';
    }

    /**
    * AUXILIAR - Get session ID para antifraude
    */
    private function get_session_id() {
        if (!isset($_SESSION)) {
            session_start();
        }
        
        if (!isset($_SESSION['pagarme_session_id'])) {
            $_SESSION['pagarme_session_id'] = wp_generate_password(32, false);
        }
        
        return $_SESSION['pagarme_session_id'];
    }

    /**
    * ATUALIZAR STATUS DO PEDIDO - CORRIGIDO PARA BOLETO/PIX
    */
    private function update_order_status($order, $response) {
        $status = $response['status'] ?? 'pending';
        
        switch ($status) {
            case 'paid':
                $order->payment_complete($response['order_id']);
                $order->add_order_note('Pagamento aprovado pelo Pagar.me');
                break;
                
            case 'pending':
                $order->update_status('on-hold', 'Aguardando confirmação de pagamento');
                break;
                
            case 'failed':
                $order->update_status('failed', 'Pagamento recusado');
                break;
                
            default:
                $order->update_status('pending', 'Processando pagamento');
        }
        
        $order->save();
    }

    /**
    * AUXILIAR - Get API URL
    */
    private function get_api_url() {
        if ($this->environment === 'production') {
            return 'https://api.pagar.me/core/v5';
        }
        
        return 'https://api.pagar.me/core/v5'; // Sandbox usa mesma URL com chave diferente
    }


    // FIM DOS MÉTODOS CORRIGIDOS

    
    /**
     * Criar pedido na API Pagar.me
     */
    private function create_pagarme_order($payment_data) {
        $api_url = $this->get_api_url() . '/orders';
        
        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode(trim($this->api_key) . ':'),
                'Content-Type' => 'application/json'
            ),
            'body' => wp_json_encode($payment_data),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message()
            );
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code === 200 || $status_code === 201) {
            return array(
                'success' => true,
                'order_id' => $body['id'],
                'status' => $body['status'],
                'charges' => $body['charges'] ?? array()
            );
        }
        
        return array(
            'success' => false,
            'error' => $body['message'] ?? 'Erro desconhecido'
        );
    }
}

// PARTE 2: API REST PARA INTEGRAÇÃO COM MULTISTEP

/**
 * Classe de Endpoints da API
 * Expõe métodos para o plugin multistep consumir
 */
class PagarMe_API_Endpoints {
    
    private $gateway;
    
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_action('init', array($this, 'init_gateway'));
    }
    
    public function init_gateway() {
        if (class_exists('PagarMe_Gateway_Complete')) {
            $this->gateway = new PagarMe_Gateway_Complete();
        }
    }
    
    public function register_routes() {
        $namespace = 'pagarme/v1';
        
        // Obter métodos disponíveis
        register_rest_route($namespace, '/payment-methods', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_available_methods'),
            'permission_callback' => '__return_true'
        ));
        
        // Validar multipagamento
        register_rest_route($namespace, '/validate-multipayment', array(
            'methods' => 'POST',
            'callback' => array($this, 'validate_multipayment'),
            'permission_callback' => '__return_true'
        ));
        
        // Obter configurações de antifraude
        register_rest_route($namespace, '/antifraud-settings', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_antifraud_settings'),
            'permission_callback' => '__return_true'
        ));
        
        // Calcular parcelas
        register_rest_route($namespace, '/installments', array(
            'methods' => 'POST',
            'callback' => array($this, 'calculate_installments'),
            'permission_callback' => '__return_true'
        ));
    }
    
    /**
     * Endpoint: Obter métodos de pagamento disponíveis
     */
    public function get_available_methods() {
        if (!$this->gateway) {
            return new \WP_Error('gateway_not_found', 'Gateway não inicializado', array('status' => 500));
        }
        
        $response = array(
            'methods' => $this->gateway->get_enabled_payment_methods(),
            'multipayment' => array(
                'enabled' => $this->gateway->is_multipayment_enabled(),
                'combinations' => $this->gateway->get_multipayment_combinations(),
                'min_value' => $this->gateway->get_option('multipayment_min_value'),
                'max_methods' => $this->gateway->get_option('multipayment_max_methods')
            )
        );
        
        return rest_ensure_response($response);
    }
    
    /**
     * Endpoint: Validar combinação de multipagamento
     */
    public function validate_multipayment($request) {
        $params = $request->get_json_params();
        $methods = $params['methods'] ?? array();
        $total = $params['total'] ?? 0;
        
        // Validar soma dos valores
        $sum = array_sum($methods);
        if (abs($sum - $total) > 0.01) {
            return rest_ensure_response(array(
                'valid' => false,
                'message' => 'A soma dos valores não corresponde ao total'
            ));
        }
        
        // Validar combinação
        $method_keys = array_keys($methods);
        $combination = implode('_', $method_keys);
        $allowed = $this->gateway->get_multipayment_combinations();
        
        // Verificar se a combinação é permitida
        $is_valid = false;
        foreach ($allowed as $allowed_combo) {
            $combo_methods = explode('_', $allowed_combo);
            if (count(array_diff($method_keys, $combo_methods)) === 0) {
                $is_valid = true;
                break;
            }
        }
        
        return rest_ensure_response(array(
            'valid' => $is_valid,
            'message' => $is_valid ? 'Combinação válida' : 'Combinação não permitida'
        ));
    }
    
    /**
     * Endpoint: Obter configurações de antifraude
     */
    public function get_antifraud_settings() {
        $cart_total = WC()->cart ? WC()->cart->total : 0;
        
        return rest_ensure_response(array(
            'enabled' => $this->gateway->is_antifraud_enabled($cart_total),
            'min_value' => $this->gateway->get_option('antifraud_min_value'),
            'auto_cancel' => $this->gateway->get_option('antifraud_auto_cancel') === 'yes',
            'manual_review' => $this->gateway->get_option('antifraud_manual_review') === 'yes'
        ));
    }
    
    /**
     * Endpoint: Calcular parcelas
     */
    public function calculate_installments($request) {
        $params = $request->get_json_params();
        $amount = $params['amount'] ?? 0;
        
        $max_installments = intval($this->gateway->get_option('credit_card_max_installments', 12));
        $min_value = floatval($this->gateway->get_option('credit_card_min_installment_value', 5));
        $interest_rate = floatval($this->gateway->get_option('credit_card_interest_rate', 0)) / 100;
        
        $installments = array();
        
        for ($i = 1; $i <= $max_installments; $i++) {
            $installment_amount = $amount / $i;
            
            // Aplicar juros se configurado
            if ($i > 1 && $interest_rate > 0) {
                $installment_amount = $amount * ($interest_rate * pow(1 + $interest_rate, $i)) / 
                                     (pow(1 + $interest_rate, $i) - 1);
            }
            
            // Verificar valor mínimo
            if ($installment_amount < $min_value && $i > 1) {
                break;
            }
            
            $installments[] = array(
                'number' => $i,
                'value' => round($installment_amount, 2),
                'total' => round($installment_amount * $i, 2),
                'interest' => $i > 1 && $interest_rate > 0
            );
        }
        
        return rest_ensure_response(array(
            'installments' => $installments,
            'max_installments' => count($installments)
        ));
    }
}

// PARTE 3: WEBHOOK HANDLER COM ANTIFRAUDE

/**
 * Classe de Handler de Webhooks
 * Processa eventos do Pagar.me incluindo antifraude
 */
namespace PagarMeGateway;

class PagarMe_Webhook_Handler {
    
    private $logger;
    
    public function __construct() {
        $this->logger = new Logger();
        
        // CRITICAL: Registrar o hook DENTRO do construtor
        add_action('woocommerce_api_pagarme_webhook', array($this, 'handle_webhook'));
        
        error_log('Pagar.me Webhook: Hook registrado com sucesso');
    }
    
    public function handle_webhook() {
        error_log('Pagar.me Webhook: handle_webhook() foi chamado!');
        
        // Pegar dados do POST
        $input = file_get_contents('php://input');
        $webhook_data = json_decode($input, true);
        
        // Log detalhado
        $this->logger->info('Webhook recebido', array(
            'type' => $webhook_data['type'] ?? 'unknown',
            'id' => $webhook_data['id'] ?? 'N/A',
            'data_present' => isset($webhook_data['data']) ? 'yes' : 'no'
        ));
        
        // Validar dados
        if (!$webhook_data || !isset($webhook_data['type'])) {
            http_response_code(400);
            echo json_encode(array('error' => 'Invalid webhook data'));
            exit;
        }
        
        $type = $webhook_data['type'];
        $data = $webhook_data['data'] ?? array();
        
        try {
            // Processar diferentes tipos de webhook
            switch ($type) {
                case 'order.paid':
                    $this->handle_order_paid($data);
                    break;
                    
                case 'charge.paid':
                    $this->handle_charge_paid($data);
                    break;
                    
                case 'charge.payment_failed':
                    $this->handle_charge_failed($data);
                    break;
                    
                default:
                    $this->logger->info('Webhook type não tratado', array('type' => $type));
            }
            
            // Responder com sucesso
            http_response_code(200);
            echo json_encode(array('success' => true, 'message' => 'Webhook processado'));
            
        } catch (\Exception $e) {
            $this->logger->error('Erro ao processar webhook', array(
                'error' => $e->getMessage(),
                'type' => $type
            ));
            
            http_response_code(500);
            echo json_encode(array('error' => $e->getMessage()));
        }
        
        exit; // IMPORTANTE: parar execução após processar
    }
    
    // Seus handlers existentes...
    private function handle_order_paid($order_data) {
        // código existente
    }
    
    private function handle_charge_paid($charge_data) {
        // código existente
    }
    
    private function handle_charge_failed($charge_data) {
        // código existente
    }
}

// CRITICAL: Instanciar a classe DENTRO do namespace
add_action('plugins_loaded', function() {
    if (class_exists('WC_Payment_Gateway')) {
        new PagarMe_Webhook_Handler();
        error_log('Pagar.me Webhook Handler: Inicializado com sucesso');
    }
}, 11);


// PARTE 4: HOOKS E FILTROS PARA INTEGRAÇÃO

/**
 * Hook para o Multistep obter configurações
 */
add_filter('multistep_payment_gateway_config', function($config) {
    if (class_exists('PagarMeGateway\PagarMe_Gateway_Complete')) {
        $gateway = new \PagarMeGateway\PagarMe_Gateway_Complete();  // CORRIGIDO (adicionada \ no início)
        
        $config['pagarme'] = array(
            'enabled' => $gateway->enabled === 'yes',
            'methods' => $gateway->get_enabled_payment_methods(),
            'multipayment' => array(
                'enabled' => $gateway->is_multipayment_enabled(),
                'combinations' => $gateway->get_multipayment_combinations(),
                'min_value' => $gateway->get_option('multipayment_min_value'),
                'max_methods' => $gateway->get_option('multipayment_max_methods')
            ),
            'antifraud' => array(
                'enabled' => $gateway->is_antifraud_enabled(),
                'min_value' => $gateway->get_option('antifraud_min_value')
            )
        );
    }
    return $config;
}, 10, 1);

/**
 * Adicionar classe CSS no checkout baseado nos métodos habilitados
 */
add_filter('body_class', function($classes) {
    if (is_checkout()) {
        $gateway = new \PagarMeGateway\PagarMe_Gateway_Complete();  // CORRIGIDO (adicionada \ no início)
        $methods = $gateway->get_enabled_payment_methods();
        
        foreach (array_keys($methods) as $method) {
            $classes[] = 'pagarme-' . str_replace('_', '-', $method) . '-enabled';
        }
        
        if ($gateway->is_multipayment_enabled()) {
            $classes[] = 'pagarme-multipayment-enabled';
        }
        
        if ($gateway->is_antifraud_enabled()) {
            $classes[] = 'pagarme-antifraud-enabled';
        }
    }
    return $classes;
});

/**
 * Expor dados via JavaScript para o Multistep
 */
add_action('wp_footer', function() {
    if (is_checkout()) {
        $gateway = new \PagarMeGateway\PagarMe_Gateway_Complete();  // CORRIGIDO (adicionada \ no início)
        ?>
        <script type="text/javascript">
        window.PagarMeGatewayConfig = <?php echo json_encode(array(
            'methods' => $gateway->get_enabled_payment_methods(),
            'multipayment' => array(
                'enabled' => $gateway->is_multipayment_enabled(),
                'combinations' => $gateway->get_multipayment_combinations()
            ),
            'antifraud' => $gateway->is_antifraud_enabled(),
            'ajax_url' => admin_url('admin-ajax.php'),
            'api_url' => rest_url('pagarme/v1'),
            'nonce' => wp_create_nonce('pagarme_checkout')
        )); ?>;
        </script>
        <?php
    }
});

// PARTE 5: INICIALIZAÇÃO

/**
 * Inicializar o sistema completo
 */
add_action('plugins_loaded', function() {
    if (class_exists('WC_Payment_Gateway')) {
        // Registrar Gateway
        add_filter('woocommerce_payment_gateways', function($gateways) {
            $gateways[] = 'PagarMeGateway\\PagarMe_Gateway_Complete';
            return $gateways;
        });
        
        // Inicializar API
        new PagarMeGateway\PagarMe_API_Endpoints();
        
        // Inicializar Webhook Handler
        new PagarMeGateway\PagarMe_Webhook_Handler();
    }
}, 11);

// FIM DO ARQUIVO
