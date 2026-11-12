/**
 * Exemplo de integração Railway com a API do Plugin Pagar.me Gateway
 * 
 * Este exemplo mostra como consumir os dados dos pedidos do WooCommerce
 * e integrá-los com o ERP Sankhya
 */

const axios = require('axios');

class WooCommerceIntegration {
    constructor(config) {
        this.woocommerceUrl = config.woocommerceUrl;
        this.apiKey = config.apiKey;
        this.sankhyaClient = config.sankhyaClient; // Seu cliente Sankhya existente
        
        this.apiClient = axios.create({
            baseURL: `${this.woocommerceUrl}/wp-json/pagarme-gateway/v1`,
            headers: {
                'X-API-Key': this.apiKey,
                'Content-Type': 'application/json'
            },
            timeout: 30000
        });
    }

    /**
     * Processar pedidos pendentes de integração
     */
    async processPendingOrders() {
        try {
            console.log('Iniciando processamento de pedidos pendentes...');
            
            let hasMore = true;
            let offset = 0;
            const limit = 50;
            let totalProcessed = 0;

            while (hasMore) {
                const response = await this.apiClient.get('/orders/pending', {
                    params: { limit, offset }
                });

                const { orders, has_more } = response.data;
                hasMore = has_more;

                console.log(`Processando lote de ${orders.length} pedidos (offset: ${offset})`);

                for (const order of orders) {
                    try {
                        await this.processOrder(order);
                        totalProcessed++;
                    } catch (error) {
                        console.error(`Erro ao processar pedido ${order.wc_order_id}:`, error.message);
                        
                        // Marcar como erro na API
                        await this.markOrderProcessed(order.wc_order_id, {
                            status: 'error',
                            error_message: error.message
                        });
                    }
                }

                offset += limit;
                
                // Pequena pausa entre lotes para não sobrecarregar
                if (hasMore) {
                    await this.sleep(1000);
                }
            }

            console.log(`Processamento concluído. Total de pedidos processados: ${totalProcessed}`);
            return { success: true, processed: totalProcessed };

        } catch (error) {
            console.error('Erro no processamento de pedidos:', error.message);
            throw error;
        }
    }

    /**
     * Processar um pedido individual
     */
    async processOrder(orderData) {
        console.log(`Processando pedido WC #${orderData.wc_order_id} - Pagar.me: ${orderData.pagarme_order_id}`);

        // 1. Validar dados do pedido
        this.validateOrderData(orderData);

        // 2. Preparar dados para o Sankhya
        const sankhyaData = this.prepareSankhyaData(orderData);

        // 3. Criar pedido no Sankhya
        const sankhyaResponse = await this.createSankhyaOrder(sankhyaData);

        // 4. Marcar como processado no WooCommerce
        await this.markOrderProcessed(orderData.wc_order_id, {
            status: 'processed',
            sankhya_order_id: sankhyaResponse.orderId
        });

        console.log(`Pedido ${orderData.wc_order_id} integrado com sucesso - Sankhya ID: ${sankhyaResponse.orderId}`);
        
        return sankhyaResponse;
    }

    /**
     * Validar dados do pedido antes de processar
     */
    validateOrderData(orderData) {
        const required = ['wc_order_id', 'pagarme_order_id', 'sankhya_data'];
        
        for (const field of required) {
            if (!orderData[field]) {
                throw new Error(`Campo obrigatório ausente: ${field}`);
            }
        }

        if (!orderData.sankhya_data.items || orderData.sankhya_data.items.length === 0) {
            throw new Error('Pedido não possui itens válidos');
        }

        if (!orderData.sankhya_data.customer.email) {
            throw new Error('Email do cliente é obrigatório');
        }
    }

    /**
     * Preparar dados no formato esperado pelo Sankhya
     */
    prepareSankhyaData(orderData) {
        const { sankhya_data, billing_address } = orderData;

        return {
            // Cabeçalho do pedido
            header: {
                CODEMP: sankhya_data.company_code,
                CODTIPVENDA: sankhya_data.negotiation_type,
                DTNEG: this.formatDateForSankhya(sankhya_data.order_data.date),
                CODPARC: this.getOrCreateCustomerCode(sankhya_data.customer),
                VLRNOTA: parseFloat(sankhya_data.order_data.total),
                OBSERVACAO: `Pedido WooCommerce #${orderData.wc_order_id} - Pagar.me: ${orderData.pagarme_order_id}`,
                
                // Endereço de cobrança
                ENDERECO: billing_address.address_1,
                CIDADE: billing_address.city,
                UF: billing_address.state,
                CEP: billing_address.postcode.replace(/\D/g, ''),
                
                // Dados do pagamento
                FORMA_PAGAMENTO: 'CARTAO_CREDITO',
                PAGARME_ORDER_ID: orderData.pagarme_order_id
            },

            // Itens do pedido
            items: this.formatItemsForSankhya(sankhya_data.items),

            // Metadados para rastreamento
            metadata: {
                wc_order_id: orderData.wc_order_id,
                wc_order_number: orderData.wc_order_number,
                integration_date: new Date().toISOString(),
                source: 'woocommerce_railway'
            }
        };
    }

    /**
     * Formatar itens para o padrão Sankhya
     */
    formatItemsForSankhya(items) {
        return items
            .filter(item => item.type !== 'fee' && item.type !== 'shipping') // Filtrar apenas produtos
            .map(item => ({
                CODPROD: item.sankhya_product_code,
                QTDNEG: item.quantity,
                VLRUNIT: parseFloat(item.unit_price),
                VLRTOT: parseFloat(item.total_price),
                CODVOL: 'UN', // Unidade padrão
                CODLOCALORIG: 0, // Local de estoque padrão
                
                // Informações adicionais
                PRODUTO_NOME: item.product_name,
                SKU_WC: item.sku,
                WC_ITEM_ID: item.wc_item_id
            }));
    }

    /**
     * Criar pedido no Sankhya (usando seu cliente existente)
     */
    async createSankhyaOrder(sankhyaData) {
        try {
            // Aqui você usa sua implementação existente do Sankhya
            const response = await this.sankhyaClient.createOrder({
                cabecalho: sankhyaData.header,
                itens: sankhyaData.items
            });

            return {
                success: true,
                orderId: response.NUNOTA, // ou qualquer ID que o Sankhya retorne
                sankhyaResponse: response
            };

        } catch (error) {
            console.error('Erro ao criar pedido no Sankhya:', error);
            throw new Error(`Falha na integração Sankhya: ${error.message}`);
        }
    }

    /**
     * Marcar pedido como processado no WooCommerce
     */
    async markOrderProcessed(wcOrderId, data) {
        try {
            await this.apiClient.post(`/orders/${wcOrderId}/processed`, data);
        } catch (error) {
            console.error(`Erro ao marcar pedido ${wcOrderId} como processado:`, error.message);
            // Não re-lançar o erro aqui para não interromper o fluxo
        }
    }

    /**
     * Buscar dados de um pedido específico
     */
    async getOrderData(wcOrderId) {
        try {
            const response = await this.apiClient.get(`/orders/${wcOrderId}`);
            return response.data.order;
        } catch (error) {
            if (error.response?.status === 404) {
                return null;
            }
            throw error;
        }
    }

    /**
     * Buscar lote de pedidos com filtros
     */
    async getOrdersBatch(filters = {}) {
        try {
            const response = await this.apiClient.get('/orders/batch', {
                params: filters
            });
            return response.data.orders;
        } catch (error) {
            console.error('Erro ao buscar lote de pedidos:', error.message);
            throw error;
        }
    }

    /**
     * Obter status da integração
     */
    async getIntegrationStatus() {
        try {
            const response = await this.apiClient.get('/status');
            return response.data.status;
        } catch (error) {
            console.error('Erro ao obter status da integração:', error.message);
            throw error;
        }
    }

    /**
     * Reprocessar pedido com erro
     */
    async retryOrder(wcOrderId) {
        try {
            const response = await this.apiClient.post(`/orders/${wcOrderId}/retry`);
            console.log(`Pedido ${wcOrderId} marcado para reprocessamento`);
            return response.data;
        } catch (error) {
            console.error(`Erro ao marcar pedido ${wcOrderId} para retry:`, error.message);
            throw error;
        }
    }

    // Métodos utilitários

    formatDateForSankhya(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('pt-BR'); // DD/MM/YYYY
    }

    getOrCreateCustomerCode(customerData) {
        // Implementar lógica para buscar/criar código do cliente no Sankhya
        // Por enquanto, retorna um código baseado no email
        return `WC_${customerData.email.split('@')[0].toUpperCase()}`;
    }

    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
}

// Exemplo de uso
class SankhyaIntegrationService {
    constructor() {
        this.integration = new WooCommerceIntegration({
            woocommerceUrl: process.env.WOOCOMMERCE_URL,
            apiKey: process.env.WOOCOMMERCE_API_KEY,
            sankhyaClient: this.initSankhyaClient()
        });
    }

    initSankhyaClient() {
        // Retornar sua instância do cliente Sankhya existente
        // return new SankhyaClient({ ... });
        return {
            createOrder: async (data) => {
                // Sua implementação existente
                console.log('Criando pedido no Sankhya:', data);
                return { NUNOTA: Math.floor(Math.random() * 1000000) };
            }
        };
    }

    /**
     * Processo principal executado periodicamente
     */
    async run() {
        try {
            console.log('Iniciando sincronização WooCommerce → Sankhya');
            
            // Verificar status da integração
            const status = await this.integration.getIntegrationStatus();
            console.log('Status da integração:', status);

            // Processar pedidos pendentes
            const result = await this.integration.processPendingOrders();
            
            console.log('Sincronização concluída:', result);
            return result;

        } catch (error) {
            console.error('Erro na sincronização:', error);
            
            // Aqui você pode implementar notificações, retry, etc.
            await this.handleError(error);
            
            throw error;
        }
    }

    async handleError(error) {
        // Implementar lógica de tratamento de erro:
        // - Enviar notificação por email/Slack
        // - Salvar em log de erros
        // - Implementar circuit breaker, etc.
        
        console.log('Enviando notificação de erro...');
    }

    /**
     * Processar apenas pedidos de um período específico
     */
    async syncDateRange(startDate, endDate) {
        try {
            const filters = {
                start_date: startDate,
                end_date: endDate,
                processed: 'false'
            };

            const orders = await this.integration.getOrdersBatch(filters);
            console.log(`Encontrados ${orders.length} pedidos para sincronizar`);

            let processed = 0;
            for (const order of orders) {
                try {
                    await this.integration.processOrder(order);
                    processed++;
                } catch (error) {
                    console.error(`Erro ao processar pedido ${order.wc_order_id}:`, error.message);
                }
            }

            console.log(`Sincronização de período concluída. Processados: ${processed}/${orders.length}`);
            return { total: orders.length, processed, errors: orders.length - processed };

        } catch (error) {
            console.error('Erro na sincronização por período:', error);
            throw error;
        }
    }
}

// Execução
if (require.main === module) {
    const service = new SankhyaIntegrationService();
    
    // Executar uma vez
    service.run()
        .then(result => {
            console.log('Processo concluído:', result);
            process.exit(0);
        })
        .catch(error => {
            console.error('Processo falhou:', error);
            process.exit(1);
        });
}

module.exports = { WooCommerceIntegration, SankhyaIntegrationService };