<?php
/*
Plugin Name: Gerenciador de Preços de Planos de Saúde
Description: Plugin para gerenciar tabelas de preços de planos de saúde por cidade e por operadora (Hapvida completa; Amil, Unimed e SulAmérica em modo tabela única) com shortcodes individuais, comparação entre operadoras e sistema de descontos
Version: 7.1
Author: Seu Nome
*/

// Impede acesso direto ao arquivo
if (!defined('ABSPATH')) {
    exit;
}

class Gerenciador_Precos_Planos {
    
    private $option_name = 'gpp_cidades_planos';
    private $settings_option = 'gpp_settings';
    private $regional_option = 'gpp_valores_regionais';

    // Caches por request (evitam reconstruir a lista de cidades a cada shortcode)
    private $cache_cidades_global = null;
    private $indice_shortcode = null;

    // Registro sob demanda: filtro de slugs ativo e slugs já registrados no request
    private $filtro_slugs = null;          // null = registra todos; array = só estes
    private $slugs_registrados = array();  // slug_base => true

    /**
     * ===== OPERADORAS =====
     * Cada operadora tem seu próprio "banco" de cidades (option separada) e seu
     * próprio prefixo de shortcode. A Hapvida mantém o comportamento original
     * (option "gpp_cidades_planos" e shortcodes SEM prefixo) para não quebrar
     * páginas já publicadas. As demais operadoras usam prefixo no shortcode.
     */
    private $operadoras = array(
        'hapvida' => array(
            'nome'         => 'Hapvida',
            'option'       => 'gpp_cidades_planos',
            'prefixo'      => '',
            'cor'          => '#0054B8',
            'cor_destaque' => '#F05A22',
            'simples'      => false,
            'url_botao'    => 'https://tabelaplanos.com.br/plano-hapvida-valores',
        ),
        'amil' => array(
            'nome'         => 'Amil',
            'option'       => 'gpp_cidades_planos_amil',
            'prefixo'      => 'amil_',
            'cor'          => '#002D72',
            'cor_destaque' => '#009FE3',
            'simples'      => true,
            'url_botao'    => 'https://tabelaplanos.com.br/plano-amil-valores',
        ),
        'unimed' => array(
            'nome'         => 'Unimed',
            'option'       => 'gpp_cidades_planos_unimed',
            'prefixo'      => 'unimed_',
            'cor'          => '#00995D',
            'cor_destaque' => '#FF6F00',
            'simples'      => true,
            'url_botao'    => 'https://tabelaplanos.com.br/plano-unimed-valores',
        ),
        'sulamerica' => array(
            'nome'         => 'SulAmérica',
            'option'       => 'gpp_cidades_planos_sulamerica',
            'prefixo'      => 'sulamerica_',
            'cor'          => '#ED8B00',
            'cor_destaque' => '#00857C',
            'simples'      => true,
            'url_botao'    => 'https://tabelaplanos.com.br/plano-sulamerica-valores',
        ),
    );

    /**
     * Indica se a operadora usa o modo "simples": uma única tabela
     * (Faixa Etária → Valor) por cidade, sem tipo/acomodação/coparticipação.
     */
    private function operadora_e_simples($operadora) {
        $operadora = $this->sanitizar_operadora($operadora);
        return !empty($this->operadoras[$operadora]['simples']);
    }

    /**
     * Retorna a lista de operadoras configuradas
     */
    public function obter_operadoras() {
        return $this->operadoras;
    }

    /**
     * Verifica se a chave de operadora é válida; senão retorna 'hapvida'
     */
    private function sanitizar_operadora($operadora) {
        return (is_string($operadora) && isset($this->operadoras[$operadora])) ? $operadora : 'hapvida';
    }

    /**
     * Retorna os dados de configuração de uma operadora
     */
    public function obter_config_operadora($operadora) {
        $operadora = $this->sanitizar_operadora($operadora);
        return $this->operadoras[$operadora];
    }

    public function __construct() {
        // Adiciona menu no admin
        add_action('admin_menu', array($this, 'adicionar_menu_admin'));

        // Registra shortcodes COM PROTEÇÃO para não registrar em requisições Elementor
        add_action('init', array($this, 'registrar_shortcodes_com_protecao'), 999);

        // ===== Registra variáveis no RankMath (%variavel%) =====
        // DESLIGADO POR PADRÃO: registrava milhares de variáveis em CADA página
        // (o RankMath dispara este gancho a cada request), consumindo muita
        // memória mesmo quando o site usa apenas shortcodes [..]. Reative com:
        //   add_filter('gpp_habilitar_variaveis_rankmath', '__return_true');
        if (apply_filters('gpp_habilitar_variaveis_rankmath', false)) {
            add_action('rank_math/vars/register', array($this, 'registrar_variaveis_rankmath'));
        }

        // Enfileira estilos no frontend
        add_action('wp_enqueue_scripts', array($this, 'enfileirar_estilos_frontend'));

        // AJAX para salvar dados
        add_action('wp_ajax_gpp_salvar_cidade', array($this, 'ajax_salvar_cidade'));
        add_action('wp_ajax_gpp_excluir_cidade', array($this, 'ajax_excluir_cidade'));
        add_action('wp_ajax_gpp_buscar_cidade', array($this, 'ajax_buscar_cidade'));
        add_action('wp_ajax_gpp_aplicar_desconto_global', array($this, 'ajax_aplicar_desconto_global'));
        add_action('wp_ajax_gpp_remover_todos_descontos', array($this, 'ajax_remover_todos_descontos'));

        // Adiciona submenu de variáveis
        add_action('admin_menu', array($this, 'adicionar_submenu_variaveis'), 11);

        // Adiciona submenu de valores regionais
        add_action('admin_menu', array($this, 'adicionar_submenu_regionais'), 12);

        // AJAX para salvar valores regionais
        add_action('wp_ajax_gpp_salvar_valores_regionais', array($this, 'ajax_salvar_valores_regionais'));

        // ===== FILTROS PARA PROCESSAR SHORTCODES EM TÍTULOS E META TAGS =====
        // IMPORTANTE: Só adiciona filtros se NÃO estiver em contexto problemático
        add_action('init', array($this, 'registrar_filtros_shortcode'), 5);

        // ===== SCHEMA/STRUCTURED DATA =====
        // Processa shortcodes dentro dos schemas do RankMath
        add_filter('rank_math/json_ld', array($this, 'processar_shortcodes_schema_rankmath'), 99, 2);

        // Textarea extra para schema customizado (opcional)
        add_action('add_meta_boxes', array($this, 'adicionar_metabox_schema'));
        add_action('save_post', array($this, 'salvar_metabox_schema'));
        add_action('wp_footer', array($this, 'renderizar_schema_frontend'), 99);
    }

    /**
     * Registra filtros de shortcode apenas em contextos seguros
     */
    public function registrar_filtros_shortcode() {
        // NÃO adiciona filtros em contextos problemáticos
        if ($this->should_skip_shortcode_registration()) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('GPP: Pulando registro de FILTROS (contexto não seguro)');
            }
            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('GPP: Registrando filtros de shortcode');
        }

        // Processa shortcodes em títulos de posts/páginas (com proteção para Elementor)
        add_filter('the_title', array($this, 'processar_shortcode_title_seguro'), 11, 2);
        add_filter('single_post_title', array($this, 'processar_shortcode_title_unico'), 11);
        add_filter('wp_title', array($this, 'processar_shortcode_wp_title'), 11);
        add_filter('document_title_parts', array($this, 'processar_shortcode_title_parts'), 11);

        // Processa shortcodes em widgets de título
        add_filter('widget_title', 'do_shortcode', 11);

        // === YOAST SEO ===
        add_filter('wpseo_title', 'do_shortcode', 11);
        add_filter('wpseo_metadesc', 'do_shortcode', 11);
        add_filter('wpseo_opengraph_title', 'do_shortcode', 11);
        add_filter('wpseo_opengraph_desc', 'do_shortcode', 11);
        add_filter('wpseo_twitter_title', 'do_shortcode', 11);
        add_filter('wpseo_twitter_description', 'do_shortcode', 11);

        // === RANK MATH SEO ===
        add_filter('rank_math/frontend/title', 'do_shortcode', 11);
        add_filter('rank_math/frontend/description', 'do_shortcode', 11);
        add_filter('rank_math/opengraph/facebook/title', 'do_shortcode', 11);
        add_filter('rank_math/opengraph/facebook/description', 'do_shortcode', 11);
        add_filter('rank_math/opengraph/twitter/title', 'do_shortcode', 11);
        add_filter('rank_math/opengraph/twitter/description', 'do_shortcode', 11);

        // === ALL IN ONE SEO ===
        add_filter('aioseop_title', 'do_shortcode', 11);
        add_filter('aioseop_description', 'do_shortcode', 11);
        add_filter('aioseop_title_page', 'do_shortcode', 11);

        // === SEOPress ===
        add_filter('seopress_titles_title', 'do_shortcode', 11);
        add_filter('seopress_titles_desc', 'do_shortcode', 11);
        add_filter('seopress_social_og_title', 'do_shortcode', 11);
        add_filter('seopress_social_og_desc', 'do_shortcode', 11);
        add_filter('seopress_social_twitter_title', 'do_shortcode', 11);
        add_filter('seopress_social_twitter_desc', 'do_shortcode', 11);

        // === The SEO Framework ===
        add_filter('the_seo_framework_title_from_custom_field', 'do_shortcode', 11);
        add_filter('the_seo_framework_description_from_custom_field', 'do_shortcode', 11);
        add_filter('the_seo_framework_generated_description', 'do_shortcode', 11);

        // Processa shortcodes em custom fields (ACF e outros)
        add_filter('acf/load_value', array($this, 'processar_shortcode_acf'), 11, 3);
        add_filter('get_post_metadata', array($this, 'processar_shortcode_meta'), 11, 4);
    }

    /**
     * Registra shortcodes apenas quando necessário (não em requisições Elementor)
     */
    public function registrar_shortcodes_com_protecao() {
        // NÃO registra shortcodes em contextos problemáticos
        if ($this->should_skip_shortcode_registration()) {
            return;
        }

        // DEBUG: Aumenta limite de memória se necessário
        $current_limit = ini_get('memory_limit');
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('GPP: Limite de memória atual: ' . $current_limit);
        }

        // Shortcodes GLOBAIS (poucos, baratos) — sempre registrados.
        $this->registrar_shortcodes_regionais();
        $this->registrar_shortcodes_data();
        $this->registrar_shortcode_comparativo();

        // ===== REGISTRO SOB DEMANDA (anti "limite de shortcodes") =====
        // O WordPress monta UMA regex gigante com TODOS os shortcodes registrados.
        // Registrar os de todas as cidades em toda página fazia essa regex crescer
        // até falhar (seções sumindo). Agora registramos por cidade apenas quando
        // a cidade aparece no conteúdo da página atual.
        //
        // Válvula de escape: para voltar ao registro de TUDO de uma vez, use:
        //   add_filter('gpp_registrar_todos_shortcodes', '__return_true');
        if (apply_filters('gpp_registrar_todos_shortcodes', false)) {
            $this->registrar_shortcodes();
            $this->registrar_shortcodes_simples();
            $this->registrar_shortcodes_variaveis();
            $this->registrar_shortcodes_comparar();
        } else {
            // Pré-registra as cidades citadas no post atual (cobre Elementor, que
            // guarda o conteúdo em _elementor_data, e o editor clássico).
            add_action('template_redirect', array($this, 'registrar_shortcodes_da_pagina_atual'));
            // Rede de segurança: escaneia textos imediatamente antes do do_shortcode.
            add_filter('the_content', array($this, 'escanear_e_registrar_passthrough'), 1);
            add_filter('the_excerpt', array($this, 'escanear_e_registrar_passthrough'), 1);
            add_filter('the_title', array($this, 'escanear_e_registrar_passthrough'), 1);
            add_filter('widget_text', array($this, 'escanear_e_registrar_passthrough'), 1);
            add_filter('widget_block_content', array($this, 'escanear_e_registrar_passthrough'), 1);
            add_filter('widget_title', array($this, 'escanear_e_registrar_passthrough'), 1);
            add_filter('elementor/frontend/the_content', array($this, 'escanear_e_registrar_passthrough'), 1);
            add_filter('render_block', array($this, 'escanear_e_registrar_passthrough'), 1);
        }

        // DEBUG: Log após registro
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $cidades = $this->obter_todas_cidades_global();
            error_log('GPP: Shortcodes registrados para ' . count($cidades) . ' cidades (todas as operadoras)');
            error_log('GPP: Variáveis limitadas a faixas 0, 1 e 9 (primeira, segunda e última)');
            error_log('GPP: Shortcodes regionais: 12 (2 regiões × 6 campos)');
            error_log('GPP: Memória usada: ' . size_format(memory_get_usage(true)));
        }
    }

    /**
     * Verifica se deve pular o registro de shortcodes
     */
    private function should_skip_shortcode_registration() {
        // DEBUG: Log completo do contexto
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'unknown';
            $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'none';

            error_log('GPP DEBUG: Verificando contexto');
            error_log('  - URL: ' . $url);
            error_log('  - is_admin(): ' . (is_admin() ? 'TRUE' : 'false'));
            error_log('  - DOING_AJAX: ' . (defined('DOING_AJAX') && DOING_AJAX ? 'TRUE' : 'false'));
            error_log('  - Referer: ' . $referer);
        }

        // PROTEÇÃO AGRESSIVA: Pula no admin completamente (inclusive no próprio plugin)
        // Shortcodes não são necessários no admin
        if (is_admin()) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('GPP: ✓ PULANDO - is_admin() = true');
            }
            return true;
        }

        // Pula em QUALQUER requisição AJAX
        if (defined('DOING_AJAX') && DOING_AJAX) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('GPP: ✓ PULANDO - DOING_AJAX = true');
            }
            return true;
        }

        // Pula no editor do Elementor
        if (isset($_GET['elementor-preview']) || isset($_GET['elementor_library']) || isset($_GET['elementor-preview-mode'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('GPP: ✓ PULANDO - Elementor GET param');
            }
            return true;
        }

        // Pula se detectar Elementor no User-Agent ou Referer
        if (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'elementor') !== false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('GPP: ✓ PULANDO - Elementor no referer');
            }
            return true;
        }

        // Pula em requisições REST API
        if (defined('REST_REQUEST') && REST_REQUEST) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('GPP: ✓ PULANDO - REST_REQUEST');
            }
            return true;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('GPP: ✗ NÃO PULOU - Vai registrar filtros/shortcodes');
        }

        return false;
    }

    /**
     * Verifica se estamos no contexto do Elementor que não deve processar shortcodes
     */
    private function is_elementor_context() {
        // Não processa no editor do Elementor
        if (isset($_GET['elementor-preview']) || isset($_GET['elementor_library'])) {
            return true;
        }

        // Não processa em requisições AJAX do Elementor
        if (defined('DOING_AJAX') && DOING_AJAX) {
            if (isset($_REQUEST['action']) && strpos($_REQUEST['action'], 'elementor') !== false) {
                return true;
            }
        }

        // Não processa no admin (exceto frontend)
        if (is_admin() && !wp_doing_ajax()) {
            return true;
        }

        return false;
    }

    /**
     * Processa shortcodes em títulos de forma segura (com proteção Elementor)
     */
    public function processar_shortcode_title_seguro($title, $id = null) {
        // Não processa no contexto do Elementor
        if ($this->is_elementor_context()) {
            return $title;
        }

        // Não processa títulos vazios
        if (empty($title) || !is_string($title)) {
            return $title;
        }

        // Só processa se realmente houver shortcodes no título
        if (strpos($title, '[') === false) {
            return $title;
        }

        return do_shortcode($title);
    }

    /**
     * Processa shortcodes em título único
     */
    public function processar_shortcode_title_unico($title) {
        if ($this->is_elementor_context()) {
            return $title;
        }

        if (empty($title) || !is_string($title) || strpos($title, '[') === false) {
            return $title;
        }

        return do_shortcode($title);
    }

    /**
     * Processa shortcodes em wp_title
     */
    public function processar_shortcode_wp_title($title) {
        if ($this->is_elementor_context()) {
            return $title;
        }

        if (empty($title) || !is_string($title) || strpos($title, '[') === false) {
            return $title;
        }

        return do_shortcode($title);
    }
    
    /**
     * Processa shortcodes nas partes do título do documento
     */
    public function processar_shortcode_title_parts($title_parts) {
        if ($this->is_elementor_context()) {
            return $title_parts;
        }

        if (isset($title_parts['title']) && is_string($title_parts['title'])) {
            $title_parts['title'] = do_shortcode($title_parts['title']);
        }
        if (isset($title_parts['tagline']) && is_string($title_parts['tagline'])) {
            $title_parts['tagline'] = do_shortcode($title_parts['tagline']);
        }
        if (isset($title_parts['site']) && is_string($title_parts['site'])) {
            $title_parts['site'] = do_shortcode($title_parts['site']);
        }
        return $title_parts;
    }

    /**
     * Processa shortcodes em campos ACF
     */
    public function processar_shortcode_acf($value, $post_id, $field) {
        // Não processa no contexto do Elementor
        if ($this->is_elementor_context()) {
            return $value;
        }

        // Só processa strings que contenham shortcodes
        if (is_string($value) && strpos($value, '[') !== false) {
            return do_shortcode($value);
        }
        return $value;
    }

    /**
     * Processa shortcodes em custom fields/meta fields
     */
    public function processar_shortcode_meta($value, $object_id, $meta_key, $single) {
        // Evita recursão infinita
        static $processing = false;

        if ($processing) {
            return $value;
        }

        // Não processa no contexto do Elementor
        if ($this->is_elementor_context()) {
            return $value;
        }

        // IMPORTANTE: Ignora metadados do Elementor para evitar conflitos
        if (strpos($meta_key, '_elementor') === 0 || strpos($meta_key, 'elementor') !== false) {
            return $value;
        }

        // Lista de meta keys comuns de SEO que devem ter shortcodes processados
        $seo_meta_keys = array(
            '_yoast_wpseo_title',
            '_yoast_wpseo_metadesc',
            'rank_math_title',
            'rank_math_description',
            '_aioseop_title',
            '_aioseop_description',
            '_seopress_titles_title',
            '_seopress_titles_desc',
        );

        if (in_array($meta_key, $seo_meta_keys)) {
            $processing = true;

            // Obtém o valor real do meta
            remove_filter('get_post_metadata', array($this, 'processar_shortcode_meta'), 11);
            $real_value = get_metadata('post', $object_id, $meta_key, $single);
            add_filter('get_post_metadata', array($this, 'processar_shortcode_meta'), 11, 4);

            // Só processa se for string e tiver shortcodes
            if (is_string($real_value) && !empty($real_value) && strpos($real_value, '[') !== false) {
                // Registro sob demanda: registra as cidades citadas no meta antes.
                $this->escanear_e_registrar($real_value);
                $real_value = do_shortcode($real_value);
            }

            $processing = false;
            return $real_value;
        }

        return $value;
    }
    
    /**
     * Adiciona submenu para listar variáveis disponíveis
     */
    public function adicionar_submenu_variaveis() {
        add_submenu_page(
            'gerenciador-precos-planos',
            'Variáveis Dinâmicas',
            'Variáveis Dinâmicas',
            'manage_options',
            'gpp-variaveis',
            array($this, 'pagina_variaveis')
        );
    }

    /**
     * Adiciona submenu para valores regionais (SP/BH e Demais Capitais)
     */
    public function adicionar_submenu_regionais() {
        add_submenu_page(
            'gerenciador-precos-planos',
            'Valores Regionais',
            'Valores Regionais',
            'manage_options',
            'gpp-regionais',
            array($this, 'pagina_valores_regionais')
        );
    }

    /**
     * Obtém desconto de um tipo específico com lógica de prioridade
     */
    private function obter_desconto_tipo($cidade, $tipo) {
        // Se tem descontos diferenciados ativos, usa o específico
        if (isset($cidade['tem_desconto_diferenciado']) && $cidade['tem_desconto_diferenciado']) {
            if (isset($cidade['descontos_diferenciados'][$tipo]) && $cidade['descontos_diferenciados'][$tipo] > 0) {
                return floatval($cidade['descontos_diferenciados'][$tipo]);
            }
            return 0;
        }
        
        // Caso contrário, usa o desconto global
        $desconto_personalizado = isset($cidade['desconto_personalizado']) ? floatval($cidade['desconto_personalizado']) : 0;
        $tem_desconto_15 = isset($cidade['desconto_15']) && $cidade['desconto_15'] === true;
        
        if ($desconto_personalizado > 0) {
            return $desconto_personalizado;
        } else if ($tem_desconto_15) {
            return 15;
        }
        
        return 0;
    }
    
    /**
     * Obtém valor da primeira faixa de um tipo/acomodação específica
     */
    private function obter_valor_primeira_faixa($cidade, $tipo, $acomodacao, $coparticipacao) {
        $campo = $tipo . '_' . $acomodacao . '_' . $coparticipacao;
        
        if (isset($cidade[$campo]) && !empty($cidade[$campo]) && isset($cidade[$campo][0]['valor'])) {
            return $this->obter_valor_formatado_simples($cidade, $cidade[$campo][0]['valor'], $tipo);
        }
        
        return '-';
    }
    
    /**
     * Encontra o shortcode do menor valor de uma cidade
     */
    private function encontrar_menor_valor_cidade($cidade) {
        $menor_valor = null;
        $menor_shortcode = null;
        $menor_valor_display = null;
        $menor_tipo_plano = null;
        $menor_acomodacao = null;
        $menor_coparticipacao = null;
        
        $tipos_plano = array(
            'empresarial' => 'emp',
            'individual' => 'ind',
            'pme' => 'pme',
            'adesao' => 'ade'
        );
        
        $acomodacoes = array('ambulatorial', 'enfermaria', 'apartamento');
        $coparticipacoes = array('total', 'parcial');
        
        foreach ($tipos_plano as $tipo_key => $tipo_sigla) {
            // Verifica se o tipo está ativo
            if (!isset($cidade['tipos_planos_ativos'][$tipo_key]) || !$cidade['tipos_planos_ativos'][$tipo_key]) {
                continue;
            }
            
            foreach ($acomodacoes as $acom) {
                $campo_ativo = $tipo_key . '_' . $acom . '_ativo';
                
                if (!isset($cidade[$campo_ativo]) || !$cidade[$campo_ativo]) {
                    continue;
                }
                
                foreach ($coparticipacoes as $copart) {
                    $campo = $tipo_key . '_' . $acom . '_' . $copart;
                    
                    if (isset($cidade[$campo]) && !empty($cidade[$campo])) {
                        // Verifica a primeira faixa (geralmente a mais barata)
                        if (isset($cidade[$campo][0]['valor'])) {
                            $valor_string = $cidade[$campo][0]['valor'];
                            
                            // Converte para número para comparação
                            $preco_limpo = str_replace(array('R$', ' ', '.'), '', $valor_string);
                            $preco_limpo = str_replace(',', '.', $preco_limpo);
                            $preco_numerico = floatval($preco_limpo);
                            
                            // Aplica desconto se houver
                            $desconto = $this->obter_desconto_tipo($cidade, $tipo_key);
                            if ($desconto > 0) {
                                $multiplicador = 1 - ($desconto / 100);
                                $preco_numerico = $preco_numerico * $multiplicador;
                            }
                            
                            if ($menor_valor === null || $preco_numerico < $menor_valor) {
                                $menor_valor = $preco_numerico;
                                $menor_shortcode = $cidade['shortcode'] . '_' . $tipo_sigla . '_' . $acom . $copart;
                                $menor_valor_display = $this->obter_valor_formatado_simples($cidade, $valor_string, $tipo_key);
                                $menor_tipo_plano = $tipo_key;
                                $menor_acomodacao = $acom;
                                $menor_coparticipacao = $copart;
                            }
                        }
                    }
                }
            }
        }
        
        return array(
            'shortcode' => $menor_shortcode,
            'valor' => $menor_valor_display,
            'tipo_plano' => $menor_tipo_plano,
            'acomodacao' => $menor_acomodacao,
            'coparticipacao' => $menor_coparticipacao
        );
    }
    
    /**
     * Encontra o shortcode do maior valor de uma cidade
     */
    private function encontrar_maior_valor_cidade($cidade) {
        $maior_valor = null;
        $maior_shortcode = null;
        $maior_valor_display = null;
        $maior_tipo_plano = null;
        $maior_acomodacao = null;
        $maior_coparticipacao = null;

        $tipos_plano = array(
            'empresarial' => 'emp',
            'individual' => 'ind',
            'pme' => 'pme',
            'adesao' => 'ade'
        );

        $acomodacoes = array('ambulatorial', 'enfermaria', 'apartamento');
        $coparticipacoes = array('total', 'parcial');

        foreach ($tipos_plano as $tipo_key => $tipo_sigla) {
            if (!isset($cidade['tipos_planos_ativos'][$tipo_key]) || !$cidade['tipos_planos_ativos'][$tipo_key]) {
                continue;
            }

            foreach ($acomodacoes as $acom) {
                $campo_ativo = $tipo_key . '_' . $acom . '_ativo';

                if (!isset($cidade[$campo_ativo]) || !$cidade[$campo_ativo]) {
                    continue;
                }

                foreach ($coparticipacoes as $copart) {
                    $campo = $tipo_key . '_' . $acom . '_' . $copart;

                    if (isset($cidade[$campo]) && !empty($cidade[$campo])) {
                        // Pega a última faixa etária (geralmente a mais cara)
                        $ultima_faixa = end($cidade[$campo]);
                        if (isset($ultima_faixa['valor'])) {
                            $valor_string = $ultima_faixa['valor'];

                            $preco_limpo = str_replace(array('R$', ' ', '.'), '', $valor_string);
                            $preco_limpo = str_replace(',', '.', $preco_limpo);
                            $preco_numerico = floatval($preco_limpo);

                            $desconto = $this->obter_desconto_tipo($cidade, $tipo_key);
                            if ($desconto > 0) {
                                $multiplicador = 1 - ($desconto / 100);
                                $preco_numerico = $preco_numerico * $multiplicador;
                            }

                            if ($maior_valor === null || $preco_numerico > $maior_valor) {
                                $maior_valor = $preco_numerico;
                                $maior_shortcode = $cidade['shortcode'] . '_' . $tipo_sigla . '_' . $acom . $copart;
                                $maior_valor_display = $this->obter_valor_formatado_simples($cidade, $valor_string, $tipo_key);
                                $maior_tipo_plano = $tipo_key;
                                $maior_acomodacao = $acom;
                                $maior_coparticipacao = $copart;
                            }
                        }
                    }
                }
            }
        }

        return array(
            'shortcode' => $maior_shortcode,
            'valor' => $maior_valor_display,
            'tipo_plano' => $maior_tipo_plano,
            'acomodacao' => $maior_acomodacao,
            'coparticipacao' => $maior_coparticipacao
        );
    }

    /**
     * Página que lista todas as variáveis disponíveis
     */
public function pagina_variaveis() {
    $operadora_ativa = $this->sanitizar_operadora(isset($_GET['operadora']) ? $_GET['operadora'] : 'hapvida');
    $operadora_cfg = $this->operadoras[$operadora_ativa];
    $is_simples_var = $this->operadora_e_simples($operadora_ativa);
    $cidades = $this->obter_todas_cidades($operadora_ativa);

    // ===== ANÁLISE DINÂMICA: Quais colunas realmente existem? =====
    $colunas_existentes = array();

    if (!$is_simples_var && !empty($cidades)) {
        $tipos_plano = array(
            'empresarial' => array('sigla' => 'emp', 'nome' => 'Empresarial'),
            'individual' => array('sigla' => 'ind', 'nome' => 'Individual'),
            'pme' => array('sigla' => 'pme', 'nome' => 'PME'),
            'adesao' => array('sigla' => 'ade', 'nome' => 'Adesao')
        );
        
        $acomodacoes = array('ambulatorial', 'enfermaria', 'apartamento');
        $coparticipacoes = array('total', 'parcial');
        
        // Verifica quais combinações existem e CONTA quantas cidades têm
        foreach ($tipos_plano as $tipo_key => $tipo_info) {
            foreach ($coparticipacoes as $copart) {
                $coluna_key = $tipo_key . '_' . $copart;
                
                // Conta quantas cidades têm esta combinação
                $contador_cidades = 0;
                
                foreach ($cidades as $cidade) {
                    // Verifica se o tipo está ativo
                    if (!isset($cidade['tipos_planos_ativos'][$tipo_key]) || !$cidade['tipos_planos_ativos'][$tipo_key]) {
                        continue;
                    }
                    
                    // Procura em qualquer acomodação
                    $tem_plano = false;
                    foreach ($acomodacoes as $acom) {
                        $campo_ativo = $tipo_key . '_' . $acom . '_ativo';
                        $campo_dados = $tipo_key . '_' . $acom . '_' . $copart;
                        
                        if (isset($cidade[$campo_ativo]) && $cidade[$campo_ativo] && 
                            isset($cidade[$campo_dados]) && !empty($cidade[$campo_dados])) {
                            $tem_plano = true;
                            break;
                        }
                    }
                    
                    if ($tem_plano) {
                        $contador_cidades++;
                    }
                }
                
                // Se pelo menos uma cidade tem, adiciona a coluna
                if ($contador_cidades > 0) {
                    $colunas_existentes[$coluna_key] = array(
                        'tipo' => $tipo_key,
                        'sigla' => $tipo_info['sigla'],
                        'nome' => $tipo_info['nome'],
                        'copart' => $copart,
                        'copart_nome' => ucfirst($copart),
                        'qtd_cidades' => $contador_cidades
                    );
                }
            }
        }
    }
    ?>
    <div class="wrap gpp-variaveis-page">
        <h1>📋 Variáveis Dinâmicas Disponíveis</h1>

        <h2 class="nav-tab-wrapper" style="margin-bottom: 20px;">
            <?php foreach ($this->operadoras as $op_key => $op_info):
                $url_aba = admin_url('admin.php?page=gpp-variaveis&operadora=' . $op_key);
                $classe_ativa = ($op_key === $operadora_ativa) ? ' nav-tab-active' : '';
            ?>
                <a href="<?php echo esc_url($url_aba); ?>" class="nav-tab<?php echo $classe_ativa; ?>">
                    <?php echo esc_html($op_info['nome']); ?>
                </a>
            <?php endforeach; ?>
        </h2>

        <div style="padding: 10px 15px; margin-bottom: 15px; background: <?php echo esc_attr($operadora_cfg['cor']); ?>; color: #fff; border-radius: 5px; font-size: 16px;">
            Operadora: <strong><?php echo esc_html($operadora_cfg['nome']); ?></strong>
            <?php if ($operadora_cfg['prefixo'] !== ''): ?>
                &nbsp;—&nbsp; todos os shortcodes abaixo já incluem o prefixo <code style="background: rgba(255,255,255,0.25); color: #fff; padding: 2px 6px; border-radius: 3px;"><?php echo esc_html($operadora_cfg['prefixo']); ?></code>
            <?php endif; ?>
        </div>

        <div style="background: #fff; padding: 20px; margin: 20px 0; border-left: 4px solid #0054b8;">
            <h2>Como usar as variáveis</h2>
            <p>✅ As variáveis abaixo podem ser usadas em <strong>qualquer lugar do WordPress</strong>: títulos de páginas, textos, meta descriptions, schemas, widgets, etc.</p>
        </div>

        <?php if (empty($cidades)): ?>
            <div class="notice notice-warning">
                <p>Nenhuma cidade cadastrada ainda para <?php echo esc_html($operadora_cfg['nome']); ?>. <a href="<?php echo admin_url('admin.php?page=gerenciador-precos-planos&operadora=' . $operadora_ativa); ?>">Adicione uma cidade primeiro</a>.</p>
            </div>
        <?php elseif ($is_simples_var): ?>

            <!-- ===== MODO SIMPLES: uma tabela por cidade ===== -->
            <?php foreach ($cidades as $cidade):
                $cidade['operadora'] = $operadora_ativa;
                $base = $cidade['shortcode'];
                $tabela = (isset($cidade['tabela_simples']) && is_array($cidade['tabela_simples'])) ? $cidade['tabela_simples'] : array();
            ?>
                <div style="background: #fff; border: 1px solid #ddd; margin-bottom: 15px; padding: 20px;">
                    <h3 style="margin-top: 0; color: <?php echo esc_attr($operadora_cfg['cor']); ?>;">
                        <?php echo esc_html($cidade['nome']); ?>
                        <span style="color:#666; font-weight: normal;">(base: <code><?php echo esc_html($base); ?></code>)</span>
                    </h3>

                    <p>
                        <strong>Tabela completa:</strong>
                        <code class="gpp-copiar-var" data-var="[<?php echo esc_attr($base); ?>]" style="cursor:pointer;">[<?php echo esc_html($base); ?>]</code>
                        &nbsp;|&nbsp; sem avisos:
                        <code class="gpp-copiar-var" data-var="[<?php echo esc_attr($base); ?>_sd]" style="cursor:pointer;">[<?php echo esc_html($base); ?>_sd]</code>
                        &nbsp;|&nbsp; menor: <code class="gpp-copiar-var" data-var="[<?php echo esc_attr($base); ?>_menorvalor]" style="cursor:pointer;">[<?php echo esc_html($base); ?>_menorvalor]</code>
                        &nbsp;|&nbsp; maior: <code class="gpp-copiar-var" data-var="[<?php echo esc_attr($base); ?>_maiorvalor]" style="cursor:pointer;">[<?php echo esc_html($base); ?>_maiorvalor]</code>
                    </p>

                    <?php if (!empty($tabela)):
                        $idx_reg = array_flip($this->indices_faixas_registrar(count($tabela)));
                    ?>
                        <p style="font-size:12px; color:#718096; margin:0 0 6px;">Por desempenho, só a <strong>1ª, 2ª e última</strong> faixa têm shortcode individual. Para mostrar todas, use a tabela completa <code>[<?php echo esc_html($base); ?>]</code>.</p>
                        <table class="wp-list-table widefat fixed striped" style="max-width:700px;">
                            <thead>
                                <tr>
                                    <th style="width:35%;">Faixa Etária</th>
                                    <th style="width:40%;">Shortcode da faixa</th>
                                    <th style="width:25%;">Valor</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tabela as $idx => $faixa):
                                    $sc_faixa = $base . '_' . $idx;
                                    $tem_sc = isset($idx_reg[$idx]);
                                ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($faixa['faixa_etaria']); ?></strong></td>
                                        <td>
                                            <?php if ($tem_sc): ?>
                                                <code class="gpp-copiar-var" data-var="[<?php echo esc_attr($sc_faixa); ?>]" style="cursor:pointer;">[<?php echo esc_html($sc_faixa); ?>]</code>
                                            <?php else: ?>
                                                <span style="color:#999;">— use <code>[<?php echo esc_html($base); ?>]</code></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong><?php echo $this->formatar_preco_com_desconto($faixa['valor'], $this->obter_desconto_simples($cidade)); ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <em>Tabela ainda não cadastrada.</em>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

        <?php else: ?>

            <!-- ===== SEÇÃO 1: ATALHOS RÁPIDOS - PRIMEIRA FAIXA ===== -->
            <div style="background: #fff; padding: 20px; margin: 20px 0; border: 2px solid #0054b8; border-radius: 5px;">
                <h2 style="margin-top: 0; color: #0054b8;">⚡ Atalhos Rápidos - Primeira Faixa Etária (Mais Usados)</h2>
                <p style="color: #666; font-style: italic;">Use estes shortcodes para pegar automaticamente o valor da primeira faixa etária (geralmente "0 a 18 anos")</p>
                
                <?php if (empty($colunas_existentes)): ?>
                    <div class="notice notice-warning" style="margin: 15px 0;">
                        <p>Nenhum plano cadastrado ainda. Adicione planos às cidades para ver os atalhos.</p>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="wp-list-table widefat fixed striped gpp-tabela-atalhos" style="margin-top: 15px;">
                            <thead>
                                <tr style="background: #0054b8; color: #fff;">
                                    <th style="width: 150px; color: #fff; font-weight: bold;">Cidade</th>
                                    <th style="width: 80px; color: #fff; font-weight: bold;">Descontos</th>
                                    <?php 
                                    $total_cidades = count($cidades);
                                    foreach ($colunas_existentes as $coluna): 
                                        $percentual = round(($coluna['qtd_cidades'] / $total_cidades) * 100);
                                        $cor_badge = '';
                                        if ($percentual >= 80) {
                                            $cor_badge = '#46b450';
                                        } elseif ($percentual >= 50) {
                                            $cor_badge = '#ffb900';
                                        } else {
                                            $cor_badge = '#dc3232';
                                        }
                                    ?>
                                        <th style="color: #fff; font-weight: bold;">
                                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                                <span><?php echo esc_html($coluna['nome'] . ' ' . $coluna['copart_nome']); ?></span>
                                                <span style="background: <?php echo $cor_badge; ?>; color: #fff; padding: 2px 6px; border-radius: 10px; font-size: 10px; margin-left: 5px;" title="<?php echo $coluna['qtd_cidades']; ?> de <?php echo $total_cidades; ?> cidades">
                                                    <?php echo $coluna['qtd_cidades']; ?>/<?php echo $total_cidades; ?>
                                                </span>
                                            </div>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cidades as $cidade): 
                                    // Calcula descontos por tipo
                                    $descontos_info = array();
                                    $tipos_check = array('empresarial' => 'Emp', 'individual' => 'Ind', 'pme' => 'PME', 'adesao' => 'Ade');
                                    
                                    foreach ($tipos_check as $tipo_key => $tipo_label) {
                                        $desc = $this->obter_desconto_tipo($cidade, $tipo_key);
                                        if ($desc > 0) {
                                            $descontos_info[] = $tipo_label . ': ' . $desc . '%';
                                        }
                                    }
                                    
                                    $desconto_display = !empty($descontos_info) ? implode('<br>', $descontos_info) : '-';
                                ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($cidade['nome']); ?></strong></td>
                                        <td style="font-size: 10px;"><?php echo $desconto_display; ?></td>
                                        
                                        <?php foreach ($colunas_existentes as $coluna): 
                                            $tipo_key = $coluna['tipo'];
                                            $sigla = $coluna['sigla'];
                                            $copart = $coluna['copart'];
                                            
                                            // Verifica se este tipo está ativo nesta cidade
                                            $tipo_ativo = isset($cidade['tipos_planos_ativos'][$tipo_key]) && $cidade['tipos_planos_ativos'][$tipo_key];
                                            
                                            if (!$tipo_ativo) {
                                                echo '<td class="gpp-celula-vazia">-</td>';
                                                continue;
                                            }
                                            
                                            // Procura em qual acomodação este plano existe
                                            $acomodacoes_prioridade = array('ambulatorial', 'enfermaria', 'apartamento');
                                            $valor_encontrado = null;
                                            $shortcode_encontrado = null;
                                            
                                            foreach ($acomodacoes_prioridade as $acom) {
                                                $campo_ativo = $tipo_key . '_' . $acom . '_ativo';
                                                $campo_dados = $tipo_key . '_' . $acom . '_' . $copart;
                                                
                                                if (isset($cidade[$campo_ativo]) && $cidade[$campo_ativo] && 
                                                    isset($cidade[$campo_dados]) && !empty($cidade[$campo_dados]) &&
                                                    isset($cidade[$campo_dados][0]['valor'])) {
                                                    
                                                    $valor_encontrado = $this->obter_valor_formatado_simples($cidade, $cidade[$campo_dados][0]['valor'], $tipo_key);
                                                    $shortcode_encontrado = $cidade['shortcode'] . '_' . $sigla . '_' . $acom . $copart;
                                                    break;
                                                }
                                            }
                                            
                                            if ($valor_encontrado):
                                        ?>
                                                <td class="gpp-celula-preenchida">
                                                    <code class="gpp-code-atalho">[<?php echo esc_html($shortcode_encontrado); ?>]</code>
                                                    <button class="button button-small gpp-copiar-var" data-var="[<?php echo esc_attr($shortcode_encontrado); ?>]" style="margin-left: 5px;">📋</button>
                                                    <br><small style="color: <?php echo $copart === 'total' ? '#0054b8' : '#F05A22'; ?>; font-weight: bold;"><?php echo esc_html($valor_encontrado); ?></small>
                                                </td>
                                            <?php else: ?>
                                                <td class="gpp-celula-vazia">-</td>
                                            <?php endif; ?>
                                            
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div style="margin-top: 15px; padding: 10px; background: #f0f0f0; border-left: 4px solid #0054b8;">
                        <strong>💡 Legenda dos badges:</strong>
                        <span style="display: inline-block; margin-left: 10px;">
                            <span style="background: #46b450; color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 11px;">Verde</span> = Maioria das cidades tem
                        </span>
                        <span style="display: inline-block; margin-left: 10px;">
                            <span style="background: #ffb900; color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 11px;">Amarelo</span> = Metade das cidades tem
                        </span>
                        <span style="display: inline-block; margin-left: 10px;">
                            <span style="background: #dc3232; color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 11px;">Vermelho</span> = Poucas cidades têm
                        </span>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- ===== SEÇÃO 2: BUSCA E ACCORDIONS DETALHADOS ===== -->
            <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ddd;">
                <h2 style="margin-top: 0; color: #0054b8;">🔍 Buscar Cidade (Todas as Faixas Etárias)</h2>
                <p style="color: #666;"><em>Para ver TODAS as faixas etárias de uma cidade, use a busca abaixo</em></p>
                <input type="text" id="gpp-buscar-cidade" placeholder="Digite o nome da cidade..." style="width: 100%; max-width: 500px; padding: 10px; font-size: 16px; border: 2px solid #0054b8; border-radius: 4px;">
            </div>
            
            <div class="gpp-accordions">
                <?php foreach ($cidades as $index => $cidade): 
                    $cidade_id = 'cidade-' . $index;
                ?>
                    <div class="gpp-accordion-item gpp-cidade-accordion" data-cidade="<?php echo esc_attr(strtolower($cidade['nome'])); ?>" style="margin-bottom: 15px; border: 1px solid #ddd; background: #fff;">
                        <div class="gpp-accordion-header" data-target="<?php echo $cidade_id; ?>" style="background: #f7f7f7; padding: 15px; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <h3 style="margin: 0; display: inline-block;">
                                    <span class="dashicons dashicons-arrow-right gpp-accordion-icon" style="transition: transform 0.3s;"></span>
                                    <?php echo esc_html($cidade['nome']); ?>
                                </h3>
                                <span style="margin-left: 15px; color: #666;">
                                    (Shortcode base: <code><?php echo esc_html($cidade['shortcode']); ?></code>)
                                </span>
                            </div>
                            <span class="button" style="pointer-events: none;">Ver Detalhes</span>
                        </div>
                        
                        <div class="gpp-accordion-content" id="<?php echo $cidade_id; ?>" style="display: none; padding: 20px;">
                            
                            <?php 
                            // Define os tipos de planos disponíveis
                            $tipos_plano = array(
                                'empresarial' => array('emoji' => '📈', 'nome' => 'Empresariais', 'sigla' => 'emp'),
                                'individual' => array('emoji' => '👤', 'nome' => 'Individuais', 'sigla' => 'ind'),
                                'pme' => array('emoji' => '🏢', 'nome' => 'PME', 'sigla' => 'pme'),
                                'adesao' => array('emoji' => '🤝', 'nome' => 'por Adesao', 'sigla' => 'ade')
                            );
                            
                            foreach ($tipos_plano as $tipo_key => $tipo_info):
                                // Verifica se este tipo está ativo
                                if (!isset($cidade['tipos_planos_ativos'][$tipo_key]) || !$cidade['tipos_planos_ativos'][$tipo_key]) {
                                    continue;
                                }
                                
                                $desconto_tipo = $this->obter_desconto_tipo($cidade, $tipo_key);
                            ?>
                                <h4 style="color: #0054b8; border-bottom: 2px solid #0054b8; padding-bottom: 5px; margin-top: 30px;">
                                    <?php echo $tipo_info['emoji']; ?> Planos <?php echo $tipo_info['nome']; ?>
                                    <?php if ($desconto_tipo > 0): ?>
                                        <span style="background: #F05A22; color: #fff; padding: 4px 10px; border-radius: 3px; font-size: 12px; margin-left: 10px;">Desconto: <?php echo $desconto_tipo; ?>%</span>
                                    <?php endif; ?>
                                </h4>
                                
                                <?php
                                // Define as acomodações
                                $acomodacoes = array(
                                    'ambulatorial' => 'Ambulatorial',
                                    'enfermaria' => 'Enfermaria',
                                    'apartamento' => 'Apartamento'
                                );
                                
                                foreach ($acomodacoes as $acom_key => $acom_nome):
                                    // Verifica se esta acomodação está ativa
                                    $campo_ativo_acom = $tipo_key . '_' . $acom_key . '_ativo';
                                    if (!isset($cidade[$campo_ativo_acom]) || !$cidade[$campo_ativo_acom]) {
                                        continue;
                                    }
                                ?>
                                    <h5 style="margin-top: 20px; color: #333; background: #f0f0f0; padding: 10px; border-left: 4px solid #0054b8;">
                                        <?php echo $acom_nome; ?>
                                    </h5>
                                    
                                    <?php
                                    // Coparticipação Total
                                    $campo_total = $tipo_key . '_' . $acom_key . '_total';
                                    if (!empty($cidade[$campo_total])):
                                    ?>
                                        <h6 style="margin-top: 15px; color: #555;">Coparticipação Total</h6>
                                        <table class="wp-list-table widefat fixed striped" style="margin-bottom: 15px;">
                                            <thead>
                                                <tr>
                                                    <th style="width: 30%;">Faixa Etária</th>
                                                    <th style="width: 45%;">Shortcode</th>
                                                    <th style="width: 25%;">Valor</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($cidade[$campo_total] as $idx => $plano): 
                                                    $shortcode_var = $cidade['shortcode'] . '_' . $tipo_info['sigla'] . '_' . $acom_key . 'total_' . $idx;
                                                ?>
                                                    <tr>
                                                        <td><strong><?php echo esc_html($plano['faixa_etaria']); ?></strong></td>
                                                        <td>
                                                            <code>[<?php echo esc_html($shortcode_var); ?>]</code>
                                                            <button class="button button-small gpp-copiar-var" data-var="[<?php echo esc_attr($shortcode_var); ?>]">📋 Copiar</button>
                                                        </td>
                                                        <td><strong><?php echo $this->obter_valor_formatado_simples($cidade, $plano['valor'], $tipo_key); ?></strong></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>
                                    
                                    <?php
                                    // Coparticipação Parcial
                                    $campo_parcial = $tipo_key . '_' . $acom_key . '_parcial';
                                    if (!empty($cidade[$campo_parcial])):
                                    ?>
                                        <h6 style="margin-top: 15px; color: #555;">Coparticipação Parcial</h6>
                                        <table class="wp-list-table widefat fixed striped" style="margin-bottom: 15px;">
                                            <thead>
                                                <tr>
                                                    <th style="width: 30%;">Faixa Etária</th>
                                                    <th style="width: 45%;">Shortcode</th>
                                                    <th style="width: 25%;">Valor</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($cidade[$campo_parcial] as $idx => $plano): 
                                                    $shortcode_var = $cidade['shortcode'] . '_' . $tipo_info['sigla'] . '_' . $acom_key . 'parcial_' . $idx;
                                                ?>
                                                    <tr>
                                                        <td><strong><?php echo esc_html($plano['faixa_etaria']); ?></strong></td>
                                                        <td>
                                                            <code>[<?php echo esc_html($shortcode_var); ?>]</code>
                                                            <button class="button button-small gpp-copiar-var" data-var="[<?php echo esc_attr($shortcode_var); ?>]">📋 Copiar</button>
                                                        </td>
                                                        <td><strong><?php echo $this->obter_valor_formatado_simples($cidade, $plano['valor'], $tipo_key); ?></strong></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>
                                    
                                <?php endforeach; ?>
                                
                            <?php endforeach; ?>
                            
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
        <?php endif; ?>
    </div>
    
    <style>
        .gpp-variaveis-page code {
            background: #f0f0f0;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 12px;
        }
        
        .gpp-code-atalho {
            background: #e7f3ff !important;
            padding: 3px 6px;
            border-radius: 3px;
            font-size: 11px;
            border: 1px solid #0054b8;
        }
        
        .gpp-tabela-atalhos .gpp-celula-vazia {
            background-color: #f9f9f9 !important;
            color: #ccc !important;
            text-align: center;
            font-style: italic;
        }
        
        .gpp-tabela-atalhos .gpp-celula-preenchida {
            background-color: #fff !important;
        }
        
        .gpp-accordion-header:hover {
            background: #e8e8e8 !important;
        }
        
        .gpp-accordion-icon {
            transition: transform 0.3s ease;
            display: inline-block;
        }
        
        .gpp-accordion-icon.rotated {
            transform: rotate(90deg);
        }
        
        .gpp-accordion-content {
            border-top: 1px solid #ddd;
        }
        
        #gpp-buscar-cidade {
            transition: border-color 0.3s;
        }
        
        #gpp-buscar-cidade:focus {
            outline: none;
            border-color: #F05A22;
            box-shadow: 0 0 5px rgba(240, 90, 34, 0.3);
        }
        
        .gpp-cidade-accordion.hidden {
            display: none !important;
        }
    </style>
    
    <script>
    jQuery(document).ready(function($) {
        // Copiar variáveis
        $('.gpp-copiar-var').on('click', function(e) {
            e.stopPropagation();
            var shortcode = $(this).data('var');
            
            var $temp = $('<input>');
            $('body').append($temp);
            $temp.val(shortcode).select();
            document.execCommand('copy');
            $temp.remove();
            
            var $btn = $(this);
            var textoOriginal = $btn.html();
            $btn.html('✅ Copiado!');
            setTimeout(function() {
                $btn.html(textoOriginal);
            }, 2000);
        });
        
        // Accordion toggle
        $('.gpp-accordion-header').on('click', function() {
            var target = $(this).data('target');
            var $content = $('#' + target);
            var $icon = $(this).find('.gpp-accordion-icon');
            
            if ($content.is(':visible')) {
                $content.slideUp(300);
                $icon.removeClass('rotated');
            } else {
                $content.slideDown(300);
                $icon.addClass('rotated');
            }
        });
        
        // Busca de cidades
        $('#gpp-buscar-cidade').on('input', function() {
            var searchTerm = $(this).val().toLowerCase().trim();
            
            if (searchTerm === '') {
                $('.gpp-cidade-accordion').removeClass('hidden');
            } else {
                $('.gpp-cidade-accordion').each(function() {
                    var cidadeNome = $(this).data('cidade');
                    if (cidadeNome.indexOf(searchTerm) !== -1) {
                        $(this).removeClass('hidden');
                    } else {
                        $(this).addClass('hidden');
                    }
                });
            }
        });
    });
    </script>
    <?php
}

    /**
     * Página de administração para valores regionais (SP/BH e Demais Capitais)
     */
    public function pagina_valores_regionais() {
        $valores = get_option($this->regional_option, array());

        // Estrutura padrão dos campos
        $campos = array(
            'consultas_eletivas' => 'Consultas eletivas',
            'consultas_urgencia' => 'Consultas de urgência/emergência',
            'exames_simples' => 'Exames simples (sangue, urina, etc.)',
            'exames_complexos' => 'Exames complexos (ressonância, tomografia, etc.)',
            'terapias_neurologicas' => 'Terapias neurológicas (fonoaudiologia, fisioterapia neurológica)',
            'demais_terapias' => 'Demais terapias (fisioterapia convencional, psicologia, nutrição)'
        );

        // Regiões disponíveis
        $regioes = array(
            'sp_bh' => array(
                'nome' => 'São Paulo e Belo Horizonte',
                'cor' => '#0054b8',
                'emoji' => '🏙️'
            ),
            'demais_capitais' => array(
                'nome' => 'Demais Capitais',
                'cor' => '#28a745',
                'emoji' => '🌆'
            )
        );
        ?>
        <div class="wrap">
            <h1>Valores Regionais - Procedimentos Médicos</h1>

            <div style="background: #fff; padding: 20px; margin: 20px 0; border-left: 4px solid #0054b8;">
                <h2 style="margin-top: 0;">Como usar</h2>
                <p>Configure os valores de procedimentos médicos para cada região. Os shortcodes serão gerados automaticamente.</p>

                <h3>📌 Shortcodes Individuais</h3>
                <p><strong>Exemplo:</strong> <code>[sp_bh_consultas_eletivas]</code> ou <code>[demais_capitais_exames_simples]</code></p>
                <p>Exibe apenas o valor de um procedimento específico.</p>

                <h3>📋 Shortcodes de Tabela Completa</h3>
                <p><strong>Coparticipação TOTAL</strong> (todos os procedimentos com valores):</p>
                <ul style="margin-left: 20px;">
                    <li><code>[sp_bh_tabela_total]</code> - Tabela completa SP/BH</li>
                    <li><code>[demais_capitais_tabela_total]</code> - Tabela completa demais capitais</li>
                </ul>

                <p><strong>Coparticipação PARCIAL</strong> (consultas/exames isentos, valores só em terapias):</p>
                <ul style="margin-left: 20px;">
                    <li><code>[sp_bh_tabela_parcial]</code> - Tabela parcial SP/BH</li>
                    <li><code>[demais_capitais_tabela_parcial]</code> - Tabela parcial demais capitais</li>
                </ul>

                <p><strong>Atalhos sem sufixo</strong> (funcionam como _total):</p>
                <ul style="margin-left: 20px;">
                    <li><code>[sp_bh_tabela]</code> = <code>[sp_bh_tabela_total]</code></li>
                    <li><code>[demais_capitais_tabela]</code> = <code>[demais_capitais_tabela_total]</code></li>
                </ul>
            </div>

            <form id="gpp-form-regionais">
                <?php foreach ($regioes as $regiao_key => $regiao_info): ?>
                    <div style="background: #fff; padding: 20px; margin: 20px 0; border: 2px solid <?php echo $regiao_info['cor']; ?>; border-radius: 5px;">
                        <h2 style="margin-top: 0; color: <?php echo $regiao_info['cor']; ?>;">
                            <?php echo $regiao_info['emoji']; ?> <?php echo $regiao_info['nome']; ?>
                        </h2>

                        <table class="form-table">
                            <?php foreach ($campos as $campo_key => $campo_label):
                                $valor_atual = isset($valores[$regiao_key][$campo_key]) ? $valores[$regiao_key][$campo_key] : '';
                                $shortcode = '[' . $regiao_key . '_' . $campo_key . ']';
                            ?>
                                <tr>
                                    <th style="width: 40%;">
                                        <label for="<?php echo $regiao_key; ?>_<?php echo $campo_key; ?>"><?php echo $campo_label; ?></label>
                                        <br>
                                        <code style="font-size: 11px; background: #f0f0f0; padding: 2px 6px;"><?php echo $shortcode; ?></code>
                                        <button type="button" class="button button-small gpp-copiar-shortcode" data-shortcode="<?php echo esc_attr($shortcode); ?>" style="margin-left: 5px;">📋</button>
                                    </th>
                                    <td>
                                        <input type="text"
                                            id="<?php echo $regiao_key; ?>_<?php echo $campo_key; ?>"
                                            name="valores[<?php echo $regiao_key; ?>][<?php echo $campo_key; ?>]"
                                            value="<?php echo esc_attr($valor_atual); ?>"
                                            class="regular-text"
                                            placeholder="Ex: R$ 150,00 ou 150.00">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                <?php endforeach; ?>

                <p class="submit">
                    <button type="submit" class="button button-primary button-large">Salvar Valores Regionais</button>
                </p>
            </form>

            <!-- Tabela de referência dos shortcodes -->
            <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ddd;">
                <h2 style="margin-top: 0;">📋 Referência Rápida de Shortcodes</h2>

                <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
                    <thead>
                        <tr style="background: #0054b8; color: #fff;">
                            <th style="color: #fff; font-weight: bold;">Procedimento</th>
                            <th style="color: #fff; font-weight: bold;">🏙️ SP e BH</th>
                            <th style="color: #fff; font-weight: bold;">🌆 Demais Capitais</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($campos as $campo_key => $campo_label): ?>
                            <tr>
                                <td><strong><?php echo $campo_label; ?></strong></td>
                                <td>
                                    <code>[sp_bh_<?php echo $campo_key; ?>]</code>
                                    <button type="button" class="button button-small gpp-copiar-shortcode" data-shortcode="[sp_bh_<?php echo $campo_key; ?>]">📋</button>
                                    <?php if (isset($valores['sp_bh'][$campo_key]) && !empty($valores['sp_bh'][$campo_key])): ?>
                                        <br><small style="color: #0054b8; font-weight: bold;"><?php echo esc_html($valores['sp_bh'][$campo_key]); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code>[demais_capitais_<?php echo $campo_key; ?>]</code>
                                    <button type="button" class="button button-small gpp-copiar-shortcode" data-shortcode="[demais_capitais_<?php echo $campo_key; ?>]">📋</button>
                                    <?php if (isset($valores['demais_capitais'][$campo_key]) && !empty($valores['demais_capitais'][$campo_key])): ?>
                                        <br><small style="color: #28a745; font-weight: bold;"><?php echo esc_html($valores['demais_capitais'][$campo_key]); ?></small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <h3 style="margin-top: 30px;">📋 Shortcodes de Tabelas Completas</h3>
                <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
                    <thead>
                        <tr style="background: #28a745; color: #fff;">
                            <th style="color: #fff; font-weight: bold;">Tipo de Tabela</th>
                            <th style="color: #fff; font-weight: bold;">🏙️ SP e BH</th>
                            <th style="color: #fff; font-weight: bold;">🌆 Demais Capitais</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Tabela TOTAL</strong><br><small>Todos os procedimentos com valores</small></td>
                            <td>
                                <code>[sp_bh_tabela_total]</code>
                                <button type="button" class="button button-small gpp-copiar-shortcode" data-shortcode="[sp_bh_tabela_total]">📋</button>
                            </td>
                            <td>
                                <code>[demais_capitais_tabela_total]</code>
                                <button type="button" class="button button-small gpp-copiar-shortcode" data-shortcode="[demais_capitais_tabela_total]">📋</button>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Tabela PARCIAL</strong><br><small>Consultas/exames isentos, valores apenas em terapias</small></td>
                            <td>
                                <code>[sp_bh_tabela_parcial]</code>
                                <button type="button" class="button button-small gpp-copiar-shortcode" data-shortcode="[sp_bh_tabela_parcial]">📋</button>
                            </td>
                            <td>
                                <code>[demais_capitais_tabela_parcial]</code>
                                <button type="button" class="button button-small gpp-copiar-shortcode" data-shortcode="[demais_capitais_tabela_parcial]">📋</button>
                            </td>
                        </tr>
                        <tr style="background: #f0f0f0;">
                            <td><strong>Atalho (sem sufixo)</strong><br><small>Funciona como _total</small></td>
                            <td>
                                <code>[sp_bh_tabela]</code>
                                <button type="button" class="button button-small gpp-copiar-shortcode" data-shortcode="[sp_bh_tabela]">📋</button>
                            </td>
                            <td>
                                <code>[demais_capitais_tabela]</code>
                                <button type="button" class="button button-small gpp-copiar-shortcode" data-shortcode="[demais_capitais_tabela]">📋</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Copiar shortcode
            $('.gpp-copiar-shortcode').on('click', function() {
                var shortcode = $(this).data('shortcode');
                var $btn = $(this);
                var textoOriginal = $btn.html();

                navigator.clipboard.writeText(shortcode).then(function() {
                    $btn.html('✅');
                    setTimeout(function() {
                        $btn.html(textoOriginal);
                    }, 2000);
                });
            });

            // Salvar valores via AJAX
            $('#gpp-form-regionais').on('submit', function(e) {
                e.preventDefault();

                var formData = $(this).serializeArray();
                var valores = {};

                formData.forEach(function(item) {
                    var match = item.name.match(/valores\[(\w+)\]\[(\w+)\]/);
                    if (match) {
                        var regiao = match[1];
                        var campo = match[2];
                        if (!valores[regiao]) {
                            valores[regiao] = {};
                        }
                        valores[regiao][campo] = item.value;
                    }
                });

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'gpp_salvar_valores_regionais',
                        nonce: '<?php echo wp_create_nonce('gpp_nonce'); ?>',
                        valores: JSON.stringify(valores)
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Valores salvos com sucesso!');
                            location.reload();
                        } else {
                            alert('Erro ao salvar: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('Erro de conexão ao salvar.');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Obtém valor formatado simples (com desconto se houver)
     */
    private function obter_valor_formatado_simples($cidade_data, $valor, $tipo_plano) {
        if (empty($valor)) {
            return 'N/A';
        }
        
        // Obtém desconto específico do tipo
        $desconto = $this->obter_desconto_tipo($cidade_data, $tipo_plano);
        
        return $this->formatar_preco_com_desconto($valor, $desconto);
    }
    
    /**
     * Normaliza dados do JSON para formato padrão
     */
    private function normalizar_json_plano($json_string) {
        if (empty($json_string)) {
            return null;
        }
        
        $dados = json_decode(stripslashes($json_string), true);
        
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($dados)) {
            return null;
        }
        
        // Normaliza cada item para usar "valor"
        $dados_normalizados = array();
        foreach ($dados as $item) {
            $item_normalizado = array();
            
            // Mantém faixa_etaria
            if (isset($item['faixa_etaria'])) {
                $item_normalizado['faixa_etaria'] = $item['faixa_etaria'];
            }
            
            // Normaliza o campo de valor
            if (isset($item['valor'])) {
                $item_normalizado['valor'] = $item['valor'];
            } elseif (isset($item['coparticipacao_total'])) {
                $item_normalizado['valor'] = $item['coparticipacao_total'];
            } elseif (isset($item['coparticipacao_parcial'])) {
                $item_normalizado['valor'] = $item['coparticipacao_parcial'];
            }
            
            // Só adiciona se tiver os campos necessários
            if (isset($item_normalizado['faixa_etaria']) && isset($item_normalizado['valor'])) {
                $dados_normalizados[] = $item_normalizado;
            }
        }
        
        return $dados_normalizados;
    }
    
    /**
     * Registra shortcodes de variáveis dinâmicas
     */
    public function registrar_shortcodes_variaveis() {
        $cidades = $this->obter_todas_cidades_global();
        $plugin_instance = $this;

        if (!empty($cidades)) {
            foreach ($cidades as $cidade_data) {
                // Registro sob demanda: respeita o filtro de slugs.
                if (!$this->slug_permitido($this->obter_slug_base_cidade($cidade_data))) {
                    continue;
                }

                // Cria cópia local para evitar problemas de referência em closures
                $cidade_local = $cidade_data;
                $shortcode_base = $cidade_local['shortcode'];

                // Define os tipos de planos
                $tipos_plano = array(
                    'empresarial' => 'emp',
                    'individual' => 'ind',
                    'pme' => 'pme',
                    'adesao' => 'ade'
                );

                // Define as acomodações
                $acomodacoes = array('ambulatorial', 'enfermaria', 'apartamento');

                foreach ($tipos_plano as $tipo_key => $tipo_sigla) {
                    // Verifica se este tipo de plano está ativo
                    if (!isset($cidade_local['tipos_planos_ativos'][$tipo_key]) || !$cidade_local['tipos_planos_ativos'][$tipo_key]) {
                        continue;
                    }

                    // Cria variáveis locais para este tipo
                    $tipo_key_local = $tipo_key;
                    $tipo_sigla_local = $tipo_sigla;

                    foreach ($acomodacoes as $acom) {
                        // Verifica se esta acomodação está ativa
                        $campo_ativo_acom = $tipo_key_local . '_' . $acom . '_ativo';
                        if (!isset($cidade_local[$campo_ativo_acom]) || !$cidade_local[$campo_ativo_acom]) {
                            continue;
                        }

                        $acom_local = $acom;

                        // Total
                        $campo_total = $tipo_key_local . '_' . $acom_local . '_total';
                        if (!empty($cidade_local[$campo_total])) {
                            // OTIMIZAÇÃO: Registra apenas faixas 0, 1 e 9 (primeira, segunda e última)
                            // Evita sobrecarga com 10 faixas × múltiplas cidades
                            foreach ($cidade_local[$campo_total] as $index => $plano) {
                                // Registra apenas índices 0, 1 e 9
                                if ($index !== 0 && $index !== 1 && $index !== 9) {
                                    continue;
                                }

                                $shortcode_name = $shortcode_base . '_' . $tipo_sigla_local . '_' . $acom_local . 'total_' . $index;

                                // Cria variáveis locais para a closure
                                $plano_local = $plano;

                                add_shortcode($shortcode_name, function() use ($plugin_instance, $cidade_local, $plano_local, $tipo_key_local) {
                                    return $plugin_instance->obter_valor_formatado_simples($cidade_local, $plano_local['valor'], $tipo_key_local);
                                });
                            }

                            // ✅ ATALHO: Shortcode sem índice para primeira faixa (0-18 anos)
                            $shortcode_first = $shortcode_base . '_' . $tipo_sigla_local . '_' . $acom_local . 'total';
                            $campo_total_local = $campo_total;

                            add_shortcode($shortcode_first, function() use ($plugin_instance, $cidade_local, $campo_total_local, $tipo_key_local) {
                                if (!empty($cidade_local[$campo_total_local][0]['valor'])) {
                                    return $plugin_instance->obter_valor_formatado_simples($cidade_local, $cidade_local[$campo_total_local][0]['valor'], $tipo_key_local);
                                }
                                return 'N/A';
                            });
                        }

                        // Parcial
                        $campo_parcial = $tipo_key_local . '_' . $acom_local . '_parcial';
                        if (!empty($cidade_local[$campo_parcial])) {
                            // OTIMIZAÇÃO: Registra apenas faixas 0, 1 e 9 (primeira, segunda e última)
                            // Evita sobrecarga com 10 faixas × múltiplas cidades
                            foreach ($cidade_local[$campo_parcial] as $index => $plano) {
                                // Registra apenas índices 0, 1 e 9
                                if ($index !== 0 && $index !== 1 && $index !== 9) {
                                    continue;
                                }

                                $shortcode_name = $shortcode_base . '_' . $tipo_sigla_local . '_' . $acom_local . 'parcial_' . $index;

                                // Cria variáveis locais para a closure
                                $plano_local = $plano;

                                add_shortcode($shortcode_name, function() use ($plugin_instance, $cidade_local, $plano_local, $tipo_key_local) {
                                    return $plugin_instance->obter_valor_formatado_simples($cidade_local, $plano_local['valor'], $tipo_key_local);
                                });
                            }

                            // ✅ ATALHO: Shortcode sem índice para primeira faixa (0-18 anos)
                            $shortcode_first = $shortcode_base . '_' . $tipo_sigla_local . '_' . $acom_local . 'parcial';
                            $campo_parcial_local = $campo_parcial;

                            add_shortcode($shortcode_first, function() use ($plugin_instance, $cidade_local, $campo_parcial_local, $tipo_key_local) {
                                if (!empty($cidade_local[$campo_parcial_local][0]['valor'])) {
                                    return $plugin_instance->obter_valor_formatado_simples($cidade_local, $cidade_local[$campo_parcial_local][0]['valor'], $tipo_key_local);
                                }
                                return 'N/A';
                            });
                        }
                    }
                }
            }
        }
    }

    /**
     * Registra shortcodes de valores regionais (SP/BH e Demais Capitais)
     */
    public function registrar_shortcodes_regionais() {
        $valores = get_option($this->regional_option, array());
        $plugin_instance = $this;

        // Campos disponíveis com labels formatados
        $campos = array(
            'consultas_eletivas' => 'Consultas eletivas',
            'consultas_urgencia' => 'Consultas de urgência/emergência',
            'exames_simples' => 'Exames simples (sangue, urina, etc.)',
            'exames_complexos' => 'Exames complexos (ressonância, tomografia, etc.)',
            'terapias_neurologicas' => 'Terapias neurológicas (fonoaudiologia, fisioterapia neurológica)',
            'demais_terapias' => 'Demais terapias (fisioterapia convencional, psicologia, nutrição)'
        );

        // Regiões disponíveis
        $regioes = array('sp_bh', 'demais_capitais');

        // Registra shortcodes individuais para cada campo
        foreach ($regioes as $regiao) {
            foreach ($campos as $campo_key => $campo_label) {
                $shortcode_name = $regiao . '_' . $campo_key;

                // Cria variáveis locais para a closure
                $regiao_local = $regiao;
                $campo_local = $campo_key;

                add_shortcode($shortcode_name, function() use ($valores, $regiao_local, $campo_local) {
                    if (isset($valores[$regiao_local][$campo_local]) && !empty($valores[$regiao_local][$campo_local])) {
                        $valor = $valores[$regiao_local][$campo_local];
                        // Adiciona R$ se o valor não começar com ele
                        if (stripos($valor, 'R$') === false) {
                            $valor = 'R$ ' . $valor;
                        }
                        return esc_html($valor);
                    }
                    return 'N/A';
                });
            }

            // Registra shortcode de tabela completa (retrocompatibilidade - funciona como 'total')
            $shortcode_tabela = $regiao . '_tabela';
            $regiao_local = $regiao;

            add_shortcode($shortcode_tabela, function() use ($plugin_instance, $valores, $regiao_local, $campos) {
                return $plugin_instance->renderizar_tabela_regional($valores, $regiao_local, $campos, 'total');
            });

            // Registra shortcode de tabela TOTAL (todos os valores)
            $shortcode_tabela_total = $regiao . '_tabela_total';
            $regiao_local_total = $regiao;

            add_shortcode($shortcode_tabela_total, function() use ($plugin_instance, $valores, $regiao_local_total, $campos) {
                return $plugin_instance->renderizar_tabela_regional($valores, $regiao_local_total, $campos, 'total');
            });

            // Registra shortcode de tabela PARCIAL (isento nos 4 primeiros, valores só nas terapias)
            $shortcode_tabela_parcial = $regiao . '_tabela_parcial';
            $regiao_local_parcial = $regiao;

            add_shortcode($shortcode_tabela_parcial, function() use ($plugin_instance, $valores, $regiao_local_parcial, $campos) {
                return $plugin_instance->renderizar_tabela_regional($valores, $regiao_local_parcial, $campos, 'parcial');
            });
        }
    }

    /**
     * Registra shortcodes de data: [ano_atual] e [mes_atual]
     * Retorna o ano e mês atuais para uso em títulos e meta descrições
     */
    public function registrar_shortcodes_data() {
        // Shortcode [ano_atual] - retorna o ano atual (ex: 2026)
        add_shortcode('ano_atual', function() {
            return date('Y');
        });

        // Shortcode [mes_atual] - retorna o mês atual em português (ex: Abril)
        add_shortcode('mes_atual', function() {
            $meses = array(
                1  => 'Janeiro',
                2  => 'Fevereiro',
                3  => 'Março',
                4  => 'Abril',
                5  => 'Maio',
                6  => 'Junho',
                7  => 'Julho',
                8  => 'Agosto',
                9  => 'Setembro',
                10 => 'Outubro',
                11 => 'Novembro',
                12 => 'Dezembro'
            );
            return $meses[(int) date('n')];
        });
    }

    /**
     * Renderiza a tabela de valores regionais em HTML
     * @param array $valores - Valores regionais salvos
     * @param string $regiao - Nome da região (sp_bh ou demais_capitais)
     * @param array $campos - Array de campos com labels
     * @param string $modo - 'total' (todos os valores) ou 'parcial' (isento nos 4 primeiros)
     */
    public function renderizar_tabela_regional($valores, $regiao, $campos, $modo = 'total') {
        // Campos que são isentos na coparticipação parcial
        $campos_isentos_parcial = array('consultas_eletivas', 'consultas_urgencia', 'exames_simples', 'exames_complexos');

        ob_start();
        ?>
        <div class="gpp-container-cidade">
            <div class="tabela-precos-hapvida">
                <table>
                    <thead>
                        <tr>
                            <th>Procedimento</th>
                            <th>Valor de Coparticipação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($campos as $campo_key => $campo_label): ?>
                            <tr>
                                <td><?php echo esc_html($campo_label); ?></td>
                                <td>
                                    <span class="valor-destaque">
                                        <?php
                                        // Se modo parcial E campo está na lista de isentos, mostra "Isento"
                                        if ($modo === 'parcial' && in_array($campo_key, $campos_isentos_parcial)) {
                                            echo 'Isento';
                                        } else {
                                            // Senão, mostra o valor normal com R$
                                            if (isset($valores[$regiao][$campo_key]) && !empty($valores[$regiao][$campo_key])) {
                                                $valor = $valores[$regiao][$campo_key];
                                                // Adiciona R$ se o valor não começar com ele
                                                if (stripos($valor, 'R$') === false) {
                                                    $valor = 'R$ ' . $valor;
                                                }
                                                echo esc_html($valor);
                                            } else {
                                                echo 'N/A';
                                            }
                                        }
                                        ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * ===== NOVA FUNCIONALIDADE =====
     * Registra variáveis dinâmicas no RankMath
     * Formato: %cidade_tipo_acomodacao_coparticipacao_faixa%
     * Exemplo: %fortaleza_emp_ambulatorialtotal_0%
     */
    public function registrar_variaveis_rankmath() {
        // Verifica se a função rank_math_register_var_replacement existe
        if (!function_exists('rank_math_register_var_replacement')) {
            return;
        }

        // Registra variáveis de data no RankMath: %ano_atual% e %mes_atual%
        rank_math_register_var_replacement(
            'ano_atual',
            array(
                'name'        => 'Ano Atual',
                'description' => 'Retorna o ano atual (ex: ' . date('Y') . ')',
                'variable'    => 'ano_atual',
                'example'     => date('Y'),
            ),
            function() {
                return date('Y');
            }
        );

        $meses_pt = array(
            1  => 'Janeiro',
            2  => 'Fevereiro',
            3  => 'Março',
            4  => 'Abril',
            5  => 'Maio',
            6  => 'Junho',
            7  => 'Julho',
            8  => 'Agosto',
            9  => 'Setembro',
            10 => 'Outubro',
            11 => 'Novembro',
            12 => 'Dezembro'
        );

        rank_math_register_var_replacement(
            'mes_atual',
            array(
                'name'        => 'Mês Atual',
                'description' => 'Retorna o mês atual em português (ex: ' . $meses_pt[(int) date('n')] . ')',
                'variable'    => 'mes_atual',
                'example'     => $meses_pt[(int) date('n')],
            ),
            function() use ($meses_pt) {
                return $meses_pt[(int) date('n')];
            }
        );

        $cidades = $this->obter_todas_cidades_global();

        if (empty($cidades)) {
            return;
        }

        // Lista de tipos de planos
        $tipos = array('empresarial', 'individual', 'pme', 'adesao');
        
        // Lista de acomodações
        $acomodacoes = array('ambulatorial', 'enfermaria', 'apartamento');
        
        // Lista de coparticipações
        $coparticipacoes = array('total', 'parcial');
        
        // Mapeia siglas para tipos
        $siglas = array(
            'empresarial' => 'emp',
            'individual' => 'ind',
            'pme' => 'pme',
            'adesao' => 'ade'
        );
        
        foreach ($cidades as $cidade) {
            if (!isset($cidade['shortcode']) || empty($cidade['shortcode'])) {
                continue;
            }
            
            $cidade_slug = $cidade['shortcode'];
            
            // Para cada combinação de tipo/acomodação/coparticipação
            foreach ($tipos as $tipo) {
                // Verifica se este tipo está ativo
                if (!isset($cidade['tipos_planos_ativos'][$tipo]) || !$cidade['tipos_planos_ativos'][$tipo]) {
                    continue;
                }
                
                $tipo_sigla = $siglas[$tipo];
                
                foreach ($acomodacoes as $acomodacao) {
                    $campo_ativo = $tipo . '_' . $acomodacao . '_ativo';
                    
                    // Verifica se esta acomodação está ativa
                    if (!isset($cidade[$campo_ativo]) || !$cidade[$campo_ativo]) {
                        continue;
                    }
                    
                    foreach ($coparticipacoes as $coparticipacao) {
                        $campo = $tipo . '_' . $acomodacao . '_' . $coparticipacao;
                        
                        // Verifica se existe dados para este campo
                        if (!isset($cidade[$campo]) || empty($cidade[$campo])) {
                            continue;
                        }
                        
                        // DISPOSITIVO ANTI-SOBRECARGA: registra a variável do RankMath
                        // apenas para a 1ª, 2ª e última faixa (mesmo critério dos
                        // shortcodes), evitando milhares de registros e estouro de memória.
                        $faixas_permitidas = array_flip($this->indices_faixas_registrar(count($cidade[$campo])));

                        // Registra variável para cada faixa etária (limitada)
                        foreach ($cidade[$campo] as $faixa_index => $faixa_data) {
                            if (!isset($faixas_permitidas[$faixa_index])) {
                                continue;
                            }
                            // Nome da variável (sem colchetes, igual ao shortcode)
                            $var_name = $cidade_slug . '_' . $tipo_sigla . '_' . $acomodacao . $coparticipacao . '_' . $faixa_index;
                            
                            // Registra a variável no RankMath
                            rank_math_register_var_replacement(
                                $var_name,
                                array(
                                    'name'        => 'Preço: ' . ucfirst($cidade_slug) . ' - ' . strtoupper($tipo_sigla) . ' ' . ucfirst($acomodacao) . ' ' . ucfirst($coparticipacao) . ' (Faixa ' . $faixa_index . ')',
                                    'description' => 'Valor do plano ' . $tipo . ' ' . $acomodacao . ' com coparticipação ' . $coparticipacao . ' para a faixa etária ' . $faixa_index . ' em ' . ucfirst($cidade_slug),
                                    'variable'    => $var_name,
                                    'example'     => 'R$ 99,81',
                                ),
                                function() use ($cidade_slug, $tipo, $acomodacao, $coparticipacao, $faixa_index) {
                                    // Chama a função que retorna o valor formatado
                                    return $this->obter_valor_variavel_rankmath($cidade_slug, $tipo, $acomodacao, $coparticipacao, $faixa_index);
                                }
                            );
                        }
                    }
                }
            }
        }
    }
    
    /**
     * ===== NOVA FUNCIONALIDADE =====
     * Obtém o valor formatado para uma variável do RankMath
     */
    private function obter_valor_variavel_rankmath($cidade_slug, $tipo, $acomodacao, $coparticipacao, $faixa) {
        // Busca a cidade em todas as operadoras (shortcodes são globalmente únicos)
        $cidade_encontrada = $this->obter_cidade_por_shortcode($cidade_slug);

        if (!$cidade_encontrada) {
            return 'N/A';
        }
        
        $campo = $tipo . '_' . $acomodacao . '_' . $coparticipacao;
        
        if (!isset($cidade_encontrada[$campo]) || empty($cidade_encontrada[$campo])) {
            return 'N/A';
        }
        
        if (!isset($cidade_encontrada[$campo][$faixa])) {
            return 'N/A';
        }
        
        $valor = $cidade_encontrada[$campo][$faixa]['valor'];
        
        // Aplica desconto se houver
        $desconto = $this->obter_desconto_tipo($cidade_encontrada, $tipo);
        
        // Remove formatação e converte para número
        $preco_limpo = str_replace(array('R$', ' ', '.'), '', $valor);
        $preco_limpo = str_replace(',', '.', $preco_limpo);
        $preco_numerico = floatval($preco_limpo);
        
        // Aplica desconto se houver
        if ($desconto > 0) {
            $multiplicador = 1 - ($desconto / 100);
            $preco_com_desconto = $preco_numerico * $multiplicador;
            
            // Retorna formatado com vírgula (padrão brasileiro)
            return 'R$ ' . number_format($preco_com_desconto, 2, ',', '.');
        }
        
        // Retorna formatado com vírgula (padrão brasileiro)
        return 'R$ ' . number_format($preco_numerico, 2, ',', '.');
    }
    
    
    /**
     * Adiciona menu na área administrativa
     */
    public function adicionar_menu_admin() {
        add_menu_page(
            'Preços de Planos',
            'Preços de Planos',
            'manage_options',
            'gerenciador-precos-planos',
            array($this, 'pagina_admin'),
            'dashicons-money-alt',
            30
        );
    }
    
    // ===== SCHEMA/STRUCTURED DATA =====

    /**
     * Processa shortcodes dentro dos schemas do RankMath
     * Intercepta o JSON-LD antes de ser renderizado e substitui shortcodes pelos valores reais
     */
    public function processar_shortcodes_schema_rankmath($data, $jsonld) {
        // Percorre recursivamente todos os valores do schema
        return $this->processar_shortcodes_recursivo($data);
    }

    /**
     * Percorre recursivamente um array e processa shortcodes em strings
     */
    private function processar_shortcodes_recursivo($data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->processar_shortcodes_recursivo($value);
            }
        } elseif (is_string($data) && strpos($data, '[') !== false) {
            // Registro sob demanda: garante que as cidades citadas no schema
            // estejam registradas antes de processar os shortcodes.
            $this->escanear_e_registrar($data);
            $data = do_shortcode($data);
        }
        return $data;
    }

    /**
     * Adiciona meta box com textarea para schema em posts e páginas
     */
    public function adicionar_metabox_schema() {
        $post_types = array('post', 'page');
        foreach ($post_types as $post_type) {
            add_meta_box(
                'gpp_schema_markup',
                'Schema Markup (Structured Data)',
                array($this, 'renderizar_metabox_schema'),
                $post_type,
                'normal',
                'low'
            );
        }
    }

    /**
     * Renderiza o conteúdo da meta box de schema
     */
    public function renderizar_metabox_schema($post) {
        $schema_content = get_post_meta($post->ID, '_gpp_schema_markup', true);
        wp_nonce_field('gpp_schema_nonce_action', 'gpp_schema_nonce');
        ?>
        <div style="margin-bottom: 10px;">
            <p style="margin-bottom: 10px;">
                <strong>Cole aqui o script de Schema (JSON-LD) com shortcodes de preços e coparticipação.</strong><br>
                Os shortcodes serão processados automaticamente no frontend.
            </p>
            <p style="color: #666; font-size: 12px; margin-bottom: 10px;">
                <strong>Exemplos de shortcodes disponíveis:</strong><br>
                Preços: <code>[fortaleza_menorvalor]</code> <code>[fortaleza_maiorvalor]</code> <code>[fortaleza_menortabela]</code> <code>[fortaleza_ind_ambulatorialtotal_0]</code><br>
                Coparticipação: <code>[sp_bh_consultas_eletivas]</code> <code>[demais_capitais_exames_simples]</code>
            </p>
            <textarea
                id="gpp_schema_markup"
                name="gpp_schema_markup"
                rows="15"
                style="width: 100%; font-family: monospace; font-size: 13px;"
                placeholder='Exemplo:
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Product",
  "name": "Plano de Saúde Hapvida",
  "offers": {
    "@type": "Offer",
    "price": "[fortaleza_menorvalor]",
    "priceCurrency": "BRL"
  }
}
</script>'
            ><?php echo esc_textarea($schema_content); ?></textarea>
        </div>
        <?php
    }

    /**
     * Salva o conteúdo da meta box de schema
     */
    public function salvar_metabox_schema($post_id) {
        // Verifica nonce
        if (!isset($_POST['gpp_schema_nonce']) || !wp_verify_nonce($_POST['gpp_schema_nonce'], 'gpp_schema_nonce_action')) {
            return;
        }

        // Verifica autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Verifica permissões
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (isset($_POST['gpp_schema_markup'])) {
            // Salva sem sanitizar para preservar tags <script> e JSON
            update_post_meta($post_id, '_gpp_schema_markup', wp_unslash($_POST['gpp_schema_markup']));
        }
    }

    /**
     * Renderiza o schema no frontend processando shortcodes
     */
    public function renderizar_schema_frontend() {
        // Só processa em posts/páginas individuais no frontend
        if (is_admin() || !is_singular()) {
            return;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return;
        }

        $schema_content = get_post_meta($post_id, '_gpp_schema_markup', true);

        if (empty($schema_content)) {
            return;
        }

        // Registro sob demanda: registra as cidades citadas no schema antes.
        $this->escanear_e_registrar($schema_content);

        // Processa os shortcodes dentro do conteúdo do schema
        $schema_processado = do_shortcode($schema_content);

        // Limpa valores formatados para uso em JSON (remove R$ e formata para número)
        // Isso é útil quando o shortcode retorna "R$ 99,81" mas o JSON precisa de "99.81"
        // O usuário pode escolher usar os valores como estão ou usar o formato numérico

        // Exibe o schema processado
        echo "\n<!-- GPP Schema Markup -->\n";
        echo $schema_processado;
        echo "\n<!-- /GPP Schema Markup -->\n";
    }

    /**
 * Registra shortcodes dinamicamente para cada cidade
 */
public function registrar_shortcodes() {
    $cidades = $this->obter_todas_cidades_global();

    if (!empty($cidades)) {
        foreach ($cidades as $cidade_data) {
            // Operadoras "simples" têm seus próprios shortcodes em
            // registrar_shortcodes_simples(); não registram tipos/menor/maior aqui.
            if (isset($cidade_data['operadora']) && $this->operadora_e_simples($cidade_data['operadora'])) {
                continue;
            }

            // Registro sob demanda: só registra os slugs filtrados (se houver filtro).
            if (!$this->slug_permitido($this->obter_slug_base_cidade($cidade_data))) {
                continue;
            }

            $shortcode_base = $cidade_data['shortcode'];

            $tipos_plano = array('empresarial', 'individual', 'pme', 'adesao');
            
            foreach ($tipos_plano as $tipo) {
                // Verifica se este tipo está ativo
                if (isset($cidade_data['tipos_planos_ativos'][$tipo]) && $cidade_data['tipos_planos_ativos'][$tipo]) {
                    
                    // SHORTCODE COMPLETO (ambas coparticipações) - Com disclaimers
                    $shortcode = $shortcode_base . '_' . $tipo;
                    add_shortcode($shortcode, function($atts) use ($shortcode_base, $tipo) {
                        $c = $this->obter_cidade_por_shortcode($shortcode_base);
                        if ($c) {
                            return $this->renderizar_tabela_cidade($c, $tipo, true, 'AMBAS');
                        }
                        return '';
                    });

                    // SHORTCODE COMPLETO (ambas coparticipações) - Sem disclaimers
                    $shortcode_sd = $shortcode_base . '_' . $tipo . '_sd';
                    add_shortcode($shortcode_sd, function($atts) use ($shortcode_base, $tipo) {
                        $c = $this->obter_cidade_por_shortcode($shortcode_base);
                        if ($c) {
                            return $this->renderizar_tabela_cidade($c, $tipo, false, 'AMBAS');
                        }
                        return '';
                    });

                    // SHORTCODE APENAS TOTAL - Com disclaimers
                    $shortcode_total = $shortcode_base . '_' . $tipo . '_total';
                    add_shortcode($shortcode_total, function($atts) use ($shortcode_base, $tipo) {
                        $c = $this->obter_cidade_por_shortcode($shortcode_base);
                        if ($c) {
                            return $this->renderizar_tabela_cidade($c, $tipo, true, 'SOMENTE_TOTAL');
                        }
                        return '';
                    });

                    // SHORTCODE APENAS TOTAL - Sem disclaimers
                    $shortcode_total_sd = $shortcode_base . '_' . $tipo . '_total_sd';
                    add_shortcode($shortcode_total_sd, function($atts) use ($shortcode_base, $tipo) {
                        $c = $this->obter_cidade_por_shortcode($shortcode_base);
                        if ($c) {
                            return $this->renderizar_tabela_cidade($c, $tipo, false, 'SOMENTE_TOTAL');
                        }
                        return '';
                    });

                    // SHORTCODE APENAS PARCIAL - Com disclaimers
                    $shortcode_parcial = $shortcode_base . '_' . $tipo . '_parcial';
                    add_shortcode($shortcode_parcial, function($atts) use ($shortcode_base, $tipo) {
                        $c = $this->obter_cidade_por_shortcode($shortcode_base);
                        if ($c) {
                            return $this->renderizar_tabela_cidade($c, $tipo, true, 'SOMENTE_PARCIAL');
                        }
                        return '';
                    });

                    // SHORTCODE APENAS PARCIAL - Sem disclaimers
                    $shortcode_parcial_sd = $shortcode_base . '_' . $tipo . '_parcial_sd';
                    add_shortcode($shortcode_parcial_sd, function($atts) use ($shortcode_base, $tipo) {
                        $c = $this->obter_cidade_por_shortcode($shortcode_base);
                        if ($c) {
                            return $this->renderizar_tabela_cidade($c, $tipo, false, 'SOMENTE_PARCIAL');
                        }
                        return '';
                    });
                }
            }

            // ===== SHORTCODE DE MENOR VALOR DA CIDADE =====
            // Exemplo: [fortaleza_menorvalor] → retorna o menor preço entre todos os planos
            $shortcode_menor = $shortcode_base . '_menorvalor';
            $shortcode_base_menor = $shortcode_base;

            add_shortcode($shortcode_menor, function() use ($shortcode_base_menor) {
                $c = $this->obter_cidade_por_shortcode($shortcode_base_menor);
                if ($c) {
                    $menor = $this->encontrar_menor_valor_cidade($c);
                    if ($menor && !empty($menor['valor'])) {
                        return $menor['valor'];
                    }
                }
                return 'N/A';
            });

            // ===== SHORTCODE DE MENOR TABELA COMPLETA DA CIDADE =====
            // Exemplo: [fortaleza_menortabela] → retorna a tabela completa do plano com menor preço
            $shortcode_menor_tabela = $shortcode_base . '_menortabela';
            $shortcode_base_menor_tabela = $shortcode_base;

            add_shortcode($shortcode_menor_tabela, function() use ($shortcode_base_menor_tabela) {
                $c = $this->obter_cidade_por_shortcode($shortcode_base_menor_tabela);
                if ($c) {
                    $menor = $this->encontrar_menor_valor_cidade($c);
                    if ($menor && !empty($menor['tipo_plano'])) {
                        $filtro = ($menor['coparticipacao'] === 'total') ? 'SOMENTE_TOTAL' : 'SOMENTE_PARCIAL';
                        return $this->renderizar_tabela_cidade($c, $menor['tipo_plano'], false, $filtro);
                    }
                }
                return '';
            });

            // ===== SHORTCODE DE MAIOR VALOR DA CIDADE =====
            // Exemplo: [fortaleza_maiorvalor] → retorna o maior preço entre todos os planos
            $shortcode_maior = $shortcode_base . '_maiorvalor';
            $shortcode_base_maior = $shortcode_base;

            add_shortcode($shortcode_maior, function() use ($shortcode_base_maior) {
                $c = $this->obter_cidade_por_shortcode($shortcode_base_maior);
                if ($c) {
                    $maior = $this->encontrar_maior_valor_cidade($c);
                    if ($maior && !empty($maior['valor'])) {
                        return $maior['valor'];
                    }
                }
                return 'N/A';
            });
        }
    }
}

    /**
     * Calcula o "slug base" de uma cidade (sem o prefixo da operadora).
     * Ex.: cidade da Unimed com shortcode "unimed_fortaleza" => "fortaleza".
     */
    private function obter_slug_base_cidade($cidade) {
        $shortcode = isset($cidade['shortcode']) ? $cidade['shortcode'] : '';
        $operadora = isset($cidade['operadora']) ? $cidade['operadora'] : 'hapvida';
        $prefixo = isset($this->operadoras[$operadora]) ? $this->operadoras[$operadora]['prefixo'] : '';
        if ($prefixo !== '' && strpos($shortcode, $prefixo) === 0) {
            return substr($shortcode, strlen($prefixo));
        }
        return $shortcode;
    }

    /**
     * Verifica se uma cidade tem dados cadastrados para um tipo de plano,
     * considerando o filtro de coparticipação (AMBAS / SOMENTE_TOTAL / SOMENTE_PARCIAL).
     */
    private function cidade_tem_dados_tipo($cidade, $tipo, $filtro_coparticipacao = 'AMBAS') {
        if (!isset($cidade['tipos_planos_ativos'][$tipo]) || !$cidade['tipos_planos_ativos'][$tipo]) {
            return false;
        }

        $acomodacoes = array('ambulatorial', 'enfermaria', 'apartamento');

        foreach ($acomodacoes as $acom) {
            $campo_ativo = $tipo . '_' . $acom . '_ativo';
            if (empty($cidade[$campo_ativo])) {
                continue;
            }

            if ($filtro_coparticipacao === 'AMBAS' || $filtro_coparticipacao === 'SOMENTE_TOTAL') {
                if (!empty($cidade[$tipo . '_' . $acom . '_total'])) {
                    return true;
                }
            }
            if ($filtro_coparticipacao === 'AMBAS' || $filtro_coparticipacao === 'SOMENTE_PARCIAL') {
                if (!empty($cidade[$tipo . '_' . $acom . '_parcial'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Registra os shortcodes de COMPARAÇÃO entre operadoras para uma mesma cidade.
     * Formato: [comparar_{cidade}_{tipo}], [comparar_{cidade}_{tipo}_total],
     *          [comparar_{cidade}_{tipo}_parcial]
     * Ex.: [comparar_fortaleza_empresarial_total]
     */
    public function registrar_shortcodes_comparar() {
        $todas = $this->obter_todas_cidades_global();
        if (empty($todas)) {
            return;
        }

        $tipos_plano = array('empresarial', 'individual', 'pme', 'adesao');

        // slug_base => lista de tipos (apenas operadoras COMPLETAS, p/ comparar por tipo)
        $mapa_tipos = array();
        // todos os slugs existentes em qualquer operadora (p/ comparação sem tipo)
        $slugs = array();

        foreach ($todas as $cidade) {
            $slug_base = $this->obter_slug_base_cidade($cidade);
            if ($slug_base === '') {
                continue;
            }
            // Registro sob demanda: só os slugs filtrados.
            if (!$this->slug_permitido($slug_base)) {
                continue;
            }
            $slugs[$slug_base] = true;

            // Operadoras simples não têm "tipo"; só as completas alimentam o mapa de tipos
            if (!$this->operadora_e_simples($cidade['operadora'])) {
                if (!isset($mapa_tipos[$slug_base])) {
                    $mapa_tipos[$slug_base] = array();
                }
                foreach ($tipos_plano as $tipo) {
                    if ($this->cidade_tem_dados_tipo($cidade, $tipo, 'AMBAS')) {
                        $mapa_tipos[$slug_base][$tipo] = true;
                    }
                }
            }
        }

        // Comparação SEM tipo: [comparar_CIDADE] — funciona mesmo se a cidade só
        // existir em operadoras simples (operadora completa mostra o plano mais barato)
        foreach (array_keys($slugs) as $slug_base) {
            $slug_local = $slug_base;
            add_shortcode('comparar_' . $slug_base, function() use ($slug_local) {
                return $this->renderizar_comparacao_operadoras($slug_local, '', 'AMBAS');
            });
        }

        // Comparação POR tipo: [comparar_CIDADE_TIPO(_total|_parcial)]
        foreach ($mapa_tipos as $slug_base => $tipos_existentes) {
            foreach (array_keys($tipos_existentes) as $tipo) {
                $variantes = array(
                    ''         => 'AMBAS',
                    '_total'   => 'SOMENTE_TOTAL',
                    '_parcial' => 'SOMENTE_PARCIAL',
                );

                foreach ($variantes as $sufixo => $filtro) {
                    $shortcode_name = 'comparar_' . $slug_base . '_' . $tipo . $sufixo;
                    $slug_local = $slug_base;
                    $tipo_local = $tipo;
                    $filtro_local = $filtro;

                    add_shortcode($shortcode_name, function() use ($slug_local, $tipo_local, $filtro_local) {
                        return $this->renderizar_comparacao_operadoras($slug_local, $tipo_local, $filtro_local);
                    });
                }
            }
        }
    }

    /**
     * Renderiza a comparação entre operadoras (cards lado a lado / empilhados)
     * para uma mesma cidade. Operadoras simples mostram sua tabela única;
     * operadoras completas mostram o tipo pedido (ou o plano mais barato, se
     * $tipo_plano estiver vazio).
     */
    public function renderizar_comparacao_operadoras($slug_base, $tipo_plano = '', $filtro_coparticipacao = 'AMBAS') {
        $cards = array();

        // Percorre as operadoras na ordem da configuração
        foreach ($this->operadoras as $op_key => $op_info) {
            $shortcode_alvo = $op_info['prefixo'] . $slug_base;

            $cidade_encontrada = null;
            foreach ($this->obter_todas_cidades($op_key) as $cidade) {
                if (isset($cidade['shortcode']) && $cidade['shortcode'] === $shortcode_alvo) {
                    $cidade['operadora'] = $op_key;
                    $cidade_encontrada = $cidade;
                    break;
                }
            }

            if (!$cidade_encontrada) {
                continue;
            }

            $tabela_html = '';

            if ($this->operadora_e_simples($op_key)) {
                // Operadora simples: sempre a tabela única (ignora tipo/coparticipação)
                if (empty($cidade_encontrada['tabela_simples'])) {
                    continue;
                }
                $tabela_html = $this->renderizar_tabela_simples($cidade_encontrada, false);
            } elseif ($tipo_plano === '') {
                // Operadora completa, comparação sem tipo: usa o plano mais barato
                $menor = $this->encontrar_menor_valor_cidade($cidade_encontrada);
                if (empty($menor['tipo_plano'])) {
                    continue;
                }
                $filtro = ($menor['coparticipacao'] === 'total') ? 'SOMENTE_TOTAL' : 'SOMENTE_PARCIAL';
                $tabela_html = $this->renderizar_tabela_cidade($cidade_encontrada, $menor['tipo_plano'], false, $filtro);
            } else {
                // Operadora completa, comparação por tipo
                if (!$this->cidade_tem_dados_tipo($cidade_encontrada, $tipo_plano, $filtro_coparticipacao)) {
                    continue;
                }
                $tabela_html = $this->renderizar_tabela_cidade($cidade_encontrada, $tipo_plano, false, $filtro_coparticipacao);
            }

            if ($tabela_html === '') {
                continue;
            }

            ob_start();
            ?>
            <div class="gpp-card-operadora gpp-op-<?php echo esc_attr($op_key); ?>">
                <div class="gpp-card-header" style="background-color: <?php echo esc_attr($op_info['cor']); ?>;">
                    <?php echo esc_html($op_info['nome']); ?>
                </div>
                <?php echo $tabela_html; ?>
            </div>
            <?php
            $cards[] = ob_get_clean();
        }

        if (empty($cards)) {
            return '';
        }

        return '<div class="gpp-comparacao-operadoras">' . implode('', $cards) . '</div>';
    }

    /**
     * Desconto aplicável a uma cidade em modo simples (usa o desconto global).
     */
    private function obter_desconto_simples($cidade_data) {
        return $this->obter_desconto_tipo($cidade_data, 'simples');
    }

    /**
     * Retorna o menor/maior valor (formatado, com desconto) da tabela simples.
     */
    private function obter_extremo_tabela_simples($cidade_data, $modo = 'menor') {
        $tabela = (isset($cidade_data['tabela_simples']) && is_array($cidade_data['tabela_simples'])) ? $cidade_data['tabela_simples'] : array();
        if (empty($tabela)) {
            return null;
        }

        $desconto = $this->obter_desconto_simples($cidade_data);
        $melhor_num = null;
        $melhor_valor = null;

        foreach ($tabela as $linha) {
            if (!isset($linha['valor'])) {
                continue;
            }
            $limpo = str_replace(array('R$', ' ', '.'), '', $linha['valor']);
            $limpo = str_replace(',', '.', $limpo);
            $num = floatval($limpo);
            if ($desconto > 0) {
                $num = $num * (1 - ($desconto / 100));
            }

            if ($melhor_num === null
                || ($modo === 'menor' && $num < $melhor_num)
                || ($modo === 'maior' && $num > $melhor_num)) {
                $melhor_num = $num;
                $melhor_valor = $this->formatar_preco_com_desconto($linha['valor'], $desconto);
            }
        }

        return $melhor_valor;
    }

    /**
     * Renderiza a tabela ÚNICA (modo simples) de uma cidade.
     */
    public function renderizar_tabela_simples($cidade_data, $mostrar_disclaimers = true) {
        $operadora_key = isset($cidade_data['operadora']) ? $cidade_data['operadora'] : 'hapvida';
        $operadora_cfg = $this->obter_config_operadora($operadora_key);
        $desconto = $this->obter_desconto_simples($cidade_data);
        $tabela = (isset($cidade_data['tabela_simples']) && is_array($cidade_data['tabela_simples'])) ? $cidade_data['tabela_simples'] : array();

        if (empty($tabela)) {
            return '';
        }

        ob_start();
        ?>
        <div class="gpp-container-cidade gpp-op-<?php echo esc_attr($operadora_key); ?>">
            <div class="tabela-precos-hapvida">
                <table>
                    <thead>
                        <tr>
                            <th>Faixa Etária</th>
                            <th>Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tabela as $linha): ?>
                            <tr>
                                <td><?php echo esc_html($linha['faixa_etaria']); ?></td>
                                <td>
                                    <span class="valor-destaque">
                                        <?php echo $this->formatar_preco_com_desconto($linha['valor'], $desconto); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($desconto > 0 && !$mostrar_disclaimers): ?>
                <p class="gpp-desconto-pequeno">* Valores com <?php echo number_format($desconto, 0); ?>% de desconto já aplicado</p>
            <?php endif; ?>

            <?php if ($mostrar_disclaimers): ?>
                <?php if ($desconto > 0): ?>
                    <p class="gpp-desconto-info"><strong>* Valores com <?php echo number_format($desconto, 0); ?>% de desconto aplicado</strong></p>
                <?php endif; ?>

                <div class="gpp-observacoes-info">
                    <strong>ℹ️ Observações Importantes:</strong><br>
                    Os valores apresentados referem-se ao plano <?php echo esc_html($operadora_cfg['nome']); ?>, podendo sofrer alterações ou reajustes a qualquer momento, sem aviso prévio. <br><br>Para obter uma cotação completa — incluindo OUTRAS CIDADES, coberturas e eventuais promoções vigentes — clique no botão abaixo.
                </div>

                <div class="gpp-botao-container">
                    <a href="#" class="gpp-botao-consulta acao-abrir-popup">
                        Consulte as promoções de hoje
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * DISPOSITIVO ANTI-SOBRECARGA: dada a quantidade de faixas, devolve apenas
     * os índices que viram shortcode individual — primeira (0), segunda (1) e
     * última (total-1). Evita registrar dezenas de shortcodes por cidade e
     * quebrar o site (mesmo critério já usado nas variáveis da Hapvida).
     */
    private function indices_faixas_registrar($total) {
        $total = (int) $total;
        $indices = array();
        if ($total <= 0) {
            return $indices;
        }
        $indices[0] = true;            // primeira
        if ($total > 1) {
            $indices[1] = true;        // segunda
        }
        $indices[$total - 1] = true;   // última
        return array_keys($indices);
    }

    /**
     * Registra os shortcodes das operadoras em modo "simples" (uma tabela por cidade).
     * Ex. (Unimed): [unimed_fortaleza], [unimed_fortaleza_sd],
     *               [unimed_fortaleza_menorvalor], [unimed_fortaleza_maiorvalor],
     *               [unimed_fortaleza_0], [unimed_fortaleza_1], ...
     */
    public function registrar_shortcodes_simples() {
        foreach ($this->operadoras as $op_key => $op_info) {
            if (empty($op_info['simples'])) {
                continue;
            }

            foreach ($this->obter_todas_cidades($op_key) as $cidade) {
                if (empty($cidade['shortcode'])) {
                    continue;
                }
                $cidade['operadora'] = $op_key;

                // Registro sob demanda: respeita o filtro de slugs.
                if (!$this->slug_permitido($this->obter_slug_base_cidade($cidade))) {
                    continue;
                }

                $base = $cidade['shortcode'];

                // Tabela COM disclaimers
                add_shortcode($base, function() use ($base) {
                    $c = $this->obter_cidade_por_shortcode($base);
                    return $c ? $this->renderizar_tabela_simples($c, true) : '';
                });

                // Tabela SEM disclaimers
                add_shortcode($base . '_sd', function() use ($base) {
                    $c = $this->obter_cidade_por_shortcode($base);
                    return $c ? $this->renderizar_tabela_simples($c, false) : '';
                });

                // Menor valor
                add_shortcode($base . '_menorvalor', function() use ($base) {
                    $c = $this->obter_cidade_por_shortcode($base);
                    $r = $c ? $this->obter_extremo_tabela_simples($c, 'menor') : null;
                    return ($r !== null) ? $r : 'N/A';
                });

                // Maior valor
                add_shortcode($base . '_maiorvalor', function() use ($base) {
                    $c = $this->obter_cidade_por_shortcode($base);
                    $r = $c ? $this->obter_extremo_tabela_simples($c, 'maior') : null;
                    return ($r !== null) ? $r : 'N/A';
                });

                // Valor de cada faixa: [base_0], [base_1], [base_<última>]
                // DISPOSITIVO ANTI-SOBRECARGA: registra só primeira, segunda e
                // última faixa (igual à Hapvida), evitando quebrar o site com
                // muitos shortcodes. A tabela completa continua em [base].
                $tabela = (isset($cidade['tabela_simples']) && is_array($cidade['tabela_simples'])) ? $cidade['tabela_simples'] : array();
                foreach ($this->indices_faixas_registrar(count($tabela)) as $idx_local) {
                    add_shortcode($base . '_' . $idx_local, function() use ($base, $idx_local) {
                        $c = $this->obter_cidade_por_shortcode($base);
                        if ($c && isset($c['tabela_simples'][$idx_local]['valor'])) {
                            return $this->formatar_preco_com_desconto($c['tabela_simples'][$idx_local]['valor'], $this->obter_desconto_simples($c));
                        }
                        return 'N/A';
                    });
                }
            }
        }
    }

    /**
     * ===== TABELA COMPARATIVA DE COTAÇÃO (FAMÍLIA) =====
     * Registra o shortcode [tabela_comparativa cidade="fortaleza"] que cota o
     * valor de uma família nas 4 operadoras e monta uma tabela comparativa.
     *
     * Atributos:
     *   cidade          (obrigatório) slug da cidade SEM prefixo (ex.: fortaleza)
     *   idades          (opcional)    idades separadas por vírgula. Default "35,35,5,8"
     *   tipo            (opcional)    força um tipo de plano da Hapvida (empresarial, ...)
     *   acomodacao      (opcional)    força acomodação da Hapvida (ambulatorial, ...)
     *   coparticipacao  (opcional)    força total|parcial da Hapvida
     *   titulo_economia (opcional)    rótulo da última coluna. Default "Economia vs Hapvida"
     */
    public function registrar_shortcode_comparativo() {
        add_shortcode('tabela_comparativa', array($this, 'render_tabela_comparativa'));
    }

    /**
     * Verifica se uma faixa etária (texto) inclui uma idade.
     * Aceita "0 a 18 anos", "59 anos ou mais", "59+", "34 a 38", etc.
     */
    private function faixa_inclui_idade($faixa_etaria, $idade) {
        if (!preg_match_all('/\d+/', $faixa_etaria, $m) || empty($m[0])) {
            return false;
        }
        $nums = array_map('intval', $m[0]);

        if (count($nums) >= 2) {
            return ($idade >= $nums[0] && $idade <= $nums[1]);
        }

        // Apenas um número: "X ou mais" / "X+" => X até infinito; senão idade exata
        $lower = function_exists('mb_strtolower') ? mb_strtolower($faixa_etaria) : strtolower($faixa_etaria);
        if (strpos($lower, 'mais') !== false || strpos($lower, 'acima') !== false || strpos($faixa_etaria, '+') !== false) {
            return ($idade >= $nums[0]);
        }
        return ($idade === $nums[0]);
    }

    /**
     * Converte "R$ 1.234,56" para número (float) aplicando desconto.
     */
    private function valor_para_numero_com_desconto($valor_str, $desconto) {
        $limpo = str_replace(array('R$', ' ', '.'), '', (string) $valor_str);
        $limpo = str_replace(',', '.', $limpo);
        $num = floatval($limpo);
        if ($desconto > 0) {
            $num = $num * (1 - ($desconto / 100));
        }
        return $num;
    }

    /**
     * Soma o valor mensal de uma família (lista de idades) numa tabela de faixas.
     * Retorna null se alguma idade não tiver faixa correspondente.
     */
    private function calcular_total_familia($faixas, $idades, $desconto) {
        if (empty($faixas) || empty($idades)) {
            return null;
        }
        $total = 0;
        foreach ($idades as $idade) {
            $achou = false;
            foreach ($faixas as $f) {
                if (!isset($f['faixa_etaria']) || !isset($f['valor'])) {
                    continue;
                }
                if ($this->faixa_inclui_idade($f['faixa_etaria'], $idade)) {
                    $total += $this->valor_para_numero_com_desconto($f['valor'], $desconto);
                    $achou = true;
                    break;
                }
            }
            if (!$achou) {
                return null;
            }
        }
        return $total;
    }

    /**
     * Para a Hapvida (modo completo), encontra o MENOR total de família entre
     * todos os planos disponíveis (ou os restritos pelos atributos).
     */
    private function calcular_melhor_total_hapvida($cidade, $idades, $atts) {
        $tipos   = array('empresarial', 'individual', 'pme', 'adesao');
        $acoms   = array('ambulatorial', 'enfermaria', 'apartamento');
        $coparts = array('total', 'parcial');

        if (!empty($atts['tipo']))           { $tipos   = array(sanitize_key($atts['tipo'])); }
        if (!empty($atts['acomodacao']))     { $acoms   = array(sanitize_key($atts['acomodacao'])); }
        if (!empty($atts['coparticipacao'])) { $coparts = array(sanitize_key($atts['coparticipacao'])); }

        $melhor = null;
        foreach ($tipos as $tipo) {
            if (empty($cidade['tipos_planos_ativos'][$tipo])) {
                continue;
            }
            $desconto = $this->obter_desconto_tipo($cidade, $tipo);
            foreach ($acoms as $acom) {
                if (empty($cidade[$tipo . '_' . $acom . '_ativo'])) {
                    continue;
                }
                foreach ($coparts as $copart) {
                    $campo = $tipo . '_' . $acom . '_' . $copart;
                    if (empty($cidade[$campo])) {
                        continue;
                    }
                    $total = $this->calcular_total_familia($cidade[$campo], $idades, $desconto);
                    if ($total !== null && ($melhor === null || $total < $melhor)) {
                        $melhor = $total;
                    }
                }
            }
        }
        return $melhor;
    }

    /**
     * Formata número como moeda brasileira (R$ 1.234,56).
     */
    private function formatar_moeda($num) {
        return 'R$ ' . number_format($num, 2, ',', '.');
    }

    /**
     * Renderiza a tabela comparativa de cotação familiar entre as operadoras.
     */
    public function render_tabela_comparativa($atts) {
        $atts = shortcode_atts(array(
            'cidade'          => '',
            'idades'          => '35,35,5,8',
            'tipo'            => '',
            'acomodacao'      => '',
            'coparticipacao'  => '',
            'titulo_economia' => 'Economia vs Hapvida',
        ), $atts, 'tabela_comparativa');

        $slug = sanitize_title($atts['cidade']);
        if ($slug === '') {
            return '<em>Informe a cidade: [tabela_comparativa cidade="fortaleza"]</em>';
        }

        // Idades da família
        $idades = array();
        foreach (explode(',', $atts['idades']) as $i) {
            $i = trim($i);
            if ($i !== '' && is_numeric($i)) {
                $idades[] = (int) $i;
            }
        }
        if (empty($idades)) {
            $idades = array(35, 35, 5, 8);
        }
        $qtd_pessoas = count($idades);

        // Calcula o total mensal de cada operadora
        $linhas = array();
        foreach ($this->operadoras as $op_key => $op_info) {
            $cidade = $this->obter_cidade_por_shortcode($op_info['prefixo'] . $slug);
            if (!$cidade) {
                continue;
            }
            $cidade['operadora'] = $op_key;

            if ($this->operadora_e_simples($op_key)) {
                $faixas   = (isset($cidade['tabela_simples']) && is_array($cidade['tabela_simples'])) ? $cidade['tabela_simples'] : array();
                $desconto = $this->obter_desconto_simples($cidade);
                $mensal   = $this->calcular_total_familia($faixas, $idades, $desconto);
            } else {
                $mensal = $this->calcular_melhor_total_hapvida($cidade, $idades, $atts);
            }

            if ($mensal === null) {
                continue;
            }

            $linhas[$op_key] = array(
                'nome'   => $op_info['nome'],
                'cor'    => $op_info['cor'],
                'mensal' => $mensal,
                'anual'  => $mensal * 12,
            );
        }

        if (empty($linhas)) {
            return '<em>Não há dados cadastrados para "' . esc_html($slug) . '" nas operadoras.</em>';
        }

        // Hapvida é a base de comparação
        $hapvida_anual = isset($linhas['hapvida']) ? $linhas['hapvida']['anual'] : null;

        // Ordena: Hapvida primeiro, depois as demais (ordem da configuração)
        $ordem = array();
        if (isset($linhas['hapvida'])) {
            $ordem['hapvida'] = $linhas['hapvida'];
        }
        foreach ($linhas as $k => $v) {
            if ($k !== 'hapvida') {
                $ordem[$k] = $v;
            }
        }

        // Descrição da família
        $desc_familia = 'Cotação para ' . $qtd_pessoas . ' vida' . ($qtd_pessoas > 1 ? 's' : '') . ' — idades: ' . implode(', ', $idades) . ' anos.';

        ob_start();
        ?>
        <div style="overflow-x: auto; margin-bottom: 8px; border-radius: 12px; border: 1px solid #e2e8f0;">
        <table style="width: 100%; border-collapse: collapse; min-width: 500px;">
        <thead>
        <tr style="background: linear-gradient(135deg,#1a1a2e,#16213e);">
        <th style="padding: 14px 12px; color: #fff; font-weight: bold; font-size: 14px; text-align: left; border-bottom: 2px solid #ff6b00;">Operadora</th>
        <th style="padding: 14px 12px; color: #fff; font-weight: bold; font-size: 14px; text-align: left; border-bottom: 2px solid #ff6b00;">Mensal (<?php echo (int) $qtd_pessoas; ?> pessoas)</th>
        <th style="padding: 14px 12px; color: #fff; font-weight: bold; font-size: 14px; text-align: left; border-bottom: 2px solid #ff6b00;">Anual</th>
        <th style="padding: 14px 12px; color: #fff; font-weight: bold; font-size: 14px; text-align: left; border-bottom: 2px solid #ff6b00;"><?php echo esc_html($atts['titulo_economia']); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php
        $i = 0;
        $total_linhas = count($ordem);
        foreach ($ordem as $op_key => $dados):
            $ultima = ($i === $total_linhas - 1);
            $borda = $ultima ? '' : 'border-bottom: 1px solid #f1f5f9;';
            $is_hapvida = ($op_key === 'hapvida');

            // Cor de fundo da linha
            if ($is_hapvida) {
                $bg = '#fff8f3';
            } else {
                $bg = ($i % 2 === 0) ? '#fff' : '#f8f9fa';
            }

            // Coluna economia
            if ($is_hapvida || $hapvida_anual === null) {
                $economia_html = '<span style="font-weight: bold; color: #1a202c;">—</span>';
            } else {
                $diff = $dados['anual'] - $hapvida_anual; // > 0 => operadora mais cara que Hapvida
                if ($diff > 0) {
                    $pct = ($dados['anual'] > 0) ? round(($diff / $dados['anual']) * 100) : 0;
                    $economia_html = '<span style="color: #c53030; font-weight: 600;">-' . $this->formatar_moeda($diff) . ' (' . $pct . '%)</span>';
                } elseif ($diff < 0) {
                    $pct = ($hapvida_anual > 0) ? round((abs($diff) / $hapvida_anual) * 100) : 0;
                    $economia_html = '<span style="color: #2f855a; font-weight: 600;">+' . $this->formatar_moeda(abs($diff)) . ' (' . $pct . '%)</span>';
                } else {
                    $economia_html = '<span style="color: #1a202c; font-weight: 600;">R$ 0,00</span>';
                }
            }

            $cor_nome  = $is_hapvida ? '#ff6b00' : '#1a202c';
            $peso_nome = $is_hapvida ? '800' : '600';
            $cor_valor = $is_hapvida ? '#ff6b00' : '#4a5568';
            $peso_valor = $is_hapvida ? 'bold' : 'normal';
        ?>
        <tr style="background: <?php echo $bg; ?>;">
        <td style="padding: 10px 12px; <?php echo $borda; ?> font-size: 14px; font-weight: <?php echo $peso_nome; ?>; color: <?php echo $cor_nome; ?>;"><?php echo esc_html(strtoupper($dados['nome'])); ?></td>
        <td style="padding: 10px 12px; <?php echo $borda; ?> font-size: 14px; font-weight: <?php echo $peso_valor; ?>; color: <?php echo $cor_valor; ?>;"><?php echo esc_html($this->formatar_moeda($dados['mensal'])); ?></td>
        <td style="padding: 10px 12px; <?php echo $borda; ?> font-size: 14px; font-weight: <?php echo $peso_valor; ?>; color: <?php echo $cor_valor; ?>;"><?php echo esc_html($this->formatar_moeda($dados['anual'])); ?></td>
        <td style="padding: 10px 12px; <?php echo $borda; ?> font-size: 14px;"><?php echo $economia_html; ?></td>
        </tr>
        <?php
            $i++;
        endforeach;
        ?>
        </tbody>
        </table>
        </div>
        <p style="font-size: 12px; color: #718096; margin: 0 0 20px 0;"><?php echo esc_html($desc_familia); ?> Valores sujeitos a alteração; consulte condições.</p>
        <?php
        return ob_get_clean();
    }

    /**
 * Renderiza a tabela para uma cidade específica e tipo de plano
 */
/**
 * Renderiza a tabela para uma cidade específica e tipo de plano
 */
private function renderizar_tabela_cidade($cidade_data, $tipo_plano, $mostrar_disclaimers = true, $filtro_coparticipacao = 'AMBAS') {
    ob_start();

    // Identifica a operadora desta cidade (default 'hapvida' p/ retrocompatibilidade)
    $operadora_key = isset($cidade_data['operadora']) ? $cidade_data['operadora'] : 'hapvida';
    $operadora_cfg = $this->obter_config_operadora($operadora_key);
    $operadora_nome = $operadora_cfg['nome'];
    $operadora_url = $operadora_cfg['url_botao'];

    // Obtém desconto específico do tipo de plano
    $desconto = $this->obter_desconto_tipo($cidade_data, $tipo_plano);
    
    // Define nome do tipo de plano
    $nomes_tipos = array(
        'empresarial' => 'empresarial',
        'individual' => 'individual',
        'pme' => 'PME',
        'adesao' => 'por adesão'
    );
    $tipo_plano_nome = isset($nomes_tipos[$tipo_plano]) ? $nomes_tipos[$tipo_plano] : $tipo_plano;
    
    // Define as acomodações
    $acomodacoes = array(
        'ambulatorial' => 'Ambulatorial',
        'enfermaria' => 'Enfermaria',
        'apartamento' => 'Apartamento'
    );
    
    // ===== NOVA LÓGICA: Se filtro específico, renderiza apenas PRIMEIRA acomodação encontrada =====
    $renderizar_apenas_primeira = ($filtro_coparticipacao === 'SOMENTE_TOTAL' || $filtro_coparticipacao === 'SOMENTE_PARCIAL');
    $ja_renderizou = false;
    
    ?>
    <div class="gpp-container-cidade gpp-op-<?php echo esc_attr($operadora_key); ?>">

        <?php
        foreach ($acomodacoes as $acom_key => $acom_nome):
            // Se já renderizou uma acomodação e o filtro é específico, para o loop
            if ($renderizar_apenas_primeira && $ja_renderizou) {
                break;
            }
            
            // Verifica se esta acomodação está ativa
            $campo_ativo_acom = $tipo_plano . '_' . $acom_key . '_ativo';
            
            if (!isset($cidade_data[$campo_ativo_acom]) || !$cidade_data[$campo_ativo_acom]) {
                continue;
            }
            
            // ===== COPARTICIPAÇÃO TOTAL =====
            if ($filtro_coparticipacao === 'AMBAS' || $filtro_coparticipacao === 'SOMENTE_TOTAL') {
                $campo_total = $tipo_plano . '_' . $acom_key . '_total';
                $tem_dados_total = isset($cidade_data[$campo_total]) && !empty($cidade_data[$campo_total]);
                
                if ($tem_dados_total) {
        ?>
            <div class="tabela-precos-hapvida">
                <table>
                    <thead>
                        <tr>
                            <th>Faixa Etária</th>
                            <th>Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cidade_data[$campo_total] as $linha): ?>
                            <tr>
                                <td><?php echo esc_html($linha['faixa_etaria']); ?></td>
                                <td>
                                    <span class="valor-destaque">
                                        <?php echo $this->formatar_preco_com_desconto($linha['valor'], $desconto); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php 
                    $ja_renderizou = true;
                    
                    // Se é filtro específico, já renderizou o que precisa
                    if ($renderizar_apenas_primeira) {
                        break; // Sai do loop de acomodações
                    }
                }
            }
            
            // ===== COPARTICIPAÇÃO PARCIAL =====
            // Só verifica parcial se ainda não renderizou (no caso de filtro específico)
            if (!$ja_renderizou || $filtro_coparticipacao === 'AMBAS') {
                if ($filtro_coparticipacao === 'AMBAS' || $filtro_coparticipacao === 'SOMENTE_PARCIAL') {
                    $campo_parcial = $tipo_plano . '_' . $acom_key . '_parcial';
                    $tem_dados_parcial = isset($cidade_data[$campo_parcial]) && !empty($cidade_data[$campo_parcial]);
                    
                    if ($tem_dados_parcial) {
        ?>
            <div class="tabela-precos-hapvida">
                <table>
                    <thead>
                        <tr>
                            <th>Faixa Etária</th>
                            <th>Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cidade_data[$campo_parcial] as $linha): ?>
                            <tr>
                                <td><?php echo esc_html($linha['faixa_etaria']); ?></td>
                                <td>
                                    <span class="valor-destaque">
                                        <?php echo $this->formatar_preco_com_desconto($linha['valor'], $desconto); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php 
                        $ja_renderizou = true;
                        
                        // Se é filtro específico, já renderizou o que precisa
                        if ($renderizar_apenas_primeira) {
                            break; // Sai do loop de acomodações
                        }
                    }
                }
            }
            
        endforeach; 
        ?>
        
        <?php if ($desconto > 0 && !$mostrar_disclaimers): ?>
            <p class="gpp-desconto-pequeno">* Valores com <?php echo number_format($desconto, 0); ?>% de desconto já aplicado</p>
        <?php endif; ?>
        
        <?php if ($mostrar_disclaimers): ?>
            <?php if ($desconto > 0): ?>
                <p class="gpp-desconto-info"><strong>* Valores com <?php echo number_format($desconto, 0); ?>% de desconto aplicado</strong></p>
            <?php endif; ?>
            
            <div class="gpp-observacoes-info">
                <strong>ℹ️ Observações Importantes:</strong><br>
                Os valores apresentados referem-se somente ao plano <?php echo $tipo_plano_nome; ?> <?php echo esc_html($operadora_nome); ?>, podendo sofrer alterações ou reajustes a qualquer momento, sem aviso prévio. <br><br>Para obter uma cotação completa — incluindo OUTRAS CIDADES, segmentações, acomodações, coberturas e eventuais promoções vigentes — clique no botão abaixo.
            </div>

            <div class="gpp-botao-container">
                <a href="#" class="gpp-botao-consulta acao-abrir-popup">
                    Consulte as promoções de hoje
                </a>
            </div>
        <?php endif; ?>
        
    </div>
    <?php
    
    return ob_get_clean();
}

    private function formatar_preco_com_desconto($preco, $desconto_percentual) {
        $preco_limpo = str_replace(array('R$', ' ', '.'), '', $preco);
        $preco_limpo = str_replace(',', '.', $preco_limpo);
        $preco_numerico = floatval($preco_limpo);
        
        if ($desconto_percentual > 0) {
            $multiplicador = 1 - ($desconto_percentual / 100);
            $preco_com_desconto = $preco_numerico * $multiplicador;
            return 'R$ ' . number_format($preco_com_desconto, 2, ',', '.');
        }
        
        return 'R$ ' . number_format($preco_numerico, 2, ',', '.');
    }
    
    /**
     * Obtém todas as cidades cadastradas de uma operadora específica
     * (default 'hapvida' para retrocompatibilidade)
     */
    private function obter_todas_cidades($operadora = 'hapvida') {
        $operadora = $this->sanitizar_operadora($operadora);
        $cidades = get_option($this->operadoras[$operadora]['option'], array());
        return is_array($cidades) ? $cidades : array();
    }

    /**
     * Obtém todas as cidades de TODAS as operadoras, cada uma marcada com a
     * chave 'operadora'. Usado no registro de shortcodes/variáveis para que os
     * shortcodes de todas as operadoras sejam registrados de uma só vez.
     */
    private function obter_todas_cidades_global() {
        // Cache por request: a lista é reconstruída uma única vez. Antes era
        // remontada do zero a cada shortcode renderizado (consumo de memória/CPU).
        if ($this->cache_cidades_global !== null) {
            return $this->cache_cidades_global;
        }
        $todas = array();
        foreach ($this->operadoras as $op_key => $op_info) {
            foreach ($this->obter_todas_cidades($op_key) as $cidade) {
                $cidade['operadora'] = $op_key;
                $todas[] = $cidade;
            }
        }
        $this->cache_cidades_global = $todas;
        return $todas;
    }

    /**
     * Encontra uma cidade (de qualquer operadora) pelo seu shortcode base.
     * Como os shortcodes são globalmente únicos (operadoras não-Hapvida usam
     * prefixo), a busca global é segura. Usa um índice (O(1)) montado uma vez
     * por request em vez de varrer todas as cidades a cada chamada.
     */
    private function obter_cidade_por_shortcode($shortcode_base) {
        if ($this->indice_shortcode === null) {
            $this->indice_shortcode = array();
            foreach ($this->obter_todas_cidades_global() as $c) {
                if (isset($c['shortcode']) && $c['shortcode'] !== '') {
                    $this->indice_shortcode[$c['shortcode']] = $c;
                }
            }
        }
        return isset($this->indice_shortcode[$shortcode_base]) ? $this->indice_shortcode[$shortcode_base] : null;
    }

    /**
     * Garante que o índice de shortcodes esteja montado.
     */
    private function garantir_indice_shortcode() {
        $this->obter_cidade_por_shortcode('');
    }

    // ===================================================================
    // ===== REGISTRO SOB DEMANDA =====
    // ===================================================================

    /**
     * Hook de filtro (pass-through): escaneia o texto e registra as cidades
     * citadas antes do do_shortcode rodar. Retorna o texto inalterado.
     */
    public function escanear_e_registrar_passthrough($texto) {
        if (is_string($texto)) {
            $this->escanear_e_registrar($texto);
        }
        return $texto;
    }

    /**
     * No carregamento da página, registra as cidades citadas no post atual.
     * Cobre Elementor (conteúdo em _elementor_data) e o editor clássico.
     */
    public function registrar_shortcodes_da_pagina_atual() {
        $post = get_queried_object();
        if (!$post || !isset($post->ID)) {
            return;
        }

        $blob = '';
        if (isset($post->post_content)) { $blob .= ' ' . $post->post_content; }
        if (isset($post->post_title))   { $blob .= ' ' . $post->post_title; }
        if (isset($post->post_excerpt)) { $blob .= ' ' . $post->post_excerpt; }

        // Conteúdo do Elementor (JSON), schema custom do plugin e campos de SEO
        $extras = array(
            '_elementor_data', '_gpp_schema_markup',
            'rank_math_title', 'rank_math_description',
            '_yoast_wpseo_title', '_yoast_wpseo_metadesc',
        );
        foreach ($extras as $meta_key) {
            $valor = get_post_meta($post->ID, $meta_key, true);
            if (is_string($valor) && $valor !== '') {
                $blob .= ' ' . $valor;
            }
        }

        $this->escanear_e_registrar($blob);
    }

    /**
     * Encontra no texto os tokens de shortcode, mapeia para o slug base da
     * cidade e registra os shortcodes apenas dessas cidades.
     */
    public function escanear_e_registrar($texto) {
        if (strpos($texto, '[') === false && strpos($texto, 'comparar_') === false) {
            return;
        }
        if (!preg_match_all('/\[\/?\s*([a-z0-9_\-]+)/i', $texto, $m) || empty($m[1])) {
            return;
        }

        $slugs = array();
        foreach (array_unique($m[1]) as $token) {
            $token = strtolower($token);

            // Comparação: [comparar_{slug}...] — o slug não tem "_" (usa hífen)
            if (strpos($token, 'comparar_') === 0) {
                $resto = substr($token, strlen('comparar_'));
                $partes = explode('_', $resto);
                if (!empty($partes[0])) {
                    $slugs[$partes[0]] = true;
                }
                continue;
            }

            $slug = $this->token_para_slug($token);
            if ($slug !== null) {
                $slugs[$slug] = true;
            }
        }

        if (!empty($slugs)) {
            $this->registrar_slugs(array_keys($slugs));
        }
    }

    /**
     * Dado um token de shortcode (ex.: "unimed_fortaleza_emp_ambulatorialtotal_0"),
     * descobre a qual cidade (shortcode base) ele pertence e devolve o slug base.
     * Faz match do shortcode base MAIS LONGO (evita confundir "fortaleza" com
     * "unimed_fortaleza").
     */
    private function token_para_slug($token) {
        $this->garantir_indice_shortcode();
        $partes = explode('_', $token);
        for ($i = count($partes); $i >= 1; $i--) {
            $candidato = implode('_', array_slice($partes, 0, $i));
            if (isset($this->indice_shortcode[$candidato])) {
                return $this->obter_slug_base_cidade($this->indice_shortcode[$candidato]);
            }
        }
        return null;
    }

    /**
     * Registra os shortcodes das cidades dos slugs informados (apenas os ainda
     * não registrados neste request).
     */
    private function registrar_slugs($slugs) {
        $novos = array();
        foreach ($slugs as $s) {
            if ($s !== '' && !isset($this->slugs_registrados[$s])) {
                $this->slugs_registrados[$s] = true;
                $novos[$s] = true;
            }
        }
        if (empty($novos)) {
            return;
        }

        // Limita as funções de registro aos slugs novos e dispara o registro.
        $this->filtro_slugs = $novos;
        $this->registrar_shortcodes();
        $this->registrar_shortcodes_simples();
        $this->registrar_shortcodes_variaveis();
        $this->registrar_shortcodes_comparar();
        $this->filtro_slugs = null;
    }

    /**
     * Indica se um slug base deve ser registrado agora (respeita o filtro).
     */
    private function slug_permitido($slug) {
        return ($this->filtro_slugs === null) || isset($this->filtro_slugs[$slug]);
    }

    /**
     * Gera slug automático a partir do nome da cidade
     */
    private function gerar_slug_cidade($nome) {
        $nome = remove_accents($nome);
        $slug = sanitize_title($nome);
        return $slug;
    }
    
    /**
     * Enfileira estilos CSS no frontend
     */
    public function enfileirar_estilos_frontend() {
        add_action('wp_head', array($this, 'adicionar_estilos_inline'), 100);
    }
    
    /**
     * Adiciona estilos inline
     */
    public function adicionar_estilos_inline() {
        ?>
        <style type="text/css">
        p {
            font-weight: normal !important;
        }
        
        .tabela-precos-hapvida {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            overflow: hidden;
            display: block;
            border-radius: 20px !important;
        }

        .tabela-precos-hapvida table {
            width: 100%;
            border-collapse: collapse;
            border-radius: 20px !important;
            overflow: hidden;
        }

        .tabela-precos-hapvida table th {
            background-color: #0054B8 !important;
            color: #FFFFFF !important;
            padding: 15px !important;
            text-align: left !important;
            font-weight: bold !important;
            border: none !important;
        }

        .tabela-precos-hapvida table thead tr th:first-child {
            border-top-left-radius: 20px !important;
        }

        .tabela-precos-hapvida table thead tr th:last-child {
            border-top-right-radius: 20px !important;
        }

        .tabela-precos-hapvida table tbody tr:last-child td:first-child {
            border-bottom-left-radius: 20px !important;
        }

        .tabela-precos-hapvida table tbody tr:last-child td:last-child {
            border-bottom-right-radius: 20px !important;
        }
        
        .tabela-precos-hapvida table tbody tr td {
            padding: 12px 15px !important;
            border-bottom: 1px solid #ddd !important;
            font-weight: normal !important;
            background-color: #FFFFFF !important;
            color: #000000 !important;
        }
        
        .tabela-precos-hapvida table tbody tr:hover td {
            background-color: #f5f5f5 !important;
        }
        
        .tabela-precos-hapvida .valor-destaque {
            color: #F05A22 !important;
            font-weight: bold !important;
        }
        
        .gpp-desconto-info {
            text-align: center !important;
            font-weight: bold !important;
            font-size: 14px !important;
            margin: 10px 0 !important;
            padding: 0 !important;
            color: #d32f2f !important;
            background-color: transparent !important;
            border: none !important;
        }
        
        .gpp-desconto-pequeno {
            text-align: center !important;
            font-weight: 600 !important;
            font-size: 16px !important;
            margin: -30px 0 5px 0 !important;
            padding: 8px !important;
            color: #F05A22 !important;
            background-color: transparent !important;
            font-style: italic !important;
        }
        
        .gpp-observacoes-info {
            text-align: left !important;
            font-weight: 600 !important;
            font-size: 16px !important;
            line-height: 1.6 !important;
            margin: 15px 0 20px 0 !important;
            padding: 20px !important;
            color: #333333 !important;
            background-color: #fff3e0 !important;
            border-left: 5px solid #F05A22 !important;
            border-radius: 20px !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1) !important;
        }
        
        .gpp-botao-consulta {
            display: inline-block !important;
            background-color: #F05A22 !important;
            color: #FFFFFF !important;
            font-size: 18px !important;
            font-weight: bold !important;
            text-decoration: none !important;
            padding: 15px 40px !important;
            border-radius: 20px !important;
            margin: 10px 0 20px 0 !important;
            text-align: center !important;
            transition: all 0.3s ease !important;
            box-shadow: 0 3px 6px rgba(0,0,0,0.15) !important;
            border: none !important;
        }
        
        .gpp-botao-consulta:hover {
            background-color: #d64a1a !important;
            color: #FFFFFF !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 5px 10px rgba(0,0,0,0.2) !important;
            text-decoration: none !important;
        }
        
        .gpp-botao-container {
            text-align: center !important;
            margin: 15px 0 !important;
        }
        
        @media screen and (max-width: 768px) {
            .tabela-precos-hapvida {
                font-size: 14px;
            }
            .tabela-precos-hapvida table th {
                font-size: 19px !important;
            }
            .tabela-precos-hapvida table tbody tr td {
                font-size: 17px !important;
            }
            .tabela-precos-hapvida th,
            .tabela-precos-hapvida td {
                padding: 10px 8px !important;
            }
            .gpp-desconto-info {
                font-size: 13px !important;
            }
            .gpp-desconto-pequeno {
                font-size: 14px !important;
            }
            .gpp-observacoes-info {
                font-size: 14px !important;
                padding: 15px !important;
            }
            .gpp-botao-consulta {
                font-size: 16px !important;
                padding: 12px 30px !important;
                width: 90% !important;
                display: block !important;
                margin: 10px auto !important;
            }
        }

        /* ===== CORES POR OPERADORA ===== */
        <?php foreach ($this->operadoras as $op_key => $op_info): ?>
        .gpp-op-<?php echo esc_attr($op_key); ?> .tabela-precos-hapvida table th {
            background-color: <?php echo esc_attr($op_info['cor']); ?> !important;
        }
        .gpp-op-<?php echo esc_attr($op_key); ?> .tabela-precos-hapvida .valor-destaque {
            color: <?php echo esc_attr($op_info['cor_destaque']); ?> !important;
        }
        .gpp-op-<?php echo esc_attr($op_key); ?> .gpp-observacoes-info {
            border-left-color: <?php echo esc_attr($op_info['cor']); ?> !important;
        }
        .gpp-op-<?php echo esc_attr($op_key); ?> .gpp-botao-consulta {
            background-color: <?php echo esc_attr($op_info['cor']); ?> !important;
        }
        .gpp-op-<?php echo esc_attr($op_key); ?> .gpp-desconto-pequeno,
        .gpp-op-<?php echo esc_attr($op_key); ?> .gpp-desconto-info {
            color: <?php echo esc_attr($op_info['cor_destaque']); ?> !important;
        }
        <?php endforeach; ?>

        /* ===== COMPARAÇÃO ENTRE OPERADORAS (CARDS RESPONSIVOS) ===== */
        .gpp-comparacao-operadoras {
            display: flex !important;
            flex-wrap: wrap !important;
            gap: 24px !important;
            align-items: stretch !important;
            margin: 20px 0 !important;
        }

        .gpp-card-operadora {
            flex: 1 1 320px !important;
            min-width: 300px !important;
            display: flex !important;
            flex-direction: column !important;
            background: #FFFFFF !important;
            border-radius: 20px !important;
            overflow: hidden !important;
            box-shadow: 0 4px 12px rgba(0,0,0,0.12) !important;
        }

        .gpp-card-operadora .gpp-card-header {
            padding: 16px 18px !important;
            color: #FFFFFF !important;
            font-weight: bold !important;
            font-size: 20px !important;
            text-align: center !important;
            letter-spacing: 0.5px !important;
        }

        .gpp-card-operadora .gpp-container-cidade {
            padding: 0 16px 16px 16px !important;
        }

        .gpp-card-operadora .tabela-precos-hapvida {
            margin: 16px 0 0 0 !important;
            box-shadow: none !important;
        }

        @media screen and (max-width: 768px) {
            .gpp-comparacao-operadoras {
                gap: 16px !important;
            }
            .gpp-card-operadora {
                flex: 1 1 100% !important;
                min-width: 100% !important;
            }
        }
        </style>
        <?php
    }

    /**
     * Renderiza o painel de REFERÊNCIA dos shortcodes de uma operadora.
     * Mostra todos os padrões disponíveis já com o prefixo da operadora
     * preenchido + um exemplo real (usando a primeira cidade cadastrada,
     * se houver). Os shortcodes podem ser copiados com 1 clique.
     */
    public function renderizar_referencia_shortcodes($operadora_ativa) {
        $operadora_ativa = $this->sanitizar_operadora($operadora_ativa);
        $op = $this->operadoras[$operadora_ativa];
        $prefixo = $op['prefixo'];
        $cor = $op['cor'];
        $is_simples_op = $this->operadora_e_simples($operadora_ativa);

        // Cidade de exemplo: usa a primeira cadastrada; senão, placeholder
        $cidades = $this->obter_todas_cidades($operadora_ativa);
        if (!empty($cidades) && isset($cidades[0]['shortcode'])) {
            $cidade_ex = $cidades[0]['shortcode'];                 // já vem com prefixo
            $slug_base_ex = $this->obter_slug_base_cidade(array(
                'shortcode' => $cidade_ex,
                'operadora' => $operadora_ativa,
            ));
        } else {
            $cidade_ex = $prefixo . 'cidade';
            $slug_base_ex = 'cidade';
        }

        // Helper local para imprimir uma linha de referência
        $linha = function ($descricao, $padrao, $exemplo) use ($cor) {
            ?>
            <tr>
                <td style="padding:8px 10px; border-bottom:1px solid #eee;"><?php echo esc_html($descricao); ?></td>
                <td style="padding:8px 10px; border-bottom:1px solid #eee;">
                    <code style="background:#f0f0f0; padding:2px 6px; border-radius:3px; font-size:12px;"><?php echo esc_html($padrao); ?></code>
                </td>
                <td style="padding:8px 10px; border-bottom:1px solid #eee;">
                    <code class="gpp-shortcode-item" data-shortcode="<?php echo esc_attr($exemplo); ?>" title="Clique para copiar" style="cursor:pointer; background:#e7f3ff; padding:2px 6px; border-radius:3px; font-size:12px; border:1px solid <?php echo esc_attr($cor); ?>;"><?php echo esc_html($exemplo); ?></code>
                </td>
            </tr>
            <?php
        };
        ?>
        <details style="background:#fff; border:2px solid <?php echo esc_attr($cor); ?>; border-radius:5px; margin:20px 0; padding:0;">
            <summary style="cursor:pointer; padding:15px 20px; font-size:16px; font-weight:bold; color:#fff; background:<?php echo esc_attr($cor); ?>; border-radius:3px;">
                📚 Referência de Shortcodes — <?php echo esc_html($op['nome']); ?>
                <?php if ($prefixo !== ''): ?>(prefixo <code style="background:rgba(255,255,255,0.25); color:#fff; padding:1px 5px; border-radius:3px;"><?php echo esc_html($prefixo); ?></code>)<?php endif; ?>
            </summary>

            <div style="padding:20px;">

                <?php if ($is_simples_op): ?>
                <p style="margin-top:0; color:#444;">
                    <strong><?php echo esc_html($op['nome']); ?></strong> usa <strong>uma única tabela por cidade</strong> (sem tipo/acomodação/coparticipação).
                    Substitua <code>CIDADE</code> pelo slug da cidade (ex.: <code><?php echo esc_html($cidade_ex); ?></code>). Todos os shortcodes começam com <code><?php echo esc_html($prefixo); ?></code>.
                </p>

                <h3 style="color:<?php echo esc_attr($cor); ?>; margin-bottom:5px;">🧾 Tabela e valores</h3>
                <table style="width:100%; border-collapse:collapse; background:#fafafa;">
                    <thead>
                        <tr style="background:<?php echo esc_attr($cor); ?>; color:#fff;">
                            <th style="padding:8px 10px; text-align:left;">O que faz</th>
                            <th style="padding:8px 10px; text-align:left;">Padrão</th>
                            <th style="padding:8px 10px; text-align:left;">Exemplo (clique p/ copiar)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $linha('Tabela completa (com avisos)', '[' . $prefixo . 'CIDADE]', '[' . $cidade_ex . ']');
                        $linha('Tabela sem avisos/botão', '[' . $prefixo . 'CIDADE_sd]', '[' . $cidade_ex . '_sd]');
                        $linha('Menor valor (texto)', '[' . $prefixo . 'CIDADE_menorvalor]', '[' . $cidade_ex . '_menorvalor]');
                        $linha('Maior valor (texto)', '[' . $prefixo . 'CIDADE_maiorvalor]', '[' . $cidade_ex . '_maiorvalor]');
                        $linha('Valor de uma faixa (só 1ª, 2ª e última)', '[' . $prefixo . 'CIDADE_N]', '[' . $cidade_ex . '_0]');
                        ?>
                    </tbody>
                </table>

                <h3 style="color:#8E44AD; margin-bottom:5px;">⚖️ Comparar operadoras (SEM prefixo)</h3>
                <p style="margin:0 0 8px; color:#666; font-size:13px;">Mostra a MESMA cidade em todas as operadoras, em cards responsivos. Use o slug <strong>sem</strong> prefixo.</p>
                <table style="width:100%; border-collapse:collapse; background:#fafafa;">
                    <tbody>
                        <?php
                        $linha('Comparar todas (sem tipo)', '[comparar_CIDADE]', '[comparar_' . $slug_base_ex . ']');
                        $linha('Comparar por tipo da Hapvida', '[comparar_CIDADE_TIPO_total]', '[comparar_' . $slug_base_ex . '_empresarial_total]');
                        $linha('Tabela comparativa (cotação família)', '[tabela_comparativa cidade="CIDADE"]', '[tabela_comparativa cidade="' . $slug_base_ex . '"]');
                        ?>
                    </tbody>
                </table>

                <h3 style="color:<?php echo esc_attr($cor); ?>; margin-bottom:5px;">📅 Data (global)</h3>
                <table style="width:100%; border-collapse:collapse; background:#fafafa;">
                    <tbody>
                        <?php
                        $linha('Ano atual', '[ano_atual]', '[ano_atual]');
                        $linha('Mês atual (por extenso)', '[mes_atual]', '[mes_atual]');
                        ?>
                    </tbody>
                </table>

                <?php else: ?>
                <p style="margin-top:0; color:#444;">
                    Substitua <code>CIDADE</code> pelo slug da cidade (ex.: <code><?php echo esc_html($slug_base_ex); ?></code>).
                    <?php if ($prefixo !== ''): ?>
                        Para <?php echo esc_html($op['nome']); ?>, todos os shortcodes começam com <code><?php echo esc_html($prefixo); ?></code>.
                    <?php else: ?>
                        A Hapvida <strong>não usa prefixo</strong> (mantém os shortcodes originais).
                    <?php endif; ?>
                    Os <code>TIPO</code> possíveis são: <code>empresarial</code>, <code>individual</code>, <code>pme</code>, <code>adesao</code>.
                </p>

                <h3 style="color:<?php echo esc_attr($cor); ?>; margin-bottom:5px;">🧾 Tabelas de preço</h3>
                <table style="width:100%; border-collapse:collapse; background:#fafafa;">
                    <thead>
                        <tr style="background:<?php echo esc_attr($cor); ?>; color:#fff;">
                            <th style="padding:8px 10px; text-align:left;">O que faz</th>
                            <th style="padding:8px 10px; text-align:left;">Padrão</th>
                            <th style="padding:8px 10px; text-align:left;">Exemplo (clique p/ copiar)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $linha('Tabela completa (total + parcial)', '[' . $prefixo . 'CIDADE_TIPO]', '[' . $cidade_ex . '_empresarial]');
                        $linha('Só coparticipação TOTAL', '[' . $prefixo . 'CIDADE_TIPO_total]', '[' . $cidade_ex . '_empresarial_total]');
                        $linha('Só coparticipação PARCIAL', '[' . $prefixo . 'CIDADE_TIPO_parcial]', '[' . $cidade_ex . '_empresarial_parcial]');
                        $linha('Versão "sem disclaimers" (sufixo _sd)', '[' . $prefixo . 'CIDADE_TIPO_total_sd]', '[' . $cidade_ex . '_empresarial_total_sd]');
                        ?>
                    </tbody>
                </table>

                <h3 style="color:<?php echo esc_attr($cor); ?>; margin-bottom:5px;">💰 Valores resumidos da cidade</h3>
                <table style="width:100%; border-collapse:collapse; background:#fafafa;">
                    <tbody>
                        <?php
                        $linha('Menor valor (texto)', '[' . $prefixo . 'CIDADE_menorvalor]', '[' . $cidade_ex . '_menorvalor]');
                        $linha('Maior valor (texto)', '[' . $prefixo . 'CIDADE_maiorvalor]', '[' . $cidade_ex . '_maiorvalor]');
                        $linha('Tabela do plano mais barato', '[' . $prefixo . 'CIDADE_menortabela]', '[' . $cidade_ex . '_menortabela]');
                        ?>
                    </tbody>
                </table>

                <h3 style="color:<?php echo esc_attr($cor); ?>; margin-bottom:5px;">🔢 Valor de uma faixa etária</h3>
                <p style="margin:0 0 8px; color:#666; font-size:13px;">Siglas do tipo: <code>emp</code>, <code>ind</code>, <code>pme</code>, <code>ade</code>. Acomodação: <code>ambulatorial</code>, <code>enfermaria</code>, <code>apartamento</code>. Faixas registradas: <code>0</code> (primeira), <code>1</code> e <code>9</code> (última). Sem o número = primeira faixa.</p>
                <table style="width:100%; border-collapse:collapse; background:#fafafa;">
                    <tbody>
                        <?php
                        $linha('Faixa específica (total)', '[' . $prefixo . 'CIDADE_SIGLA_ACOMtotal_N]', '[' . $cidade_ex . '_emp_ambulatorialtotal_0]');
                        $linha('Primeira faixa (atalho, total)', '[' . $prefixo . 'CIDADE_SIGLA_ACOMtotal]', '[' . $cidade_ex . '_emp_ambulatorialtotal]');
                        $linha('Faixa específica (parcial)', '[' . $prefixo . 'CIDADE_SIGLA_ACOMparcial_N]', '[' . $cidade_ex . '_emp_ambulatorialparcial_9]');
                        ?>
                    </tbody>
                </table>

                <h3 style="color:#8E44AD; margin-bottom:5px;">⚖️ Comparar operadoras (SEM prefixo)</h3>
                <p style="margin:0 0 8px; color:#666; font-size:13px;">Mostra a MESMA cidade em todas as operadoras, em cards responsivos. Use sempre o slug <strong>sem</strong> prefixo.</p>
                <table style="width:100%; border-collapse:collapse; background:#fafafa;">
                    <tbody>
                        <?php
                        $linha('Comparar (total)', '[comparar_CIDADE_TIPO_total]', '[comparar_' . $slug_base_ex . '_empresarial_total]');
                        $linha('Comparar (parcial)', '[comparar_CIDADE_TIPO_parcial]', '[comparar_' . $slug_base_ex . '_empresarial_parcial]');
                        $linha('Comparar (ambas)', '[comparar_CIDADE_TIPO]', '[comparar_' . $slug_base_ex . '_empresarial]');
                        $linha('Tabela comparativa (cotação família)', '[tabela_comparativa cidade="CIDADE"]', '[tabela_comparativa cidade="' . $slug_base_ex . '"]');
                        ?>
                    </tbody>
                </table>

                <h3 style="color:<?php echo esc_attr($cor); ?>; margin-bottom:5px;">📅 Data (global, qualquer operadora)</h3>
                <table style="width:100%; border-collapse:collapse; background:#fafafa;">
                    <tbody>
                        <?php
                        $linha('Ano atual', '[ano_atual]', '[ano_atual]');
                        $linha('Mês atual (por extenso)', '[mes_atual]', '[mes_atual]');
                        ?>
                    </tbody>
                </table>

                <?php endif; ?>

                <p style="margin-top:15px; color:#666; font-size:13px;">
                    👉 Para ver a lista completa cidade por cidade (todas as faixas), use
                    <a href="<?php echo admin_url('admin.php?page=gpp-variaveis&operadora=' . $operadora_ativa); ?>">Variáveis Dinâmicas</a>.
                </p>
            </div>
        </details>
        <?php
    }

    // CONTINUA NO PRÓXIMO COMENTÁRIO...
    /**
     * Página administrativa principal
     */
    public function pagina_admin() {
        $operadora_inicial = $this->sanitizar_operadora(isset($_GET['operadora']) ? $_GET['operadora'] : 'hapvida');
        $cfg_inicial = $this->operadoras[$operadora_inicial];
        ?>
        <div class="wrap">
            <h1>Gerenciador de Preços de Planos de Saúde</h1>

            <h2 class="nav-tab-wrapper" style="margin-bottom: 20px;">
                <?php foreach ($this->operadoras as $op_key => $op_info):
                    $classe_ativa = ($op_key === $operadora_inicial) ? ' nav-tab-active' : '';
                ?>
                    <a href="#" class="nav-tab gpp-op-tab<?php echo $classe_ativa; ?>" data-operadora="<?php echo esc_attr($op_key); ?>" style="<?php echo ($op_key === $operadora_inicial) ? 'box-shadow: inset 0 -3px 0 ' . esc_attr($op_info['cor']) . ';' : ''; ?>">
                        <?php echo esc_html($op_info['nome']); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <!-- ===== CONTROLES COMPARTILHADOS (operam na operadora da aba ativa) ===== -->
            <button id="gpp-adicionar-cidade" class="button button-primary" style="margin-bottom: 20px;">Adicionar Nova Cidade em <span id="gpp-add-op-nome"><?php echo esc_html($cfg_inicial['nome']); ?></span></button>

            <!-- ===== SISTEMA GLOBAL DE DESCONTOS ===== -->
            <div style="background: #fff; padding: 20px; margin: 20px 0; border: 2px solid #0054b8; border-radius: 5px;">
                <h2 style="margin-top: 0; color: #0054b8;">⚙️ Aplicar Desconto Global em Todas as Cidades de <span id="gpp-desc-op-nome"><?php echo esc_html($cfg_inicial['nome']); ?></span></h2>
                <p style="color: #666;">Configure um desconto que será aplicado em <strong>TODAS as cidades</strong> da operadora selecionada na aba acima.</p>

                <div style="margin: 15px 0;">
                    <label style="display: block; margin: 10px 0;">
                        <input type="radio" name="gpp-tipo-desconto-global" id="gpp-desconto-15-global" value="15">
                        Aplicar desconto de <strong>15%</strong>
                    </label>

                    <label style="display: block; margin: 10px 0;">
                        <input type="radio" name="gpp-tipo-desconto-global" id="gpp-desconto-personalizado-global-radio" value="personalizado">
                        Desconto personalizado:
                        <input type="number" id="gpp-desconto-personalizado-global" min="0" max="100" step="0.01" placeholder="Ex: 20" style="width: 100px; margin-left: 10px;" disabled> %
                    </label>
                </div>

                <div style="margin-top: 20px;">
                    <button id="gpp-aplicar-desconto-global" class="button button-primary">Aplicar em Todas as Cidades</button>
                    <button id="gpp-remover-todos-descontos" class="button">Remover Todos os Descontos</button>
                </div>
            </div>

            <div style="margin-bottom: 20px;">
                <a href="<?php echo admin_url('admin.php?page=gpp-variaveis&operadora=' . $operadora_inicial); ?>" id="gpp-link-variaveis" class="button button-secondary">Ver Variáveis Dinâmicas</a>
            </div>

            <!-- ===== PAINÉIS POR OPERADORA (troca instantânea via JS) ===== -->
            <?php foreach ($this->operadoras as $operadora_ativa => $operadora_cfg):
                $is_simples = $this->operadora_e_simples($operadora_ativa);
                $painel_ativo = ($operadora_ativa === $operadora_inicial);
            ?>
            <div class="gpp-op-panel" data-operadora="<?php echo esc_attr($operadora_ativa); ?>"<?php echo $painel_ativo ? '' : ' style="display:none;"'; ?>>

                <div style="padding: 10px 15px; margin-bottom: 15px; background: <?php echo esc_attr($operadora_cfg['cor']); ?>; color: #fff; border-radius: 5px; font-size: 16px;">
                    Gerenciando operadora: <strong><?php echo esc_html($operadora_cfg['nome']); ?></strong>
                    <?php if ($operadora_cfg['prefixo'] !== ''): ?>
                        &nbsp;—&nbsp; prefixo dos shortcodes: <code style="background: rgba(255,255,255,0.25); color: #fff; padding: 2px 6px; border-radius: 3px;"><?php echo esc_html($operadora_cfg['prefixo']); ?></code>
                    <?php else: ?>
                        &nbsp;—&nbsp; shortcodes <strong>sem prefixo</strong>
                    <?php endif; ?>
                </div>

                <?php $this->renderizar_referencia_shortcodes($operadora_ativa); ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 12%;">Cidade</th>
                        <th style="width: 10%;">Shortcode Base</th>
                        <th style="width: 48%;">Shortcodes Principais</th>
                        <th style="width: 12%;">Tipos Ativos</th>
                        <th style="width: 8%;">Descontos</th>
                        <th style="width: 10%;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $cidades = $this->obter_todas_cidades($operadora_ativa);
                    if (!empty($cidades)) {
                        foreach ($cidades as $index => $cidade) {
                            // Garante que a operadora esteja disponível para os cálculos/render
                            $cidade['operadora'] = $operadora_ativa;
                            // Calcula info de descontos por tipo
                            if ($is_simples) {
                                // Operadora simples: desconto único (global)
                                $desc_simples = $this->obter_desconto_simples($cidade);
                                $desconto_display = ($desc_simples > 0) ? ($desc_simples . '%') : '-';
                            } else {
                                $descontos_info = array();
                                $tipos_check = array('empresarial' => 'Emp', 'individual' => 'Ind', 'pme' => 'PME', 'adesao' => 'Ade');

                                foreach ($tipos_check as $tipo_key => $tipo_label) {
                                    $desc = $this->obter_desconto_tipo($cidade, $tipo_key);
                                    if ($desc > 0) {
                                        $descontos_info[] = $tipo_label . ': ' . $desc . '%';
                                    }
                                }

                                $desconto_display = !empty($descontos_info) ? implode('<br>', $descontos_info) : '-';
                            }
                            
                            $tipos_ativos = array();
                            
                            // Encontra o menor e maior valor
                            $menor = $this->encontrar_menor_valor_cidade($cidade);
                            $maior = $this->encontrar_maior_valor_cidade($cidade);
                            
                            // Define tipos e seus emojis/cores
                            $info_tipos = array(
                                'empresarial' => array('emoji' => '📈', 'nome' => 'Empresarial', 'cor' => '#0054b8'),
                                'individual' => array('emoji' => '👤', 'nome' => 'Individual', 'cor' => '#28a745'),
                                'pme' => array('emoji' => '🏢', 'nome' => 'PME', 'cor' => '#FF6600'),
                                'adesao' => array('emoji' => '🤝', 'nome' => 'Adesão', 'cor' => '#8E44AD')
                            );
                            
                            $shortcodes_por_tipo = array();
                            
                            if (isset($cidade['tipos_planos_ativos'])) {
                                foreach ($info_tipos as $tipo_key => $tipo_dados) {
                                    if (!empty($cidade['tipos_planos_ativos'][$tipo_key])) {
                                        $tipos_ativos[] = $tipo_dados['nome'];
                                        
                                        // Shortcode Total
                                        $sc_total = '[' . $cidade['shortcode'] . '_' . $tipo_key . '_total]';
                                        // Shortcode Parcial
                                        $sc_parcial = '[' . $cidade['shortcode'] . '_' . $tipo_key . '_parcial]';
                                        
                                        $shortcodes_por_tipo[$tipo_key] = array(
                                            'emoji' => $tipo_dados['emoji'],
                                            'nome' => $tipo_dados['nome'],
                                            'cor' => $tipo_dados['cor'],
                                            'total' => $sc_total,
                                            'parcial' => $sc_parcial
                                        );
                                    }
                                }
                            }
                            
                            $tipos_text = !empty($tipos_ativos) ? implode(', ', $tipos_ativos) : 'Nenhum';
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($cidade['nome']); ?></strong></td>
                                <td><code><?php echo esc_html($cidade['shortcode']); ?></code></td>
                                <td>
                                    <?php if ($is_simples):
                                        $slug_base_cidade = $this->obter_slug_base_cidade($cidade);
                                        $tem_tabela_simples = !empty($cidade['tabela_simples']);
                                    ?>
                                        <?php if ($tem_tabela_simples): ?>
                                            <div style="margin-bottom: 8px; padding: 6px; background: #f8f9fa; border-left: 3px solid <?php echo esc_attr($operadora_cfg['cor']); ?>; border-radius: 2px;">
                                                <strong style="color: <?php echo esc_attr($operadora_cfg['cor']); ?>; font-size: 10px;">🧾 TABELA:</strong>
                                                <code class="gpp-shortcode-item" data-shortcode="[<?php echo esc_attr($cidade['shortcode']); ?>]" style="cursor: pointer; background: #e7f3ff; padding: 2px 6px; margin: 1px; display: inline-block; border-radius: 2px; font-size: 11px; border: 1px solid <?php echo esc_attr($operadora_cfg['cor']); ?>;">[<?php echo esc_html($cidade['shortcode']); ?>]</code>
                                                <code class="gpp-shortcode-item" data-shortcode="[<?php echo esc_attr($cidade['shortcode']); ?>_sd]" style="cursor: pointer; background: #eee; padding: 2px 6px; margin: 1px; display: inline-block; border-radius: 2px; font-size: 11px; border: 1px solid <?php echo esc_attr($operadora_cfg['cor']); ?>;">[<?php echo esc_html($cidade['shortcode']); ?>_sd]</code>
                                                <br>
                                                <strong style="color: #f57c00; font-size: 10px;">💰 VALORES:</strong>
                                                <code class="gpp-shortcode-item" data-shortcode="[<?php echo esc_attr($cidade['shortcode']); ?>_menorvalor]" style="cursor: pointer; background: #fff3cd; padding: 2px 6px; margin: 1px; display: inline-block; border-radius: 2px; font-size: 10px; border: 1px solid #ffc107;">[<?php echo esc_html($cidade['shortcode']); ?>_menorvalor]</code>
                                                <code class="gpp-shortcode-item" data-shortcode="[<?php echo esc_attr($cidade['shortcode']); ?>_maiorvalor]" style="cursor: pointer; background: #fff3cd; padding: 2px 6px; margin: 1px; display: inline-block; border-radius: 2px; font-size: 10px; border: 1px solid #ffc107;">[<?php echo esc_html($cidade['shortcode']); ?>_maiorvalor]</code>
                                            </div>
                                            <div style="margin-top: 6px; padding: 6px; background: #f3e8ff; border-left: 3px solid #8E44AD; border-radius: 2px;">
                                                <strong style="color: #8E44AD; font-size: 10px;">⚖️ COMPARAR:</strong>
                                                <code class="gpp-shortcode-item" data-shortcode="[comparar_<?php echo esc_attr($slug_base_cidade); ?>]" style="cursor: pointer; background: #ede0ff; padding: 2px 6px; margin: 1px; display: inline-block; border-radius: 2px; font-size: 10px; border: 1px solid #8E44AD;">[comparar_<?php echo esc_html($slug_base_cidade); ?>]</code>
                                            </div>
                                        <?php else: ?>
                                            <em>Tabela não cadastrada</em>
                                        <?php endif; ?>
                                    <?php else: ?>
                                    <?php if ($menor['shortcode']): ?>
                                        <div style="margin-bottom: 10px; padding: 8px; background: #fff9e6; border-left: 4px solid #ffc107; border-radius: 3px;">
                                            <strong style="color: #f57c00; font-size: 10px;">💰 MENOR VALOR:</strong><br>
                                            <code class="gpp-shortcode-item" data-shortcode="[<?php echo esc_attr($cidade['shortcode']); ?>_menorvalor]" style="cursor: pointer; background: #fff3cd; padding: 3px 8px; margin: 2px 5px 2px 0; display: inline-block; border-radius: 3px; font-size: 11px; border: 1px solid #ffc107;">[<?php echo esc_html($cidade['shortcode']); ?>_menorvalor]</code>
                                            <small style="color: #f57c00; font-weight: bold;"><?php echo esc_html($menor['valor']); ?></small>
                                            <br>
                                            <strong style="color: #f57c00; font-size: 10px;">📊 MENOR TABELA:</strong><br>
                                            <code class="gpp-shortcode-item" data-shortcode="[<?php echo esc_attr($cidade['shortcode']); ?>_menortabela]" style="cursor: pointer; background: #fff3cd; padding: 3px 8px; margin: 2px 5px 2px 0; display: inline-block; border-radius: 3px; font-size: 11px; border: 1px solid #ffc107;">[<?php echo esc_html($cidade['shortcode']); ?>_menortabela]</code>
                                            <small style="color: #888; font-size: 10px;">Tabela completa do plano mais barato</small>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($maior['shortcode']): ?>
                                        <div style="margin-bottom: 10px; padding: 8px; background: #f0f4ff; border-left: 4px solid #0054b8; border-radius: 3px;">
                                            <strong style="color: #0054b8; font-size: 10px;">💎 MAIOR VALOR:</strong><br>
                                            <code class="gpp-shortcode-item" data-shortcode="[<?php echo esc_attr($cidade['shortcode']); ?>_maiorvalor]" style="cursor: pointer; background: #e7f3ff; padding: 3px 8px; margin: 2px 5px 2px 0; display: inline-block; border-radius: 3px; font-size: 11px; border: 1px solid #0054b8;">[<?php echo esc_html($cidade['shortcode']); ?>_maiorvalor]</code>
                                            <small style="color: #0054b8; font-weight: bold;"><?php echo esc_html($maior['valor']); ?></small>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php foreach ($shortcodes_por_tipo as $tipo_key => $tipo_data): ?>
                                        <div style="margin-bottom: 8px; padding: 6px; background: #f8f9fa; border-left: 3px solid <?php echo $tipo_data['cor']; ?>; border-radius: 2px;">
                                            <strong style="color: <?php echo $tipo_data['cor']; ?>; font-size: 10px;"><?php echo $tipo_data['emoji']; ?> <?php echo strtoupper($tipo_data['nome']); ?>:</strong><br>
                                            <div style="margin-top: 3px;">
                                                <span style="font-size: 9px; color: #666; display: inline-block; margin-right: 3px;">Total:</span>
                                                <code class="gpp-shortcode-item" data-shortcode="<?php echo esc_attr($tipo_data['total']); ?>" style="cursor: pointer; background: #e7f3ff; padding: 2px 6px; margin: 1px; display: inline-block; border-radius: 2px; font-size: 10px; border: 1px solid <?php echo $tipo_data['cor']; ?>;"><?php echo esc_html($tipo_data['total']); ?></code>
                                                
                                                <span style="font-size: 9px; color: #666; display: inline-block; margin: 0 3px 0 8px;">Parcial:</span>
                                                <code class="gpp-shortcode-item" data-shortcode="<?php echo esc_attr($tipo_data['parcial']); ?>" style="cursor: pointer; background: #fff3e0; padding: 2px 6px; margin: 1px; display: inline-block; border-radius: 2px; font-size: 10px; border: 1px solid <?php echo $tipo_data['cor']; ?>;"><?php echo esc_html($tipo_data['parcial']); ?></code>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>

                                    <?php if (!empty($shortcodes_por_tipo)):
                                        $slug_base_cidade = $this->obter_slug_base_cidade($cidade);
                                    ?>
                                        <div style="margin-top: 10px; padding: 6px; background: #f3e8ff; border-left: 3px solid #8E44AD; border-radius: 2px;">
                                            <strong style="color: #8E44AD; font-size: 10px;">⚖️ COMPARAR OPERADORAS (mesma cidade):</strong><br>
                                            <div style="margin-top: 3px;">
                                                <?php foreach ($shortcodes_por_tipo as $tipo_key => $tipo_data):
                                                    $sc_comp_total = '[comparar_' . $slug_base_cidade . '_' . $tipo_key . '_total]';
                                                ?>
                                                    <code class="gpp-shortcode-item" data-shortcode="<?php echo esc_attr($sc_comp_total); ?>" style="cursor: pointer; background: #ede0ff; padding: 2px 6px; margin: 1px; display: inline-block; border-radius: 2px; font-size: 10px; border: 1px solid #8E44AD;"><?php echo esc_html($sc_comp_total); ?></code>
                                                <?php endforeach; ?>
                                            </div>
                                            <small style="color: #666; font-size: 9px;">Mostra Hapvida/Amil/Unimed/SulAmérica juntas. Troque <code>_total</code> por <code>_parcial</code> ou remova o sufixo para ambas.</small>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (empty($shortcodes_por_tipo)): ?>
                                        <em>Nenhum plano cadastrado</em>
                                    <?php endif; ?>
                                    <?php endif; // fim do else ($is_simples) ?>
                                </td>
                                <td><?php echo $is_simples ? 'Plano único' : $tipos_text; ?></td>
                                <td style="font-size: 11px;"><?php echo $desconto_display; ?></td>
                                <td>
                                    <button class="button gpp-editar-cidade" data-cidade-id="<?php echo $index; ?>">Editar</button>
                                    <button class="button gpp-excluir-cidade" data-cidade-id="<?php echo $index; ?>">Excluir</button>
                                </td>
                            </tr>
                            <?php
                        }
                    } else {
                        echo '<tr><td colspan="6">Nenhuma cidade cadastrada ainda.</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
            
            <div style="background: #fff; padding: 20px; margin: 20px 0; border-left: 4px solid #0054b8;">
                <h2>Como usar</h2>
                <?php if ($is_simples): ?>
                <ol>
                    <li>Adicione ou edite cidades de <?php echo esc_html($operadora_cfg['nome']); ?></li>
                    <li>(Opcional) Configure o desconto global da cidade (15% ou personalizado)</li>
                    <li>Cole o JSON da <strong>tabela única</strong> (Faixa Etária → Valor)</li>
                    <li>Copie o shortcode <code>[<?php echo esc_html($operadora_cfg['prefixo']); ?>cidade]</code> e cole na página</li>
                </ol>
                <?php else: ?>
                <ol>
                    <li>Adicione ou edite cidades</li>
                    <li>Selecione quais tipos de planos deseja cadastrar (Empresarial, Individual, PME, Adesao)</li>
                    <li>Configure os descontos: use o desconto global OU configure descontos específicos por tipo de plano</li>
                    <li>Para cada tipo, selecione quais acomodações (Ambulatorial, Enfermaria, Apartamento)</li>
                    <li>Configure os preços usando JSON nos campos que aparecerem</li>
                    <li>Copie o shortcode e cole na página</li>
                </ol>
                <?php endif; ?>
                <h3 style="margin-top: 15px;">⚖️ Comparar operadoras na mesma página</h3>
                <p>Use o shortcode <code>[comparar_CIDADE_TIPO_total]</code> para exibir as tabelas de <strong>todas as operadoras</strong> que têm aquela cidade, lado a lado (responsivo). Exemplos:</p>
                <ul style="margin-left: 20px;">
                    <li><code>[comparar_fortaleza_empresarial_total]</code> — compara a coparticipação total empresarial em Fortaleza entre Hapvida, Amil, Unimed e SulAmérica.</li>
                    <li><code>[comparar_fortaleza_empresarial_parcial]</code> — versão parcial.</li>
                    <li><code>[comparar_fortaleza_empresarial]</code> — mostra total e parcial.</li>
                </ul>
                <p style="color:#666;"><em>A cidade no shortcode de comparação é sempre o slug <strong>sem</strong> prefixo de operadora (ex.: <code>fortaleza</code>), pois ele junta todas as operadoras.</em></p>

                <h3 style="margin-top: 15px;">💰 Tabela comparativa de cotação (família)</h3>
                <p>Use <code>[tabela_comparativa cidade="fortaleza"]</code> para gerar a tabela comparando o valor mensal/anual de uma família entre as 4 operadoras, com a coluna "Economia vs Hapvida".</p>
                <ul style="margin-left: 20px;">
                    <li>Família padrão: <strong>2 adultos de 35 anos + filhos de 5 e 8 anos</strong>. Para mudar: <code>[tabela_comparativa cidade="fortaleza" idades="35,35,5,8"]</code>.</li>
                    <li>Para a Hapvida, o cálculo usa automaticamente o plano mais barato. Para fixar um plano: adicione <code>tipo="empresarial" acomodacao="ambulatorial" coparticipacao="total"</code>.</li>
                    <li>Cada idade é somada pela sua faixa etária na tabela cadastrada. A operadora só aparece se tiver a cidade e cobrir todas as idades.</li>
                </ul>
            </div>

            </div><!-- /.gpp-op-panel -->
            <?php endforeach; ?>
        </div>

        <!-- Modal -->
        <div id="gpp-modal" class="gpp-modal" style="display: none;">
            <div class="gpp-modal-content">
                <span class="gpp-modal-close">&times;</span>
                <h2 id="gpp-modal-titulo">Adicionar Cidade</h2>
                
                <form id="gpp-form-cidade">
                    <input type="hidden" id="gpp-cidade-id" value="">
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="gpp-nome">Nome da Cidade</label></th>
                            <td>
                                <input type="text" id="gpp-nome" class="regular-text" required>
                                <p class="description">O shortcode será gerado automaticamente</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label>Desconto Global</label></th>
                            <td>
                                <label style="display: block; margin: 5px 0;">
                                    <input type="checkbox" id="gpp-desconto-15">
                                    Aplicar desconto de 15%
                                </label>
                                <br>
                                <label style="display: block; margin: 5px 0;">
                                    <input type="checkbox" id="gpp-desconto-personalizado-check">
                                    Desconto personalizado (%)
                                </label>
                                <div id="gpp-desconto-personalizado-field" style="display: none; margin-top: 10px;">
                                    <input type="number" id="gpp-desconto-personalizado" min="0" max="100" step="0.01" placeholder="Ex: 20">
                                </div>
                                <p class="description">Este desconto será aplicado em todos os tipos de planos desta cidade (a menos que você configure descontos específicos abaixo)</p>
                            </td>
                        </tr>
                        
                        <tr class="gpp-row-completo">
                            <th><label>Descontos Diferenciados</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="gpp-tem-desconto-diferenciado">
                                    <strong>Algum plano tem desconto diferente?</strong>
                                </label>
                                <p class="description">Se marcado, você poderá configurar descontos específicos para cada tipo de plano</p>
                                
                                <div id="gpp-descontos-diferenciados-container" style="display: none; margin-top: 15px; padding: 15px; background: #f0f0f0; border-radius: 5px;">
                                    <p><strong>Configure os descontos específicos por tipo:</strong></p>
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 10px;">
                                        <div>
                                            <label><strong>📈 Empresarial (%):</strong></label>
                                            <input type="number" class="gpp-desconto-diferenciado" id="gpp-desc-dif-empresarial" min="0" max="100" step="0.01" placeholder="Ex: 15" style="width: 100%;">
                                        </div>
                                        <div>
                                            <label><strong>👤 Individual (%):</strong></label>
                                            <input type="number" class="gpp-desconto-diferenciado" id="gpp-desc-dif-individual" min="0" max="100" step="0.01" placeholder="Ex: 10" style="width: 100%;">
                                        </div>
                                        <div>
                                            <label><strong>🏢 PME (%):</strong></label>
                                            <input type="number" class="gpp-desconto-diferenciado" id="gpp-desc-dif-pme" min="0" max="100" step="0.01" placeholder="Ex: 12" style="width: 100%;">
                                        </div>
                                        <div>
                                            <label><strong>🤝 Adesão (%):</strong></label>
                                            <input type="number" class="gpp-desconto-diferenciado" id="gpp-desc-dif-adesao" min="0" max="100" step="0.01" placeholder="Ex: 8" style="width: 100%;">
                                        </div>
                                    </div>
                                    <p style="margin-top: 10px; font-size: 12px; color: #666;"><em>Deixe em branco ou 0 para não aplicar desconto naquele tipo específico</em></p>
                                </div>
                            </td>
                        </tr>
                        
                        <tr class="gpp-row-completo">
                            <th><label>Tipos de Planos</label></th>
                            <td>
                                <p><strong>Selecione quais tipos de planos esta cidade terá:</strong></p>
                                <label style="display: block; margin: 5px 0;">
                                    <input type="checkbox" class="gpp-tipo-plano-check" data-tipo="empresarial" id="gpp-tipo-empresarial">
                                    📈 Empresarial
                                </label>
                                <label style="display: block; margin: 5px 0;">
                                    <input type="checkbox" class="gpp-tipo-plano-check" data-tipo="individual" id="gpp-tipo-individual">
                                    👤 Individual
                                </label>
                                <label style="display: block; margin: 5px 0;">
                                    <input type="checkbox" class="gpp-tipo-plano-check" data-tipo="pme" id="gpp-tipo-pme">
                                    🏢 PME
                                </label>
                                <label style="display: block; margin: 5px 0;">
                                    <input type="checkbox" class="gpp-tipo-plano-check" data-tipo="adesao" id="gpp-tipo-adesao">
                                    🤝 Adesao
                                </label>
                            </td>
                        </tr>
                    </table>

                    <!-- ===== MODO SIMPLES: tabela única (Faixa Etária → Valor) ===== -->
                    <div id="gpp-secao-simples" style="display:none; padding: 20px; margin: 20px 0; border-radius: 5px; background: #2c3e50;">
                        <h3 style="margin-top: 0; color: #fff;">🧾 Tabela de Preços — <span id="gpp-simples-op-nome"></span></h3>
                        <p style="color: #fff;">Esta operadora usa <strong>uma única tabela por cidade</strong>. Cole o JSON com as faixas etárias e valores:</p>
                        <label style="display:block; color:#fff; font-weight:bold; margin-bottom:6px;">JSON da tabela</label>
                        <textarea class="gpp-json-field large-text code" id="gpp-tabela-simples-json" rows="10" placeholder='[
  {"faixa_etaria": "0 a 18 anos", "valor": "199,90"},
  {"faixa_etaria": "19 a 23 anos", "valor": "229,90"}
]' style="width:100%; background:#fff; color:#333; border-radius:4px;"></textarea>
                        <div class="gpp-status-json" id="gpp-status-tabela-simples" style="color:#fff; font-weight:bold; margin-top:5px;"></div>
                    </div>

                    <!-- Seções para cada tipo de plano -->
                    <?php
                    $tipos = array(
                        'empresarial' => array('emoji' => '📈', 'nome' => 'Empresarial'),
                        'individual' => array('emoji' => '👤', 'nome' => 'Individual'),
                        'pme' => array('emoji' => '🏢', 'nome' => 'PME'),
                        'adesao' => array('emoji' => '🤝', 'nome' => 'Adesao')
                    );

                    foreach ($tipos as $tipo_key => $tipo_info):
                    ?>
                        <div class="gpp-secao-tipo" id="gpp-secao-<?php echo $tipo_key; ?>" style="display: none; padding: 20px; margin: 20px 0; border-radius: 5px;">
                            <h3 style="margin-top: 0;"><?php echo $tipo_info['emoji']; ?> Planos <?php echo $tipo_info['nome']; ?></h3>

                            <div style="margin: 15px 0; padding: 15px;">
                                <label style="color: #FFFFFF; display: block; margin-bottom: 5px;"><strong>📝 Nota / Observação do plano <?php echo $tipo_info['nome']; ?> (opcional):</strong></label>
                                <textarea class="large-text" id="gpp-nota-<?php echo $tipo_key; ?>" rows="3" placeholder="Ex.: Plano voltado para empresas a partir de 2 vidas..."></textarea>
                                <p style="color: #FFFFFF; font-size: 12px; margin: 5px 0 0 0;">Anotação interna (uso administrativo). <strong>Não</strong> é exibida no site.</p>
                            </div>

                            <div style="margin: 15px 0; padding: 15px;">
                                <p style="color: #FFFFFF;"><strong>Selecione as acomodações disponíveis:</strong></p>
                                <label style="display: block; margin: 5px 0;">
                                    <input type="checkbox" class="gpp-acomodacao-check" data-tipo="<?php echo $tipo_key; ?>" data-acomodacao="ambulatorial">
                                    🏥 Ambulatorial
                                </label>
                                <label style="display: block; margin: 5px 0;">
                                    <input type="checkbox" class="gpp-acomodacao-check" data-tipo="<?php echo $tipo_key; ?>" data-acomodacao="enfermaria">
                                    🛏️ Enfermaria
                                </label>
                                <label style="display: block; margin: 5px 0;">
                                    <input type="checkbox" class="gpp-acomodacao-check" data-tipo="<?php echo $tipo_key; ?>" data-acomodacao="apartamento">
                                    🏨 Apartamento
                                </label>
                            </div>
                            
                            <!-- Campos JSON por acomodação -->
                            <?php 
                            $acomodacoes = array(
                                'ambulatorial' => '🏥 Ambulatorial',
                                'enfermaria' => '🛏️ Enfermaria',
                                'apartamento' => '🏨 Apartamento'
                            );
                            
                            foreach ($acomodacoes as $acom_key => $acom_nome):
                            ?>
                                <div class="gpp-campos-acomodacao" id="gpp-campos-<?php echo $tipo_key; ?>-<?php echo $acom_key; ?>" style="display: none; margin: 20px 0; padding: 15px;">
                                    <h4 style="margin-top: 0; width: 100%;"><?php echo $acom_nome; ?></h4>
                                    
                                    <div class="gpp-campos-wrapper">
                                        <div class="gpp-campo-total">
                                            <label><strong>Coparticipação Total</strong></label>
                                            <textarea class="gpp-json-field large-text code" id="gpp-<?php echo $tipo_key; ?>-<?php echo $acom_key; ?>-total-json" rows="6"></textarea>
                                            <div class="gpp-status-json" id="gpp-status-<?php echo $tipo_key; ?>-<?php echo $acom_key; ?>-total"></div>
                                        </div>
                                        
                                        <div class="gpp-campo-parcial">
                                            <label><strong>Coparticipação Parcial</strong></label>
                                            <textarea class="gpp-json-field large-text code" id="gpp-<?php echo $tipo_key; ?>-<?php echo $acom_key; ?>-parcial-json" rows="6"></textarea>
                                            <div class="gpp-status-json" id="gpp-status-<?php echo $tipo_key; ?>-<?php echo $acom_key; ?>-parcial"></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                        </div>
                    <?php endforeach; ?>

                    <p class="submit">
                        <button type="submit" class="button button-primary">Salvar</button>
                        <button type="button" class="button gpp-cancelar">Cancelar</button>
                    </p>
                </form>
            </div>
        </div>
        
        <style>
            .gpp-modal {
                position: fixed;
                z-index: 100000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                overflow: auto;
                background-color: rgba(0,0,0,0.7);
            }
            
            .gpp-modal-content {
                background-color: #fefefe;
                margin: 2% auto;
                padding: 20px;
                border: 1px solid #888;
                width: 90%;
                max-width: 1200px;
                border-radius: 5px;
                max-height: 90vh;
                overflow-y: auto;
            }
            
            .gpp-modal-close {
                color: #aaa;
                float: right;
                font-size: 28px;
                font-weight: bold;
                cursor: pointer;
            }
            
            .gpp-modal-close:hover,
            .gpp-modal-close:focus {
                color: black;
            }
            
            .gpp-status-success {
                color: #46b450;
                margin-top: 5px;
            }
            
            .gpp-status-error {
                color: #dc3232;
                margin-top: 5px;
            }
            
            .gpp-shortcode-item:hover {
                opacity: 0.8;
                transform: scale(1.05);
            }
            
            /* ===== CORES ESPECÍFICAS PARA CADA TIPO DE PLANO ===== */
            
            /* EMPRESARIAL - AZUL VIBRANTE */
            #gpp-secao-empresarial {
                border-color: #0066FF !important;
                border-width: 4px !important;
                background: #0066FF !important;
            }
            
            #gpp-secao-empresarial h3 {
                color: #FFFFFF !important;
                border-bottom: 3px solid #FFFFFF;
                padding-bottom: 10px;
            }
            
            #gpp-secao-empresarial .gpp-campos-acomodacao {
                border-left-color: #0066FF !important;
                border-left-width: 5px !important;
                background: #3385FF !important;
            }
            
            #gpp-secao-empresarial .gpp-campos-acomodacao h4 {
                color: #FFFFFF !important;
            }
            
            #gpp-secao-empresarial label {
                background: #4D94FF !important;
                color: #FFFFFF !important;
                padding: 8px;
                border-radius: 4px;
                transition: all 0.2s;
            }
            
            #gpp-secao-empresarial label:hover {
                background: #1A75FF !important;
                transform: translateX(3px);
            }
            
            #gpp-secao-empresarial > div {
                background: #1A75FF !important;
                padding: 15px;
                border-radius: 4px;
            }
            
            /* INDIVIDUAL - VERDE VIBRANTE */
            #gpp-secao-individual {
                border-color: #00C851 !important;
                border-width: 4px !important;
                background: #00C851 !important;
            }
            
            #gpp-secao-individual h3 {
                color: #FFFFFF !important;
                border-bottom: 3px solid #FFFFFF;
                padding-bottom: 10px;
            }
            
            #gpp-secao-individual .gpp-campos-acomodacao {
                border-left-color: #00C851 !important;
                border-left-width: 5px !important;
                background: #2DD36F !important;
            }
            
            #gpp-secao-individual .gpp-campos-acomodacao h4 {
                color: #FFFFFF !important;
            }
            
            #gpp-secao-individual label {
                background: #4DDB82 !important;
                color: #FFFFFF !important;
                padding: 8px;
                border-radius: 4px;
                transition: all 0.2s;
            }
            
            #gpp-secao-individual label:hover {
                background: #1ACB5E !important;
                transform: translateX(3px);
            }
            
            #gpp-secao-individual > div {
                background: #1ACB5E !important;
                padding: 15px;
                border-radius: 4px;
            }
            
            /* PME - LARANJA FORTE */
            #gpp-secao-pme {
                border-color: #FF6600 !important;
                border-width: 4px !important;
                background: #FF6600 !important;
            }
            
            #gpp-secao-pme h3 {
                color: #FFFFFF !important;
                border-bottom: 3px solid #FFFFFF;
                padding-bottom: 10px;
            }
            
            #gpp-secao-pme .gpp-campos-acomodacao {
                border-left-color: #FF6600 !important;
                border-left-width: 5px !important;
                background: #FF8533 !important;
            }
            
            #gpp-secao-pme .gpp-campos-acomodacao h4 {
                color: #FFFFFF !important;
            }
            
            #gpp-secao-pme label {
                background: #FF9D4D !important;
                color: #FFFFFF !important;
                padding: 8px;
                border-radius: 4px;
                transition: all 0.2s;
            }
            
            #gpp-secao-pme label:hover {
                background: #FF751A !important;
                transform: translateX(3px);
            }
            
            #gpp-secao-pme > div {
                background: #FF751A !important;
                padding: 15px;
                border-radius: 4px;
            }
            
            /* ADESÃO - ROXO FORTE */
            #gpp-secao-adesao {
                border-color: #8E44AD !important;
                border-width: 4px !important;
                background: #8E44AD !important;
            }
            
            #gpp-secao-adesao h3 {
                color: #FFFFFF !important;
                border-bottom: 3px solid #FFFFFF;
                padding-bottom: 10px;
            }
            
            #gpp-secao-adesao .gpp-campos-acomodacao {
                border-left-color: #8E44AD !important;
                border-left-width: 5px !important;
                background: #A569BD !important;
            }
            
            #gpp-secao-adesao .gpp-campos-acomodacao h4 {
                color: #FFFFFF !important;
            }
            
            #gpp-secao-adesao label {
                background: #BB8FCE !important;
                color: #FFFFFF !important;
                padding: 8px;
                border-radius: 4px;
                transition: all 0.2s;
            }
            
            #gpp-secao-adesao label:hover {
                background: #9B59B6 !important;
                transform: translateX(3px);
            }
            
            #gpp-secao-adesao > div {
                background: #9B59B6 !important;
                padding: 15px;
                border-radius: 4px;
            }
            
            /* Melhorias visuais gerais */
            .gpp-secao-tipo {
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            }
            
            .gpp-campos-acomodacao h4 {
                font-size: 16px;
                margin-bottom: 15px;
            }
            
            /* CAMPOS LADO A LADO - 50% CADA */
            .gpp-campos-wrapper {
                display: flex;
                flex-wrap: wrap;
                gap: 20px;
                width: 100%;
            }
            
            .gpp-campo-total,
            .gpp-campo-parcial {
                flex: 1;
                min-width: calc(50% - 10px);
                box-sizing: border-box;
            }
            
            .gpp-campo-total label,
            .gpp-campo-parcial label {
                display: block;
                margin-bottom: 8px;
                color: #FFFFFF;
                font-weight: bold;
            }
            
            .gpp-campo-total textarea,
            .gpp-campo-parcial textarea {
                width: 100%;
                background: #FFFFFF !important;
                color: #333333 !important;
                border: 2px solid rgba(255,255,255,0.5) !important;
                border-radius: 4px;
            }
            
            .gpp-campo-total textarea:focus,
            .gpp-campo-parcial textarea:focus {
                border-color: #FFFFFF !important;
                box-shadow: 0 0 5px rgba(255,255,255,0.8) !important;
            }
            
            .gpp-status-json {
                color: #FFFFFF !important;
                font-weight: bold;
                margin-top: 5px;
            }
            
            @media (max-width: 1200px) {
                .gpp-campo-total,
                .gpp-campo-parcial {
                    min-width: 100%;
                }
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            var modal = $('#gpp-modal');
            <?php
            $ops_js = array();
            foreach ($this->operadoras as $k => $o) {
                $ops_js[$k] = array(
                    'nome'    => $o['nome'],
                    'simples' => $this->operadora_e_simples($k),
                    'prefixo' => $o['prefixo'],
                );
            }
            ?>
            var GPP_OPS = <?php echo wp_json_encode($ops_js); ?>;
            var GPP_OPERADORA = '<?php echo esc_js($operadora_inicial); ?>';
            var GPP_SIMPLES = !!(GPP_OPS[GPP_OPERADORA] && GPP_OPS[GPP_OPERADORA].simples);

            var GPP_URL_VARIAVEIS = '<?php echo admin_url('admin.php?page=gpp-variaveis&operadora='); ?>';
            var GPP_URL_ADMIN = '<?php echo admin_url('admin.php?page=gerenciador-precos-planos&operadora='); ?>';

            // Ajusta os campos do modal conforme o modo da operadora (simples x completo)
            function gppAplicarModoModal(simples) {
                if (simples) {
                    $('.gpp-row-completo').hide();
                    $('.gpp-secao-tipo').hide();
                    $('#gpp-secao-simples').show();
                    if (GPP_OPS[GPP_OPERADORA]) {
                        $('#gpp-simples-op-nome').text(GPP_OPS[GPP_OPERADORA].nome);
                    }
                } else {
                    $('.gpp-row-completo').show();
                    $('#gpp-secao-simples').hide();
                    // As seções de tipo permanecem controladas pelos checkboxes
                }
            }

            // Troca de operadora SEM recarregar a página
            function gppTrocarOperadora(op) {
                if (!GPP_OPS[op]) { return; }
                GPP_OPERADORA = op;
                GPP_SIMPLES = !!GPP_OPS[op].simples;

                $('.gpp-op-tab').removeClass('nav-tab-active').css('box-shadow', '');
                $('.gpp-op-tab[data-operadora="' + op + '"]').addClass('nav-tab-active');

                $('.gpp-op-panel').hide();
                $('.gpp-op-panel[data-operadora="' + op + '"]').show();

                $('#gpp-add-op-nome, #gpp-desc-op-nome').text(GPP_OPS[op].nome);
                $('#gpp-link-variaveis').attr('href', GPP_URL_VARIAVEIS + op);

                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', GPP_URL_ADMIN + op);
                }
            }

            $('.gpp-op-tab').on('click', function(e) {
                e.preventDefault();
                gppTrocarOperadora($(this).data('operadora'));
            });

            // ===== SISTEMA GLOBAL DE DESCONTOS =====
            
            // Habilita campo personalizado quando radio é selecionado
            $('input[name="gpp-tipo-desconto-global"]').on('change', function() {
                if ($('#gpp-desconto-personalizado-global-radio').is(':checked')) {
                    $('#gpp-desconto-personalizado-global').prop('disabled', false);
                } else {
                    $('#gpp-desconto-personalizado-global').prop('disabled', true).val('');
                }
            });
            
            // Aplicar desconto global
            $('#gpp-aplicar-desconto-global').on('click', function() {
                var tipoDesconto = $('input[name="gpp-tipo-desconto-global"]:checked').val();
                
                if (!tipoDesconto) {
                    alert('Selecione um tipo de desconto');
                    return;
                }
                
                var valorDesconto = 0;
                if (tipoDesconto === '15') {
                    valorDesconto = 15;
                } else if (tipoDesconto === 'personalizado') {
                    valorDesconto = parseFloat($('#gpp-desconto-personalizado-global').val());
                    if (!valorDesconto || valorDesconto <= 0) {
                        alert('Digite um valor válido para o desconto personalizado');
                        return;
                    }
                }
                
                if (!confirm('Aplicar desconto de ' + valorDesconto + '% em TODAS as cidades e TODOS os tipos de planos?')) {
                    return;
                }
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'gpp_aplicar_desconto_global',
                        valor_desconto: valorDesconto,
                        tipo: tipoDesconto,
                        operadora: GPP_OPERADORA,
                        nonce: '<?php echo wp_create_nonce('gpp_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data);
                            location.reload();
                        } else {
                            alert('Erro: ' + response.data);
                        }
                    }
                });
            });
            
            // Remover todos os descontos
            $('#gpp-remover-todos-descontos').on('click', function() {
                if (!confirm('Remover TODOS os descontos de TODAS as cidades e TODOS os tipos de planos?')) {
                    return;
                }
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'gpp_remover_todos_descontos',
                        operadora: GPP_OPERADORA,
                        nonce: '<?php echo wp_create_nonce('gpp_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data);
                            location.reload();
                        } else {
                            alert('Erro: ' + response.data);
                        }
                    }
                });
            });
            
            // ===== FIM SISTEMA GLOBAL =====
            
            // Copiar shortcodes principais na tabela
            $(document).on('click', '.gpp-shortcode-item', function() {
                var shortcode = $(this).data('shortcode');
                
                var $temp = $('<input>');
                $('body').append($temp);
                $temp.val(shortcode).select();
                document.execCommand('copy');
                $temp.remove();
                
                var $this = $(this);
                var originalText = $this.text();
                var originalBg = $this.css('background-color');
                
                $this.css('background-color', '#46b450');
                $this.text('✓ Copiado!');
                
                setTimeout(function() {
                    $this.css('background-color', originalBg);
                    $this.text(originalText);
                }, 1500);
            });
            
            // Controle dos tipos de planos
            $('.gpp-tipo-plano-check').on('change', function() {
                var tipo = $(this).data('tipo');
                var secao = $('#gpp-secao-' + tipo);
                
                if ($(this).is(':checked')) {
                    secao.slideDown(300);
                } else {
                    secao.slideUp(300);
                    // Desmarca todas as acomodações
                    secao.find('.gpp-acomodacao-check').prop('checked', false).trigger('change');
                }
            });
            
            // Controle das acomodações
            $('.gpp-acomodacao-check').on('change', function() {
                var tipo = $(this).data('tipo');
                var acomodacao = $(this).data('acomodacao');
                var campos = $('#gpp-campos-' + tipo + '-' + acomodacao);
                
                if ($(this).is(':checked')) {
                    campos.slideDown(300);
                } else {
                    campos.slideUp(300);
                }
            });
            
            // Validação JSON em tempo real
            $('.gpp-json-field').on('input', function() {
                var id = $(this).attr('id');
                var statusId = id.replace('-json', '').replace(/^gpp-/, 'gpp-status-');
                var statusDiv = $('#' + statusId);
                var valor = $(this).val().trim();
                
                if (valor === '') {
                    statusDiv.html('');
                    return;
                }
                
                try {
                    var dados = JSON.parse(valor);
                    if (!Array.isArray(dados)) {
                        statusDiv.html('<span class="gpp-status-error">✗ Erro: JSON deve ser um array</span>');
                        return;
                    }
                    
                    for (var i = 0; i < dados.length; i++) {
                        var item = dados[i];
                        
                        // Verifica se tem faixa_etaria
                        if (!item.faixa_etaria) {
                            statusDiv.html('<span class="gpp-status-error">✗ Erro: Item ' + (i+1) + ' sem faixa_etaria</span>');
                            return;
                        }
                        
                        // Verifica se tem algum campo de valor (aceita múltiplos formatos)
                        var temValor = item.valor || item.coparticipacao_total || item.coparticipacao_parcial;
                        if (!temValor) {
                            statusDiv.html('<span class="gpp-status-error">✗ Erro: Item ' + (i+1) + ' sem valor (use "valor", "coparticipacao_total" ou "coparticipacao_parcial")</span>');
                            return;
                        }
                    }
                    
                    statusDiv.html('<span class="gpp-status-success">✓ JSON válido (' + dados.length + ' faixas)</span>');
                } catch (e) {
                    statusDiv.html('<span class="gpp-status-error">✗ Erro: ' + e.message + '</span>');
                }
            });
            
            // Controle desconto personalizado
            $('#gpp-desconto-personalizado-check').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#gpp-desconto-personalizado-field').show();
                    $('#gpp-desconto-15').prop('checked', false).prop('disabled', true);
                } else {
                    $('#gpp-desconto-personalizado-field').hide();
                    $('#gpp-desconto-personalizado').val('');
                    $('#gpp-desconto-15').prop('disabled', false);
                }
            });
            
            // Controle descontos diferenciados
            $('#gpp-tem-desconto-diferenciado').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#gpp-descontos-diferenciados-container').slideDown(300);
                    // Desabilita controles de desconto global
                    $('#gpp-desconto-15').prop('disabled', true);
                    $('#gpp-desconto-personalizado-check').prop('disabled', true);
                    $('#gpp-desconto-personalizado').prop('disabled', true);
                } else {
                    $('#gpp-descontos-diferenciados-container').slideUp(300);
                    // Limpa campos
                    $('.gpp-desconto-diferenciado').val('');
                    // Habilita controles de desconto global
                    $('#gpp-desconto-15').prop('disabled', false);
                    $('#gpp-desconto-personalizado-check').prop('disabled', false);
                    if ($('#gpp-desconto-personalizado-check').is(':checked')) {
                        $('#gpp-desconto-personalizado').prop('disabled', false);
                    }
                }
            });
            
            // Abrir modal adicionar
            $('#gpp-adicionar-cidade').on('click', function() {
                $('#gpp-modal-titulo').text('Adicionar Nova Cidade');
                $('#gpp-form-cidade')[0].reset();
                $('#gpp-cidade-id').val('');
                $('#gpp-desconto-personalizado-field').hide();
                $('#gpp-descontos-diferenciados-container').hide();
                $('#gpp-desconto-15').prop('disabled', false);
                $('#gpp-desconto-personalizado-check').prop('disabled', false);
                $('.gpp-secao-tipo').hide();
                $('.gpp-campos-acomodacao').hide();
                $('.gpp-tipo-plano-check').prop('checked', false);
                $('.gpp-acomodacao-check').prop('checked', false);
                $('.gpp-desconto-diferenciado').val('');
                $('#gpp-tabela-simples-json').val('');
                $('.gpp-status-json').empty();
                gppAplicarModoModal(GPP_SIMPLES);
                modal.show();
            });

            // Abrir modal editar
            $(document).on('click', '.gpp-editar-cidade', function() {
                var cidadeId = $(this).data('cidade-id');

                $('#gpp-modal-titulo').text('Editar Cidade');
                $('#gpp-cidade-id').val(cidadeId);
                gppAplicarModoModal(GPP_SIMPLES);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'gpp_buscar_cidade',
                        cidade_id: cidadeId,
                        operadora: GPP_OPERADORA,
                        nonce: '<?php echo wp_create_nonce('gpp_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            var cidade = response.data;
                            
                            $('#gpp-nome').val(cidade.nome);
                            
                            // Verifica se tem descontos diferenciados
                            if (cidade.tem_desconto_diferenciado) {
                                $('#gpp-tem-desconto-diferenciado').prop('checked', true);
                                $('#gpp-descontos-diferenciados-container').show();
                                $('#gpp-desconto-15').prop('disabled', true);
                                $('#gpp-desconto-personalizado-check').prop('disabled', true);
                                $('#gpp-desconto-personalizado').prop('disabled', true);
                                
                                // Preenche descontos diferenciados
                                if (cidade.descontos_diferenciados) {
                                    $('#gpp-desc-dif-empresarial').val(cidade.descontos_diferenciados.empresarial || '');
                                    $('#gpp-desc-dif-individual').val(cidade.descontos_diferenciados.individual || '');
                                    $('#gpp-desc-dif-pme').val(cidade.descontos_diferenciados.pme || '');
                                    $('#gpp-desc-dif-adesao').val(cidade.descontos_diferenciados.adesao || '');
                                }
                            } else {
                                // Configura desconto global
                                if (cidade.desconto_personalizado && cidade.desconto_personalizado > 0) {
                                    $('#gpp-desconto-personalizado-check').prop('checked', true);
                                    $('#gpp-desconto-personalizado').val(cidade.desconto_personalizado);
                                    $('#gpp-desconto-personalizado-field').show();
                                    $('#gpp-desconto-15').prop('disabled', true);
                                } else {
                                    $('#gpp-desconto-personalizado-check').prop('checked', false);
                                    $('#gpp-desconto-15').prop('disabled', false);
                                }
                                
                                if (cidade.desconto_15) {
                                    $('#gpp-desconto-15').prop('checked', true);
                                }
                            }
                            
                            // MODO SIMPLES: preenche a tabela única e encerra
                            if (GPP_SIMPLES) {
                                if (cidade.tabela_simples && cidade.tabela_simples.length > 0) {
                                    $('#gpp-tabela-simples-json').val(JSON.stringify(cidade.tabela_simples, null, 2));
                                } else {
                                    $('#gpp-tabela-simples-json').val('');
                                }
                                $('.gpp-status-json').empty();
                                modal.show();
                                return;
                            }

                            // Configura tipos de planos e acomodações
                            var tipos = ['empresarial', 'individual', 'pme', 'adesao'];
                            var acomodacoes = ['ambulatorial', 'enfermaria', 'apartamento'];

                            tipos.forEach(function(tipo) {
                                if (cidade.tipos_planos_ativos && cidade.tipos_planos_ativos[tipo]) {
                                    $('#gpp-tipo-' + tipo).prop('checked', true);
                                    $('#gpp-secao-' + tipo).show();

                                    // Preenche a nota/observação do plano
                                    $('#gpp-nota-' + tipo).val(cidade[tipo + '_nota'] || '');

                                    acomodacoes.forEach(function(acom) {
                                        var campoAtivoAcom = tipo + '_' + acom + '_ativo';
                                        if (cidade[campoAtivoAcom]) {
                                            $('.gpp-acomodacao-check[data-tipo="' + tipo + '"][data-acomodacao="' + acom + '"]').prop('checked', true);
                                            $('#gpp-campos-' + tipo + '-' + acom).show();
                                            
                                            // Preenche JSONs
                                            var campoTotal = tipo + '_' + acom + '_total';
                                            if (cidade[campoTotal] && cidade[campoTotal].length > 0) {
                                                $('#gpp-' + tipo + '-' + acom + '-total-json').val(JSON.stringify(cidade[campoTotal], null, 2));
                                            }
                                            
                                            var campoParcial = tipo + '_' + acom + '_parcial';
                                            if (cidade[campoParcial] && cidade[campoParcial].length > 0) {
                                                $('#gpp-' + tipo + '-' + acom + '-parcial-json').val(JSON.stringify(cidade[campoParcial], null, 2));
                                            }
                                        }
                                    });
                                }
                            });
                            
                            $('.gpp-status-json').empty();
                            modal.show();
                        }
                    }
                });
            });
            
            // Fechar modal
            $('.gpp-modal-close, .gpp-cancelar').on('click', function() {
                modal.hide();
            });
            
            // Salvar cidade
            $('#gpp-form-cidade').on('submit', function(e) {
                e.preventDefault();

                // ===== MODO SIMPLES: nome + desconto global + tabela única =====
                if (GPP_SIMPLES) {
                    var descontoPersonalizadoS = 0;
                    if ($('#gpp-desconto-personalizado-check').is(':checked')) {
                        descontoPersonalizadoS = $('#gpp-desconto-personalizado').val();
                    }

                    var formDataS = {
                        action: 'gpp_salvar_cidade',
                        nonce: '<?php echo wp_create_nonce('gpp_nonce'); ?>',
                        operadora: GPP_OPERADORA,
                        cidade_id: $('#gpp-cidade-id').val(),
                        nome: $('#gpp-nome').val(),
                        desconto_15: $('#gpp-desconto-15').is(':checked') ? 'true' : 'false',
                        desconto_personalizado: descontoPersonalizadoS,
                        tabela_simples: $('#gpp-tabela-simples-json').val().trim()
                    };

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: formDataS,
                        success: function(response) {
                            if (response.success) {
                                alert(response.data.message);
                                location.reload();
                            } else {
                                alert('Erro: ' + response.data);
                            }
                        }
                    });
                    return;
                }

                var temDescontoDiferenciado = $('#gpp-tem-desconto-diferenciado').is(':checked');

                var formData = {
                    action: 'gpp_salvar_cidade',
                    nonce: '<?php echo wp_create_nonce('gpp_nonce'); ?>',
                    operadora: GPP_OPERADORA,
                    cidade_id: $('#gpp-cidade-id').val(),
                    nome: $('#gpp-nome').val(),
                    tem_desconto_diferenciado: temDescontoDiferenciado,
                    tipos_planos_ativos: {},
                    dados_planos: {}
                };
                
                if (temDescontoDiferenciado) {
                    // Coleta descontos diferenciados
                    formData.descontos_diferenciados = {
                        empresarial: parseFloat($('#gpp-desc-dif-empresarial').val()) || 0,
                        individual: parseFloat($('#gpp-desc-dif-individual').val()) || 0,
                        pme: parseFloat($('#gpp-desc-dif-pme').val()) || 0,
                        adesao: parseFloat($('#gpp-desc-dif-adesao').val()) || 0
                    };
                } else {
                    // Coleta desconto global
                    var descontoPersonalizado = 0;
                    if ($('#gpp-desconto-personalizado-check').is(':checked')) {
                        descontoPersonalizado = $('#gpp-desconto-personalizado').val();
                    }
                    
                    formData.desconto_15 = $('#gpp-desconto-15').is(':checked') ? 'true' : 'false';
                    formData.desconto_personalizado = descontoPersonalizado;
                }
                
                // Coleta dados de cada tipo
                var tipos = ['empresarial', 'individual', 'pme', 'adesao'];
                var acomodacoes = ['ambulatorial', 'enfermaria', 'apartamento'];
                
                tipos.forEach(function(tipo) {
                    formData.tipos_planos_ativos[tipo] = $('#gpp-tipo-' + tipo).is(':checked');

                    if (formData.tipos_planos_ativos[tipo]) {
                        // Coleta a nota/observação do plano
                        formData.dados_planos[tipo + '_nota'] = $('#gpp-nota-' + tipo).val();

                        acomodacoes.forEach(function(acom) {
                            var isAcomAtivo = $('.gpp-acomodacao-check[data-tipo="' + tipo + '"][data-acomodacao="' + acom + '"]').is(':checked');
                            formData.dados_planos[tipo + '_' + acom + '_ativo'] = isAcomAtivo;
                            
                            if (isAcomAtivo) {
                                // Coleta JSON total
                                var jsonTotal = $('#gpp-' + tipo + '-' + acom + '-total-json').val().trim();
                                if (jsonTotal) {
                                    formData.dados_planos[tipo + '_' + acom + '_total'] = jsonTotal;
                                }
                                
                                // Coleta JSON parcial
                                var jsonParcial = $('#gpp-' + tipo + '-' + acom + '-parcial-json').val().trim();
                                if (jsonParcial) {
                                    formData.dados_planos[tipo + '_' + acom + '_parcial'] = jsonParcial;
                                }
                            }
                        });
                    }
                });
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            location.reload();
                        } else {
                            alert('Erro: ' + response.data);
                        }
                    }
                });
            });
            
            // Excluir cidade
            $(document).on('click', '.gpp-excluir-cidade', function() {
                if (!confirm('Tem certeza que deseja excluir esta cidade?')) {
                    return;
                }
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'gpp_excluir_cidade',
                        cidade_id: $(this).data('cidade-id'),
                        operadora: GPP_OPERADORA,
                        nonce: '<?php echo wp_create_nonce('gpp_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data);
                            location.reload();
                        }
                    }
                });
            });
        });
        </script>
        <?php
    }
 
    public function ajax_salvar_cidade() {
        check_ajax_referer('gpp_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }

        $operadora = $this->sanitizar_operadora(isset($_POST['operadora']) ? $_POST['operadora'] : 'hapvida');
        $prefixo = $this->operadoras[$operadora]['prefixo'];

        $cidade_id = (isset($_POST['cidade_id']) && $_POST['cidade_id'] !== '') ? intval($_POST['cidade_id']) : -1;
        $nome = sanitize_text_field($_POST['nome']);

        // O shortcode base inclui o prefixo da operadora (Hapvida = sem prefixo)
        $shortcode = $prefixo . $this->gerar_slug_cidade($nome);
        $cidades = $this->obter_todas_cidades($operadora);

        // ===== MODO SIMPLES: uma única tabela por cidade =====
        if ($this->operadora_e_simples($operadora)) {
            $desconto_15 = isset($_POST['desconto_15']) && $_POST['desconto_15'] === 'true';
            $desconto_personalizado = isset($_POST['desconto_personalizado']) ? floatval($_POST['desconto_personalizado']) : 0;

            $nova_cidade = array(
                'nome'                    => $nome,
                'shortcode'               => $shortcode,
                'operadora'               => $operadora,
                'tipos_planos_ativos'     => array(),
                'tem_desconto_diferenciado' => false,
                'desconto_15'             => $desconto_15,
                'desconto_personalizado'  => $desconto_personalizado,
                'tabela_simples'          => array(),
            );

            if (isset($_POST['tabela_simples']) && !empty($_POST['tabela_simples'])) {
                $dados_normalizados = $this->normalizar_json_plano($_POST['tabela_simples']);
                if ($dados_normalizados !== null) {
                    $nova_cidade['tabela_simples'] = $dados_normalizados;
                }
            }

            if ($cidade_id >= 0 && $cidade_id < count($cidades) && isset($cidades[$cidade_id])) {
                $cidades[$cidade_id] = $nova_cidade;
                $mensagem = 'Cidade atualizada com sucesso!';
            } else {
                $cidades[] = $nova_cidade;
                $mensagem = 'Cidade adicionada com sucesso!';
            }

            update_option($this->operadoras[$operadora]['option'], $cidades);
            wp_send_json_success(array('message' => $mensagem));
            return;
        }

        // Converte tipos_planos_ativos para booleanos corretos
        $tipos_planos_ativos = array(
            'empresarial' => false,
            'individual' => false,
            'pme' => false,
            'adesao' => false
        );
        
        if (isset($_POST['tipos_planos_ativos']) && is_array($_POST['tipos_planos_ativos'])) {
            foreach ($_POST['tipos_planos_ativos'] as $tipo => $valor) {
                $tipos_planos_ativos[$tipo] = ($valor === true || $valor === 'true' || $valor === 1 || $valor === '1');
            }
        }
        
        $nova_cidade = array(
            'nome' => $nome,
            'shortcode' => $shortcode,
            'operadora' => $operadora,
            'tipos_planos_ativos' => $tipos_planos_ativos
        );
        
        // Verifica se tem descontos diferenciados
        $tem_desconto_diferenciado = isset($_POST['tem_desconto_diferenciado']) && 
            ($_POST['tem_desconto_diferenciado'] === true || $_POST['tem_desconto_diferenciado'] === 'true');
        
        $nova_cidade['tem_desconto_diferenciado'] = $tem_desconto_diferenciado;
        
        if ($tem_desconto_diferenciado && isset($_POST['descontos_diferenciados'])) {
            // Usa descontos diferenciados
            $nova_cidade['descontos_diferenciados'] = array(
                'empresarial' => floatval($_POST['descontos_diferenciados']['empresarial']),
                'individual' => floatval($_POST['descontos_diferenciados']['individual']),
                'pme' => floatval($_POST['descontos_diferenciados']['pme']),
                'adesao' => floatval($_POST['descontos_diferenciados']['adesao'])
            );
        } else {
            // Usa desconto global
            $desconto_15 = isset($_POST['desconto_15']) && $_POST['desconto_15'] === 'true';
            $desconto_personalizado = isset($_POST['desconto_personalizado']) ? floatval($_POST['desconto_personalizado']) : 0;
            
            $nova_cidade['desconto_15'] = $desconto_15;
            $nova_cidade['desconto_personalizado'] = $desconto_personalizado;
        }
        
        // Processa dados dos planos
        if (isset($_POST['dados_planos']) && is_array($_POST['dados_planos'])) {
            foreach ($_POST['dados_planos'] as $campo => $valor) {
                if (strpos($campo, '_ativo') !== false) {
                    // Campo booleano
                    $nova_cidade[$campo] = ($valor === 'true' || $valor === true || $valor === 1 || $valor === '1');
                } elseif (substr($campo, -5) === '_nota') {
                    // Campo de nota/observação - texto livre
                    $nova_cidade[$campo] = sanitize_textarea_field($valor);
                } else {
                    // Campo JSON - normaliza o formato
                    if (!empty($valor)) {
                        $dados_normalizados = $this->normalizar_json_plano($valor);
                        
                        if ($dados_normalizados !== null) {
                            $nova_cidade[$campo] = $dados_normalizados;
                        }
                    }
                }
            }
        }
        
        if ($cidade_id >= 0 && $cidade_id < count($cidades) && isset($cidades[$cidade_id])) {
            $cidades[$cidade_id] = $nova_cidade;
            $mensagem = 'Cidade atualizada com sucesso!';
        } else {
            $cidades[] = $nova_cidade;
            $mensagem = 'Cidade adicionada com sucesso!';
        }

        update_option($this->operadoras[$operadora]['option'], $cidades);
        wp_send_json_success(array('message' => $mensagem));
    }
    
    /**
     * AJAX - Excluir cidade
     */
    public function ajax_excluir_cidade() {
        check_ajax_referer('gpp_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }
        
        $operadora = $this->sanitizar_operadora(isset($_POST['operadora']) ? $_POST['operadora'] : 'hapvida');
        $cidade_id = intval($_POST['cidade_id']);
        $cidades = $this->obter_todas_cidades($operadora);

        if (isset($cidades[$cidade_id])) {
            unset($cidades[$cidade_id]);
            $cidades = array_values($cidades);
            update_option($this->operadoras[$operadora]['option'], $cidades);
            wp_send_json_success('Cidade excluída com sucesso!');
        }

        wp_send_json_error('Cidade não encontrada');
    }
    
    /**
     * AJAX - Buscar cidade
     */
    public function ajax_buscar_cidade() {
        check_ajax_referer('gpp_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }
        
        $operadora = $this->sanitizar_operadora(isset($_POST['operadora']) ? $_POST['operadora'] : 'hapvida');
        $cidade_id = intval($_POST['cidade_id']);
        $cidades = $this->obter_todas_cidades($operadora);

        if (isset($cidades[$cidade_id])) {
            wp_send_json_success($cidades[$cidade_id]);
        }

        wp_send_json_error('Cidade não encontrada');
    }
    
    /**
     * AJAX - Aplicar desconto global em todas as cidades
     */
    public function ajax_aplicar_desconto_global() {
        check_ajax_referer('gpp_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }
        
        $operadora = $this->sanitizar_operadora(isset($_POST['operadora']) ? $_POST['operadora'] : 'hapvida');
        $valor_desconto = floatval($_POST['valor_desconto']);
        $tipo = sanitize_text_field($_POST['tipo']);

        $cidades = $this->obter_todas_cidades($operadora);

        foreach ($cidades as &$cidade) {
            // Remove descontos diferenciados
            $cidade['tem_desconto_diferenciado'] = false;
            unset($cidade['descontos_diferenciados']);

            // Aplica desconto global
            if ($tipo === '15') {
                $cidade['desconto_15'] = true;
                $cidade['desconto_personalizado'] = 0;
            } else {
                $cidade['desconto_15'] = false;
                $cidade['desconto_personalizado'] = $valor_desconto;
            }
        }
        unset($cidade);

        update_option($this->operadoras[$operadora]['option'], $cidades);
        wp_send_json_success('Desconto de ' . $valor_desconto . '% aplicado em todas as cidades de ' . $this->operadoras[$operadora]['nome'] . '!');
    }
    
    /**
     * AJAX - Remover todos os descontos
     */
    public function ajax_remover_todos_descontos() {
        check_ajax_referer('gpp_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }
        
        $operadora = $this->sanitizar_operadora(isset($_POST['operadora']) ? $_POST['operadora'] : 'hapvida');
        $cidades = $this->obter_todas_cidades($operadora);

        foreach ($cidades as &$cidade) {
            $cidade['desconto_15'] = false;
            $cidade['desconto_personalizado'] = 0;
            $cidade['tem_desconto_diferenciado'] = false;
            unset($cidade['descontos_diferenciados']);
        }
        unset($cidade);

        update_option($this->operadoras[$operadora]['option'], $cidades);
        wp_send_json_success('Todos os descontos foram removidos!');
    }

    /**
     * AJAX - Salvar valores regionais (SP/BH e Demais Capitais)
     */
    public function ajax_salvar_valores_regionais() {
        check_ajax_referer('gpp_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }

        $valores_json = isset($_POST['valores']) ? stripslashes($_POST['valores']) : '';
        $valores = json_decode($valores_json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error('Erro ao processar os dados');
        }

        // Sanitiza os valores
        $valores_sanitizados = array();
        $regioes_permitidas = array('sp_bh', 'demais_capitais');
        $campos_permitidos = array(
            'consultas_eletivas',
            'consultas_urgencia',
            'exames_simples',
            'exames_complexos',
            'terapias_neurologicas',
            'demais_terapias'
        );

        foreach ($regioes_permitidas as $regiao) {
            if (isset($valores[$regiao]) && is_array($valores[$regiao])) {
                $valores_sanitizados[$regiao] = array();
                foreach ($campos_permitidos as $campo) {
                    if (isset($valores[$regiao][$campo])) {
                        $valores_sanitizados[$regiao][$campo] = sanitize_text_field($valores[$regiao][$campo]);
                    }
                }
            }
        }

        update_option($this->regional_option, $valores_sanitizados);
        wp_send_json_success('Valores regionais salvos com sucesso!');
    }
}

// Inicializa o plugin
function inicializar_gerenciador_precos_planos() {
    return new Gerenciador_Precos_Planos();
}

add_action('plugins_loaded', 'inicializar_gerenciador_precos_planos');

/**
 * Função global para obter valor de uma cidade diretamente via PHP
 */
function gpp_get_valor_cidade($cidade_slug, $tipo_plano, $acomodacao, $coparticipacao, $index = 0) {
    // Procura a cidade em todas as operadoras (shortcodes são globalmente únicos:
    // Hapvida sem prefixo, demais operadoras com prefixo)
    $options_operadoras = array(
        'gpp_cidades_planos',
        'gpp_cidades_planos_amil',
        'gpp_cidades_planos_unimed',
        'gpp_cidades_planos_sulamerica',
    );

    $cidade_encontrada = null;
    foreach ($options_operadoras as $option_name) {
        $cidades = get_option($option_name, array());
        if (!is_array($cidades) || empty($cidades)) {
            continue;
        }
        foreach ($cidades as $cidade) {
            if (isset($cidade['shortcode']) && $cidade['shortcode'] === $cidade_slug) {
                $cidade_encontrada = $cidade;
                break 2;
            }
        }
    }

    if (!$cidade_encontrada) {
        return 'N/A';
    }
    
    $campo = $tipo_plano . '_' . $acomodacao . '_' . $coparticipacao;
    
    if (!isset($cidade_encontrada[$campo]) || empty($cidade_encontrada[$campo])) {
        return 'N/A';
    }
    
    if (!isset($cidade_encontrada[$campo][$index])) {
        return 'N/A';
    }
    
    $valor = $cidade_encontrada[$campo][$index]['valor'];
    
    // Lógica de desconto com prioridade
    $desconto = 0;
    if (isset($cidade_encontrada['tem_desconto_diferenciado']) && $cidade_encontrada['tem_desconto_diferenciado']) {
        if (isset($cidade_encontrada['descontos_diferenciados'][$tipo_plano])) {
            $desconto = floatval($cidade_encontrada['descontos_diferenciados'][$tipo_plano]);
        }
    } else {
        $desconto_personalizado = isset($cidade_encontrada['desconto_personalizado']) ? floatval($cidade_encontrada['desconto_personalizado']) : 0;
        $tem_desconto_15 = isset($cidade_encontrada['desconto_15']) && $cidade_encontrada['desconto_15'] === true;
        
        if ($desconto_personalizado > 0) {
            $desconto = $desconto_personalizado;
        } else if ($tem_desconto_15) {
            $desconto = 15;
        }
    }
    
    $preco_limpo = str_replace(array('R$', ' ', '.'), '', $valor);
    $preco_limpo = str_replace(',', '.', $preco_limpo);
    $preco_numerico = floatval($preco_limpo);
    
    if ($desconto > 0) {
        $multiplicador = 1 - ($desconto / 100);
        $preco_com_desconto = $preco_numerico * $multiplicador;
        return 'R$ ' . number_format($preco_com_desconto, 2, ',', '.');
    }
    
    return 'R$ ' . number_format($preco_numerico, 2, ',', '.');
}