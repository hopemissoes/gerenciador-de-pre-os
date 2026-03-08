<?php
/*
Plugin Name: Comparador de Planos de Saúde
Description: Shortcode para comparar planos de saúde disponíveis em uma mesma cidade, lado a lado
Version: 1.0
Author: Seu Nome
Depends: Gerenciador de Preços de Planos de Saúde
*/

if (!defined('ABSPATH')) {
    exit;
}

class GPP_Comparador_Planos {

    private $option_name = 'gpp_cidades_planos';

    public function __construct() {
        add_action('init', array($this, 'registrar_shortcodes'));
        add_action('wp_enqueue_scripts', array($this, 'registrar_assets'));
    }

    private function obter_todas_cidades() {
        $cidades = get_option($this->option_name, array());
        return is_array($cidades) ? $cidades : array();
    }

    private function obter_desconto_tipo($cidade, $tipo) {
        if (isset($cidade['tem_desconto_diferenciado']) && $cidade['tem_desconto_diferenciado']) {
            if (isset($cidade['descontos_diferenciados'][$tipo]) && $cidade['descontos_diferenciados'][$tipo] > 0) {
                return floatval($cidade['descontos_diferenciados'][$tipo]);
            }
            return 0;
        }

        $desconto_personalizado = isset($cidade['desconto_personalizado']) ? floatval($cidade['desconto_personalizado']) : 0;
        $tem_desconto_15 = isset($cidade['desconto_15']) && $cidade['desconto_15'] === true;

        if ($desconto_personalizado > 0) {
            return $desconto_personalizado;
        } elseif ($tem_desconto_15) {
            return 15;
        }

        return 0;
    }

    private function valor_para_float($valor) {
        $limpo = str_replace(array('R$', ' ', '.'), '', $valor);
        $limpo = str_replace(',', '.', $limpo);
        return floatval($limpo);
    }

    private function formatar_moeda($valor) {
        return 'R$ ' . number_format($valor, 2, ',', '.');
    }

    /**
     * Registra shortcodes para cada cidade que tem mais de um plano ativo
     * Formato: [comparador_planos_{cidade_shortcode}]
     */
    public function registrar_shortcodes() {
        $cidades = $this->obter_todas_cidades();
        if (empty($cidades)) {
            return;
        }

        foreach ($cidades as $cidade_data) {
            $shortcode_base = $cidade_data['shortcode'];
            $tag = 'comparador_planos_' . $shortcode_base;
            add_shortcode($tag, array($this, 'renderizar_comparador'));
        }
    }

    public function registrar_assets() {
        add_action('wp_head', array($this, 'imprimir_estilos'), 102);
    }

    /**
     * Coleta todos os planos ativos de uma cidade
     */
    private function coletar_planos_cidade($cidade) {
        $tipos_plano = array(
            'empresarial' => 'Empresarial',
            'individual' => 'Individual',
            'pme' => 'PME',
            'adesao' => 'Adesão'
        );

        $acomodacoes = array(
            'ambulatorial' => 'Ambulatorial',
            'enfermaria' => 'Enfermaria',
            'apartamento' => 'Apartamento'
        );

        $coparticipacoes = array(
            'total' => 'Copart. Total',
            'parcial' => 'Copart. Parcial'
        );

        $planos = array();

        foreach ($tipos_plano as $tipo_key => $tipo_nome) {
            if (empty($cidade['tipos_planos_ativos'][$tipo_key])) {
                continue;
            }

            foreach ($acomodacoes as $acom_key => $acom_nome) {
                $campo_ativo = $tipo_key . '_' . $acom_key . '_ativo';
                if (empty($cidade[$campo_ativo])) {
                    continue;
                }

                foreach ($coparticipacoes as $cop_key => $cop_nome) {
                    $campo = $tipo_key . '_' . $acom_key . '_' . $cop_key;
                    if (empty($cidade[$campo])) {
                        continue;
                    }

                    $desconto = $this->obter_desconto_tipo($cidade, $tipo_key);
                    $faixas = array();
                    $menor_valor = PHP_FLOAT_MAX;

                    foreach ($cidade[$campo] as $faixa) {
                        $valor_base = $this->valor_para_float($faixa['valor']);
                        if ($desconto > 0) {
                            $valor_base = $valor_base * (1 - $desconto / 100);
                        }
                        $valor_base = round($valor_base, 2);
                        if ($valor_base < $menor_valor) {
                            $menor_valor = $valor_base;
                        }
                        $faixas[] = array(
                            'faixa_etaria' => $faixa['faixa_etaria'],
                            'valor' => $valor_base
                        );
                    }

                    $planos[] = array(
                        'id' => $campo,
                        'tipo' => $tipo_key,
                        'tipo_nome' => $tipo_nome,
                        'acomodacao' => $acom_key,
                        'acomodacao_nome' => $acom_nome,
                        'coparticipacao' => $cop_key,
                        'coparticipacao_nome' => $cop_nome,
                        'label' => $tipo_nome . ' - ' . $acom_nome,
                        'label_completo' => $tipo_nome . ' - ' . $acom_nome . ' (' . $cop_nome . ')',
                        'faixas' => $faixas,
                        'menor_valor' => $menor_valor,
                        'desconto' => $desconto
                    );
                }
            }
        }

        return $planos;
    }

    /**
     * Renderiza o comparador de planos
     */
    public function renderizar_comparador($atts, $content, $tag) {
        $cidade_slug = str_replace('comparador_planos_', '', $tag);

        $cidades = $this->obter_todas_cidades();
        $cidade = null;
        foreach ($cidades as $c) {
            if ($c['shortcode'] === $cidade_slug) {
                $cidade = $c;
                break;
            }
        }

        if (!$cidade) {
            return '<p>Cidade não encontrada.</p>';
        }

        $planos = $this->coletar_planos_cidade($cidade);

        if (count($planos) < 2) {
            return '<p>Esta cidade possui apenas um plano cadastrado. O comparador requer pelo menos 2 planos.</p>';
        }

        $id_unico = 'gpp-comp-' . esc_attr($cidade_slug) . '-' . wp_rand(1000, 9999);
        $dados_json = wp_json_encode($planos);

        ob_start();
        ?>
        <div class="gpp-comparador-container" id="<?php echo $id_unico; ?>">
            <div class="gpp-comparador-header">
                <h3>Comparador de Planos — <?php echo esc_html($cidade['nome']); ?></h3>
                <p class="gpp-comparador-subtitulo">Selecione até 3 planos para comparar lado a lado</p>
            </div>

            <!-- Filtros -->
            <div class="gpp-comp-filtros">
                <div class="gpp-comp-filtro-grupo">
                    <label>Filtrar por tipo:</label>
                    <div class="gpp-comp-filtro-btns gpp-comp-filtro-tipo">
                        <button type="button" class="gpp-comp-filtro-btn active" data-filtro="todos">Todos</button>
                        <?php
                        $tipos_presentes = array_unique(array_column($planos, 'tipo'));
                        $nomes_tipos = array('empresarial' => 'Empresarial', 'individual' => 'Individual', 'pme' => 'PME', 'adesao' => 'Adesão');
                        foreach ($tipos_presentes as $tp):
                        ?>
                            <button type="button" class="gpp-comp-filtro-btn" data-filtro="<?php echo esc_attr($tp); ?>"><?php echo esc_html($nomes_tipos[$tp]); ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Seleção de planos -->
            <div class="gpp-comp-selecao">
                <div class="gpp-comp-planos-lista">
                    <?php foreach ($planos as $idx => $plano): ?>
                        <div class="gpp-comp-plano-item" data-idx="<?php echo $idx; ?>" data-tipo="<?php echo esc_attr($plano['tipo']); ?>">
                            <label>
                                <input type="checkbox" class="gpp-comp-check" value="<?php echo $idx; ?>">
                                <span class="gpp-comp-plano-info">
                                    <span class="gpp-comp-plano-tipo gpp-comp-badge-<?php echo esc_attr($plano['tipo']); ?>"><?php echo esc_html($plano['tipo_nome']); ?></span>
                                    <span class="gpp-comp-plano-nome"><?php echo esc_html($plano['acomodacao_nome']); ?> — <?php echo esc_html($plano['coparticipacao_nome']); ?></span>
                                    <span class="gpp-comp-plano-desde">A partir de <strong><?php echo esc_html($this->formatar_moeda($plano['menor_valor'])); ?></strong>/mês</span>
                                </span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button type="button" class="gpp-comp-comparar" disabled>Comparar planos selecionados</button>
            </div>

            <!-- Resultado da comparação -->
            <div class="gpp-comp-resultado" style="display:none;">
                <div class="gpp-comp-resultado-header">
                    <h4>Comparação de Planos</h4>
                    <button type="button" class="gpp-comp-voltar">Alterar seleção</button>
                </div>

                <div class="gpp-comp-tabela-wrapper">
                    <table class="gpp-comp-tabela">
                        <thead class="gpp-comp-thead"></thead>
                        <tbody class="gpp-comp-tbody"></tbody>
                    </table>
                </div>

                <div class="gpp-comp-legenda">
                    <span class="gpp-comp-legenda-item gpp-comp-melhor-preco">Melhor preço na faixa</span>
                </div>

                <div class="gpp-comp-aviso">
                    Os valores apresentados referem-se aos planos Hapvida e podem sofrer alterações sem aviso prévio.
                    Para uma cotação personalizada, clique no botão abaixo.
                </div>

                <div class="gpp-botao-container" style="text-align:center;">
                    <a href="https://tabelaplanos.com.br/plano-hapvida-valores" target="_blank" class="gpp-botao-consulta">
                        Solicitar cotação personalizada
                    </a>
                </div>
            </div>
        </div>

        <script type="text/javascript">
        (function(){
            var container = document.getElementById('<?php echo $id_unico; ?>');
            if (!container) return;

            var planos = <?php echo $dados_json; ?>;
            var maxSelecao = 3;

            var checkboxes = container.querySelectorAll('.gpp-comp-check');
            var btnComparar = container.querySelector('.gpp-comp-comparar');
            var divSelecao = container.querySelector('.gpp-comp-selecao');
            var divFiltros = container.querySelector('.gpp-comp-filtros');
            var divResultado = container.querySelector('.gpp-comp-resultado');
            var btnVoltar = container.querySelector('.gpp-comp-voltar');
            var filtroBtns = container.querySelectorAll('.gpp-comp-filtro-btn');

            // Filtro por tipo
            for (var f = 0; f < filtroBtns.length; f++) {
                filtroBtns[f].addEventListener('click', function() {
                    for (var x = 0; x < filtroBtns.length; x++) {
                        filtroBtns[x].classList.remove('active');
                    }
                    this.classList.add('active');

                    var filtro = this.getAttribute('data-filtro');
                    var items = container.querySelectorAll('.gpp-comp-plano-item');
                    for (var i = 0; i < items.length; i++) {
                        if (filtro === 'todos' || items[i].getAttribute('data-tipo') === filtro) {
                            items[i].style.display = '';
                        } else {
                            items[i].style.display = 'none';
                            // Desmarcar checkboxes ocultos
                            var cb = items[i].querySelector('.gpp-comp-check');
                            if (cb) cb.checked = false;
                        }
                    }
                    atualizarBotao();
                });
            }

            function contarSelecionados() {
                var count = 0;
                for (var i = 0; i < checkboxes.length; i++) {
                    if (checkboxes[i].checked) count++;
                }
                return count;
            }

            function atualizarBotao() {
                var count = contarSelecionados();
                btnComparar.disabled = count < 2;
                if (count >= 2) {
                    btnComparar.textContent = 'Comparar ' + count + ' planos selecionados';
                } else {
                    btnComparar.textContent = 'Selecione ao menos 2 planos';
                }
            }

            for (var i = 0; i < checkboxes.length; i++) {
                checkboxes[i].addEventListener('change', function() {
                    if (this.checked && contarSelecionados() > maxSelecao) {
                        this.checked = false;
                        return;
                    }
                    atualizarBotao();
                });
            }

            function formatarMoeda(v) {
                return 'R$ ' + v.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            }

            btnComparar.addEventListener('click', function() {
                var selecionados = [];
                for (var i = 0; i < checkboxes.length; i++) {
                    if (checkboxes[i].checked) {
                        selecionados.push(parseInt(checkboxes[i].value));
                    }
                }

                if (selecionados.length < 2) return;

                // Coleta todas as faixas etárias únicas
                var todasFaixas = [];
                var faixasSet = {};
                for (var s = 0; s < selecionados.length; s++) {
                    var plano = planos[selecionados[s]];
                    for (var f = 0; f < plano.faixas.length; f++) {
                        var fe = plano.faixas[f].faixa_etaria;
                        if (!faixasSet[fe]) {
                            faixasSet[fe] = true;
                            todasFaixas.push(fe);
                        }
                    }
                }

                // Constrói header
                var thead = container.querySelector('.gpp-comp-thead');
                var trHead = '<tr><th>Faixa Etária</th>';
                for (var s = 0; s < selecionados.length; s++) {
                    var p = planos[selecionados[s]];
                    trHead += '<th>' + p.label_completo + '</th>';
                }
                trHead += '</tr>';
                thead.innerHTML = trHead;

                // Constrói body
                var tbody = container.querySelector('.gpp-comp-tbody');
                tbody.innerHTML = '';

                for (var fi = 0; fi < todasFaixas.length; fi++) {
                    var faixa = todasFaixas[fi];
                    var valores = [];
                    var menorValor = Infinity;

                    // Coleta valores
                    for (var s = 0; s < selecionados.length; s++) {
                        var plano = planos[selecionados[s]];
                        var val = null;
                        for (var fx = 0; fx < plano.faixas.length; fx++) {
                            if (plano.faixas[fx].faixa_etaria === faixa) {
                                val = plano.faixas[fx].valor;
                                break;
                            }
                        }
                        valores.push(val);
                        if (val !== null && val < menorValor) {
                            menorValor = val;
                        }
                    }

                    var tr = document.createElement('tr');
                    var tdFaixa = '<td>' + faixa + '</td>';
                    tr.innerHTML = tdFaixa;

                    for (var v = 0; v < valores.length; v++) {
                        var td = document.createElement('td');
                        if (valores[v] === null) {
                            td.textContent = '—';
                            td.style.color = '#ccc';
                            td.style.textAlign = 'center';
                        } else {
                            td.innerHTML = '<strong>' + formatarMoeda(valores[v]) + '</strong>';
                            if (valores[v] === menorValor && selecionados.length > 1) {
                                td.classList.add('gpp-comp-celula-melhor');
                            }
                        }
                        tr.appendChild(td);
                    }

                    tbody.appendChild(tr);
                }

                // Mostra resultado, esconde seleção
                divSelecao.style.display = 'none';
                divFiltros.style.display = 'none';
                divResultado.style.display = 'block';
                divResultado.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });

            btnVoltar.addEventListener('click', function() {
                divResultado.style.display = 'none';
                divSelecao.style.display = '';
                divFiltros.style.display = '';
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Imprime estilos CSS
     */
    public function imprimir_estilos() {
        ?>
        <style type="text/css">
        .gpp-comparador-container {
            max-width: 900px;
            margin: 30px auto;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        .gpp-comparador-header {
            background: linear-gradient(135deg, #0054B8, #003d8a);
            color: #fff;
            padding: 25px 30px;
            text-align: center;
        }

        .gpp-comparador-header h3 {
            margin: 0 0 5px 0 !important;
            font-size: 22px !important;
            color: #fff !important;
        }

        .gpp-comparador-subtitulo {
            margin: 0 !important;
            font-size: 14px !important;
            color: #e0e8f5 !important;
        }

        /* Filtros */
        .gpp-comp-filtros {
            padding: 18px 30px 0;
            background: #fafbfc;
        }

        .gpp-comp-filtro-grupo label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #555;
            margin-bottom: 8px;
        }

        .gpp-comp-filtro-btns {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .gpp-comp-filtro-btn {
            padding: 6px 14px;
            border: 1px solid #ddd;
            background: #fff;
            border-radius: 20px;
            font-size: 13px;
            cursor: pointer;
            color: #555;
            transition: all 0.2s;
        }

        .gpp-comp-filtro-btn:hover {
            border-color: #0054B8;
            color: #0054B8;
        }

        .gpp-comp-filtro-btn.active {
            background: #0054B8;
            color: #fff;
            border-color: #0054B8;
        }

        /* Seleção de planos */
        .gpp-comp-selecao {
            padding: 20px 30px 25px;
            background: #fafbfc;
        }

        .gpp-comp-planos-lista {
            display: grid;
            gap: 8px;
            margin-bottom: 18px;
        }

        .gpp-comp-plano-item {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            background: #fff;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .gpp-comp-plano-item:hover {
            border-color: #0054B8;
            box-shadow: 0 2px 6px rgba(0,84,184,0.1);
        }

        .gpp-comp-plano-item label {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            cursor: pointer;
            gap: 12px;
        }

        .gpp-comp-check {
            width: 18px;
            height: 18px;
            accent-color: #0054B8;
            flex-shrink: 0;
        }

        .gpp-comp-plano-info {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            flex: 1;
        }

        .gpp-comp-plano-tipo {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #fff;
        }

        .gpp-comp-badge-empresarial { background: #0054B8; }
        .gpp-comp-badge-individual { background: #2e7d32; }
        .gpp-comp-badge-pme { background: #f57c00; }
        .gpp-comp-badge-adesao { background: #7b1fa2; }

        .gpp-comp-plano-nome {
            font-size: 14px;
            font-weight: 600;
            color: #333;
        }

        .gpp-comp-plano-desde {
            font-size: 12px;
            color: #888;
            margin-left: auto;
        }

        .gpp-comp-plano-desde strong {
            color: #F05A22;
        }

        .gpp-comp-comparar {
            width: 100%;
            padding: 14px;
            background: #F05A22;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.3s, transform 0.2s;
        }

        .gpp-comp-comparar:hover:not(:disabled) {
            background: #d64a1a;
            transform: translateY(-1px);
        }

        .gpp-comp-comparar:disabled {
            background: #ccc;
            cursor: not-allowed;
        }

        /* Resultado */
        .gpp-comp-resultado {
            padding: 25px 30px;
            background: #fff;
            border-top: 3px solid #0054B8;
        }

        .gpp-comp-resultado-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
        }

        .gpp-comp-resultado-header h4 {
            margin: 0 !important;
            font-size: 18px !important;
            color: #0054B8 !important;
        }

        .gpp-comp-voltar {
            padding: 6px 16px;
            border: 1px solid #0054B8;
            background: #fff;
            color: #0054B8;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .gpp-comp-voltar:hover {
            background: #0054B8;
            color: #fff;
        }

        /* Tabela de comparação */
        .gpp-comp-tabela-wrapper {
            overflow-x: auto;
            margin-bottom: 15px;
        }

        .gpp-comp-tabela {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            min-width: 400px;
        }

        .gpp-comp-tabela th {
            background: #0054B8 !important;
            color: #fff !important;
            padding: 12px 14px !important;
            text-align: center !important;
            font-weight: 600 !important;
            font-size: 12px !important;
            border: 1px solid #003d8a !important;
        }

        .gpp-comp-tabela th:first-child {
            text-align: left !important;
            min-width: 130px;
        }

        .gpp-comp-tabela td {
            padding: 10px 14px !important;
            border: 1px solid #eee !important;
            text-align: center !important;
            color: #333 !important;
            background: #fff !important;
        }

        .gpp-comp-tabela td:first-child {
            text-align: left !important;
            font-weight: 600 !important;
            background: #f8f9fa !important;
        }

        .gpp-comp-tabela tr:hover td {
            background: #f5f8ff !important;
        }

        .gpp-comp-tabela tr:hover td:first-child {
            background: #eef2f7 !important;
        }

        .gpp-comp-celula-melhor {
            background: #e8f5e9 !important;
            color: #2e7d32 !important;
            position: relative;
        }

        .gpp-comp-celula-melhor strong {
            color: #2e7d32;
        }

        /* Legenda */
        .gpp-comp-legenda {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            font-size: 12px;
        }

        .gpp-comp-legenda-item {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #666;
        }

        .gpp-comp-melhor-preco::before {
            content: '';
            display: inline-block;
            width: 14px;
            height: 14px;
            background: #e8f5e9;
            border: 1px solid #a5d6a7;
            border-radius: 3px;
        }

        .gpp-comp-aviso {
            margin-top: 10px;
            padding: 14px;
            background: #fff3e0;
            border-left: 4px solid #F05A22;
            border-radius: 4px;
            font-size: 12px;
            color: #666;
            line-height: 1.5;
        }

        @media (max-width: 600px) {
            .gpp-comp-selecao,
            .gpp-comp-filtros,
            .gpp-comp-resultado {
                padding-left: 16px;
                padding-right: 16px;
            }

            .gpp-comparador-header {
                padding: 18px 16px;
            }

            .gpp-comp-plano-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 4px;
            }

            .gpp-comp-plano-desde {
                margin-left: 0;
            }

            .gpp-comp-resultado-header {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }
        }
        </style>
        <?php
    }
}

// Inicializa
add_action('plugins_loaded', function() {
    new GPP_Comparador_Planos();
}, 21);
