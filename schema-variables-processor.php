<?php
/**
 * Plugin Name: Schema Variables Processor
 * Description: Processa variáveis %variavel% em schemas e meta tags (versão simplificada sem meta-box)
 * Version: 1.0
 * Author: Adaptado do Hapvida Schema Addon v4.0
 */

if (!defined('ABSPATH')) exit;

class Schema_Variables_Processor {

    public function __construct() {
        // Processa variáveis no wp_head (alta prioridade para executar cedo)
        add_action('wp_head', array($this, 'processar_variaveis_head'), 999);
    }

    /**
     * Processa variáveis %variavel% no HTML do head
     * Usa output buffering de forma segura
     */
    public function processar_variaveis_head() {
        // NÃO processa no admin
        if (is_admin()) {
            return;
        }

        // NÃO processa no Elementor (verificações rigorosas)
        if (isset($_GET['elementor-preview']) ||
            isset($_GET['elementor_library']) ||
            isset($_GET['elementor-edit']) ||
            (isset($_GET['action']) && in_array($_GET['action'], array('elementor', 'elementor_ajax')))) {
            return;
        }

        // NÃO processa em AJAX
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }

        // Processa apenas em posts/páginas individuais
        if (!is_singular()) {
            return;
        }

        // Inicia output buffering
        ob_start(array($this, 'processar_buffer'));
    }

    /**
     * Processa o buffer HTML substituindo variáveis
     */
    public function processar_buffer($buffer) {
        // Se não tem variável %, retorna direto (performance)
        if (strpos($buffer, '%') === false) {
            return $buffer;
        }

        // Busca todas as variáveis no formato %variavel%
        preg_match_all('/%([^%]+)%/', $buffer, $matches);

        if (!empty($matches[1])) {
            // Remove duplicatas
            $variaveis = array_unique($matches[1]);

            foreach ($variaveis as $variavel) {
                // Obtém o valor da variável
                $valor = $this->obter_valor_variavel($variavel);

                if ($valor !== false) {
                    // Substitui todas as ocorrências da variável
                    $buffer = str_replace("%{$variavel}%", $valor, $buffer);
                }
            }
        }

        return $buffer;
    }

    /**
     * Obtém o valor de uma variável executando o shortcode
     */
    private function obter_valor_variavel($variavel) {
        // Executa o shortcode correspondente
        // Exemplo: %fortaleza_emp_ambulatorialtotal_1% → [fortaleza_emp_ambulatorialtotal_1]
        $shortcode_result = do_shortcode("[{$variavel}]");

        // Se o shortcode retornou algo diferente do próprio código, é válido
        if ($shortcode_result !== "[{$variavel}]") {
            // Remove "R$ " e formata apenas o número para schemas
            $valor_limpo = str_replace(array('R$', ' '), '', $shortcode_result);
            $valor_limpo = str_replace('.', '', $valor_limpo);
            $valor_limpo = str_replace(',', '.', $valor_limpo);

            return $valor_limpo;
        }

        return false;
    }
}

// Inicializa o plugin
new Schema_Variables_Processor();
