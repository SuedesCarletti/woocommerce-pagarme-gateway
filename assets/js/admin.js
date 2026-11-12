/**
 * Scripts do Painel Administrativo - Pagar.me Gateway
 */

(function($) {
    'use strict';
    
    var PagarMeAdmin = {
        
        currentTab: 'dashboard',
        dashboardInterval: null,
        
        init: function() {
            this.bindEvents();
            this.initCurrentTab();
            this.setupSelect2();
        },
        
        bindEvents: function() {
            // Navegação entre abas
            $('.nav-tab').on('click', this.switchTab.bind(this));
            
            // Dashboard
            $('#test-connection').on('click', this.testConnection.bind(this));
            $('#sync-pending').on('click', this.syncPending.bind(this));
            $('#export-data').on('click', this.exportData.bind(this));
            $('.copy-webhook').on('click', this.copyWebhook.bind(this));
            
            // Integração
            $('#test-pagarme').on('click', this.testPagarMe.bind(this));
            $('#test-webhook').on('click', this.simulateWebhook.bind(this));
            $('#regenerate-api-key').on('click', this.regenerateApiKey.bind(this));
            
            // Mapeamentos
            $('#add-product-mapping').on('click', this.addProductMapping.bind(this));
            $('#add-customer-mapping').on('click', this.addCustomerMapping.bind(this));
            $('#add-category-mapping').on('click', this.addCategoryMapping.bind(this));
            $(document).on('click', '.remove-mapping', this.removeMapping.bind(this));
            
            // Logs
            $('#refresh-logs').on('click', this.refreshLogs.bind(this));
            $('#export-logs').on('click', this.exportLogs.bind(this));
            $('#clear-logs').on('click', this.clearLogs.bind(this));
            $('#log-level-filter, #log-date-filter').on('change', this.filterLogs.bind(this));
            $('#logs-prev').on('click', this.logsPrevPage.bind(this));
            $('#logs-next').on('click', this.logsNextPage.bind(this));
        },
        
        initCurrentTab: function() {
            var urlParams = new URLSearchParams(window.location.search);
            var tab = urlParams.get('tab') || 'dashboard';
            this.currentTab = tab;
            
            console.log('Inicializando aba:', tab);
            
            // Garantir que a aba correta está visível
            $('.dashboard-content, .integration-content, .mapping-content, .logs-content').hide();
            
            var $content = $('.' + tab + '-content');
            if ($content.length > 0) {
                $content.show();
                console.log('Aba', tab, 'exibida com sucesso');
            } else {
                console.error('Conteúdo não encontrado para aba:', tab);
                // Fallback: mostrar dashboard
                $('.dashboard-content').show();
                tab = 'dashboard';
            }
            
            // Atualizar classe ativa na navegação
            $('.nav-tab').removeClass('nav-tab-active');
            $('.nav-tab[href*="tab=' + tab + '"]').addClass('nav-tab-active');
            
            // Inicializar funcionalidades específicas da aba
            switch (tab) {
                case 'dashboard':
                    this.initDashboard();
                    break;
                case 'integration':
                    this.initIntegration();
                    break;
                case 'mapping':
                    this.initMapping();
                    break;
                case 'logs':
                    this.initLogs();
                    break;
                default:
                    console.warn('Aba desconhecida:', tab);
            }
        },
        
        setupSelect2: function() {
            // Configurar Select2 para busca de produtos
            $('#product-select').select2({
                placeholder: 'Buscar produto...',
                minimumInputLength: 2,
                ajax: {
                    url: pagarme_admin.ajax_url,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            action: 'search_products',
                            search: params.term,
                            nonce: pagarme_admin.nonce
                        };
                    },
                    processResults: function(data) {
                        return {
                            results: data.data || []
                        };
                    }
                }
            });
            
            // Configurar Select2 para busca de clientes
            $('#customer-select').select2({
                placeholder: 'Buscar cliente...',
                minimumInputLength: 2,
                ajax: {
                    url: pagarme_admin.ajax_url,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            action: 'search_customers',
                            search: params.term,
                            nonce: pagarme_admin.nonce
                        };
                    },
                    processResults: function(data) {
                        return {
                            results: data.data || []
                        };
                    }
                }
            });
        },
        
        switchTab: function(e) {
            e.preventDefault();
            
            // Pegar o elemento clicado
            var $clickedTab = $(e.target).closest('.nav-tab');
            var href = $clickedTab.attr('href');
            
            if (!href) {
                console.error('Href não encontrado no elemento:', $clickedTab);
                return;
            }
            
            // Extrair nome da aba
            var tab = href.split('tab=')[1];
            if (!tab) {
                console.error('Tab não encontrada na URL:', href);
                return;
            }
            
            console.log('Mudando para aba:', tab);
            this.currentTab = tab;
            
            // Atualizar classes ativas
            $('.nav-tab').removeClass('nav-tab-active');
            $clickedTab.addClass('nav-tab-active');
            
            // Esconder todo conteúdo primeiro
            $('.dashboard-content, .integration-content, .mapping-content, .logs-content').hide();
            
            // Mostrar conteúdo da aba específica
            var $content = $('.' + tab + '-content');
            if ($content.length === 0) {
                console.error('Conteúdo não encontrado para aba:', tab);
                // Fallback: mostrar dashboard
                $('.dashboard-content').show();
                return;
            }
            
            $content.show();
            console.log('Aba exibida:', tab);
            
            // Atualizar URL sem recarregar página
            var newUrl = window.location.pathname + '?page=pagarme-gateway-settings&tab=' + tab;
            if (window.history && window.history.pushState) {
                window.history.pushState({}, '', newUrl);
            }
            
            // Inicializar aba
            this.initCurrentTab();
        },
        
        initDashboard: function() {
            this.loadDashboardStats();
            this.loadRecentTransactions();
            this.checkSystemStatus();
            
            // Atualizar a cada 30 segundos
            if (this.dashboardInterval) {
                clearInterval(this.dashboardInterval);
            }
            
            this.dashboardInterval = setInterval(function() {
                this.loadDashboardStats();
                this.loadRecentTransactions();
            }.bind(this), 30000);
        },
        
        loadDashboardStats: function() {
            $.ajax({
                url: pagarme_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_dashboard_stats',
                    nonce: pagarme_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#pending-orders').text(response.data.pending_orders);
                        $('#processed-today').text(response.data.processed_today);
                        $('#success-rate').text(response.data.success_rate);
                        $('#monthly-revenue').html(response.data.monthly_revenue);
                    }
                },
                error: function() {
                    this.showNotice('Erro ao carregar estatísticas', 'error');
                }.bind(this)
            });
        },
        
        loadRecentTransactions: function() {
            $.ajax({
                url: pagarme_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_recent_transactions',
                    nonce: pagarme_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var tbody = $('#recent-transactions');
                        tbody.empty();
                        
                        if (response.data.length === 0) {
                            tbody.append('<tr><td colspan="6">Nenhuma transação encontrada</td></tr>');
                            return;
                        }
                        
                        $.each(response.data, function(i, transaction) {
                            var statusClass = transaction.sankhya_status === 'Integrado' ? 'status-success' : 
                                            transaction.sankhya_status === 'Processado' ? 'status-warning' : 'status-pending';
                            
                            var row = '<tr>' +
                                     '<td><a href="/wp-admin/post.php?post=' + transaction.order_id + '&action=edit">' + 
                                     transaction.order_id + '</a></td>' +
                                     '<td>' + transaction.customer_name + '</td>' +
                                     '<td>' + transaction.total + '</td>' +
                                     '<td><span class="status-badge status-' + transaction.wc_status.toLowerCase() + '">' + 
                                     transaction.wc_status + '</span></td>' +
                                     '<td><span class="status-badge ' + statusClass + '">' + 
                                     transaction.sankhya_status + '</span></td>' +
                                     '<td>' + transaction.date + '</td>' +
                                     '</tr>';
                            
                            tbody.append(row);
                        });
                    }
                },
                error: function() {
                    $('#recent-transactions').html('<tr><td colspan="6">Erro ao carregar transações</td></tr>');
                }
            });
        },
        
        checkSystemStatus: function() {
            // Testar conexão com Pagar.me
            $.ajax({
                url: pagarme_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'test_pagarme_connection',
                    nonce: pagarme_admin.nonce
                },
                success: function(response) {
                    var statusElement = $('#pagarme-status');
                    if (response.success) {
                        statusElement.html('<span class="status-indicator status-ok">✓</span> Conectado');
                    } else {
                        statusElement.html('<span class="status-indicator status-error">✗</span> Erro: ' + response.data);
                    }
                },
                error: function() {
                    $('#pagarme-status').html('<span class="status-indicator status-error">✗</span> Falha na conexão');
                }
            });
            
            // Atualizar última sincronização
            $('#last-sync').text(new Date().toLocaleString('pt-BR'));
        },
        
        testConnection: function() {
            var button = $('#test-connection');
            var originalText = button.text();
            
            button.prop('disabled', true).html('<span class="spinner is-active"></span> Testando...');
            
            $.ajax({
                url: pagarme_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'test_pagarme_connection',
                    nonce: pagarme_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        this.showNotice(pagarme_admin.messages.connection_success, 'success');
                    } else {
                        this.showNotice(pagarme_admin.messages.connection_error + ': ' + response.data, 'error');
                    }
                }.bind(this),
                error: function() {
                    this.showNotice(pagarme_admin.messages.connection_error, 'error');
                }.bind(this),
                complete: function() {
                    button.prop('disabled', false).html(originalText);
                }
            });
        },
        
        copyWebhook: function() {
            var webhookUrl = pagarme_admin.webhook_url;
            
            if (navigator.clipboard) {
                navigator.clipboard.writeText(webhookUrl).then(function() {
                    this.showNotice('URL do webhook copiada!', 'success');
                }.bind(this));
            } else {
                // Fallback para navegadores antigos
                var textArea = document.createElement('textarea');
                textArea.value = webhookUrl;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                this.showNotice('URL do webhook copiada!', 'success');
            }
        },
        
        testPagarMe: function() {
            var button = $('#test-pagarme');
            var originalText = button.text();
            
            button.prop('disabled', true).html('<span class="spinner is-active"></span> Testando...');
            
            $.ajax({
                url: pagarme_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'test_pagarme_connection',
                    nonce: pagarme_admin.nonce
                },
                success: function(response) {
                    var resultDiv = $('#test-results');
                    resultDiv.show();
                    
                    if (response.success) {
                        resultDiv.html('<div class="notice notice-success"><p><strong>Sucesso:</strong> ' + response.data + '</p></div>');
                    } else {
                        resultDiv.html('<div class="notice notice-error"><p><strong>Erro:</strong> ' + response.data + '</p></div>');
                    }
                },
                error: function() {
                    $('#test-results').show().html('<div class="notice notice-error"><p><strong>Erro:</strong> Falha na requisição</p></div>');
                },
                complete: function() {
                    button.prop('disabled', false).html(originalText);
                }
            });
        },
        
        regenerateApiKey: function() {
            if (!confirm('Tem certeza que deseja gerar uma nova chave API? A chave atual será invalidada.')) {
                return;
            }
            
            var button = $('#regenerate-api-key');
            var originalText = button.text();
            
            button.prop('disabled', true).html('<span class="spinner is-active"></span> Gerando...');
            
            $.ajax({
                url: pagarme_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'regenerate_api_key',
                    nonce: pagarme_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('input[name="pagarme_gateway_api_key"]').val(response.data.api_key);
                        this.showNotice(response.data.message, 'success');
                    } else {
                        this.showNotice('Erro ao gerar nova chave: ' + response.data, 'error');
                    }
                }.bind(this),
                error: function() {
                    this.showNotice('Erro ao gerar nova chave', 'error');
                }.bind(this),
                complete: function() {
                    button.prop('disabled', false).html(originalText);
                }
            });
        },
        
        addProductMapping: function() {
            var productId = $('#product-select').val();
            var negotiationType = $('#product-negotiation-type').val();
            
            if (!productId || !negotiationType) {
                this.showNotice('Selecione um produto e informe o tipo de negociação', 'error');
                return;
            }
            
            $.ajax({
                url: pagarme_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'save_product_mapping',
                    product_id: productId,
                    negotiation_type: negotiationType,
                    nonce: pagarme_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        this.showNotice(pagarme_admin.messages.mapping_saved, 'success');
                        this.refreshProductMappings();
                        $('#product-select').val(null).trigger('change');
                        $('#product-negotiation-type').val('');
                    } else {
                        this.showNotice('Erro ao salvar mapeamento: ' + response.data, 'error');
                    }
                }.bind(this),
                error: function() {
                    this.showNotice('Erro ao salvar mapeamento', 'error');
                }.bind(this)
            });
        },
        
        addCustomerMapping: function() {
            var customerId = $('#customer-select').val();
            var negotiationType = $('#customer-negotiation-type').val();
            
            if (!customerId || !negotiationType) {
                this.showNotice('Selecione um cliente e informe o tipo de negociação', 'error');
                return;
            }
            
            $.ajax({
                url: pagarme_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'save_customer_mapping',
                    customer_id: customerId,
                    negotiation_type: negotiationType,
                    nonce: pagarme_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        this.showNotice(pagarme_admin.messages.mapping_saved, 'success');
                        this.refreshCustomerMappings();
                        $('#customer-select').val(null).trigger('change');
                        $('#customer-negotiation-type').val('');
                    } else {
                        this.showNotice('Erro ao salvar mapeamento: ' + response.data, 'error');
                    }
                }.bind(this),
                error: function() {
                    this.showNotice('Erro ao salvar mapeamento', 'error');
                }.bind(this)
            });
        },
        
        removeMapping: function(e) {
            e.preventDefault();
            
            if (!confirm(pagarme_admin.messages.confirm_remove)) {
                return;
            }
            
            var button = $(e.target).closest('.remove-mapping');
            var type = button.data('type');
            var id = button.data('id');
            
            $.ajax({
                url: pagarme_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'remove_mapping',
                    type: type,
                    id: id,
                    nonce: pagarme_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        this.showNotice(pagarme_admin.messages.mapping_removed, 'success');
                        button.closest('tr').fadeOut(function() {
                            $(this).remove();
                        });
                    } else {
                        this.showNotice('Erro ao remover mapeamento: ' + response.data, 'error');
                    }
                }.bind(this),
                error: function() {
                    this.showNotice('Erro ao remover mapeamento', 'error');
                }.bind(this)
            });
        },
        
        initLogs: function() {
            this.loadLogs();
            this.loadLogStats();
        },
        
        loadLogs: function(page = 1) {
            var level = $('#log-level-filter').val();
            var date = $('#log-date-filter').val();
            
            $('#logs-tbody').html('<tr><td colspan="6" class="loading"><span class="spinner is-active"></span> Carregando logs...</td></tr>');
            
            $.ajax({
                url: pagarme_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_logs',
                    level: level,
                    date: date,
                    page: page,
                    nonce: pagarme_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var tbody = $('#logs-tbody');
                        tbody.empty();
                        
                        if (response.data.logs.length === 0) {
                            tbody.append('<tr><td colspan="6">Nenhum log encontrado</td></tr>');
                            return;
                        }
                        
                        $.each(response.data.logs, function(i, log) {
                            var levelClass = 'log-level-' + log.level.toLowerCase();
                            var row = '<tr class="' + levelClass + '">' +
                                     '<td>' + log.timestamp + '</td>' +
                                     '<td><span class="log-level ' + levelClass + '">' + log.level + '</span></td>' +
                                     '<td><code>' + log.context_id + '</code></td>' +
                                     '<td>' + log.message + '</td>' +
                                     '<td>' + (log.user_id || '-') + '</td>' +
                                     '<td>' + (log.ip_address || '-') + '</td>' +
                                     '</tr>';
                            
                            tbody.append(row);
                        });
                        
                        // Atualizar paginação
                        this.updateLogsPagination(response.data.pagination);
                    }
                }.bind(this),
                error: function() {
                    $('#logs-tbody').html('<tr><td colspan="6">Erro ao carregar logs</td></tr>');
                }
            });
        },
        
        updateLogsPagination: function(pagination) {
            $('#logs-pagination-info').text('Página ' + pagination.current + ' de ' + pagination.total);
            
            $('#logs-prev').prop('disabled', pagination.current <= 1);
            $('#logs-next').prop('disabled', pagination.current >= pagination.total);
        },
        
        refreshLogs: function() {
            this.loadLogs();
            this.loadLogStats();
        },
        
        exportLogs: function() {
            var level = $('#log-level-filter').val();
            var date = $('#log-date-filter').val();
            
            var params = new URLSearchParams({
                action: 'export_logs',
                nonce: pagarme_admin.nonce
            });
            
            if (level) params.append('level', level);
            if (date) params.append('date', date);
            
            window.open(pagarme_admin.ajax_url + '?' + params.toString());
        },
        
        showNotice: function(message, type) {
            var noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
            var notice = $('<div class="notice ' + noticeClass + ' is-dismissible">' +
                          '<p>' + message + '</p>' +
                          '<button type="button" class="notice-dismiss">' +
                          '<span class="screen-reader-text">Descartar este aviso.</span>' +
                          '</button>' +
                          '</div>');
            
            $('.pagarme-gateway-admin h1').after(notice);
            
            // Auto-dismiss após 5 segundos
            setTimeout(function() {
                notice.fadeOut();
            }, 5000);
            
            // Bind dismiss button
            notice.find('.notice-dismiss').on('click', function() {
                notice.fadeOut();
            });
        }
    };
    
    // Inicializar quando o documento estiver pronto
    $(document).ready(function() {
        PagarMeAdmin.init();
    });
    
})(jQuery);