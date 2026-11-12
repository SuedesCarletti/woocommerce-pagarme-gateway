# Gateway Pagar.me para WooCommerce com API Railway

Plugin WordPress que implementa o gateway de pagamento Pagar.me no WooCommerce e expõe uma API REST para integração com ERP Sankhya via Railway.

## Funcionalidades

- Gateway de pagamento Pagar.me integrado ao WooCommerce
- Processamento de cartão de crédito com validação em tempo real
- Webhooks para atualização automática de status
- API REST completa para integração Railway
- Mapeamento de tipos de negociação por produto/cliente/categoria
- Sistema de logs avançado
- Painel administrativo completo
- Formatação de dados para Sankhya ERP

## Requisitos

- WordPress 5.0+
- WooCommerce 4.0+
- PHP 7.4+
- MySQL 5.6+
- Conta Pagar.me (sandbox ou produção)

## Instalação

### 1. Upload do Plugin

```bash
# Via FTP/cPanel
1. Faça upload da pasta do plugin para /wp-content/plugins/
2. Acesse Plugins > Plugins Instalados no WordPress
3. Ative o "Gateway Pagar.me para WooCommerce"

# Via WordPress Admin
1. Acesse Plugins > Adicionar Novo
2. Clique em "Enviar Plugin"
3. Selecione o arquivo .zip do plugin
4. Clique em "Instalar Agora" e depois "Ativar"
```

### 2. Configuração Inicial

1. **Acesse as configurações:**
   - WooCommerce → Gateway Pagar.me

2. **Configure o Gateway:**
   - WooCommerce → Configurações → Pagamentos
   - Localize "Pagar.me" e clique em "Configurar"
   - Preencha sua API Key do Pagar.me
   - Configure empresa e tipo de negociação padrão

## Configuração Detalhada

### Gateway Pagar.me

```php
// Configurações básicas
Ativar Gateway: Sim
Título: "Cartão de Crédito - Pagar.me"
Descrição: "Pague com segurança usando seu cartão de crédito"
API Key: sua_chave_secreta_pagarme
Ambiente: Sandbox/Produção
Soft Descriptor: Nome da sua loja (máx. 13 caracteres)

// Configurações Sankhya
Código Empresa Padrão: 10
Tipo Negociação Padrão: 763
```

### Webhooks Pagar.me

Configure no painel do Pagar.me:

```
URL: https://seusite.com.br/wc-api/pagarme_webhook

Eventos:
order.paid
order.payment_failed  
order.canceled
charge.paid
charge.payment_failed
```

### API para Railway

A chave API é gerada automaticamente na ativação. Para regenerar:

1. Acesse WooCommerce → Gateway Pagar.me → Integração
2. Clique em "Regenerar" na seção Chave API
3. Use esta chave no header `X-API-Key` das requisições

## Endpoints da API

### Base URL
```
https://seusite.com.br/wp-json/pagarme-gateway/v1
```

### Autenticação
```javascript
headers: {
  'X-API-Key': 'REDACTED_SECRET',
  'Content-Type': 'application/json'
}
```

### Endpoints Disponíveis

#### 1. Buscar Pedidos Pendentes
```http
GET /orders/pending?limit=50&offset=0
```

**Resposta:**
```json
{
  "success": true,
  "orders": [
    {
      "wc_order_id": 123,
      "wc_order_number": "123",
      "pagarme_order_id": "or_abc123",
      "sankhya_data": {
        "company_code": "10",
        "negotiation_type": "763",
        "customer": { ... },
        "order_data": { ... },
        "items": [ ... ]
      },
      "billing_address": { ... },
      "status": "processing",
      "processed": false,
      "created_at": "2025-01-15 10:30:00"
    }
  ],
  "total": 1,
  "has_more": false
}
```

#### 2. Dados de Pedido Específico
```http
GET /orders/{id}
```

#### 3. Marcar Pedido como Processado
```http
POST /orders/{id}/processed
Content-Type: application/json

{
  "sankhya_order_id": "12345",
  "status": "processed"
}
```

#### 4. Buscar Lote de Pedidos
```http
GET /orders/batch?start_date=2025-01-01&end_date=2025-01-31&processed=false
```

#### 5. Status da Integração
```http
GET /status
```

#### 6. Reprocessar Pedido
```http
POST /orders/{id}/retry
```

## Mapeamentos

### Por Produto
Configure tipos de negociação específicos para produtos individuais:

1. Acesse Gateway Pagar.me → Mapeamentos
2. Na seção "Por Produto", selecione o produto
3. Informe o tipo de negociação
4. Clique em "Adicionar"

### Por Cliente
Configure tipos específicos para clientes:

1. Na seção "Por Cliente", busque o cliente
2. Informe o tipo de negociação
3. Clique em "Adicionar"

### Por Categoria
Configure para categorias inteiras:

1. Na seção "Por Categoria", selecione a categoria
2. Informe o tipo de negociação
3. Clique em "Adicionar"

**Ordem de prioridade:**
1. Produto específico
2. Cliente específico  
3. Categoria do produto
4. Tipo padrão configurado

## Integração Railway

### Exemplo de Implementação

```javascript
const axios = require('axios');

class WooCommerceIntegration {
  constructor(config) {
    this.apiClient = axios.create({
      baseURL: `${config.woocommerceUrl}/wp-json/pagarme-gateway/v1`,
      headers: {
        'X-API-Key': config.apiKey,
        'Content-Type': 'application/json'
      }
    });
  }

  async processPendingOrders() {
    const response = await this.apiClient.get('/orders/pending');
    const orders = response.data.orders;

    for (const order of orders) {
      try {
        // Processar no Sankhya
        const sankhyaResponse = await this.createSankhyaOrder(order);
        
        // Marcar como processado
        await this.apiClient.post(`/orders/${order.wc_order_id}/processed`, {
          sankhya_order_id: sankhyaResponse.id,
          status: 'processed'
        });
        
        console.log(`Pedido ${order.wc_order_id} processado`);
      } catch (error) {
        console.error(`Erro no pedido ${order.wc_order_id}:`, error);
        
        // Marcar erro
        await this.apiClient.post(`/orders/${order.wc_order_id}/processed`, {
          status: 'error',
          error_message: error.message
        });
      }
    }
  }

  async createSankhyaOrder(orderData) {
    // Sua lógica de integração Sankhya aqui
    return { id: 'SANKHYA_ORDER_ID' };
  }
}

// Uso
const integration = new WooCommerceIntegration({
  woocommerceUrl: 'https://seusite.com.br',
  apiKey: 'REDACTED_SECRET'
});

integration.processPendingOrders();
```

### Estrutura dos Dados

Os dados são formatados especificamente para facilitar a integração com Sankhya:

```javascript
{
  "sankhya_data": {
    "company_code": "10",
    "negotiation_type": "763",
    "customer": {
      "name": "João Silva",
      "email": "joao@email.com",
      "document": "123.456.789-00",
      "phone": "(11) 99999-9999"
    },
    "order_data": {
      "total": 150.00,
      "date": "2025-01-15 10:30:00",
      "payment_method": "credit_card"
    },
    "items": [
      {
        "product_name": "Produto Exemplo",
        "sku": "PROD-001",
        "sankhya_product_code": "PROD-001",
        "quantity": 2,
        "unit_price": 75.00,
        "total_price": 150.00
      }
    ]
  }
}
```

## Monitoramento

### Dashboard
Acesse WooCommerce → Gateway Pagar.me → Dashboard para visualizar:

- Pedidos pendentes de integração
- Pedidos processados hoje
- Taxa de sucesso dos últimos 30 dias
- Receita mensal via Pagar.me
- Últimas transações
- Status do sistema

### Logs
O sistema mantém logs detalhados em:

- **Arquivo:** `/wp-content/uploads/pagarme-gateway-logs/`
- **Banco:** Tabela `wp_pagarme_gateway_logs`
- **WooCommerce:** Integrado com o sistema de logs nativo

Níveis de log disponíveis:
- `ERROR`: Apenas erros críticos
- `WARNING`: Avisos e erros  
- `INFO`: Informações gerais (recomendado)
- `DEBUG`: Tudo (desenvolvimento)

## Desenvolvimento

### Estrutura do Plugin

```
pagarme-woocommerce-gateway/
├── pagarme-gateway.php              # Arquivo principal
├── includes/
│   ├── class-pagarme-gateway.php    # Gateway principal
│   ├── class-webhook-handler.php    # Processamento webhooks
│   ├── class-rest-api.php          # API REST
│   ├── class-data-formatter.php    # Formatação de dados
│   └── class-logger.php            # Sistema de logs
├── admin/
│   └── class-admin-settings.php    # Painel administrativo
├── assets/
│   ├── js/
│   │   ├── checkout.js             # Scripts do checkout
│   │   └── admin.js               # Scripts do admin
│   └── css/
│       └── admin-style.css        # Estilos do admin
└── examples/
    └── railway-integration.js      # Exemplo integração Railway
```

### Hooks Disponíveis

```php
// Antes de processar pagamento
do_action('pagarme_gateway_before_payment', $order, $payment_data);

// Após pagamento aprovado
do_action('pagarme_gateway_payment_approved', $order, $pagarme_response);

// Antes de salvar dados de integração
apply_filters('pagarme_gateway_integration_data', $integration_data, $order);

// Determinar tipo de negociação
apply_filters('pagarme_gateway_negotiation_type', $type, $order);
```

### Personalização

#### Adicionar Campo Personalizado no Checkout
```php
add_action('woocommerce_after_checkout_billing_form', function($checkout) {
    woocommerce_form_field('custom_field', array(
        'type' => 'text',
        'class' => array('form-row-wide'),
        'label' => 'Campo Personalizado',
        'required' => false,
    ), $checkout->get_value('custom_field'));
});

// Salvar no pedido
add_action('woocommerce_checkout_update_order_meta', function($order_id) {
    if ($_POST['custom_field']) {
        update_post_meta($order_id, '_custom_field', sanitize_text_field($_POST['custom_field']));
    }
});
```

#### Customizar Dados de Integração
```php
add_filter('pagarme_gateway_integration_data', function($data, $order) {
    $data['custom_info'] = get_post_meta($order->get_id(), '_custom_field', true);
    return $data;
}, 10, 2);
```

## Solução de Problemas

### Problemas Comuns

#### 1. Webhook não funciona
- Verifique se a URL está correta no painel Pagar.me
- Teste a URL: `https://seusite.com.br/wc-api/pagarme_webhook`
- Verifique logs em Gateway Pagar.me → Logs

#### 2. API retorna erro 401
- Verifique se a chave API está correta
- Regenere a chave em Gateway Pagar.me → Integração

#### 3. Pedidos não aparecem como pendentes
- Verifique se o pagamento foi aprovado
- Confirme se o gateway está ativo
- Veja os logs para erros de processamento

#### 4. Erro de conexão com Pagar.me
- Teste a conexão em Gateway Pagar.me → Integração
- Verifique sua chave API no painel Pagar.me
- Confirme se não há firewall bloqueando

### Debug Mode

Para ativar logs detalhados:

1. Acesse Gateway Pagar.me → Integração
2. Altere "Nível de Log" para "Debug (Tudo)"
3. Reproduza o problema
4. Verifique os logs em Gateway Pagar.me → Logs

### Limpeza de Dados

Para limpar dados de teste:

```sql
-- Limpar logs antigos (30+ dias)
DELETE FROM wp_pagarme_gateway_logs 
WHERE timestamp < DATE_SUB(NOW(), INTERVAL 30 DAY);

-- Limpar fila de integração processada
DELETE FROM wp_pagarme_integration_queue 
WHERE status = 'processed' AND processed_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
```

## Segurança

### Boas Práticas Implementadas

- Sanitização de todos os inputs
- Validação rigorosa de dados de cartão
- Logs sem informações sensíveis
- Chaves API com hash seguro
- Proteção contra ataques CSRF
- Rate limiting nos endpoints da API
- Validação de assinatura dos webhooks

### Compliance

- **PCI DSS:** Dados de cartão não são armazenados
- **LGPD:** Logs anonimizados, dados sensíveis mascarados
- **Segurança:** Comunicação apenas via HTTPS

## Suporte

### Logs de Erro
Os logs estão disponíveis em:
- Dashboard do plugin
- `/wp-content/uploads/pagarme-gateway-logs/`
- WooCommerce → Status → Logs

### Informações do Sistema
Para reportar problemas, inclua:
- Versão do WordPress
- Versão do WooCommerce  
- Versão do plugin
- Logs relevantes
- Descrição detalhada do erro

## Changelog

### v1.0.0 (2025-01-15)
- Lançamento inicial
- Gateway Pagar.me integrado
- API REST completa
- Sistema de mapeamentos
- Painel administrativo
- Sistema de logs avançado
- Webhooks funcionais
- Validação de cartão em tempo real

## Licença

Todos os direitos reservados. Código publicado apenas para consulta e avaliação; veja o arquivo [LICENSE](LICENSE).

## Contribuição

Contribuições são bem-vindas! Por favor:

1. Fork o repositório
2. Crie uma branch para sua feature
3. Faça commit das alterações
4. Envie um pull request

---

**Desenvolvido com para facilitar integrações WooCommerce + Pagar.me + Sankhya**