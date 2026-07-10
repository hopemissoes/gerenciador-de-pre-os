<?php
/**
 * GPP_Admin — Telas do wp-admin: página principal (abas), Variáveis Dinâmicas, Valores Regionais, referência de shortcodes e CSS do admin.
 *
 * Parte da classe Gerenciador_Precos_Planos (dividida em traits para
 * facilitar a manutenção). Não usar fora da classe principal.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait GPP_Admin {

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

    // CONTINUA NO PRÓXIMO COMENTÁRIO...
    /**
     * Página administrativa principal
     */
    public function pagina_admin() {
        $operadora_inicial = $this->sanitizar_operadora(isset($_GET['operadora']) ? $_GET['operadora'] : 'hapvida');
        $cfg_inicial = $this->operadoras[$operadora_inicial];
        $this->imprimir_estilos_admin();
        ?>
        <div class="wrap gpp-admin">
            <h1>💰 Gerenciador de Preços de Planos de Saúde</h1>

            <h2 class="nav-tab-wrapper" style="margin-bottom: 20px;">
                <?php foreach ($this->operadoras as $op_key => $op_info):
                    $classe_ativa = ($op_key === $operadora_inicial) ? ' nav-tab-active' : '';
                ?>
                    <a href="#" class="nav-tab gpp-op-tab<?php echo $classe_ativa; ?>" data-operadora="<?php echo esc_attr($op_key); ?>" style="<?php echo ($op_key === $operadora_inicial) ? 'box-shadow: inset 0 -3px 0 ' . esc_attr($op_info['cor']) . ';' : ''; ?>">
                        <?php echo esc_html($op_info['nome']); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <!-- ===== ABAS INTERNAS: Cidades / Descontos / Referência / Ajuda ===== -->
            <div class="gpp-tabs-internas" role="tablist">
                <button type="button" class="gpp-tab-int gpp-tab-ativa" data-tab="cidades" role="tab">Cidades</button>
                <button type="button" class="gpp-tab-int" data-tab="descontos" role="tab">Descontos</button>
                <button type="button" class="gpp-tab-int" data-tab="referencia" role="tab">Referência de shortcodes</button>
                <button type="button" class="gpp-tab-int" data-tab="ajuda" role="tab">Ajuda</button>
            </div>

            <!-- ===================== ABA: CIDADES ===================== -->
            <div class="gpp-tab-panel" data-tab="cidades">

                <div class="gpp-toolbar">
                    <button id="gpp-adicionar-cidade" class="button button-primary">+ Adicionar cidade em <span id="gpp-add-op-nome"><?php echo esc_html($cfg_inicial['nome']); ?></span></button>
                    <input type="search" id="gpp-busca-admin" class="gpp-busca-admin" placeholder="Buscar cidade…" aria-label="Buscar cidade">
                </div>

            <?php foreach ($this->operadoras as $operadora_ativa => $operadora_cfg):
                $is_simples = $this->operadora_e_simples($operadora_ativa);
                $painel_ativo = ($operadora_ativa === $operadora_inicial);
            ?>
            <div class="gpp-op-panel" data-operadora="<?php echo esc_attr($operadora_ativa); ?>"<?php echo $painel_ativo ? '' : ' style="display:none;"'; ?>>

            <table class="wp-list-table widefat fixed striped gpp-tabela-cidades">
                <thead>
                    <tr>
                        <th style="width: 20%;">Cidade</th>
                        <th style="width: 20%;">Shortcode base</th>
                        <th style="width: 22%;">Planos</th>
                        <th style="width: 14%;">Desconto</th>
                        <th style="width: 24%;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $cidades = $this->obter_todas_cidades($operadora_ativa);
                    if (!empty($cidades)) {
                        foreach ($cidades as $index => $cidade) {
                            // Garante que a operadora esteja disponível para os cálculos/render
                            $cidade['operadora'] = $operadora_ativa;
                            // Calcula info de descontos por tipo (vira badges na coluna Desconto)
                            $desconto_badges = array();
                            if ($is_simples) {
                                // Operadora simples: desconto único (global)
                                $desc_simples = $this->obter_desconto_simples($cidade);
                                if ($desc_simples > 0) {
                                    $desconto_badges[] = $desc_simples . '%';
                                }
                            } else {
                                $tipos_check = array('empresarial' => 'Emp', 'individual' => 'Ind', 'pme' => 'PME', 'adesao' => 'Ade');
                                $descontos_por_valor = array();

                                foreach ($tipos_check as $tipo_key => $tipo_label) {
                                    $desc = $this->obter_desconto_tipo($cidade, $tipo_key);
                                    if ($desc > 0) {
                                        $descontos_por_valor[(string) $desc][] = $tipo_label;
                                    }
                                }

                                // Todos os tipos com o mesmo desconto => um único badge "15%"
                                if (count($descontos_por_valor) === 1 && count(reset($descontos_por_valor)) === count($tipos_check)) {
                                    $desconto_badges[] = array_keys($descontos_por_valor)[0] . '%';
                                } else {
                                    foreach ($descontos_por_valor as $valor_desc => $labels) {
                                        $desconto_badges[] = implode('/', $labels) . ' ' . $valor_desc . '%';
                                    }
                                }
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
                            <tr data-cidade="<?php echo esc_attr(function_exists('mb_strtolower') ? mb_strtolower($cidade['nome']) : strtolower($cidade['nome'])); ?>">
                                <td><strong><?php echo esc_html($cidade['nome']); ?></strong></td>
                                <td><code class="gpp-shortcode-item gpp-chip gpp-chip-primario" data-shortcode="[<?php echo esc_attr($cidade['shortcode']); ?>]" title="Clique para copiar">[<?php echo esc_html($cidade['shortcode']); ?>]</code></td>
                                <td>
                                    <?php if ($is_simples): ?>
                                        <span class="gpp-badge gpp-badge-simples">Plano único</span>
                                    <?php elseif (!empty($shortcodes_por_tipo)): ?>
                                        <?php foreach ($shortcodes_por_tipo as $tipo_key => $tipo_data): ?>
                                            <span class="gpp-badge gpp-badge-<?php echo esc_attr($tipo_key); ?>"><?php echo esc_html($tipo_data['nome']); ?></span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="gpp-texto-vazio">Nenhum plano</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($desconto_badges)): ?>
                                        <?php foreach ($desconto_badges as $badge_desc): ?>
                                            <span class="gpp-badge gpp-badge-desc"><?php echo esc_html($badge_desc); ?></span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="gpp-texto-vazio">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="button gpp-editar-cidade" data-cidade-id="<?php echo $index; ?>">Editar</button>
                                    <button class="button gpp-abrir-gaveta" data-alvo="gpp-sc-<?php echo esc_attr($operadora_ativa); ?>-<?php echo (int) $index; ?>" data-cidade="<?php echo esc_attr($cidade['nome']); ?>">Shortcodes ›</button>
                                    <button class="button gpp-excluir-cidade" data-cidade-id="<?php echo $index; ?>">Excluir</button>

                                    <!-- Conteúdo da gaveta de shortcodes desta cidade (oculto; o JS copia para a gaveta) -->
                                    <div id="gpp-sc-<?php echo esc_attr($operadora_ativa); ?>-<?php echo (int) $index; ?>" class="gpp-gaveta-conteudo" hidden>
                                    <?php if ($is_simples):
                                        $slug_base_cidade = $this->obter_slug_base_cidade($cidade);
                                        $tem_tabela_simples = !empty($cidade['tabela_simples']);
                                    ?>
                                        <?php if ($tem_tabela_simples): ?>
                                            <div class="gpp-bloco-sc" style="--gpp-accent: <?php echo esc_attr($operadora_cfg['cor']); ?>;">
                                                <strong class="gpp-bloco-sc-titulo">🧾 Tabela</strong>
                                                <code class="gpp-shortcode-item gpp-chip gpp-chip-primario" data-shortcode="[<?php echo esc_attr($cidade['shortcode']); ?>]">[<?php echo esc_html($cidade['shortcode']); ?>]</code>
                                                <code class="gpp-shortcode-item gpp-chip" data-shortcode="[<?php echo esc_attr($cidade['shortcode']); ?>_sd]">[<?php echo esc_html($cidade['shortcode']); ?>_sd]</code>
                                                <br>
                                                <strong class="gpp-bloco-sc-titulo" style="color: #b45309; margin-top: 4px;">💰 Valores</strong>
                                                <code class="gpp-shortcode-item gpp-chip gpp-chip-alerta" data-shortcode="[<?php echo esc_attr($cidade['shortcode']); ?>_menorvalor]">[<?php echo esc_html($cidade['shortcode']); ?>_menorvalor]</code>
                                                <code class="gpp-shortcode-item gpp-chip gpp-chip-alerta" data-shortcode="[<?php echo esc_attr($cidade['shortcode']); ?>_maiorvalor]">[<?php echo esc_html($cidade['shortcode']); ?>_maiorvalor]</code>
                                            </div>
                                            <div class="gpp-bloco-sc" style="--gpp-accent: #8E44AD;">
                                                <strong class="gpp-bloco-sc-titulo">⚖️ Comparar</strong>
                                                <code class="gpp-shortcode-item gpp-chip gpp-chip-roxo" data-shortcode="[comparar_<?php echo esc_attr($slug_base_cidade); ?>]">[comparar_<?php echo esc_html($slug_base_cidade); ?>]</code>
                                            </div>
                                        <?php else: ?>
                                            <em>Tabela não cadastrada</em>
                                        <?php endif; ?>
                                    <?php else: ?>
                                    <?php if ($menor['shortcode']): ?>
                                        <div class="gpp-bloco-sc" style="--gpp-accent: #f0a000;">
                                            <strong class="gpp-bloco-sc-titulo" style="color: #b45309;">💰 Menor valor</strong>
                                            <code class="gpp-shortcode-item gpp-chip gpp-chip-alerta" data-shortcode="[<?php echo esc_attr($cidade['shortcode']); ?>_menorvalor]">[<?php echo esc_html($cidade['shortcode']); ?>_menorvalor]</code>
                                            <small style="font-weight: bold; color: #b45309;"><?php echo esc_html($menor['valor']); ?></small>
                                            <br>
                                            <strong class="gpp-bloco-sc-titulo" style="color: #b45309; margin-top: 4px;">📊 Menor tabela</strong>
                                            <code class="gpp-shortcode-item gpp-chip gpp-chip-alerta" data-shortcode="[<?php echo esc_attr($cidade['shortcode']); ?>_menortabela]">[<?php echo esc_html($cidade['shortcode']); ?>_menortabela]</code>
                                            <small>Tabela completa do plano mais barato</small>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($maior['shortcode']): ?>
                                        <div class="gpp-bloco-sc" style="--gpp-accent: #0054b8;">
                                            <strong class="gpp-bloco-sc-titulo">💎 Maior valor</strong>
                                            <code class="gpp-shortcode-item gpp-chip gpp-chip-primario" data-shortcode="[<?php echo esc_attr($cidade['shortcode']); ?>_maiorvalor]">[<?php echo esc_html($cidade['shortcode']); ?>_maiorvalor]</code>
                                            <small style="font-weight: bold; color: #0a4b78;"><?php echo esc_html($maior['valor']); ?></small>
                                        </div>
                                    <?php endif; ?>

                                    <?php foreach ($shortcodes_por_tipo as $tipo_key => $tipo_data): ?>
                                        <div class="gpp-bloco-sc" style="--gpp-accent: <?php echo esc_attr($tipo_data['cor']); ?>;">
                                            <strong class="gpp-bloco-sc-titulo"><?php echo $tipo_data['emoji']; ?> <?php echo esc_html($tipo_data['nome']); ?></strong>
                                            <small>Total:</small>
                                            <code class="gpp-shortcode-item gpp-chip gpp-chip-primario" data-shortcode="<?php echo esc_attr($tipo_data['total']); ?>"><?php echo esc_html($tipo_data['total']); ?></code>
                                            <small>Parcial:</small>
                                            <code class="gpp-shortcode-item gpp-chip gpp-chip-alerta" data-shortcode="<?php echo esc_attr($tipo_data['parcial']); ?>"><?php echo esc_html($tipo_data['parcial']); ?></code>
                                        </div>
                                    <?php endforeach; ?>

                                    <?php if (!empty($shortcodes_por_tipo)):
                                        $slug_base_cidade = $this->obter_slug_base_cidade($cidade);
                                    ?>
                                        <div class="gpp-bloco-sc" style="--gpp-accent: #8E44AD;">
                                            <strong class="gpp-bloco-sc-titulo">⚖️ Comparar operadoras (mesma cidade)</strong>
                                            <?php foreach ($shortcodes_por_tipo as $tipo_key => $tipo_data):
                                                $sc_comp_total = '[comparar_' . $slug_base_cidade . '_' . $tipo_key . '_total]';
                                            ?>
                                                <code class="gpp-shortcode-item gpp-chip gpp-chip-roxo" data-shortcode="<?php echo esc_attr($sc_comp_total); ?>"><?php echo esc_html($sc_comp_total); ?></code>
                                            <?php endforeach; ?>
                                            <br><small>Mostra Hapvida/Amil/Unimed/SulAmérica juntas. Troque <code>_total</code> por <code>_parcial</code> ou remova o sufixo para ambas.</small>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (empty($shortcodes_por_tipo)): ?>
                                        <em>Nenhum plano cadastrado</em>
                                    <?php endif; ?>
                                    <?php endif; // fim do else ($is_simples) ?>
                                    </div><!-- /.gpp-gaveta-conteudo -->
                                </td>
                            </tr>
                            <?php
                        }
                    } else {
                        echo '<tr><td colspan="5">Nenhuma cidade cadastrada ainda.</td></tr>';
                    }
                    ?>
                </tbody>
            </table>

            </div><!-- /.gpp-op-panel -->
            <?php endforeach; ?>

            </div><!-- /aba cidades -->

            <!-- ===================== ABA: DESCONTOS ===================== -->
            <div class="gpp-tab-panel" data-tab="descontos" style="display:none;">
                <div class="gpp-card" style="max-width: 560px;">
                    <h2 style="margin-top: 0;">⚙️ Desconto global — <span id="gpp-desc-op-nome"><?php echo esc_html($cfg_inicial['nome']); ?></span></h2>
                    <p style="color: #666;">Aplica em <strong>todas as cidades</strong> da operadora selecionada na aba de cima. Descontos individuais por cidade continuam no formulário de edição.</p>

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
                        <button id="gpp-aplicar-desconto-global" class="button button-primary">Aplicar em todas as cidades</button>
                        <button id="gpp-remover-todos-descontos" class="button">Remover todos os descontos</button>
                    </div>
                </div>
            </div>

            <!-- ===================== ABA: REFERÊNCIA ===================== -->
            <div class="gpp-tab-panel" data-tab="referencia" style="display:none;">
                <div class="gpp-toolbar">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=gpp-variaveis&operadora=' . $operadora_inicial)); ?>" id="gpp-link-variaveis" class="button button-secondary">Ver Variáveis Dinâmicas (todas as faixas)</a>
                </div>
                <?php foreach ($this->operadoras as $operadora_ativa => $operadora_cfg):
                    $painel_ativo = ($operadora_ativa === $operadora_inicial);
                ?>
                <div class="gpp-op-panel" data-operadora="<?php echo esc_attr($operadora_ativa); ?>"<?php echo $painel_ativo ? '' : ' style="display:none;"'; ?>>
                    <?php $this->renderizar_referencia_shortcodes($operadora_ativa); ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- ===================== ABA: AJUDA ===================== -->
            <div class="gpp-tab-panel" data-tab="ajuda" style="display:none;">
                <?php foreach ($this->operadoras as $operadora_ativa => $operadora_cfg):
                    $is_simples = $this->operadora_e_simples($operadora_ativa);
                    $painel_ativo = ($operadora_ativa === $operadora_inicial);
                ?>
                <div class="gpp-op-panel" data-operadora="<?php echo esc_attr($operadora_ativa); ?>"<?php echo $painel_ativo ? '' : ' style="display:none;"'; ?>>
                <div class="gpp-card" style="border-left: 4px solid <?php echo esc_attr($operadora_cfg['cor']); ?>;">
                    <h2>Como usar — <?php echo esc_html($operadora_cfg['nome']); ?></h2>
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
        </div>

        <!-- ===== GAVETA DE SHORTCODES (abre pela aba Cidades) ===== -->
        <div id="gpp-gaveta-overlay" class="gpp-gaveta-overlay" style="display:none;"></div>
        <aside id="gpp-gaveta" class="gpp-gaveta" aria-hidden="true">
            <div class="gpp-gaveta-cabecalho">
                <div>
                    <h2 id="gpp-gaveta-titulo" style="margin: 0; font-size: 16px;"></h2>
                    <p style="margin: 2px 0 0; font-size: 12px; color: #64748b;">Clique em um shortcode para copiar</p>
                </div>
                <button type="button" id="gpp-gaveta-fechar" class="gpp-gaveta-fechar" aria-label="Fechar">✕</button>
            </div>
            <div id="gpp-gaveta-corpo" class="gpp-gaveta-corpo"></div>
        </aside>

        <!-- Modal -->
        <div id="gpp-modal" class="gpp-modal" style="display: none;">
            <div class="gpp-modal-content">
                <span class="gpp-modal-close">&times;</span>
                <h2 id="gpp-modal-titulo">Adicionar Cidade</h2>
                
                <form id="gpp-form-cidade">
                    <input type="hidden" id="gpp-cidade-id" value="">

                    <!-- ===== ABAS DO MODAL =====
                         O formulário inteiro é dividido em abas: Dados da cidade,
                         Descontos e uma aba por tipo de plano marcado (ou a aba
                         "Tabela de preços" nas operadoras de tabela única). -->
                    <div id="gpp-tipos-tabs" class="gpp-tipos-tabs">
                        <button type="button" class="gpp-tab-tipo gpp-tab-tipo-ativa" data-alvo="dados" style="--gpp-accent:#0054b8;">🏙️ Dados da cidade</button>
                        <button type="button" class="gpp-tab-tipo" data-alvo="descontos" style="--gpp-accent:#c2410c;">💸 Descontos</button>
                        <button type="button" class="gpp-tab-tipo" data-alvo="simples" id="gpp-tab-simples" style="display:none; --gpp-accent:#2c3e50;">🧾 Tabela de preços</button>
                        <button type="button" class="gpp-tab-tipo" data-alvo="empresarial" data-tipo="empresarial" style="display:none; --gpp-accent:#0066FF;">📈 Empresarial</button>
                        <button type="button" class="gpp-tab-tipo" data-alvo="individual" data-tipo="individual" style="display:none; --gpp-accent:#00A344;">👤 Individual</button>
                        <button type="button" class="gpp-tab-tipo" data-alvo="pme" data-tipo="pme" style="display:none; --gpp-accent:#FF6600;">🏢 PME</button>
                        <button type="button" class="gpp-tab-tipo" data-alvo="adesao" data-tipo="adesao" style="display:none; --gpp-accent:#8E44AD;">🤝 Adesão</button>
                    </div>

                    <!-- ===================== ABA: DADOS DA CIDADE ===================== -->
                    <div class="gpp-painel-modal" data-painel="dados">
                    <table class="form-table">
                        <tr>
                            <th><label for="gpp-nome">Nome da Cidade</label></th>
                            <td>
                                <input type="text" id="gpp-nome" class="regular-text" required>
                                <p class="description">O shortcode será gerado automaticamente</p>
                            </td>
                        </tr>

                        <tr class="gpp-row-completo">
                            <th><label>Tipos de Planos</label></th>
                            <td>
                                <p><strong>Selecione quais tipos de planos esta cidade terá:</strong></p>
                                <label class="gpp-check-pilula" style="--gpp-accent:#0066FF;">
                                    <input type="checkbox" class="gpp-tipo-plano-check" data-tipo="empresarial" id="gpp-tipo-empresarial">
                                    📈 Empresarial
                                </label>
                                <label class="gpp-check-pilula" style="--gpp-accent:#00A344;">
                                    <input type="checkbox" class="gpp-tipo-plano-check" data-tipo="individual" id="gpp-tipo-individual">
                                    👤 Individual
                                </label>
                                <label class="gpp-check-pilula" style="--gpp-accent:#FF6600;">
                                    <input type="checkbox" class="gpp-tipo-plano-check" data-tipo="pme" id="gpp-tipo-pme">
                                    🏢 PME
                                </label>
                                <label class="gpp-check-pilula" style="--gpp-accent:#8E44AD;">
                                    <input type="checkbox" class="gpp-tipo-plano-check" data-tipo="adesao" id="gpp-tipo-adesao">
                                    🤝 Adesao
                                </label>
                                <p class="description" style="margin-top: 8px;">Cada tipo marcado vira uma <strong>aba</strong> na barra acima — os preços são editados lá.</p>
                            </td>
                        </tr>
                    </table>
                    </div>

                    <!-- ===================== ABA: DESCONTOS ===================== -->
                    <div class="gpp-painel-modal" data-painel="descontos" style="display:none;">
                    <table class="form-table">
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

                                <div id="gpp-descontos-diferenciados-container" style="display: none; margin-top: 15px; padding: 15px; background: #fff7e8; border: 1px solid #f0dcb4; border-radius: 10px;">
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
                    </table>
                    </div>

                    <!-- ===== MODO SIMPLES: tabela única (Faixa Etária → Valor) ===== -->
                    <div id="gpp-secao-simples" class="gpp-painel-modal" data-painel="simples" style="display:none;">
                        <h3>🧾 Tabela de Preços — <span id="gpp-simples-op-nome"></span></h3>
                        <p>Esta operadora usa <strong>uma única tabela por cidade</strong>. Cole o JSON com as faixas etárias e valores:</p>
                        <div class="gpp-editor-json">
                            <div class="gpp-editor-toolbar">
                                <label style="font-weight:bold;">JSON da tabela</label>
                                <span class="gpp-editor-ferramentas">
                                    <button type="button" class="button button-small gpp-json-formatar" title="Reindenta o JSON colado">{ } Formatar</button>
                                    <span class="gpp-reajuste">
                                        Reajuste <input type="text" class="gpp-reajuste-pct" placeholder="7,5" aria-label="Percentual de reajuste"> %
                                        <button type="button" class="button button-small gpp-reajuste-aplicar">Aplicar</button>
                                        <button type="button" class="button button-small gpp-reajuste-desfazer" style="display:none;">↩ Desfazer</button>
                                    </span>
                                </span>
                            </div>
                            <textarea class="gpp-json-field large-text code" id="gpp-tabela-simples-json" rows="10" placeholder='[
  {"faixa_etaria": "0 a 18 anos", "valor": "199,90"},
  {"faixa_etaria": "19 a 23 anos", "valor": "229,90"}
]'></textarea>
                            <div class="gpp-status-json" id="gpp-status-tabela-simples"></div>
                        </div>
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
                        <div class="gpp-secao-tipo" id="gpp-secao-<?php echo $tipo_key; ?>" style="display: none;">
                            <h3 style="margin-top: 0;"><?php echo $tipo_info['emoji']; ?> Planos <?php echo $tipo_info['nome']; ?></h3>

                            <div style="margin: 15px 0; padding: 15px;">
                                <label style="display: block; margin-bottom: 5px;"><strong>📝 Nota / Observação do plano <?php echo $tipo_info['nome']; ?> (opcional):</strong></label>
                                <textarea class="large-text" id="gpp-nota-<?php echo $tipo_key; ?>" rows="3" placeholder="Ex.: Plano voltado para empresas a partir de 2 vidas..."></textarea>
                                <p style="color: #64748b; font-size: 12px; margin: 5px 0 0 0;">Anotação interna (uso administrativo). <strong>Não</strong> é exibida no site.</p>
                            </div>

                            <div style="margin: 15px 0; padding: 15px;">
                                <p><strong>Selecione as acomodações disponíveis:</strong></p>
                                <label class="gpp-check-pilula">
                                    <input type="checkbox" class="gpp-acomodacao-check" data-tipo="<?php echo $tipo_key; ?>" data-acomodacao="ambulatorial">
                                    🏥 Ambulatorial
                                </label>
                                <label class="gpp-check-pilula">
                                    <input type="checkbox" class="gpp-acomodacao-check" data-tipo="<?php echo $tipo_key; ?>" data-acomodacao="enfermaria">
                                    🛏️ Enfermaria
                                </label>
                                <label class="gpp-check-pilula">
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
                                            <div class="gpp-editor-json">
                                                <div class="gpp-editor-toolbar">
                                                    <label><strong>Coparticipação Total</strong></label>
                                                    <span class="gpp-editor-ferramentas">
                                                        <button type="button" class="button button-small gpp-json-formatar" title="Reindenta o JSON colado">{ }</button>
                                                        <span class="gpp-reajuste">
                                                            <input type="text" class="gpp-reajuste-pct" placeholder="7,5" aria-label="Percentual de reajuste"> %
                                                            <button type="button" class="button button-small gpp-reajuste-aplicar">Reajustar</button>
                                                            <button type="button" class="button button-small gpp-reajuste-desfazer" style="display:none;">↩</button>
                                                        </span>
                                                    </span>
                                                </div>
                                                <textarea class="gpp-json-field large-text code" id="gpp-<?php echo $tipo_key; ?>-<?php echo $acom_key; ?>-total-json" rows="6"></textarea>
                                                <div class="gpp-status-json" id="gpp-status-<?php echo $tipo_key; ?>-<?php echo $acom_key; ?>-total"></div>
                                            </div>
                                        </div>

                                        <div class="gpp-campo-parcial">
                                            <div class="gpp-editor-json">
                                                <div class="gpp-editor-toolbar">
                                                    <label><strong>Coparticipação Parcial</strong></label>
                                                    <span class="gpp-editor-ferramentas">
                                                        <button type="button" class="button button-small gpp-json-formatar" title="Reindenta o JSON colado">{ }</button>
                                                        <span class="gpp-reajuste">
                                                            <input type="text" class="gpp-reajuste-pct" placeholder="7,5" aria-label="Percentual de reajuste"> %
                                                            <button type="button" class="button button-small gpp-reajuste-aplicar">Reajustar</button>
                                                            <button type="button" class="button button-small gpp-reajuste-desfazer" style="display:none;">↩</button>
                                                        </span>
                                                    </span>
                                                </div>
                                                <textarea class="gpp-json-field large-text code" id="gpp-<?php echo $tipo_key; ?>-<?php echo $acom_key; ?>-parcial-json" rows="6"></textarea>
                                                <div class="gpp-status-json" id="gpp-status-<?php echo $tipo_key; ?>-<?php echo $acom_key; ?>-parcial"></div>
                                            </div>
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

            // ===== ABAS DO MODAL (Dados / Descontos / Tabela / um por tipo) =====
            var GPP_TIPOS = ['empresarial', 'individual', 'pme', 'adesao'];

            function gppTiposMarcados() {
                return GPP_TIPOS.filter(function (t) {
                    return $('#gpp-tipo-' + t).is(':checked');
                });
            }

            // Ativa uma aba do modal e mostra só o painel correspondente
            function gppAtivarAbaModal(alvo) {
                $('#gpp-tipos-tabs .gpp-tab-tipo').removeClass('gpp-tab-tipo-ativa');
                $('#gpp-tipos-tabs .gpp-tab-tipo[data-alvo="' + alvo + '"]').addClass('gpp-tab-tipo-ativa');

                $('.gpp-painel-modal').hide();
                $('.gpp-secao-tipo').hide();

                if (GPP_TIPOS.indexOf(alvo) !== -1) {
                    $('#gpp-secao-' + alvo).show();
                } else {
                    $('.gpp-painel-modal[data-painel="' + alvo + '"]').show();
                }
            }

            // Sincroniza a visibilidade das abas com o modo/checkboxes.
            // tipoPreferido: abre direto na aba desse tipo (recém-marcado).
            function gppAtualizarTabsTipos(tipoPreferido) {
                var marcados = GPP_SIMPLES ? [] : gppTiposMarcados();

                // abas de tipo: só as marcadas (e nunca no modo simples)
                GPP_TIPOS.forEach(function (t) {
                    $('#gpp-tipos-tabs .gpp-tab-tipo[data-tipo="' + t + '"]').toggle(marcados.indexOf(t) !== -1);
                });
                // aba da tabela única: só no modo simples
                $('#gpp-tab-simples').toggle(!!GPP_SIMPLES);

                if (tipoPreferido && marcados.indexOf(tipoPreferido) !== -1) {
                    gppAtivarAbaModal(tipoPreferido);
                    return;
                }

                // se a aba ativa sumiu (tipo desmarcado / troca de modo), volta para Dados
                var $ativa = $('#gpp-tipos-tabs .gpp-tab-tipo-ativa');
                if (!$ativa.length || $ativa.css('display') === 'none') {
                    gppAtivarAbaModal('dados');
                }
            }

            $(document).on('click', '#gpp-tipos-tabs .gpp-tab-tipo', function () {
                gppAtivarAbaModal($(this).data('alvo'));
            });

            // Ajusta os campos do modal conforme o modo da operadora (simples x completo)
            function gppAplicarModoModal(simples) {
                if (simples) {
                    $('.gpp-row-completo').hide();
                    if (GPP_OPS[GPP_OPERADORA]) {
                        $('#gpp-simples-op-nome').text(GPP_OPS[GPP_OPERADORA].nome);
                    }
                } else {
                    $('.gpp-row-completo').show();
                }
                gppAtualizarTabsTipos();
            }

            // Fecha a gaveta de shortcodes
            function gppFecharGaveta() {
                $('#gpp-gaveta').removeClass('gpp-aberta').attr('aria-hidden', 'true');
                $('#gpp-gaveta-overlay').hide();
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

                // Reseta busca e fecha a gaveta ao trocar de operadora
                $('#gpp-busca-admin').val('');
                $('.gpp-tabela-cidades tbody tr').show();
                gppFecharGaveta();

                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', GPP_URL_ADMIN + op);
                }
            }

            $('.gpp-op-tab').on('click', function(e) {
                e.preventDefault();
                gppTrocarOperadora($(this).data('operadora'));
            });

            // ===== ABAS INTERNAS (Cidades / Descontos / Referência / Ajuda) =====
            $('.gpp-tab-int').on('click', function() {
                var alvo = $(this).data('tab');
                $('.gpp-tab-int').removeClass('gpp-tab-ativa');
                $(this).addClass('gpp-tab-ativa');
                $('.gpp-tab-panel').hide();
                $('.gpp-tab-panel[data-tab="' + alvo + '"]').show();
                gppFecharGaveta();
            });

            // ===== BUSCA DE CIDADES =====
            $('#gpp-busca-admin').on('input', function() {
                var termo = $(this).val().toLowerCase().trim();
                $('.gpp-tabela-cidades tbody tr').each(function() {
                    var nome = ($(this).data('cidade') || '').toString();
                    $(this).toggle(termo === '' || nome.indexOf(termo) !== -1);
                });
            });

            // ===== GAVETA DE SHORTCODES =====
            $(document).on('click', '.gpp-abrir-gaveta', function() {
                var conteudo = document.getElementById($(this).data('alvo'));
                if (!conteudo) { return; }
                $('#gpp-gaveta-titulo').text($(this).data('cidade'));
                $('#gpp-gaveta-corpo').html(conteudo.innerHTML);
                $('#gpp-gaveta').addClass('gpp-aberta').attr('aria-hidden', 'false');
                $('#gpp-gaveta-overlay').show();
            });

            $('#gpp-gaveta-fechar, #gpp-gaveta-overlay').on('click', gppFecharGaveta);
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') { gppFecharGaveta(); }
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
            
            // Controle dos tipos de planos: marcar cria a aba do tipo na barra
            // (sem sair da aba Dados — a pílula acesa é o feedback); desmarcar
            // remove a aba e limpa as acomodações do tipo.
            $('.gpp-tipo-plano-check').on('change', function() {
                var tipo = $(this).data('tipo');

                if (!$(this).is(':checked')) {
                    $('#gpp-secao-' + tipo).find('.gpp-acomodacao-check').prop('checked', false).trigger('change');
                }
                gppAtualizarTabsTipos();
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
            
            // ===== EDITOR JSON DINÂMICO (validação + pré-visualização ao vivo) =====

            // Converte "1.234,56" / "1234.56" / "R$ 199,90" em número
            function gppParaNumero(txt) {
                txt = String(txt == null ? '' : txt).replace(/[^0-9.,]/g, '');
                if (txt === '') { return NaN; }
                if (txt.indexOf(',') !== -1) {
                    txt = txt.replace(/\./g, '').replace(',', '.');
                } else if ((txt.match(/\./g) || []).length > 1) {
                    txt = txt.replace(/\./g, '');
                }
                return parseFloat(txt);
            }

            // Formata número como "1.234,56" (padrão brasileiro, sem R$)
            function gppParaMoeda(n) {
                return n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            // Extrai o valor de um item (aceita os 3 formatos que o plugin aceita)
            function gppValorDoItem(item) {
                return item.valor || item.coparticipacao_total || item.coparticipacao_parcial || '';
            }

            // Valida o JSON e devolve {dados} ou {erro}
            function gppValidarJson(texto) {
                if (texto.trim() === '') { return { vazio: true }; }
                var dados;
                try {
                    dados = JSON.parse(texto);
                } catch (e) {
                    return { erro: e.message };
                }
                if (!Array.isArray(dados)) {
                    return { erro: 'JSON deve ser um array' };
                }
                for (var i = 0; i < dados.length; i++) {
                    var item = dados[i];
                    if (!item || typeof item !== 'object' || !item.faixa_etaria) {
                        return { erro: 'Item ' + (i + 1) + ' sem faixa_etaria' };
                    }
                    if (!gppValorDoItem(item)) {
                        return { erro: 'Item ' + (i + 1) + ' sem valor (use "valor", "coparticipacao_total" ou "coparticipacao_parcial")' };
                    }
                }
                return { dados: dados };
            }

            // Atualiza o status de validação de um textarea de JSON
            function gppAtualizarEditor($campo) {
                var id = $campo.attr('id');
                var statusDiv = $('#' + id.replace('-json', '').replace(/^gpp-/, 'gpp-status-'));
                var r = gppValidarJson($campo.val());

                if (r.vazio) {
                    statusDiv.html('');
                    return;
                }
                if (r.erro) {
                    statusDiv.html('<span class="gpp-status-error">✗ Erro: ' + r.erro + '</span>');
                    return;
                }

                statusDiv.html('<span class="gpp-status-success">✓ JSON válido (' + r.dados.length + ' faixas)</span>');
            }

            function gppAtualizarTodosEditores() {
                $('.gpp-json-field').each(function () { gppAtualizarEditor($(this)); });
            }

            $(document).on('input', '.gpp-json-field', function () {
                gppAtualizarEditor($(this));
            });

            // Botão { } Formatar — reindenta o JSON colado
            $(document).on('click', '.gpp-json-formatar', function () {
                var $editor = $(this).closest('.gpp-editor-json');
                var $campo = $editor.find('.gpp-json-field');
                var r = gppValidarJson($campo.val());
                if (r.dados) {
                    $campo.val(JSON.stringify(r.dados, null, 2));
                }
                gppAtualizarEditor($campo);
            });

            // Botão Reajustar % — multiplica todos os valores do JSON
            $(document).on('click', '.gpp-reajuste-aplicar', function () {
                var $editor = $(this).closest('.gpp-editor-json');
                var $campo = $editor.find('.gpp-json-field');
                var id = $campo.attr('id');
                var statusDiv = $('#' + id.replace('-json', '').replace(/^gpp-/, 'gpp-status-'));
                var pct = gppParaNumero($editor.find('.gpp-reajuste-pct').val());

                if (isNaN(pct) || pct === 0) {
                    statusDiv.html('<span class="gpp-status-error">✗ Informe o percentual de reajuste (ex.: 7,5)</span>');
                    return;
                }
                var r = gppValidarJson($campo.val());
                if (r.vazio || r.erro) {
                    statusDiv.html('<span class="gpp-status-error">✗ Cole um JSON válido antes de reajustar</span>');
                    return;
                }

                // Guarda o estado anterior para o Desfazer
                $campo.data('gpp-anterior', $campo.val());

                r.dados.forEach(function (item) {
                    ['valor', 'coparticipacao_total', 'coparticipacao_parcial'].forEach(function (chave) {
                        if (item[chave]) {
                            var n = gppParaNumero(item[chave]);
                            if (!isNaN(n)) {
                                item[chave] = gppParaMoeda(n * (1 + pct / 100));
                            }
                        }
                    });
                });

                $campo.val(JSON.stringify(r.dados, null, 2));
                gppAtualizarEditor($campo);
                statusDiv.html('<span class="gpp-status-success">✓ Reajuste de ' + $editor.find('.gpp-reajuste-pct').val() + '% aplicado em ' + r.dados.length + ' faixas — confira e clique em Salvar</span>');
                $editor.find('.gpp-reajuste-desfazer').show();
            });

            // Botão Desfazer — restaura o JSON de antes do reajuste
            $(document).on('click', '.gpp-reajuste-desfazer', function () {
                var $editor = $(this).closest('.gpp-editor-json');
                var $campo = $editor.find('.gpp-json-field');
                var anterior = $campo.data('gpp-anterior');
                if (typeof anterior === 'string') {
                    $campo.val(anterior);
                    gppAtualizarEditor($campo);
                }
                $(this).hide();
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
                $('.gpp-reajuste-desfazer').hide();
                $('.gpp-reajuste-pct').val('');
                gppAplicarModoModal(GPP_SIMPLES);
                gppAtivarAbaModal('dados');
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
                                $('.gpp-reajuste-desfazer').hide();
                                gppAtualizarTodosEditores();
                                gppAtivarAbaModal('dados');
                                modal.show();
                                return;
                            }

                            // Configura tipos de planos e acomodações
                            var tipos = ['empresarial', 'individual', 'pme', 'adesao'];
                            var acomodacoes = ['ambulatorial', 'enfermaria', 'apartamento'];

                            tipos.forEach(function(tipo) {
                                if (cidade.tipos_planos_ativos && cidade.tipos_planos_ativos[tipo]) {
                                    $('#gpp-tipo-' + tipo).prop('checked', true);

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
                            
                            $('.gpp-reajuste-desfazer').hide();
                            gppAtualizarTodosEditores();
                            // Sincroniza as abas de tipo e abre na aba Dados
                            gppAtualizarTabsTipos();
                            gppAtivarAbaModal('dados');
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
    $this->imprimir_estilos_admin();
    ?>
    <div class="wrap gpp-admin gpp-variaveis-page">
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

        <div class="gpp-banner-op" style="background: linear-gradient(135deg, <?php echo esc_attr($operadora_cfg['cor']); ?>, <?php echo esc_attr($this->escurecer_cor($operadora_cfg['cor'], 0.25)); ?>);">
            Operadora: <strong><?php echo esc_html($operadora_cfg['nome']); ?></strong>
            <?php if ($operadora_cfg['prefixo'] !== ''): ?>
                &nbsp;—&nbsp; todos os shortcodes abaixo já incluem o prefixo <code><?php echo esc_html($operadora_cfg['prefixo']); ?></code>
            <?php endif; ?>
        </div>

        <div class="gpp-card" style="border-left: 4px solid <?php echo esc_attr($operadora_cfg['cor']); ?>;">
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
                                                <?php $idx_reg_total = array_flip($this->indices_faixas_registrar(count($cidade[$campo_total]))); ?>
                                                <?php foreach ($cidade[$campo_total] as $idx => $plano):
                                                    $shortcode_var = $cidade['shortcode'] . '_' . $tipo_info['sigla'] . '_' . $acom_key . 'total_' . $idx;
                                                ?>
                                                    <tr>
                                                        <td><strong><?php echo esc_html($plano['faixa_etaria']); ?></strong></td>
                                                        <td>
                                                            <?php if (isset($idx_reg_total[$idx])): ?>
                                                                <code>[<?php echo esc_html($shortcode_var); ?>]</code>
                                                                <button class="button button-small gpp-copiar-var" data-var="[<?php echo esc_attr($shortcode_var); ?>]">📋 Copiar</button>
                                                            <?php else: ?>
                                                                <span style="color:#999;">— sem shortcode individual (por desempenho, só 1ª, 2ª e última faixa)</span>
                                                            <?php endif; ?>
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
                                                <?php $idx_reg_parcial = array_flip($this->indices_faixas_registrar(count($cidade[$campo_parcial]))); ?>
                                                <?php foreach ($cidade[$campo_parcial] as $idx => $plano):
                                                    $shortcode_var = $cidade['shortcode'] . '_' . $tipo_info['sigla'] . '_' . $acom_key . 'parcial_' . $idx;
                                                ?>
                                                    <tr>
                                                        <td><strong><?php echo esc_html($plano['faixa_etaria']); ?></strong></td>
                                                        <td>
                                                            <?php if (isset($idx_reg_parcial[$idx])): ?>
                                                                <code>[<?php echo esc_html($shortcode_var); ?>]</code>
                                                                <button class="button button-small gpp-copiar-var" data-var="[<?php echo esc_attr($shortcode_var); ?>]">📋 Copiar</button>
                                                            <?php else: ?>
                                                                <span style="color:#999;">— sem shortcode individual (por desempenho, só 1ª, 2ª e última faixa)</span>
                                                            <?php endif; ?>
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
        $this->imprimir_estilos_admin();
        ?>
        <div class="wrap gpp-admin">
            <h1>🏥 Valores Regionais - Procedimentos Médicos</h1>

            <div class="gpp-card" style="border-left: 4px solid #0054b8;">
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
                    <div class="gpp-card" style="border-left: 4px solid <?php echo esc_attr($regiao_info['cor']); ?>;">
                        <h2 style="margin-top: 0; color: <?php echo esc_attr($regiao_info['cor']); ?>;">
                            <?php echo $regiao_info['emoji']; ?> <?php echo esc_html($regiao_info['nome']); ?>
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
            <div class="gpp-card">
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
            // Copiar shortcode (com fallback para ambientes sem clipboard API)
            $('.gpp-copiar-shortcode').on('click', function() {
                var shortcode = $(this).data('shortcode');
                var $btn = $(this);
                var textoOriginal = $btn.html();

                var confirmar = function() {
                    $btn.html('✅');
                    setTimeout(function() {
                        $btn.html(textoOriginal);
                    }, 2000);
                };

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(shortcode).then(confirmar);
                } else {
                    var $temp = $('<input>');
                    $('body').append($temp);
                    $temp.val(shortcode).select();
                    document.execCommand('copy');
                    $temp.remove();
                    confirmar();
                }
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
     * CSS compartilhado das telas administrativas do plugin (v8).
     * Imprimido no topo de cada página do plugin no admin.
     */
    private function imprimir_estilos_admin() {
        ?>
        <style>
            /* ===== GPP ADMIN (v8) ===== */
            .gpp-admin h1 { display: flex; align-items: center; gap: 8px; }

            .gpp-card {
                background: #fff;
                border: 1px solid #dcdfe5;
                border-radius: 12px;
                padding: 20px 24px;
                margin: 20px 0;
                box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
            }
            .gpp-card > h2:first-child { margin-top: 0; }

            .gpp-banner-op {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 8px;
                padding: 14px 20px;
                margin-bottom: 16px;
                color: #fff;
                border-radius: 12px;
                font-size: 15px;
                box-shadow: 0 6px 16px -8px rgba(15, 23, 42, 0.35);
            }
            .gpp-banner-op code {
                background: rgba(255, 255, 255, 0.22);
                color: #fff;
                padding: 2px 8px;
                border-radius: 6px;
            }

            /* ===== CHIPS DE SHORTCODE (clique para copiar) ===== */
            .gpp-chip {
                display: inline-block;
                font-family: Consolas, Monaco, 'Courier New', monospace;
                font-size: 11px;
                line-height: 1.6;
                background: #f6f8fb;
                color: #1e293b;
                border: 1px solid #cfd8e3;
                border-radius: 6px;
                padding: 3px 9px;
                margin: 2px 4px 2px 0;
                cursor: pointer;
                transition: background 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
            }
            .gpp-chip:hover {
                background: #eef4fb;
                border-color: #2271b1;
                box-shadow: 0 1px 4px rgba(34, 113, 177, 0.25);
            }
            .gpp-chip-primario { background: #eaf3ff; border-color: #9ec2e8; color: #0a4b78; }
            .gpp-chip-alerta   { background: #fff7e0; border-color: #e8c968; color: #7a5b00; }
            .gpp-chip-roxo     { background: #f4ecfb; border-color: #c9a7e8; color: #5b2d83; }

            /* ===== BLOCOS DE SHORTCODES NA LISTAGEM DE CIDADES ===== */
            .gpp-bloco-sc {
                margin: 0 0 8px;
                padding: 8px 12px;
                background: #f8fafc;
                border: 1px solid #e5eaf1;
                border-left: 3px solid var(--gpp-accent, #2271b1);
                border-radius: 8px;
            }
            .gpp-bloco-sc-titulo {
                display: block;
                font-size: 10px;
                font-weight: 700;
                letter-spacing: 0.5px;
                text-transform: uppercase;
                color: var(--gpp-accent, #2271b1);
                margin-bottom: 4px;
            }
            .gpp-bloco-sc small { color: #64748b; }

            /* ===== MODAL ===== */
            .gpp-modal {
                position: fixed;
                z-index: 100000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                overflow: auto;
                background: rgba(15, 23, 42, 0.55);
            }
            .gpp-modal-content {
                background: #fff;
                margin: 3% auto;
                padding: 28px 32px;
                border: none;
                width: 90%;
                max-width: 1200px;
                border-radius: 14px;
                max-height: 90vh;
                overflow-y: auto;
                box-shadow: 0 24px 64px -24px rgba(15, 23, 42, 0.5);
            }
            /* Faixa de título colorida no topo do modal */
            #gpp-modal-titulo {
                background: linear-gradient(135deg, #0054b8, #003d87);
                color: #fff;
                margin: -28px -32px 20px;
                padding: 16px 32px;
                border-radius: 14px 14px 0 0;
                font-size: 18px;
                font-weight: 600;
            }
            .gpp-modal-close {
                float: right;
                position: relative;
                z-index: 5;
                margin: -12px -12px 0 0;
                color: rgba(255, 255, 255, 0.85);
                font-size: 26px;
                font-weight: bold;
                line-height: 1;
                cursor: pointer;
            }
            .gpp-modal-close:hover,
            .gpp-modal-close:focus { color: #fff; }

            /* ===== SEÇÕES DO FORMULÁRIO (por tipo de plano) ===== */
            .gpp-secao-tipo,
            #gpp-secao-simples {
                background: #fff;
                border: 1px solid #e2e8f0;
                border-left: 4px solid var(--gpp-accent, #2271b1);
                border-radius: 12px;
                box-shadow: 0 1px 4px rgba(15, 23, 42, 0.06);
                padding: 20px 24px;
                margin: 20px 0;
            }
            #gpp-secao-empresarial { --gpp-accent: #0066FF; }
            #gpp-secao-individual  { --gpp-accent: #00A344; }
            #gpp-secao-pme         { --gpp-accent: #FF6600; }
            #gpp-secao-adesao      { --gpp-accent: #8E44AD; }
            #gpp-secao-simples     { --gpp-accent: #2c3e50; }

            .gpp-secao-tipo h3,
            #gpp-secao-simples h3 {
                color: var(--gpp-accent);
                margin-top: 0;
                border-bottom: 2px solid var(--gpp-accent);
                padding-bottom: 10px;
            }
            .gpp-secao-tipo > div {
                background: rgba(255, 255, 255, 0.75);
                border: 1px solid var(--gpp-borda, #eef2f7);
                border-radius: 10px;
            }
            .gpp-secao-tipo label { color: #1e293b; }
            .gpp-secao-tipo label:hover { color: var(--gpp-accent); }

            .gpp-campos-acomodacao {
                background: rgba(255, 255, 255, 0.75);
                border: 1px solid var(--gpp-borda, #eef2f7);
                border-left: 3px solid var(--gpp-accent, #2271b1);
                border-radius: 10px;
            }

            /* Fundos tintados por tipo de plano (adeus tela toda branca) */
            #gpp-secao-empresarial { background: #eff5ff; --gpp-borda: #cfdff7; }
            #gpp-secao-individual  { background: #eefaf3; --gpp-borda: #c8ead6; }
            #gpp-secao-pme         { background: #fff4ea; --gpp-borda: #f6ddc2; }
            #gpp-secao-adesao      { background: #f8f0fc; --gpp-borda: #e5cff2; }
            #gpp-secao-simples     { background: #f0f4f8; --gpp-borda: #d5dee8; }
            .gpp-campos-acomodacao h4 {
                color: var(--gpp-accent, #2271b1);
                font-size: 15px;
                margin-bottom: 12px;
            }

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
                color: #1e293b;
                font-weight: 600;
            }
            .gpp-campo-total textarea,
            .gpp-campo-parcial textarea,
            #gpp-tabela-simples-json {
                width: 100%;
                background: #fff;
                color: #1e293b;
                border: 1px solid #cbd5e1;
                border-radius: 8px;
                font-family: Consolas, Monaco, 'Courier New', monospace;
                font-size: 12px;
            }
            .gpp-campo-total textarea:focus,
            .gpp-campo-parcial textarea:focus,
            #gpp-tabela-simples-json:focus {
                border-color: var(--gpp-accent, #2271b1);
                box-shadow: 0 0 0 1px var(--gpp-accent, #2271b1);
            }

            .gpp-status-json { font-weight: 600; margin-top: 5px; }
            .gpp-status-success { color: #00803b; }
            .gpp-status-error { color: #d63638; }

            @media (max-width: 1200px) {
                .gpp-campo-total,
                .gpp-campo-parcial { min-width: 100%; }
            }

            /* ===== PAINEL DE REFERÊNCIA DE SHORTCODES ===== */
            .gpp-ref-panel {
                background: #fff;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                margin: 20px 0;
                overflow: hidden;
                box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
            }
            .gpp-ref-panel summary {
                cursor: pointer;
                padding: 14px 20px;
                font-size: 15px;
                font-weight: 600;
                color: #fff;
                list-style: none;
            }
            .gpp-ref-panel summary::-webkit-details-marker { display: none; }
            .gpp-ref-panel summary::before { content: '▸ '; }
            .gpp-ref-panel[open] summary::before { content: '▾ '; }
            .gpp-ref-tabela {
                width: 100%;
                border-collapse: collapse;
                background: #fff;
                margin-bottom: 14px;
            }
            .gpp-ref-tabela th {
                padding: 8px 12px;
                text-align: left;
                color: #fff;
                font-size: 12px;
            }
            .gpp-ref-tabela td {
                padding: 8px 12px;
                border-bottom: 1px solid #eef2f7;
                font-size: 13px;
            }

            /* Página de variáveis dinâmicas: chips de copiar */
            .gpp-copiar-var { cursor: pointer; }
            code.gpp-copiar-var:hover { background: #eef4fb !important; }

            /* ===== ABAS INTERNAS (Cidades / Descontos / Referência / Ajuda) ===== */
            .gpp-tabs-internas {
                display: flex;
                gap: 2px;
                border-bottom: 1px solid #c3c4c7;
                margin: 6px 0 20px;
            }
            .gpp-tab-int {
                padding: 10px 18px;
                font-size: 14px;
                font-weight: 600;
                color: #646970;
                background: none;
                border: none;
                border-bottom: 3px solid transparent;
                cursor: pointer;
                transition: color 0.15s ease, border-color 0.15s ease;
            }
            .gpp-tab-int:hover { color: #1d2327; }
            .gpp-tab-int.gpp-tab-ativa {
                color: #0054b8;
                border-bottom-color: #0054b8;
            }
            .gpp-tab-int:focus-visible { outline: 2px solid #0054b8; outline-offset: 2px; }

            .gpp-toolbar {
                display: flex;
                align-items: center;
                gap: 10px;
                margin: 0 0 16px;
                flex-wrap: wrap;
            }
            .gpp-busca-admin {
                margin-left: auto;
                min-width: 260px;
                padding: 5px 12px;
                border: 1px solid #8c8f94;
                border-radius: 6px;
            }

            /* ===== BADGES (colunas Planos e Desconto) ===== */
            .gpp-badge {
                display: inline-block;
                font-size: 11px;
                font-weight: 700;
                border-radius: 4px;
                padding: 2px 8px;
                margin: 1px 4px 1px 0;
                white-space: nowrap;
            }
            .gpp-badge-empresarial { background: #e7f0ff; color: #1d4fa0; }
            .gpp-badge-individual  { background: #e5f6ec; color: #187a43; }
            .gpp-badge-pme         { background: #fff1e3; color: #b35a00; }
            .gpp-badge-adesao      { background: #f3e9fb; color: #7239a4; }
            .gpp-badge-simples     { background: #eef1f5; color: #475569; }
            .gpp-badge-desc        { background: #fdeee7; color: #c2410c; }
            .gpp-texto-vazio       { color: #a0a5aa; }

            .gpp-tabela-cidades td { vertical-align: middle; }
            .gpp-tabela-cidades .button { margin-right: 4px; }

            /* ===== GAVETA DE SHORTCODES ===== */
            .gpp-gaveta-overlay {
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, 0.4);
                z-index: 99990;
            }
            .gpp-gaveta {
                position: fixed;
                top: 32px; /* barra do wp-admin */
                right: 0;
                bottom: 0;
                width: 420px;
                max-width: 92vw;
                background: #fff;
                z-index: 99991;
                box-shadow: -24px 0 48px -24px rgba(0, 0, 0, 0.45);
                transform: translateX(105%);
                transition: transform 0.25s ease;
                display: flex;
                flex-direction: column;
            }
            @media (prefers-reduced-motion: reduce) {
                .gpp-gaveta { transition: none; }
            }
            @media screen and (max-width: 782px) {
                .gpp-gaveta { top: 46px; } /* barra do wp-admin no mobile */
            }
            .gpp-gaveta.gpp-aberta { transform: none; }
            .gpp-gaveta-cabecalho {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 10px;
                padding: 16px 20px;
                border-bottom: 1px solid #e2e8f0;
            }
            .gpp-gaveta-fechar {
                border: none;
                background: none;
                font-size: 18px;
                line-height: 1;
                color: #94a3b8;
                cursor: pointer;
                padding: 4px;
            }
            .gpp-gaveta-fechar:hover { color: #0f172a; }
            .gpp-gaveta-corpo {
                padding: 16px 20px;
                overflow-y: auto;
            }
            .gpp-gaveta-corpo .gpp-bloco-sc { margin-bottom: 12px; }

            /* ===== CHECKBOXES EM PÍLULA (tipos e acomodações) ===== */
            .gpp-check-pilula {
                display: inline-flex !important;
                align-items: center;
                gap: 7px;
                border: 1px solid #cbd5e1;
                border-radius: 999px;
                background: #fff;
                padding: 7px 16px 7px 12px;
                margin: 3px 8px 3px 0 !important;
                font-size: 13px;
                font-weight: 600;
                color: #334155;
                cursor: pointer;
                transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
            }
            .gpp-check-pilula:hover {
                border-color: var(--gpp-accent, #2271b1);
                color: var(--gpp-accent, #2271b1);
            }
            .gpp-check-pilula:has(input:checked) {
                background: var(--gpp-accent, #2271b1);
                border-color: var(--gpp-accent, #2271b1);
                color: #fff !important;
                box-shadow: 0 4px 10px -4px var(--gpp-accent, #2271b1);
            }
            .gpp-check-pilula input { margin: 0 !important; }

            /* ===== ABAS DE TIPOS DE PLANO (modal de cidade) ===== */
            .gpp-tipos-tabs {
                position: sticky;
                top: -28px; /* compensa o padding do modal para grudar no topo */
                z-index: 20;
                display: flex;
                align-items: center;
                gap: 6px;
                flex-wrap: wrap;
                background: #fff;
                padding: 12px 0;
                margin: 8px 0 4px;
                border-bottom: 1px solid #e2e8f0;
            }
            .gpp-tab-tipo {
                border: 1px solid #cbd5e1;
                background: #f8fafc;
                color: #1e293b;
                border-radius: 999px;
                padding: 6px 16px;
                font-size: 13px;
                font-weight: 600;
                cursor: pointer;
                transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
            }
            .gpp-tab-tipo:hover {
                border-color: var(--gpp-accent, #2271b1);
                color: var(--gpp-accent, #2271b1);
            }
            .gpp-tab-tipo.gpp-tab-tipo-ativa {
                background: var(--gpp-accent, #2271b1);
                border-color: var(--gpp-accent, #2271b1);
                color: #fff;
            }
            .gpp-tab-tipo:focus-visible {
                outline: 2px solid var(--gpp-accent, #2271b1);
                outline-offset: 2px;
            }

            /* ===== EDITOR JSON DINÂMICO (modal de cidade) ===== */
            .gpp-editor-json { margin-bottom: 4px; }
            .gpp-editor-toolbar {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                flex-wrap: wrap;
                margin-bottom: 6px;
            }
            .gpp-editor-toolbar label { margin-bottom: 0 !important; }
            .gpp-editor-ferramentas {
                display: flex;
                align-items: center;
                gap: 6px;
                flex-wrap: wrap;
                margin-left: auto;
                font-size: 12px;
                color: #64748b;
            }
            .gpp-reajuste { display: inline-flex; align-items: center; gap: 4px; }
            .gpp-reajuste-pct {
                width: 60px;
                padding: 3px 8px;
                border: 1px solid #cbd5e1;
                border-radius: 6px;
                text-align: right;
                font-size: 12px;
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
        $linha = function ($descricao, $padrao, $exemplo) {
            ?>
            <tr>
                <td><?php echo esc_html($descricao); ?></td>
                <td>
                    <code class="gpp-chip" style="cursor:default;"><?php echo esc_html($padrao); ?></code>
                </td>
                <td>
                    <code class="gpp-shortcode-item gpp-chip gpp-chip-primario" data-shortcode="<?php echo esc_attr($exemplo); ?>" title="Clique para copiar"><?php echo esc_html($exemplo); ?></code>
                </td>
            </tr>
            <?php
        };
        ?>
        <details class="gpp-ref-panel" open>
            <summary style="background: linear-gradient(135deg, <?php echo esc_attr($cor); ?>, <?php echo esc_attr($this->escurecer_cor($cor, 0.25)); ?>);">
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
                <table class="gpp-ref-tabela">
                    <thead>
                        <tr style="background:<?php echo esc_attr($cor); ?>; color:#fff;">
                            <th>O que faz</th>
                            <th>Padrão</th>
                            <th>Exemplo (clique p/ copiar)</th>
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
                <table class="gpp-ref-tabela">
                    <tbody>
                        <?php
                        $linha('Comparar todas (sem tipo)', '[comparar_CIDADE]', '[comparar_' . $slug_base_ex . ']');
                        $linha('Comparar por tipo da Hapvida', '[comparar_CIDADE_TIPO_total]', '[comparar_' . $slug_base_ex . '_empresarial_total]');
                        $linha('Tabela comparativa (cotação família)', '[tabela_comparativa cidade="CIDADE"]', '[tabela_comparativa cidade="' . $slug_base_ex . '"]');
                        ?>
                    </tbody>
                </table>

                <h3 style="color:<?php echo esc_attr($cor); ?>; margin-bottom:5px;">📅 Data (global)</h3>
                <table class="gpp-ref-tabela">
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
                <table class="gpp-ref-tabela">
                    <thead>
                        <tr style="background:<?php echo esc_attr($cor); ?>; color:#fff;">
                            <th>O que faz</th>
                            <th>Padrão</th>
                            <th>Exemplo (clique p/ copiar)</th>
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
                <table class="gpp-ref-tabela">
                    <tbody>
                        <?php
                        $linha('Menor valor (texto)', '[' . $prefixo . 'CIDADE_menorvalor]', '[' . $cidade_ex . '_menorvalor]');
                        $linha('Maior valor (texto)', '[' . $prefixo . 'CIDADE_maiorvalor]', '[' . $cidade_ex . '_maiorvalor]');
                        $linha('Tabela do plano mais barato', '[' . $prefixo . 'CIDADE_menortabela]', '[' . $cidade_ex . '_menortabela]');
                        ?>
                    </tbody>
                </table>

                <h3 style="color:<?php echo esc_attr($cor); ?>; margin-bottom:5px;">🔢 Valor de uma faixa etária</h3>
                <p style="margin:0 0 8px; color:#666; font-size:13px;">Siglas do tipo: <code>emp</code>, <code>ind</code>, <code>pme</code>, <code>ade</code>. Acomodação: <code>ambulatorial</code>, <code>enfermaria</code>, <code>apartamento</code>. Faixas registradas: <code>0</code> (primeira), <code>1</code> (segunda) e a <strong>última</strong> da tabela (ex.: <code>9</code> numa tabela de 10 faixas). Sem o número = primeira faixa.</p>
                <table class="gpp-ref-tabela">
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
                <table class="gpp-ref-tabela">
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
                <table class="gpp-ref-tabela">
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
}
