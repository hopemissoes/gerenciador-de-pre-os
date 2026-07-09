<?php
/**
 * GPP_Helpers — Utilitários: config de operadoras, cores, parser/formatação de valores, descontos, acesso às cidades (options) e slugs.
 *
 * Parte da classe Gerenciador_Precos_Planos (dividida em traits para
 * facilitar a manutenção). Não usar fora da classe principal.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait GPP_Helpers {

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

    /**
     * ===== HELPERS DE COR (usados no CSS gerado por operadora) =====
     */
    private function hex_para_rgb($hex) {
        $hex = ltrim(trim((string) $hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return array(0, 84, 184); // fallback: azul Hapvida
        }
        return array(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }

    private function cor_alpha($hex, $alpha) {
        list($r, $g, $b) = $this->hex_para_rgb($hex);
        return 'rgba(' . $r . ', ' . $g . ', ' . $b . ', ' . $alpha . ')';
    }

    private function escurecer_cor($hex, $fator = 0.2) {
        list($r, $g, $b) = $this->hex_para_rgb($hex);
        $r = max(0, (int) round($r * (1 - $fator)));
        $g = max(0, (int) round($g * (1 - $fator)));
        $b = max(0, (int) round($b * (1 - $fator)));
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * Converte um valor monetário em texto para número.
     * Aceita "R$ 1.234,56", "1234,56", "199,90" e também o formato com ponto
     * decimal ("199.90"), que antes era interpretado errado (virava 19990).
     */
    public static function converter_valor_para_numero($valor) {
        $limpo = preg_replace('/[^0-9.,]/', '', (string) $valor);
        if ($limpo === '' || $limpo === null) {
            return 0.0;
        }

        $tem_virgula = strpos($limpo, ',') !== false;
        $tem_ponto   = strpos($limpo, '.') !== false;

        if ($tem_virgula) {
            // Padrão brasileiro: ponto é milhar, vírgula é decimal
            $limpo = str_replace('.', '', $limpo);
            $limpo = str_replace(',', '.', $limpo);
        } elseif ($tem_ponto) {
            $pos_ultimo     = strrpos($limpo, '.');
            $digitos_depois = strlen($limpo) - $pos_ultimo - 1;
            // Um único ponto com 1-2 dígitos depois => decimal ("199.9" / "199.90").
            // Caso contrário ("1.234", "1.234.567") => separador de milhar.
            if (substr_count($limpo, '.') !== 1 || $digitos_depois === 3 || $digitos_depois === 0) {
                $limpo = str_replace('.', '', $limpo);
            }
        }

        return floatval($limpo);
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
                            $preco_numerico = self::converter_valor_para_numero($valor_string);
                            
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

                            $preco_numerico = self::converter_valor_para_numero($valor_string);

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
        
        // Normaliza cada item para usar "valor" (sanitizando os textos)
        $dados_normalizados = array();
        foreach ($dados as $item) {
            if (!is_array($item)) {
                continue;
            }
            $item_normalizado = array();

            // Mantém faixa_etaria
            if (isset($item['faixa_etaria']) && is_scalar($item['faixa_etaria'])) {
                $item_normalizado['faixa_etaria'] = sanitize_text_field((string) $item['faixa_etaria']);
            }

            // Normaliza o campo de valor
            if (isset($item['valor']) && is_scalar($item['valor'])) {
                $item_normalizado['valor'] = sanitize_text_field((string) $item['valor']);
            } elseif (isset($item['coparticipacao_total']) && is_scalar($item['coparticipacao_total'])) {
                $item_normalizado['valor'] = sanitize_text_field((string) $item['coparticipacao_total']);
            } elseif (isset($item['coparticipacao_parcial']) && is_scalar($item['coparticipacao_parcial'])) {
                $item_normalizado['valor'] = sanitize_text_field((string) $item['coparticipacao_parcial']);
            }
            
            // Só adiciona se tiver os campos necessários
            if (isset($item_normalizado['faixa_etaria']) && isset($item_normalizado['valor'])) {
                $dados_normalizados[] = $item_normalizado;
            }
        }
        
        return $dados_normalizados;
    }

    private function formatar_preco_com_desconto($preco, $desconto_percentual) {
        $preco_numerico = self::converter_valor_para_numero($preco);

        if ($desconto_percentual > 0) {
            $multiplicador = 1 - ($desconto_percentual / 100);
            $preco_com_desconto = $preco_numerico * $multiplicador;
            return 'R$ ' . number_format($preco_com_desconto, 2, ',', '.');
        }
        
        return 'R$ ' . number_format($preco_numerico, 2, ',', '.');
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
            $num = self::converter_valor_para_numero($linha['valor']);
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
        $num = self::converter_valor_para_numero($valor_str);
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
     * Gera slug automático a partir do nome da cidade
     */
    private function gerar_slug_cidade($nome) {
        $nome = remove_accents($nome);
        $slug = sanitize_title($nome);
        return $slug;
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
}
