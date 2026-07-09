<?php
/**
 * GPP_Shortcodes — Registro de shortcodes (com proteção de contexto) e o sistema de registro sob demanda por cidade.
 *
 * Parte da classe Gerenciador_Precos_Planos (dividida em traits para
 * facilitar a manutenção). Não usar fora da classe principal.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait GPP_Shortcodes {

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
            error_log('GPP: Variáveis limitadas à primeira, segunda e última faixa de cada tabela');
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
                            // OTIMIZAÇÃO: registra apenas a 1ª, a 2ª e a ÚLTIMA faixa
                            // (independente de quantas faixas a tabela tiver).
                            // Evita sobrecarga com 10 faixas × múltiplas cidades
                            $faixas_permitidas_total = array_flip($this->indices_faixas_registrar(count($cidade_local[$campo_total])));
                            foreach ($cidade_local[$campo_total] as $index => $plano) {
                                if (!isset($faixas_permitidas_total[$index])) {
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
                            // OTIMIZAÇÃO: registra apenas a 1ª, a 2ª e a ÚLTIMA faixa
                            // (independente de quantas faixas a tabela tiver).
                            // Evita sobrecarga com 10 faixas × múltiplas cidades
                            $faixas_permitidas_parcial = array_flip($this->indices_faixas_registrar(count($cidade_local[$campo_parcial])));
                            foreach ($cidade_local[$campo_parcial] as $index => $plano) {
                                if (!isset($faixas_permitidas_parcial[$index])) {
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
        // wp_date respeita o fuso horário configurado no WordPress
        add_shortcode('ano_atual', function() {
            return wp_date('Y');
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
            return $meses[(int) wp_date('n')];
        });
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
}
