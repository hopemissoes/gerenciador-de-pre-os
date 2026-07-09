<?php
/*
Plugin Name: Gerenciador de Preços de Planos de Saúde
Description: Plugin para gerenciar tabelas de preços de planos de saúde por cidade e por operadora (Hapvida completa; Amil, Unimed e SulAmérica em modo tabela única) com shortcodes individuais, comparação entre operadoras e sistema de descontos
Version: 9.0
Author: Seu Nome
*/

/*
 * ESTRUTURA DO PLUGIN (a partir da v9 o código é dividido por responsabilidade):
 *
 *   gerenciador-precos-planos.php ... este arquivo: config das operadoras,
 *                                     construtor (hooks) e bootstrap
 *   includes/trait-gpp-helpers.php .. utilitários (valores, descontos, cidades)
 *   includes/trait-gpp-shortcodes.php registro de shortcodes (sob demanda)
 *   includes/trait-gpp-frontend.php . renderização pública (tabelas, CSS)
 *   includes/trait-gpp-seo.php ...... títulos/metas, RankMath, schema
 *   includes/trait-gpp-admin.php .... telas do wp-admin
 *   includes/trait-gpp-ajax.php ..... handlers AJAX
 *
 * Todos os traits compõem a MESMA classe Gerenciador_Precos_Planos —
 * é só uma divisão em arquivos, sem mudança de comportamento.
 */

// Impede acesso direto ao arquivo
if (!defined('ABSPATH')) {
    exit;
}

// ===== ARQUIVOS DA CLASSE (dividida em traits por responsabilidade) =====
require_once __DIR__ . '/includes/trait-gpp-helpers.php';
require_once __DIR__ . '/includes/trait-gpp-shortcodes.php';
require_once __DIR__ . '/includes/trait-gpp-frontend.php';
require_once __DIR__ . '/includes/trait-gpp-seo.php';
require_once __DIR__ . '/includes/trait-gpp-admin.php';
require_once __DIR__ . '/includes/trait-gpp-ajax.php';

class Gerenciador_Precos_Planos {

    // A implementação vive nos traits em includes/ (a classe continua única)
    use GPP_Helpers;
    use GPP_Shortcodes;
    use GPP_Frontend;
    use GPP_SEO;
    use GPP_Admin;
    use GPP_Ajax;
    
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
    
    $preco_numerico = Gerenciador_Precos_Planos::converter_valor_para_numero($valor);

    if ($desconto > 0) {
        $multiplicador = 1 - ($desconto / 100);
        $preco_com_desconto = $preco_numerico * $multiplicador;
        return 'R$ ' . number_format($preco_com_desconto, 2, ',', '.');
    }

    return 'R$ ' . number_format($preco_numerico, 2, ',', '.');
}
