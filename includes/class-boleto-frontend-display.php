<?php
/**
 * Exibição do Boleto no Frontend (Minha Conta)
 * VISUAL IDÊNTICO à thank you page
 * 
 * @package PagarMe Gateway
 * @version 4.0.0 - Simplificado
 */

namespace PagarMeGateway;

if (!defined('ABSPATH')) {
    exit;
}

class Boleto_Frontend_Display {
    
    private $gateway_id = 'pagarme_gateway';
    
    public function __construct() {
        // Adicionar botão "Ver Boleto" na tabela de pedidos
        add_filter('woocommerce_my_account_my_orders_actions', array($this, 'add_boleto_button'), 10, 2);
        
        // REMOVIDO: Hook redundante que causava duplicação na Thank You Page
        // add_action('woocommerce_order_details_after_order_table', array($this, 'display_boleto_details'), 10, 1);
        
        // Script JavaScript para copiar linha digitável
        add_action('wp_footer', array($this, 'add_copy_script'));
    }
    
    /**
     * Adicionar botão "Ver Boleto" na tabela "Meus Pedidos"
     */
    public function add_boleto_button($actions, $order) {
        // Verificar se o pedido foi pago com o gateway Pagar.me
        if ($order->get_payment_method() !== $this->gateway_id) {
            return $actions;
        }
        
        // Verificar se existe boleto gerado
        $boleto_url = $order->get_meta('_pagarme_boleto_url', true);
        
        if (!$boleto_url) {
            return $actions;
        }
        
        // Verificar se o boleto está pago (se sim, não mostrar botão)
        if ($order->is_paid()) {
            return $actions;
        }
        
        // Adicionar botão "Ver Boleto"
        $actions['view_boleto'] = array(
            'url'  => esc_url($boleto_url),
            'name' => __('Ver Boleto', 'pagarme-gateway'),
            'class' => 'view-boleto'
        );
        
        return $actions;
    }
    
    /**
     * Exibir boleto nos detalhes do pedido (MANTIDO APENAS PARA MY ACCOUNT/VIEW ORDER)
     * VISUAL IDÊNTICO à thank you page
     */
    public function display_boleto_details($order) {
        // Verificar se o pedido foi pago com o gateway Pagar.me
        if ($order->get_payment_method() !== $this->gateway_id) {
            return;
        }
        
        // Buscar dados do boleto
        $boleto_url = $order->get_meta('_pagarme_boleto_url', true);
        $boleto_pdf = $order->get_meta('_pagarme_boleto_pdf', true);
        $boleto_line = $order->get_meta('_pagarme_boleto_line', true);
        
        // Se não tem boleto, não exibir nada
        if (!$boleto_url) {
            return;
        }
        
        // Verificar se já foi pago
        if ($order->is_paid()) {
            return; // Não exibir boleto se já foi pago
        }
        
        // USAR O MESMO HTML DA THANK YOU PAGE
        ?>
        <div class="pagarme-boleto-info" style="margin: 20px 0; padding: 20px; border: 1px solid #F05A01; border-left: 5px solid #F05A01; background: #e7f3ff; border-radius: 5px;">
            <h2 style="color: #333; font-size: 1.4em;">Seu Boleto Bancário</h2>
            <p>Clique nos botões abaixo para visualizar ou baixar:</p>
            
            <div class="boleto-buttons" style="margin: 15px 0;">
                <a href="<?php echo esc_url($boleto_url); ?>" target="_blank" rel="noopener" class="button button-primary" style="background: #F05A01; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; display: inline-block; margin-right: 10px;">
                    📄 Ver Boleto
                </a>
                <?php if ($boleto_pdf): ?>
                <a href="<?php echo esc_url($boleto_pdf); ?>" target="_blank" rel="noopener" download class="button" style="background: #28a745; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; display: inline-block;">
                    📥 Baixar PDF
                </a>
                <?php endif; ?>
            </div>
            
            <?php if ($boleto_line): ?>
            <div class="boleto-linha-digitavel" style="background: white; padding: 15px; margin-top: 15px; border-radius: 4px; border: 1px dashed #ccc;">
                <p style="margin: 0;"><strong>Linha Digitável:</strong></p>
                <code id="boleto-barcode-<?php echo esc_attr($order->get_id()); ?>" style="display: block; font-family: monospace; font-size: 14px; word-break: break-all; margin-top: 5px;"><?php echo esc_html($boleto_line); ?></code>
                <button type="button" onclick="copyBarcodeToClipboard('<?php echo esc_js($boleto_line); ?>', this)" class="button" style="margin-top: 10px; background: #F05A01; color: white; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer;">
                    📋 Copiar Código
                </button>
            </div>
            <?php endif; ?>
            
            <p class="boleto-vencimento" style="color: #d9534f; margin-top: 15px;">
                ⏰ <strong>Importante:</strong> Pague até a data de vencimento.
            </p>
        </div>
        <?php
    }
    
    /**
     * Adicionar script JavaScript para copiar linha digitável
     */
    public function add_copy_script() {
        if (!is_account_page() && !is_wc_endpoint_url('view-order')) {
            return;
        }
        
        ?>
        <script type="text/javascript">
        // Função para copiar linha digitável
        function copyBarcodeToClipboard(text, button) {
            // Criar elemento temporário
            var tempInput = document.createElement('textarea');
            tempInput.value = text;
            tempInput.style.position = 'fixed';
            tempInput.style.opacity = '0';
            document.body.appendChild(tempInput);
            
            // Selecionar e copiar
            tempInput.select();
            tempInput.setSelectionRange(0, 99999); // Para mobile
            
            try {
                document.execCommand('copy');
                
                // Feedback visual
                var originalText = button.textContent;
                button.textContent = 'Copiado!';
                button.style.background = '#28a745';
                
                setTimeout(function() {
                    button.textContent = originalText;
                    button.style.background = '#F05A01';
                }, 2000);
                
            } catch (err) {
                alert('Erro ao copiar. Por favor, copie manualmente.');
            }
            
            // Remover elemento temporário
            document.body.removeChild(tempInput);
        }
        </script>
        <?php
    }
}

// Inicializar a classe
new Boleto_Frontend_Display();