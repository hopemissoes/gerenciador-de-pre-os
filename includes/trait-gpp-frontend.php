<?php
/**
 * GPP_Frontend — Renderização pública: tabelas de preço, comparação entre operadoras, tabela comparativa de cotação e CSS do front-end.
 *
 * Parte da classe Gerenciador_Precos_Planos (dividida em traits para
 * facilitar a manutenção). Não usar fora da classe principal.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait GPP_Frontend {

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
        /* =====================================================================
           GPP — DESIGN SYSTEM DO FRONT-END (v8)
           Todas as regras são ESCOPADAS às classes do plugin (nada vaza para o
           resto do site). As cores vêm de variáveis CSS definidas por operadora.
           ===================================================================== */

        .gpp-container-cidade,
        .gpp-card-operadora,
        .gpp-comparativa-wrap {
            /* Padrão (Hapvida) — sobrescrito pelas classes .gpp-op-* abaixo */
            --gpp-cor: #0054B8;
            --gpp-cor-escura: #003d87;
            --gpp-cor-bg: rgba(0, 84, 184, 0.06);
            --gpp-destaque: #F05A22;
            --gpp-destaque-escuro: #c94515;
            --gpp-destaque-bg: rgba(240, 90, 34, 0.10);
            --gpp-destaque-sombra: rgba(240, 90, 34, 0.40);
        }

        <?php foreach ($this->operadoras as $op_key => $op_info):
            $cor = $op_info['cor'];
            $destaque = $op_info['cor_destaque'];
        ?>
        .gpp-op-<?php echo esc_attr($op_key); ?> {
            --gpp-cor: <?php echo esc_attr($cor); ?>;
            --gpp-cor-escura: <?php echo esc_attr($this->escurecer_cor($cor, 0.25)); ?>;
            --gpp-cor-bg: <?php echo esc_attr($this->cor_alpha($cor, 0.06)); ?>;
            --gpp-destaque: <?php echo esc_attr($destaque); ?>;
            --gpp-destaque-escuro: <?php echo esc_attr($this->escurecer_cor($destaque, 0.18)); ?>;
            --gpp-destaque-bg: <?php echo esc_attr($this->cor_alpha($destaque, 0.10)); ?>;
            --gpp-destaque-sombra: <?php echo esc_attr($this->cor_alpha($destaque, 0.40)); ?>;
        }
        <?php endforeach; ?>

        .gpp-container-cidade {
            margin: 24px 0;
        }

        .gpp-container-cidade p {
            font-weight: normal;
        }

        /* ===== TABELA DE PREÇOS ===== */
        .tabela-precos-hapvida {
            width: 100%;
            margin: 20px 0;
            overflow: hidden;
            background: #FFFFFF !important;
            border: 1px solid #e5eaf1 !important;
            border-radius: 16px !important;
            box-shadow: 0 10px 28px -16px rgba(15, 23, 42, 0.28) !important;
        }

        .tabela-precos-hapvida table {
            width: 100% !important;
            margin: 0 !important;
            border: none !important;
            border-collapse: separate !important;
            border-spacing: 0 !important;
        }

        .tabela-precos-hapvida table th {
            background: linear-gradient(135deg, var(--gpp-cor) 0%, var(--gpp-cor-escura) 100%) !important;
            color: #FFFFFF !important;
            padding: 14px 20px !important;
            text-align: left !important;
            font-weight: 600 !important;
            font-size: 14px !important;
            letter-spacing: 0.6px !important;
            text-transform: uppercase !important;
            border: none !important;
        }

        .tabela-precos-hapvida table th:last-child {
            text-align: right !important;
        }

        .tabela-precos-hapvida table tbody tr td {
            padding: 13px 20px !important;
            border: none !important;
            border-bottom: 1px solid #eef2f7 !important;
            font-weight: normal !important;
            font-size: 15px !important;
            background-color: #FFFFFF !important;
            color: #1e293b !important;
        }

        .tabela-precos-hapvida table tbody tr:nth-child(even) td {
            background-color: #f8fafc !important;
        }

        .tabela-precos-hapvida table tbody tr:last-child td {
            border-bottom: none !important;
        }

        .tabela-precos-hapvida table tbody tr td:last-child {
            text-align: right !important;
        }

        .tabela-precos-hapvida table tbody tr:hover td {
            background-color: var(--gpp-cor-bg) !important;
        }

        .tabela-precos-hapvida .valor-destaque {
            display: inline-block !important;
            background: var(--gpp-destaque-bg) !important;
            color: var(--gpp-destaque) !important;
            font-weight: 700 !important;
            font-size: 0.95em !important;
            padding: 4px 14px !important;
            border-radius: 999px !important;
            white-space: nowrap !important;
            font-variant-numeric: tabular-nums;
        }

        /* ===== AVISOS DE DESCONTO ===== */
        .gpp-desconto-info {
            text-align: center !important;
            font-weight: 700 !important;
            font-size: 14px !important;
            margin: 12px 0 !important;
            padding: 0 !important;
            color: var(--gpp-destaque, #d32f2f) !important;
            background-color: transparent !important;
            border: none !important;
        }

        .gpp-desconto-pequeno {
            text-align: center !important;
            font-weight: 600 !important;
            font-size: 13px !important;
            font-style: italic !important;
            margin: -8px 0 12px 0 !important;
            padding: 0 !important;
            color: var(--gpp-destaque, #F05A22) !important;
            background-color: transparent !important;
        }

        /* ===== CAIXA DE OBSERVAÇÕES ===== */
        .gpp-observacoes-info {
            text-align: left !important;
            font-weight: normal !important;
            font-size: 14px !important;
            line-height: 1.7 !important;
            margin: 18px 0 20px 0 !important;
            padding: 18px 22px !important;
            color: #475569 !important;
            background-color: #f8fafc !important;
            border: 1px solid #e2e8f0 !important;
            border-left: 4px solid var(--gpp-cor, #0054B8) !important;
            border-radius: 12px !important;
            box-shadow: none !important;
        }

        .gpp-observacoes-info > strong:first-child {
            color: var(--gpp-cor, #0054B8) !important;
            font-size: 14px !important;
            display: inline-block;
            margin-bottom: 4px;
        }

        /* ===== BOTÃO DE COTAÇÃO (CTA) ===== */
        .gpp-botao-container {
            text-align: center !important;
            margin: 18px 0 !important;
        }

        .gpp-botao-consulta {
            display: inline-block !important;
            background: linear-gradient(135deg, var(--gpp-destaque, #F05A22) 0%, var(--gpp-destaque-escuro, #d64a1a) 100%) !important;
            color: #FFFFFF !important;
            font-size: 17px !important;
            font-weight: 700 !important;
            letter-spacing: 0.2px !important;
            text-decoration: none !important;
            text-align: center !important;
            padding: 15px 42px !important;
            border-radius: 999px !important;
            margin: 8px 0 20px 0 !important;
            border: none !important;
            box-shadow: 0 10px 22px -10px var(--gpp-destaque-sombra, rgba(240,90,34,0.4)) !important;
            transition: transform 0.2s ease, box-shadow 0.2s ease !important;
        }

        .gpp-botao-consulta:hover,
        .gpp-botao-consulta:focus {
            color: #FFFFFF !important;
            text-decoration: none !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 16px 30px -10px var(--gpp-destaque-sombra, rgba(240,90,34,0.45)) !important;
        }

        /* ===== COMPARAÇÃO ENTRE OPERADORAS (CARDS RESPONSIVOS) ===== */
        .gpp-comparacao-operadoras {
            display: flex !important;
            flex-wrap: wrap !important;
            gap: 24px !important;
            align-items: stretch !important;
            margin: 24px 0 !important;
        }

        .gpp-card-operadora {
            flex: 1 1 320px !important;
            min-width: 300px !important;
            display: flex !important;
            flex-direction: column !important;
            background: #FFFFFF !important;
            border: 1px solid #e5eaf1 !important;
            border-radius: 18px !important;
            overflow: hidden !important;
            box-shadow: 0 14px 34px -18px rgba(15, 23, 42, 0.30) !important;
            transition: transform 0.2s ease, box-shadow 0.2s ease !important;
        }

        .gpp-card-operadora:hover {
            transform: translateY(-3px) !important;
            box-shadow: 0 20px 44px -18px rgba(15, 23, 42, 0.38) !important;
        }

        .gpp-card-operadora .gpp-card-header {
            padding: 16px 20px !important;
            background-image: linear-gradient(135deg, var(--gpp-cor), var(--gpp-cor-escura)) !important;
            color: #FFFFFF !important;
            font-weight: 700 !important;
            font-size: 19px !important;
            text-align: center !important;
            letter-spacing: 0.5px !important;
        }

        .gpp-card-operadora .gpp-container-cidade {
            margin: 0 !important;
            padding: 0 16px 18px 16px !important;
        }

        .gpp-card-operadora .tabela-precos-hapvida {
            margin: 16px 0 0 0 !important;
            border-radius: 12px !important;
            box-shadow: none !important;
        }

        /* ===== TABELA COMPARATIVA DE COTAÇÃO (FAMÍLIA) ===== */
        .gpp-comparativa-wrap {
            overflow-x: auto;
            margin: 0 0 10px 0;
            background: #FFFFFF;
            border: 1px solid #e5eaf1;
            border-radius: 14px;
            box-shadow: 0 10px 28px -16px rgba(15, 23, 42, 0.28);
        }

        .gpp-comparativa {
            width: 100%;
            min-width: 540px;
            margin: 0 !important;
            border: none !important;
            border-collapse: separate !important;
            border-spacing: 0 !important;
            background: #FFFFFF;
        }

        .gpp-comparativa thead th {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%) !important;
            color: #FFFFFF !important;
            padding: 14px 16px !important;
            font-size: 12.5px !important;
            font-weight: 700 !important;
            letter-spacing: 0.7px !important;
            text-transform: uppercase !important;
            text-align: left !important;
            border: none !important;
            border-bottom: 3px solid var(--gpp-destaque, #F05A22) !important;
        }

        .gpp-comparativa tbody td {
            padding: 13px 16px !important;
            font-size: 14.5px !important;
            color: #334155 !important;
            background: #FFFFFF;
            border: none !important;
            border-bottom: 1px solid #eef2f7 !important;
            font-variant-numeric: tabular-nums;
        }

        .gpp-comparativa tbody tr:nth-child(even) td {
            background: #f8fafc;
        }

        .gpp-comparativa tbody tr:last-child td {
            border-bottom: none !important;
        }

        .gpp-comparativa .gpp-op-nome {
            font-weight: 600;
            color: #1a202c;
        }

        .gpp-comparativa tr.gpp-linha-referencia td {
            background: var(--gpp-destaque-bg, #fff7f2) !important;
        }

        .gpp-comparativa tr.gpp-linha-referencia .gpp-op-nome,
        .gpp-comparativa tr.gpp-linha-referencia .gpp-valor-ref {
            color: var(--gpp-destaque, #F05A22);
            font-weight: 800;
        }

        .gpp-badge-referencia {
            display: inline-block;
            margin-left: 8px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            background: var(--gpp-destaque, #F05A22);
            color: #FFFFFF;
            padding: 2px 9px;
            border-radius: 999px;
            vertical-align: middle;
        }

        .gpp-comparativa .gpp-eco-mais-caro {
            color: #c53030;
            font-weight: 600;
        }

        .gpp-comparativa .gpp-eco-mais-barato {
            color: #2f855a;
            font-weight: 600;
        }

        .gpp-comparativa-nota {
            font-size: 12.5px !important;
            font-weight: normal !important;
            color: #64748b !important;
            margin: 6px 2px 20px 2px !important;
        }

        /* ===== RESPONSIVO ===== */
        @media screen and (max-width: 768px) {
            .tabela-precos-hapvida {
                border-radius: 12px !important;
            }
            .tabela-precos-hapvida table th {
                font-size: 12.5px !important;
                padding: 12px 14px !important;
            }
            .tabela-precos-hapvida table tbody tr td {
                font-size: 14px !important;
                padding: 11px 14px !important;
            }
            .gpp-desconto-info {
                font-size: 13px !important;
            }
            .gpp-desconto-pequeno {
                font-size: 12px !important;
            }
            .gpp-observacoes-info {
                font-size: 13px !important;
                padding: 15px 16px !important;
            }
            .gpp-botao-consulta {
                font-size: 16px !important;
                padding: 14px 30px !important;
                width: 100% !important;
                display: block !important;
                margin: 10px auto !important;
                box-sizing: border-box !important;
            }
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
                <a href="<?php echo esc_url($operadora_url); ?>" target="_blank" class="gpp-botao-consulta">
                    Consulte as promoções de hoje
                </a>
            </div>
        <?php endif; ?>
        
    </div>
    <?php
    
    return ob_get_clean();
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
                    <a href="<?php echo esc_url($operadora_cfg['url_botao']); ?>" target="_blank" class="gpp-botao-consulta">
                        Consulte as promoções de hoje
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
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
        <div class="gpp-comparativa-wrap gpp-op-hapvida">
        <table class="gpp-comparativa">
        <thead>
        <tr>
        <th>Operadora</th>
        <th>Mensal (<?php echo (int) $qtd_pessoas; ?> pessoas)</th>
        <th>Anual</th>
        <th><?php echo esc_html($atts['titulo_economia']); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php
        foreach ($ordem as $op_key => $dados):
            $is_hapvida = ($op_key === 'hapvida');

            // Coluna economia
            if ($is_hapvida || $hapvida_anual === null) {
                $economia_html = '<span class="gpp-op-nome">&mdash;</span>';
            } else {
                $diff = $dados['anual'] - $hapvida_anual; // > 0 => operadora mais cara que Hapvida
                if ($diff > 0) {
                    $pct = ($dados['anual'] > 0) ? round(($diff / $dados['anual']) * 100) : 0;
                    $economia_html = '<span class="gpp-eco-mais-caro">-' . $this->formatar_moeda($diff) . ' (' . $pct . '%)</span>';
                } elseif ($diff < 0) {
                    $pct = ($hapvida_anual > 0) ? round((abs($diff) / $hapvida_anual) * 100) : 0;
                    $economia_html = '<span class="gpp-eco-mais-barato">+' . $this->formatar_moeda(abs($diff)) . ' (' . $pct . '%)</span>';
                } else {
                    $economia_html = '<span class="gpp-op-nome">R$ 0,00</span>';
                }
            }
        ?>
        <tr<?php echo $is_hapvida ? ' class="gpp-linha-referencia"' : ''; ?>>
        <td><span class="gpp-op-nome"><?php echo esc_html(strtoupper($dados['nome'])); ?></span><?php if ($is_hapvida): ?><span class="gpp-badge-referencia">Referência</span><?php endif; ?></td>
        <td<?php echo $is_hapvida ? ' class="gpp-valor-ref"' : ''; ?>><?php echo esc_html($this->formatar_moeda($dados['mensal'])); ?></td>
        <td<?php echo $is_hapvida ? ' class="gpp-valor-ref"' : ''; ?>><?php echo esc_html($this->formatar_moeda($dados['anual'])); ?></td>
        <td><?php echo $economia_html; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        </table>
        </div>
        <p class="gpp-comparativa-nota"><?php echo esc_html($desc_familia); ?> Valores sujeitos a alteração; consulte condições.</p>
        <?php
        return ob_get_clean();
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
}
