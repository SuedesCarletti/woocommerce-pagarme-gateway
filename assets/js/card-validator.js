/**
 * VALIDADOR DE CARTÕES DE CRÉDITO
 * Versão: 1.0.0
 * Valida número, CVV, data e detecta bandeira
 */

(function($) {
    'use strict';
    
    console.log('Card Validator carregado');
    
    window.PagarmeCardValidator = {
        
        /**
         * Detectar bandeira do cartão
         */
        detectBrand: function(number) {
            // Remover espaços e traços
            number = number.replace(/[\s-]/g, '');
            
            // Padrões de bandeiras
            const patterns = {
                'visa': /^4/,
                'mastercard': /^(5[1-5]|2[2-7])/,
                'amex': /^3[47]/,
                'elo': /^(4011|4312|4389|4514|4576|5041|5066|5090|6277|6362|6363|6500|6516)/,
                'hipercard': /^(38|60)/,
                'diners': /^(30[0-5]|36|38)/,
                'discover': /^6(?:011|5)/,
                'jcb': /^35/
            };
            
            for (let brand in patterns) {
                if (patterns[brand].test(number)) {
                    return brand;
                }
            }
            
            return 'unknown';
        },
        
        /**
         * Validar número do cartão (Algoritmo de Luhn)
         */
        validateNumber: function(number) {
            // Remover caracteres não numéricos
            number = number.replace(/\D/g, '');
            
            // Mínimo de dígitos
            if (number.length < 13 || number.length > 19) {
                return false;
            }
            
            // Algoritmo de Luhn
            let sum = 0;
            let isEven = false;
            
            for (let i = number.length - 1; i >= 0; i--) {
                let digit = parseInt(number.charAt(i), 10);
                
                if (isEven) {
                    digit *= 2;
                    if (digit > 9) {
                        digit -= 9;
                    }
                }
                
                sum += digit;
                isEven = !isEven;
            }
            
            return (sum % 10) === 0;
        },
        
        /**
         * Validar CVV
         */
        validateCVV: function(cvv, brand) {
            cvv = cvv.replace(/\D/g, '');
            
            // American Express tem 4 dígitos, outros 3
            if (brand === 'amex') {
                return cvv.length === 4;
            }
            
            return cvv.length === 3;
        },
        
        /**
         * Validar data de expiração
         */
        validateExpiry: function(expiry) {
            // Remover espaços
            expiry = expiry.replace(/\s/g, '');
            
            // Formato esperado: MM/AA ou MM/AAAA
            const parts = expiry.split('/');
            
            if (parts.length !== 2) {
                return false;
            }
            
            let month = parseInt(parts[0], 10);
            let year = parseInt(parts[1], 10);
            
            // Validar mês
            if (month < 1 || month > 12) {
                return false;
            }
            
            // Ajustar ano (assumir 20XX se for 2 dígitos)
            if (year < 100) {
                year += 2000;
            }
            
            // Criar data de expiração (último dia do mês)
            const expDate = new Date(year, month, 0);
            const today = new Date();
            
            // Verificar se não expirou
            return expDate >= today;
        },
        
        /**
         * Formatar número do cartão
         */
        formatNumber: function(number) {
            // Remover caracteres não numéricos
            number = number.replace(/\D/g, '');
            
            // Detectar bandeira
            const brand = this.detectBrand(number);
            
            // Amex: 4-6-5
            if (brand === 'amex') {
                return number.replace(/(\d{4})(\d{6})(\d{5})/, '$1 $2 $3').trim();
            }
            
            // Padrão: 4-4-4-4
            return number.replace(/(\d{4})(?=\d)/g, '$1 ').trim();
        },
        
        /**
         * Formatar data de expiração
         */
        formatExpiry: function(value) {
            // Remover não numéricos
            value = value.replace(/\D/g, '');
            
            // Adicionar barra após 2 dígitos
            if (value.length >= 2) {
                return value.substring(0, 2) + '/' + value.substring(2, 4);
            }
            
            return value;
        },
        
        /**
         * Formatar CVV
         */
        formatCVV: function(value) {
            // Apenas números
            return value.replace(/\D/g, '').substring(0, 4);
        },
        
        /**
         * Obter informações da bandeira
         */
        getBrandInfo: function(brand) {
            const brands = {
                'visa': {
                    name: 'Visa',
                    pattern: /^4/,
                    length: [13, 16, 19],
                    cvvLength: 3,
                    color: '#1A1F71'
                },
                'mastercard': {
                    name: 'Mastercard',
                    pattern: /^(5[1-5]|2[2-7])/,
                    length: [16],
                    cvvLength: 3,
                    color: '#EB001B'
                },
                'amex': {
                    name: 'American Express',
                    pattern: /^3[47]/,
                    length: [15],
                    cvvLength: 4,
                    color: '#006FCF'
                },
                'elo': {
                    name: 'Elo',
                    pattern: /^(4011|4312|4389|4514|4576|5041|5066|5090|6277|6362|6363|6500|6516)/,
                    length: [16],
                    cvvLength: 3,
                    color: '#FFCB05'
                },
                'hipercard': {
                    name: 'Hipercard',
                    pattern: /^(38|60)/,
                    length: [16],
                    cvvLength: 3,
                    color: '#D8232A'
                }
            };
            
            return brands[brand] || {
                name: 'Desconhecido',
                length: [16],
                cvvLength: 3,
                color: '#666'
            };
        },
        
        /**
         * Aplicar máscara no input
         */
        applyMask: function($input, type) {
            var self = this;
            
            $input.on('input', function() {
                let value = $(this).val();
                
                switch(type) {
                    case 'number':
                        $(this).val(self.formatNumber(value));
                        break;
                    case 'expiry':
                        $(this).val(self.formatExpiry(value));
                        break;
                    case 'cvv':
                        $(this).val(self.formatCVV(value));
                        break;
                }
            });
        }
    };
    
    // Expor globalmente
    window.PagarmeCardValidator = window.PagarmeCardValidator;
    
})(jQuery);
