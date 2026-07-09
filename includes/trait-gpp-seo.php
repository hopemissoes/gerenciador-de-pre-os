<?php
/**
 * GPP_SEO — Integrações de SEO: shortcodes em títulos/metas (Yoast, RankMath etc.), variáveis do RankMath e schema markup (metabox + frontend).
 *
 * Parte da classe Gerenciador_Precos_Planos (dividida em traits para
 * facilitar a manutenção). Não usar fora da classe principal.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait GPP_SEO {

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
                'description' => 'Retorna o ano atual (ex: ' . wp_date('Y') . ')',
                'variable'    => 'ano_atual',
                'example'     => wp_date('Y'),
            ),
            function() {
                return wp_date('Y');
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
                'description' => 'Retorna o mês atual em português (ex: ' . $meses_pt[(int) wp_date('n')] . ')',
                'variable'    => 'mes_atual',
                'example'     => $meses_pt[(int) wp_date('n')],
            ),
            function() use ($meses_pt) {
                return $meses_pt[(int) wp_date('n')];
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
        $preco_numerico = self::converter_valor_para_numero($valor);

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

        // SEGURANÇA: o schema é impresso no frontend sem sanitização (para
        // preservar <script type="application/ld+json">). Por isso, só quem
        // pode publicar HTML irrestrito (admins/editores) pode salvá-lo —
        // evita XSS armazenado por usuários de papel inferior.
        if (!current_user_can('unfiltered_html')) {
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
}
