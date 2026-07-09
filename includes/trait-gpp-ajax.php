<?php
/**
 * GPP_Ajax — Handlers AJAX (salvar/excluir/buscar cidade, descontos em massa e valores regionais).
 *
 * Parte da classe Gerenciador_Precos_Planos (dividida em traits para
 * facilitar a manutenção). Não usar fora da classe principal.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait GPP_Ajax {

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
            // SEGURANÇA: só aceita chaves no formato conhecido
            // ({tipo}_{acomodacao}_{ativo|total|parcial} ou {tipo}_nota),
            // impedindo que chaves arbitrárias sobrescrevam campos internos
            // como "nome", "shortcode" ou "operadora".
            $padrao_campo = '/^(empresarial|individual|pme|adesao)_((ambulatorial|enfermaria|apartamento)_(ativo|total|parcial)|nota)$/';
            foreach ($_POST['dados_planos'] as $campo => $valor) {
                if (!preg_match($padrao_campo, $campo)) {
                    continue;
                }
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
