# Guia de Instalação Rápida

## Setup em 5 Minutos

### 1. Instalar Plugin WordPress

```bash
# Upload dos arquivos
1. Faça upload da pasta para /wp-content/plugins/
2. Ative o plugin no WordPress Admin
```

### 2. Configurar Gateway

```bash
# WordPress Admin
WooCommerce → Configurações → Pagamentos → Pagar.me → Configurar

Habilitar: Sim
API Key: [sua_chave_pagarme]
Ambiente: Sandbox/Produção
Código Empresa: 10
Tipo Negociação: 763
```

### 3. Configurar Webhook Pagar.me

```bash
# Painel Pagar.me
URL: https://seusite.com.br/wc-api/pagarme_webhook

Eventos:
order.paid
order.payment_failed
order.canceled
charge.paid
charge.payment_failed
```

### 4. Configurar Railway

```bash
# Seu projeto Railway
1. Copie examples/.env.example para .env
2. Preencha as variáveis:
   - WOOCOMMERCE_URL=https://seusite.com.br
   - WOOCOMMERCE_API_KEY=[chave_do_plugin]
   - SANKHYA_BASE_URL=[url_sankhya]
   - SANKHYA_APP_KEY=[app_key_sankhya]

3. npm install
4. npm start
```

### 5. Testar Integração

```bash
# No WordPress
1. Faça um pedido teste
2. Verifique em Gateway Pagar.me → Dashboard
3. Confirme se aparece como "Pendente"

# No Railway
1. Execute: npm run sync
2. Verifique os logs
3. Confirme integração no Sankhya
```

## Verificações Importantes

### WordPress
- [ ] Plugin ativado
- [ ] WooCommerce funcionando
- [ ] Gateway configurado
- [ ] Webhook URL correta

### Pagar.me
- [ ] Webhook configurado
- [ ] Eventos selecionados
- [ ] API Key válida
- [ ] Ambiente correto

### Railway
- [ ] Variáveis de ambiente
- [ ] Conexão com WooCommerce
- [ ] Conexão com Sankhya
- [ ] Logs funcionando

## Problemas Comuns

### Webhook não funciona
```bash
# Teste a URL manualmente
curl -X POST https://seusite.com.br/wc-api/pagarme_webhook \
  -H "Content-Type: application/json" \
  -d '{"type":"test","data":{}}'

# Deve retornar status 200
```

### API retorna 401
```bash
# Regenerar chave API
WordPress → Gateway Pagar.me → Integração → Regenerar Chave
```

### Pedidos não sincronizam
```bash
# Verificar logs
WordPress → Gateway Pagar.me → Logs

# Testar conexão
WordPress → Gateway Pagar.me → Integração → Testar Conexão
```

## URLs Importantes

- **Admin Plugin:** `/wp-admin/admin.php?page=pagarme-gateway-settings`
- **Webhook:** `/wc-api/pagarme_webhook`
- **API Base:** `/wp-json/pagarme-gateway/v1`
- **Logs:** `/wp-content/uploads/pagarme-gateway-logs/`

## Links Úteis

- [Documentação Pagar.me](https://docs.pagar.me)
- [WooCommerce Hooks](https://woocommerce.com/document/introduction-to-hooks-actions-and-filters/)
- [Railway Docs](https://docs.railway.app)

---

**Pronto! Sua integração está funcionando!**