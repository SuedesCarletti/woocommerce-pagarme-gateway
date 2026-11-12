<?php
/**
 * Sistema de Logs
 */

namespace PagarMeGateway;

if (!defined('ABSPATH')) {
    exit;
}

class Logger {
    
    private $log_level;
    private $context_id;
    private $log_file;
    
    const EMERGENCY = 0;
    const ALERT = 1;
    const CRITICAL = 2;
    const ERROR = 3;
    const WARNING = 4;
    const NOTICE = 5;
    const INFO = 6;
    const DEBUG = 7;
    
    public function __construct($log_level = self::INFO) {
        $this->log_level = $log_level;
        $this->context_id = uniqid('pmg_', true);
        
        // Definir arquivo de log
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/pagarme-gateway-logs';
        
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        $this->log_file = $log_dir . '/pagarme-gateway-' . date('Y-m-d') . '.log';
        
        // Criar .htaccess para proteger logs
        $htaccess_file = $log_dir . '/.htaccess';
        if (!file_exists($htaccess_file)) {
            file_put_contents($htaccess_file, "Order Deny,Allow\nDeny from all\n");
        }
    }
    
    public function emergency($message, $context = array()) {
        $this->log(self::EMERGENCY, $message, $context);
    }
    
    public function alert($message, $context = array()) {
        $this->log(self::ALERT, $message, $context);
    }
    
    public function critical($message, $context = array()) {
        $this->log(self::CRITICAL, $message, $context);
    }
    
    public function error($message, $context = array()) {
        $this->log(self::ERROR, $message, $context);
    }
    
    public function warning($message, $context = array()) {
        $this->log(self::WARNING, $message, $context);
    }
    
    public function notice($message, $context = array()) {
        $this->log(self::NOTICE, $message, $context);
    }
    
    public function info($message, $context = array()) {
        $this->log(self::INFO, $message, $context);
    }
    
    public function debug($message, $context = array()) {
        $this->log(self::DEBUG, $message, $context);
    }
    
    private function log($level, $message, $context = array()) {
        // Verificar se o nível está habilitado
        if ($level > $this->log_level) {
            return;
        }
        
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'level' => $this->get_level_name($level),
            'context_id' => $this->context_id,
            'message' => $message,
            'context' => $this->sanitize_context($context),
            'user_id' => get_current_user_id(),
            'ip_address' => $this->get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        );
        
        // Sanitizar dados sensíveis
        $sanitized_entry = $this->remove_sensitive_data($log_entry);
        
        // Log para arquivo
        $this->write_to_file($sanitized_entry);
        
        // Log crítico para banco de dados
        if ($level <= self::ERROR) {
            $this->store_critical_log($sanitized_entry);
        }
        
        // Log para WooCommerce logger se disponível
        if (function_exists('wc_get_logger')) {
            $wc_logger = wc_get_logger();
            $wc_logger->log($this->get_wc_level($level), $message, array(
                'source' => 'pagarme-gateway',
                'context' => $this->sanitize_context($context)
            ));
        }
    }
    
    private function write_to_file($log_entry) {
        $formatted_entry = $this->format_log_entry($log_entry);
        
        // Escrever no arquivo com lock
        $handle = fopen($this->log_file, 'a');
        if ($handle) {
            if (flock($handle, LOCK_EX)) {
                fwrite($handle, $formatted_entry . PHP_EOL);
                fflush($handle);
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
    }
    
    private function format_log_entry($entry) {
        $formatted = sprintf(
            '[%s] %s.%s: %s',
            $entry['timestamp'],
            $entry['level'],
            $entry['context_id'],
            $entry['message']
        );
        
        if (!empty($entry['context'])) {
            $formatted .= ' ' . json_encode($entry['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        
        // Adicionar informações extras para logs de erro
        if (in_array($entry['level'], ['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR'])) {
            $extras = array(
                'user_id' => $entry['user_id'],
                'ip' => $entry['ip_address'],
                'uri' => $entry['request_uri'],
                'memory' => $this->format_bytes($entry['memory_usage'])
            );
            $formatted .= ' ' . json_encode($extras, JSON_UNESCAPED_UNICODE);
        }
        
        return $formatted;
    }
    
    private function store_critical_log($log_entry) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pagarme_gateway_logs';
        
        // Criar tabela se não existir
        $this->maybe_create_logs_table();
        
        $wpdb->insert(
            $table_name,
            array(
                'timestamp' => $log_entry['timestamp'],
                'level' => $log_entry['level'],
                'context_id' => $log_entry['context_id'],
                'message' => $log_entry['message'],
                'context' => json_encode($log_entry['context']),
                'user_id' => $log_entry['user_id'],
                'ip_address' => $log_entry['ip_address'],
                'user_agent' => substr($log_entry['user_agent'], 0, 255),
                'request_uri' => substr($log_entry['request_uri'], 0, 255)
            ),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
        );
    }
    
    private function maybe_create_logs_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pagarme_gateway_logs';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                timestamp datetime NOT NULL,
                level varchar(20) NOT NULL,
                context_id varchar(50) NOT NULL,
                message text NOT NULL,
                context longtext,
                user_id bigint(20) DEFAULT 0,
                ip_address varchar(45),
                user_agent varchar(255),
                request_uri varchar(255),
                created_at timestamp DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY level (level),
                KEY timestamp (timestamp),
                KEY context_id (context_id)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }
    
    private function sanitize_context($context) {
        if (!is_array($context)) {
            return $context;
        }
        
        $sensitive_keys = array(
            'password', 'token', 'api_key', 'secret_key', 'card_number', 
            'cvv', 'cvc', 'security_code', 'authorization', 'bearer',
            'credit_card', 'card_data', 'payment_method'
        );
        
        return $this->mask_sensitive_data($context, $sensitive_keys);
    }
    
    private function mask_sensitive_data($data, $sensitive_keys) {
        if (!is_array($data)) {
            return $data;
        }
        
        foreach ($data as $key => $value) {
            $key_lower = strtolower($key);
            
            // Verificar se a chave é sensível
            foreach ($sensitive_keys as $sensitive_key) {
                if (strpos($key_lower, $sensitive_key) !== false) {
                    $data[$key] = $this->mask_value($value);
                    continue 2;
                }
            }
            
            // Recursão para arrays aninhados
            if (is_array($value)) {
                $data[$key] = $this->mask_sensitive_data($value, $sensitive_keys);
            }
        }
        
        return $data;
    }
    
    private function mask_value($value) {
        if (is_string($value)) {
            $length = strlen($value);
            if ($length <= 4) {
                return '***';
            } elseif ($length <= 8) {
                return substr($value, 0, 2) . '***';
            } else {
                return substr($value, 0, 4) . '***' . substr($value, -4);
            }
        }
        
        return '***MASKED***';
    }
    
    private function remove_sensitive_data($data) {
        // Remover informações específicas para compliance
        if (isset($data['context']['card_number'])) {
            $card = $data['context']['card_number'];
            $data['context']['card_number'] = '****-****-****-' . substr($card, -4);
        }
        
        if (isset($data['context']['cvv'])) {
            $data['context']['cvv'] = '***';
        }
        
        if (isset($data['context']['password'])) {
            unset($data['context']['password']);
        }
        
        return $data;
    }
    
    private function get_client_ip() {
        $ip_keys = array(
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        );
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    private function get_level_name($level) {
        $levels = array(
            self::EMERGENCY => 'EMERGENCY',
            self::ALERT => 'ALERT',
            self::CRITICAL => 'CRITICAL',
            self::ERROR => 'ERROR',
            self::WARNING => 'WARNING',
            self::NOTICE => 'NOTICE',
            self::INFO => 'INFO',
            self::DEBUG => 'DEBUG'
        );
        
        return $levels[$level] ?? 'UNKNOWN';
    }
    
    private function get_wc_level($level) {
        $wc_levels = array(
            self::EMERGENCY => 'emergency',
            self::ALERT => 'alert',
            self::CRITICAL => 'critical',
            self::ERROR => 'error',
            self::WARNING => 'warning',
            self::NOTICE => 'notice',
            self::INFO => 'info',
            self::DEBUG => 'debug'
        );
        
        return $wc_levels[$level] ?? 'info';
    }
    
    private function format_bytes($bytes) {
        $units = array('B', 'KB', 'MB', 'GB');
        
        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
    
    /**
     * Limpar logs antigos
     */
    public function cleanup_old_logs($days = 30) {
        // Limpar arquivos de log
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/pagarme-gateway-logs';
        
        if (is_dir($log_dir)) {
            $files = glob($log_dir . '/pagarme-gateway-*.log');
            $cutoff_time = time() - ($days * 24 * 60 * 60);
            
            foreach ($files as $file) {
                if (filemtime($file) < $cutoff_time) {
                    unlink($file);
                }
            }
        }
        
        // Limpar tabela de logs críticos
        global $wpdb;
        $table_name = $wpdb->prefix . 'pagarme_gateway_logs';
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
    }
    
    /**
     * Obter logs recentes
     */
    public function get_recent_logs($limit = 100, $level = null) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'pagarme_gateway_logs';
        
        $where_clause = '';
        $params = array();
        
        if ($level) {
            $where_clause = 'WHERE level = %s';
            $params[] = $level;
        }
        
        $params[] = $limit;
        
        $sql = "SELECT * FROM $table_name $where_clause ORDER BY timestamp DESC LIMIT %d";
        
        return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }
    
    /**
     * Obter estatísticas de logs
     */
    public function get_log_stats($days = 7) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'pagarme_gateway_logs';
        
        $stats = $wpdb->get_results($wpdb->prepare("
            SELECT 
                level,
                COUNT(*) as count,
                DATE(timestamp) as date
            FROM $table_name 
            WHERE timestamp >= DATE_SUB(NOW(), INTERVAL %d DAY)
            GROUP BY level, DATE(timestamp)
            ORDER BY date DESC, level
        ", $days), ARRAY_A);
        
        return $stats;
    }
}