<?php
/*
Plugin Name: Gerenciador de Preços de Planos de Saúde
Description: Plugin para gerenciar tabelas de preços de planos de saúde por cidade com shortcodes individuais e sistema de descontos
Version: 5.0
Author: Seu Nome
*/

// Impede acesso direto ao arquivo
if (!defined('ABSPATH')) {
    exit;
}

class Gerenciador_Precos_Planos {
    
    private $option_name = 'gpp_cidades_planos';
    private $settings_option = 'gpp_settings';
    
    public function __construct() {
        // Adiciona menu no admin
        add_action('admin_menu', array($this, 'adicionar_menu_admin'));
        
        // Registra shortcode dinâmico
        add_action('init', array($this, 'registrar_shortcodes'));
        
        // Registra shortcodes de variáveis dinâmicas
        add_action('init', array($this, 'registrar_shortcodes_variaveis'));
        
        // ===== NOVA FUNCIONALIDADE: Registra variáveis no RankMath =====
        add_action('rank_math/vars/register', array($this, 'registrar_variaveis_rankmath'));
        
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

        // Meta box para schema com shortcodes
        add_action('add_meta_boxes', array($this, 'adicionar_meta_box_schema'));
        add_action('save_post', array($this, 'salvar_meta_box_schema'));
        add_action('wp_head', array($this, 'renderizar_schema_shortcodes'), 1);
        
        // ===== FILTROS PARA PROCESSAR SHORTCODES EM TÍTULOS E META TAGS =====
        
        // Processa shortcodes em títulos de posts/páginas
        add_filter('the_title', 'do_shortcode', 11);
        add_filter('single_post_title', 'do_shortcode', 11);
        add_filter('wp_title', 'do_shortcode', 11);
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
     * Processa shortcodes nas partes do título do documento
     */
    public function processar_shortcode_title_parts($title_parts) {
        if (isset($title_parts['title'])) {
            $title_parts['title'] = do_shortcode($title_parts['title']);
        }
        if (isset($title_parts['tagline'])) {
            $title_parts['tagline'] = do_shortcode($title_parts['tagline']);
        }
        if (isset($title_parts['site'])) {
            $title_parts['site'] = do_shortcode($title_parts['site']);
        }
        return $title_parts;
    }
    
    /**
     * Processa shortcodes em campos ACF
     */
    public function processar_shortcode_acf($value, $post_id, $field) {
        if (is_string($value)) {
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
            
            if (is_string($real_value) && !empty($real_value)) {
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
                            }
                        }
                    }
                }
            }
        }
        
        return array(
            'shortcode' => $menor_shortcode,
            'valor' => $menor_valor_display
        );
    }
    
    /**
     * Página que lista todas as variáveis disponíveis
     */
public function pagina_variaveis() {
    $cidades = $this->obter_todas_cidades();
    
    // ===== ANÁLISE DINÂMICA: Quais colunas realmente existem? =====
    $colunas_existentes = array();
    
    if (!empty($cidades)) {
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
        
        <div style="background: #fff; padding: 20px; margin: 20px 0; border-left: 4px solid #0054b8;">
            <h2>Como usar as variáveis</h2>
            <p>✅ As variáveis abaixo podem ser usadas em <strong>qualquer lugar do WordPress</strong>: títulos de páginas, textos, meta descriptions, schemas, widgets, etc.</p>
        </div>
        
        <?php if (empty($cidades)): ?>
            <div class="notice notice-warning">
                <p>Nenhuma cidade cadastrada ainda. <a href="<?php echo admin_url('admin.php?page=gerenciador-precos-planos'); ?>">Adicione uma cidade primeiro</a>.</p>
            </div>
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
        $cidades = $this->obter_todas_cidades();
        
        if (!empty($cidades)) {
            foreach ($cidades as $cidade_data) {
                $shortcode_base = $cidade_data['shortcode'];
                
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
                    if (!isset($cidade_data['tipos_planos_ativos'][$tipo_key]) || !$cidade_data['tipos_planos_ativos'][$tipo_key]) {
                        continue;
                    }
                    
                    foreach ($acomodacoes as $acom) {
                        // Verifica se esta acomodação está ativa
                        $campo_ativo_acom = $tipo_key . '_' . $acom . '_ativo';
                        if (!isset($cidade_data[$campo_ativo_acom]) || !$cidade_data[$campo_ativo_acom]) {
                            continue;
                        }
                        
                        // Total
                        $campo_total = $tipo_key . '_' . $acom . '_total';
                        if (!empty($cidade_data[$campo_total])) {
                            // Shortcodes para cada faixa etária
                            foreach ($cidade_data[$campo_total] as $index => $plano) {
                                $shortcode_name = $shortcode_base . '_' . $tipo_sigla . '_' . $acom . 'total_' . $index;
                                
                                add_shortcode($shortcode_name, function() use ($cidade_data, $plano, $tipo_key) {
                                    return $this->obter_valor_formatado_simples($cidade_data, $plano['valor'], $tipo_key);
                                });
                            }
                            
                            // ✅ ATALHO: Shortcode sem índice para primeira faixa (0-18 anos)
                            $shortcode_first = $shortcode_base . '_' . $tipo_sigla . '_' . $acom . 'total';
                            add_shortcode($shortcode_first, function() use ($cidade_data, $campo_total, $tipo_key) {
                                if (!empty($cidade_data[$campo_total][0]['valor'])) {
                                    return $this->obter_valor_formatado_simples($cidade_data, $cidade_data[$campo_total][0]['valor'], $tipo_key);
                                }
                                return 'N/A';
                            });
                        }
                        
                        // Parcial
                        $campo_parcial = $tipo_key . '_' . $acom . '_parcial';
                        if (!empty($cidade_data[$campo_parcial])) {
                            // Shortcodes para cada faixa etária
                            foreach ($cidade_data[$campo_parcial] as $index => $plano) {
                                $shortcode_name = $shortcode_base . '_' . $tipo_sigla . '_' . $acom . 'parcial_' . $index;
                                
                                add_shortcode($shortcode_name, function() use ($cidade_data, $plano, $tipo_key) {
                                    return $this->obter_valor_formatado_simples($cidade_data, $plano['valor'], $tipo_key);
                                });
                            }
                            
                            // ✅ ATALHO: Shortcode sem índice para primeira faixa (0-18 anos)
                            $shortcode_first = $shortcode_base . '_' . $tipo_sigla . '_' . $acom . 'parcial';
                            add_shortcode($shortcode_first, function() use ($cidade_data, $campo_parcial, $tipo_key) {
                                if (!empty($cidade_data[$campo_parcial][0]['valor'])) {
                                    return $this->obter_valor_formatado_simples($cidade_data, $cidade_data[$campo_parcial][0]['valor'], $tipo_key);
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
        
        $cidades = $this->obter_todas_cidades();
        
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

            // Variável universal: menor valor da cidade
            $var_menor = $cidade_slug . '_menorvalor';
            rank_math_register_var_replacement(
                $var_menor,
                array(
                    'name'        => 'Menor Valor: ' . ucfirst($cidade_slug),
                    'description' => 'Menor valor de plano de saúde em ' . ucfirst($cidade_slug) . ' (qualquer tipo)',
                    'variable'    => $var_menor,
                    'example'     => 'R$ 99,81',
                ),
                function() use ($cidade_slug) {
                    $cidades = $this->obter_todas_cidades();
                    foreach ($cidades as $c) {
                        if (isset($c['shortcode']) && $c['shortcode'] === $cidade_slug) {
                            $menor = $this->encontrar_menor_valor_cidade($c);
                            return $menor['valor'] ? $menor['valor'] : 'N/A';
                        }
                    }
                    return 'N/A';
                }
            );

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
                        
                        // Registra variável para cada faixa etária
                        foreach ($cidade[$campo] as $faixa_index => $faixa_data) {
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
        $cidades = $this->obter_todas_cidades();
        
        // Encontra a cidade
        $cidade_encontrada = null;
        foreach ($cidades as $cidade) {
            if (isset($cidade['shortcode']) && $cidade['shortcode'] === $cidade_slug) {
                $cidade_encontrada = $cidade;
                break;
            }
        }
        
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
    
    /**
 * Registra shortcodes dinamicamente para cada cidade
 */
public function registrar_shortcodes() {
    $cidades = $this->obter_todas_cidades();

    if (!empty($cidades)) {
        foreach ($cidades as $cidade_data) {
            $shortcode_base = $cidade_data['shortcode'];

            // SHORTCODE UNIVERSAL: cidade_menorvalor - retorna o menor valor independente do tipo de plano
            $shortcode_menor = $shortcode_base . '_menorvalor';
            add_shortcode($shortcode_menor, function($atts) use ($shortcode_base) {
                $cidades = $this->obter_todas_cidades();
                foreach ($cidades as $c) {
                    if ($c['shortcode'] === $shortcode_base) {
                        $menor = $this->encontrar_menor_valor_cidade($c);
                        if ($menor['valor']) {
                            return $menor['valor'];
                        }
                        return 'N/A';
                    }
                }
                return 'N/A';
            });

            $tipos_plano = array('empresarial', 'individual', 'pme', 'adesao');

            foreach ($tipos_plano as $tipo) {
                // Verifica se este tipo está ativo
                if (isset($cidade_data['tipos_planos_ativos'][$tipo]) && $cidade_data['tipos_planos_ativos'][$tipo]) {
                    
                    // SHORTCODE COMPLETO (ambas coparticipações) - Com disclaimers
                    $shortcode = $shortcode_base . '_' . $tipo;
                    add_shortcode($shortcode, function($atts) use ($shortcode_base, $tipo) {
                        $cidades = $this->obter_todas_cidades();
                        foreach ($cidades as $c) {
                            if ($c['shortcode'] === $shortcode_base) {
                                return $this->renderizar_tabela_cidade($c, $tipo, true, 'AMBAS');
                            }
                        }
                        return '';
                    });
                    
                    // SHORTCODE COMPLETO (ambas coparticipações) - Sem disclaimers
                    $shortcode_sd = $shortcode_base . '_' . $tipo . '_sd';
                    add_shortcode($shortcode_sd, function($atts) use ($shortcode_base, $tipo) {
                        $cidades = $this->obter_todas_cidades();
                        foreach ($cidades as $c) {
                            if ($c['shortcode'] === $shortcode_base) {
                                return $this->renderizar_tabela_cidade($c, $tipo, false, 'AMBAS');
                            }
                        }
                        return '';
                    });
                    
                    // SHORTCODE APENAS TOTAL - Com disclaimers
                    $shortcode_total = $shortcode_base . '_' . $tipo . '_total';
                    add_shortcode($shortcode_total, function($atts) use ($shortcode_base, $tipo) {
                        $cidades = $this->obter_todas_cidades();
                        foreach ($cidades as $c) {
                            if ($c['shortcode'] === $shortcode_base) {
                                return $this->renderizar_tabela_cidade($c, $tipo, true, 'SOMENTE_TOTAL');
                            }
                        }
                        return '';
                    });
                    
                    // SHORTCODE APENAS TOTAL - Sem disclaimers
                    $shortcode_total_sd = $shortcode_base . '_' . $tipo . '_total_sd';
                    add_shortcode($shortcode_total_sd, function($atts) use ($shortcode_base, $tipo) {
                        $cidades = $this->obter_todas_cidades();
                        foreach ($cidades as $c) {
                            if ($c['shortcode'] === $shortcode_base) {
                                return $this->renderizar_tabela_cidade($c, $tipo, false, 'SOMENTE_TOTAL');
                            }
                        }
                        return '';
                    });
                    
                    // SHORTCODE APENAS PARCIAL - Com disclaimers
                    $shortcode_parcial = $shortcode_base . '_' . $tipo . '_parcial';
                    add_shortcode($shortcode_parcial, function($atts) use ($shortcode_base, $tipo) {
                        $cidades = $this->obter_todas_cidades();
                        foreach ($cidades as $c) {
                            if ($c['shortcode'] === $shortcode_base) {
                                return $this->renderizar_tabela_cidade($c, $tipo, true, 'SOMENTE_PARCIAL');
                            }
                        }
                        return '';
                    });
                    
                    // SHORTCODE APENAS PARCIAL - Sem disclaimers
                    $shortcode_parcial_sd = $shortcode_base . '_' . $tipo . '_parcial_sd';
                    add_shortcode($shortcode_parcial_sd, function($atts) use ($shortcode_base, $tipo) {
                        $cidades = $this->obter_todas_cidades();
                        foreach ($cidades as $c) {
                            if ($c['shortcode'] === $shortcode_base) {
                                return $this->renderizar_tabela_cidade($c, $tipo, false, 'SOMENTE_PARCIAL');
                            }
                        }
                        return '';
                    });
                }
            }
        }
    }
}

    /**
 * Renderiza a tabela para uma cidade específica e tipo de plano
 */
/**
 * Renderiza a tabela para uma cidade específica e tipo de plano
 */
private function renderizar_tabela_cidade($cidade_data, $tipo_plano, $mostrar_disclaimers = true, $filtro_coparticipacao = 'AMBAS') {
    ob_start();
    
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
    <div class="gpp-container-cidade">
        
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
                Os valores apresentados referem-se somente ao plano <?php echo $tipo_plano_nome; ?> Hapvida, podendo sofrer alterações ou reajustes a qualquer momento, sem aviso prévio. <br><br>Para obter uma cotação completa — incluindo OUTRAS CIDADES, segmentações, acomodações, coberturas e eventuais promoções vigentes — clique no botão abaixo.
            </div>
            
            <div class="gpp-botao-container">
                <a href="https://tabelaplanos.com.br/plano-hapvida-valores" target="_blank" class="gpp-botao-consulta">
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
     * Obtém todas as cidades cadastradas
     */
    private function obter_todas_cidades() {
        $cidades = get_option($this->option_name, array());
        return is_array($cidades) ? $cidades : array();
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
            overflow-x: auto;
            display: block;
        }
        
        .tabela-precos-hapvida table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .tabela-precos-hapvida table th {
            background-color: #0054B8 !important;
            color: #FFFFFF !important;
            padding: 15px !important;
            text-align: left !important;
            font-weight: bold !important;
            border: none !important;
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
            font-weight: 900 !important;
            font-size: 20px !important;
            margin: 20px 0 10px 0 !important;
            padding: 15px !important;
            color: #FFFFFF !important;
            background-color: #F05A22 !important;
            border-left: 5px solid #d64a1a !important;
            border-radius: 4px !important;
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
            border-radius: 4px !important;
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
            border-radius: 5px !important;
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
            .tabela-precos-hapvida th,
            .tabela-precos-hapvida td {
                padding: 10px 8px !important;
            }
            .gpp-desconto-info {
                font-size: 18px !important;
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
        </style>
        <?php
    }

    // CONTINUA NO PRÓXIMO COMENTÁRIO...
    /**
     * Página administrativa principal
     */
    public function pagina_admin() {
        ?>
        <div class="wrap">
            <h1>Gerenciador de Preços de Planos de Saúde</h1>
            
            <button id="gpp-adicionar-cidade" class="button button-primary" style="margin-bottom: 20px;">Adicionar Nova Cidade</button>
            
            <!-- ===== SISTEMA GLOBAL DE DESCONTOS ===== -->
            <div style="background: #fff; padding: 20px; margin: 20px 0; border: 2px solid #0054b8; border-radius: 5px;">
                <h2 style="margin-top: 0; color: #0054b8;">⚙️ Aplicar Desconto Global em Todas as Cidades</h2>
                <p style="color: #666;">Configure um desconto que será aplicado em <strong>TODAS as cidades</strong> e em <strong>TODOS os tipos de planos</strong>.</p>
                
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
                <a href="<?php echo admin_url('admin.php?page=gpp-variaveis'); ?>" class="button button-secondary">Ver Variáveis Dinâmicas</a>
            </div>
            
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
                    $cidades = $this->obter_todas_cidades();
                    if (!empty($cidades)) {
                        foreach ($cidades as $index => $cidade) {
                            // Calcula info de descontos por tipo
                            $descontos_info = array();
                            $tipos_check = array('empresarial' => 'Emp', 'individual' => 'Ind', 'pme' => 'PME', 'adesao' => 'Ade');
                            
                            foreach ($tipos_check as $tipo_key => $tipo_label) {
                                $desc = $this->obter_desconto_tipo($cidade, $tipo_key);
                                if ($desc > 0) {
                                    $descontos_info[] = $tipo_label . ': ' . $desc . '%';
                                }
                            }
                            
                            $desconto_display = !empty($descontos_info) ? implode('<br>', $descontos_info) : '-';
                            
                            $tipos_ativos = array();
                            
                            // Encontra o menor valor
                            $menor = $this->encontrar_menor_valor_cidade($cidade);
                            
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
                                    <?php if ($menor['shortcode']): ?>
                                        <div style="margin-bottom: 10px; padding: 8px; background: #fff9e6; border-left: 4px solid #ffc107; border-radius: 3px;">
                                            <strong style="color: #f57c00; font-size: 10px;">💰 MENOR VALOR:</strong><br>
                                            <code class="gpp-shortcode-item" data-shortcode="[<?php echo esc_attr($menor['shortcode']); ?>]" style="cursor: pointer; background: #fff3cd; padding: 3px 8px; margin: 2px 5px 2px 0; display: inline-block; border-radius: 3px; font-size: 11px; border: 1px solid #ffc107;">[<?php echo esc_html($menor['shortcode']); ?>]</code>
                                            <small style="color: #f57c00; font-weight: bold;"><?php echo esc_html($menor['valor']); ?></small>
                                            <br>
                                            <strong style="color: #e65100; font-size: 10px;">🌐 UNIVERSAL:</strong>
                                            <code class="gpp-shortcode-item" data-shortcode="[<?php echo esc_attr($cidade['shortcode']); ?>_menorvalor]" style="cursor: pointer; background: #ffe0b2; padding: 3px 8px; margin: 2px 5px 2px 0; display: inline-block; border-radius: 3px; font-size: 11px; border: 1px solid #ff9800;">[<?php echo esc_html($cidade['shortcode']); ?>_menorvalor]</code>
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
                                    
                                    <?php if (empty($shortcodes_por_tipo)): ?>
                                        <em>Nenhum plano cadastrado</em>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $tipos_text; ?></td>
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
                <ol>
                    <li>Adicione ou edite cidades</li>
                    <li>Selecione quais tipos de planos deseja cadastrar (Empresarial, Individual, PME, Adesao)</li>
                    <li>Configure os descontos: use o desconto global OU configure descontos específicos por tipo de plano</li>
                    <li>Para cada tipo, selecione quais acomodações (Ambulatorial, Enfermaria, Apartamento)</li>
                    <li>Configure os preços usando JSON nos campos que aparecerem</li>
                    <li>Copie o shortcode e cole na página</li>
                </ol>
            </div>
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
                        
                        <tr>
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
                        
                        <tr>
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
                $('.gpp-status-json').empty();
                modal.show();
            });
            
            // Abrir modal editar
            $(document).on('click', '.gpp-editar-cidade', function() {
                var cidadeId = $(this).data('cidade-id');
                
                $('#gpp-modal-titulo').text('Editar Cidade');
                $('#gpp-cidade-id').val(cidadeId);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'gpp_buscar_cidade',
                        cidade_id: cidadeId,
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
                            
                            // Configura tipos de planos e acomodações
                            var tipos = ['empresarial', 'individual', 'pme', 'adesao'];
                            var acomodacoes = ['ambulatorial', 'enfermaria', 'apartamento'];
                            
                            tipos.forEach(function(tipo) {
                                if (cidade.tipos_planos_ativos && cidade.tipos_planos_ativos[tipo]) {
                                    $('#gpp-tipo-' + tipo).prop('checked', true);
                                    $('#gpp-secao-' + tipo).show();
                                    
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
                
                var temDescontoDiferenciado = $('#gpp-tem-desconto-diferenciado').is(':checked');
                
                var formData = {
                    action: 'gpp_salvar_cidade',
                    nonce: '<?php echo wp_create_nonce('gpp_nonce'); ?>',
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
        
        $cidade_id = (isset($_POST['cidade_id']) && $_POST['cidade_id'] !== '') ? intval($_POST['cidade_id']) : -1;
        $nome = sanitize_text_field($_POST['nome']);
        
        $shortcode = $this->gerar_slug_cidade($nome);
        $cidades = $this->obter_todas_cidades();
        
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
        
        update_option($this->option_name, $cidades);
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
        
        $cidade_id = intval($_POST['cidade_id']);
        $cidades = $this->obter_todas_cidades();
        
        if (isset($cidades[$cidade_id])) {
            unset($cidades[$cidade_id]);
            $cidades = array_values($cidades);
            update_option($this->option_name, $cidades);
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
        
        $cidade_id = intval($_POST['cidade_id']);
        $cidades = $this->obter_todas_cidades();
        
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
        
        $valor_desconto = floatval($_POST['valor_desconto']);
        $tipo = sanitize_text_field($_POST['tipo']);
        
        $cidades = $this->obter_todas_cidades();
        
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
        
        update_option($this->option_name, $cidades);
        wp_send_json_success('Desconto de ' . $valor_desconto . '% aplicado em todas as cidades!');
    }
    
    /**
     * AJAX - Remover todos os descontos
     */
    public function ajax_remover_todos_descontos() {
        check_ajax_referer('gpp_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }
        
        $cidades = $this->obter_todas_cidades();
        
        foreach ($cidades as &$cidade) {
            $cidade['desconto_15'] = false;
            $cidade['desconto_personalizado'] = 0;
            $cidade['tem_desconto_diferenciado'] = false;
            unset($cidade['descontos_diferenciados']);
        }
        
        update_option($this->option_name, $cidades);
        wp_send_json_success('Todos os descontos foram removidos!');
    }

    /**
     * Adiciona meta box de Schema com Shortcodes em posts e páginas
     */
    public function adicionar_meta_box_schema() {
        $post_types = get_post_types(array('public' => true), 'names');
        foreach ($post_types as $post_type) {
            add_meta_box(
                'gpp_schema_shortcodes',
                '📋 Schema com Shortcodes (Gerenciador de Preços)',
                array($this, 'renderizar_meta_box_schema'),
                $post_type,
                'normal',
                'low'
            );
        }
    }

    /**
     * Renderiza o conteúdo da meta box de schema
     */
    public function renderizar_meta_box_schema($post) {
        wp_nonce_field('gpp_schema_nonce_action', 'gpp_schema_nonce');
        $schema_content = get_post_meta($post->ID, '_gpp_schema_shortcodes', true);
        ?>
        <div style="margin-bottom: 10px;">
            <p style="color: #666;">
                Cole aqui o script de Schema (JSON-LD) com os shortcodes de preços e coparticipação.
                Os shortcodes serão processados automaticamente ao exibir no front-end.<br>
                <strong>Exemplo:</strong> Use <code>[fortaleza_menorvalor]</code>, <code>[fortaleza_emp_ambulatorialtotal_0]</code>, etc.
            </p>
        </div>
        <textarea
            id="gpp_schema_shortcodes"
            name="gpp_schema_shortcodes"
            rows="12"
            style="width: 100%; font-family: monospace; font-size: 13px;"
            placeholder='<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Product",
  "name": "Plano de Saúde",
  "offers": {
    "@type": "Offer",
    "price": "[fortaleza_menorvalor]",
    "priceCurrency": "BRL"
  }
}
</script>'
        ><?php echo esc_textarea($schema_content); ?></textarea>
        <p style="color: #999; font-size: 12px; margin-top: 5px;">
            Inclua a tag <code>&lt;script type="application/ld+json"&gt;</code> completa. Os shortcodes dentro do script serão substituídos pelos valores reais.
        </p>
        <?php
    }

    /**
     * Salva o conteúdo da meta box de schema
     */
    public function salvar_meta_box_schema($post_id) {
        if (!isset($_POST['gpp_schema_nonce']) || !wp_verify_nonce($_POST['gpp_schema_nonce'], 'gpp_schema_nonce_action')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        if (isset($_POST['gpp_schema_shortcodes'])) {
            $schema_content = wp_unslash($_POST['gpp_schema_shortcodes']);
            update_post_meta($post_id, '_gpp_schema_shortcodes', $schema_content);
        }
    }

    /**
     * Renderiza o schema com shortcodes processados no wp_head
     */
    public function renderizar_schema_shortcodes() {
        if (!is_singular()) {
            return;
        }
        $post_id = get_the_ID();
        if (!$post_id) {
            return;
        }
        $schema_content = get_post_meta($post_id, '_gpp_schema_shortcodes', true);
        if (empty($schema_content)) {
            return;
        }
        // Processa os shortcodes dentro do conteúdo do schema
        $schema_processado = do_shortcode($schema_content);
        // Exibe o schema processado diretamente (já contém as tags script)
        echo "\n" . $schema_processado . "\n";
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
    $cidades = get_option('gpp_cidades_planos', array());
    
    if (empty($cidades)) {
        return 'N/A';
    }
    
    $cidade_encontrada = null;
    foreach ($cidades as $cidade) {
        if (isset($cidade['shortcode']) && $cidade['shortcode'] === $cidade_slug) {
            $cidade_encontrada = $cidade;
            break;
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