<?php
/*
Plugin Name: Simulador de Custo Real - Planos de Saúde
Description: Shortcode de simulador de custo real mensal considerando preço base + coparticipações por uso (consultas, exames, etc.)
Version: 1.0
Author: Seu Nome
Depends: Gerenciador de Preços de Planos de Saúde
*/

if (!defined('ABSPATH')) {
    exit;
}

class GPP_Simulador_Custo {

    private $option_name = 'gpp_cidades_planos';

    public function __construct() {
        add_action('init', array($this, 'registrar_shortcodes'));
        add_action('wp_enqueue_scripts', array($this, 'registrar_assets'));
    }

    /**
     * Obtém todas as cidades cadastradas
     */
    private function obter_todas_cidades() {
        $cidades = get_option($this->option_name, array());
        return is_array($cidades) ? $cidades : array();
    }

    /**
     * Obtém desconto do tipo de plano para a cidade
     */
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

    /**
     * Converte valor string "R$ 1.234,56" para float
     */
    private function valor_para_float($valor) {
        $limpo = str_replace(array('R$', ' ', '.'), '', $valor);
        $limpo = str_replace(',', '.', $limpo);
        return floatval($limpo);
    }

    /**
     * Formata float para moeda brasileira
     */
    private function formatar_moeda($valor) {
        return 'R$ ' . number_format($valor, 2, ',', '.');
    }

    /**
     * Registra shortcodes para cada cidade
     * Formato: [simulador_custo_{cidade_shortcode}]
     */
    public function registrar_shortcodes() {
        $cidades = $this->obter_todas_cidades();
        if (empty($cidades)) {
            return;
        }

        foreach ($cidades as $cidade_data) {
            $shortcode_base = $cidade_data['shortcode'];
            $tag = 'simulador_custo_' . $shortcode_base;
            add_shortcode($tag, array($this, 'renderizar_simulador'));
        }
    }

    /**
     * Registra CSS e JS do simulador
     */
    public function registrar_assets() {
        add_action('wp_head', array($this, 'imprimir_estilos'), 101);
        add_action('wp_footer', array($this, 'imprimir_scripts'), 99);
    }

    /**
     * Renderiza o simulador de custo real
     */
    public function renderizar_simulador($atts, $content, $tag) {
        // Extrai slug da cidade a partir do nome do shortcode
        $cidade_slug = str_replace('simulador_custo_', '', $tag);

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

        // Coleta planos disponíveis nesta cidade
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
            'total' => 'Coparticipação Total',
            'parcial' => 'Coparticipação Parcial'
        );

        // Monta dados de planos disponíveis para o JS
        $planos_disponiveis = array();

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

                    foreach ($cidade[$campo] as $faixa) {
                        $valor_base = $this->valor_para_float($faixa['valor']);
                        if ($desconto > 0) {
                            $valor_base = $valor_base * (1 - $desconto / 100);
                        }
                        $faixas[] = array(
                            'faixa_etaria' => $faixa['faixa_etaria'],
                            'valor' => round($valor_base, 2)
                        );
                    }

                    $label = $tipo_nome . ' - ' . $acom_nome . ' - ' . $cop_nome;
                    $planos_disponiveis[] = array(
                        'id' => $campo,
                        'tipo' => $tipo_key,
                        'acomodacao' => $acom_key,
                        'coparticipacao' => $cop_key,
                        'label' => $label,
                        'faixas' => $faixas
                    );
                }
            }
        }

        if (empty($planos_disponiveis)) {
            return '<p>Nenhum plano disponível para simulação nesta cidade.</p>';
        }

        $id_unico = 'gpp-sim-' . esc_attr($cidade_slug) . '-' . wp_rand(1000, 9999);
        $dados_json = wp_json_encode($planos_disponiveis);

        ob_start();
        ?>
        <div class="gpp-simulador-container" id="<?php echo $id_unico; ?>">
            <div class="gpp-simulador-header">
                <h3>Simulador de Custo Real Mensal</h3>
                <p class="gpp-simulador-subtitulo">Descubra quanto você realmente vai pagar por mês considerando o uso do plano</p>
            </div>

            <div class="gpp-simulador-form">
                <!-- Seleção de Plano -->
                <div class="gpp-sim-campo">
                    <label>Escolha o plano:</label>
                    <select class="gpp-sim-plano">
                        <?php foreach ($planos_disponiveis as $idx => $plano): ?>
                            <option value="<?php echo $idx; ?>"><?php echo esc_html($plano['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Seleção de Faixa Etária -->
                <div class="gpp-sim-campo">
                    <label>Faixa etária:</label>
                    <select class="gpp-sim-faixa">
                        <!-- Preenchido via JS -->
                    </select>
                </div>

                <!-- Quantidade de vidas -->
                <div class="gpp-sim-campo">
                    <label>Quantidade de vidas (beneficiários):</label>
                    <input type="number" class="gpp-sim-vidas" value="1" min="1" max="50">
                </div>

                <!-- Uso mensal estimado -->
                <div class="gpp-sim-uso-header">
                    <h4>Uso mensal estimado por beneficiário</h4>
                    <p class="gpp-sim-uso-desc">Informe quantas vezes cada beneficiário usará o plano por mês em média</p>
                </div>

                <div class="gpp-sim-uso-grid">
                    <div class="gpp-sim-campo-uso">
                        <label>Consultas eletivas</label>
                        <div class="gpp-sim-stepper">
                            <button type="button" class="gpp-sim-btn-menos" data-alvo="consultas">−</button>
                            <input type="number" class="gpp-sim-consultas" value="0" min="0" max="30">
                            <button type="button" class="gpp-sim-btn-mais" data-alvo="consultas">+</button>
                        </div>
                    </div>

                    <div class="gpp-sim-campo-uso">
                        <label>Exames simples</label>
                        <div class="gpp-sim-stepper">
                            <button type="button" class="gpp-sim-btn-menos" data-alvo="exames_simples">−</button>
                            <input type="number" class="gpp-sim-exames-simples" value="0" min="0" max="30">
                            <button type="button" class="gpp-sim-btn-mais" data-alvo="exames_simples">+</button>
                        </div>
                    </div>

                    <div class="gpp-sim-campo-uso">
                        <label>Exames complexos</label>
                        <div class="gpp-sim-stepper">
                            <button type="button" class="gpp-sim-btn-menos" data-alvo="exames_complexos">−</button>
                            <input type="number" class="gpp-sim-exames-complexos" value="0" min="0" max="30">
                            <button type="button" class="gpp-sim-btn-mais" data-alvo="exames_complexos">+</button>
                        </div>
                    </div>

                    <div class="gpp-sim-campo-uso">
                        <label>Pronto-socorro</label>
                        <div class="gpp-sim-stepper">
                            <button type="button" class="gpp-sim-btn-menos" data-alvo="pronto_socorro">−</button>
                            <input type="number" class="gpp-sim-pronto-socorro" value="0" min="0" max="10">
                            <button type="button" class="gpp-sim-btn-mais" data-alvo="pronto_socorro">+</button>
                        </div>
                    </div>

                    <div class="gpp-sim-campo-uso">
                        <label>Internações</label>
                        <div class="gpp-sim-stepper">
                            <button type="button" class="gpp-sim-btn-menos" data-alvo="internacoes">−</button>
                            <input type="number" class="gpp-sim-internacoes" value="0" min="0" max="5">
                            <button type="button" class="gpp-sim-btn-mais" data-alvo="internacoes">+</button>
                        </div>
                    </div>

                    <div class="gpp-sim-campo-uso">
                        <label>Terapias (fisio, fono, etc.)</label>
                        <div class="gpp-sim-stepper">
                            <button type="button" class="gpp-sim-btn-menos" data-alvo="terapias">−</button>
                            <input type="number" class="gpp-sim-terapias" value="0" min="0" max="30">
                            <button type="button" class="gpp-sim-btn-mais" data-alvo="terapias">+</button>
                        </div>
                    </div>
                </div>

                <button type="button" class="gpp-sim-calcular">Calcular Custo Real</button>
            </div>

            <!-- Resultado -->
            <div class="gpp-sim-resultado" style="display:none;">
                <div class="gpp-sim-resultado-header">
                    <h4>Resultado da Simulação</h4>
                </div>

                <div class="gpp-sim-resultado-cards">
                    <div class="gpp-sim-card gpp-sim-card-base">
                        <span class="gpp-sim-card-label">Mensalidade Base</span>
                        <span class="gpp-sim-card-valor gpp-sim-valor-base"></span>
                        <span class="gpp-sim-card-detalhe gpp-sim-detalhe-base"></span>
                    </div>

                    <div class="gpp-sim-card gpp-sim-card-uso">
                        <span class="gpp-sim-card-label">Custo estimado de uso</span>
                        <span class="gpp-sim-card-valor gpp-sim-valor-uso"></span>
                        <span class="gpp-sim-card-detalhe gpp-sim-detalhe-uso"></span>
                    </div>

                    <div class="gpp-sim-card gpp-sim-card-total">
                        <span class="gpp-sim-card-label">Custo Real Mensal Estimado</span>
                        <span class="gpp-sim-card-valor gpp-sim-valor-total"></span>
                        <span class="gpp-sim-card-detalhe gpp-sim-detalhe-total"></span>
                    </div>
                </div>

                <div class="gpp-sim-detalhamento">
                    <h5>Detalhamento dos custos de uso</h5>
                    <table class="gpp-sim-tabela-detalhe">
                        <thead>
                            <tr>
                                <th>Tipo de uso</th>
                                <th>Qtd.</th>
                                <th>Valor unitário</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody class="gpp-sim-tbody-detalhe">
                        </tbody>
                    </table>
                </div>

                <div class="gpp-sim-aviso">
                    <strong>Atenção:</strong> Os valores de coparticipação são estimativas baseadas em médias de mercado da Hapvida.
                    Os valores reais podem variar conforme o contrato e a região.
                    Para valores exatos, consulte o contrato do plano.
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

            // Valores médios de coparticipação Hapvida (estimativas de mercado)
            var custosCoparticipacao = {
                total: {
                    consultas: 50.00,
                    exames_simples: 15.00,
                    exames_complexos: 80.00,
                    pronto_socorro: 100.00,
                    internacoes: 500.00,
                    terapias: 30.00
                },
                parcial: {
                    consultas: 25.00,
                    exames_simples: 8.00,
                    exames_complexos: 40.00,
                    pronto_socorro: 50.00,
                    internacoes: 250.00,
                    terapias: 15.00
                }
            };

            var selPlano = container.querySelector('.gpp-sim-plano');
            var selFaixa = container.querySelector('.gpp-sim-faixa');
            var inputVidas = container.querySelector('.gpp-sim-vidas');
            var btnCalc = container.querySelector('.gpp-sim-calcular');

            function atualizarFaixas() {
                var idx = parseInt(selPlano.value);
                var plano = planos[idx];
                selFaixa.innerHTML = '';
                for (var i = 0; i < plano.faixas.length; i++) {
                    var opt = document.createElement('option');
                    opt.value = i;
                    opt.textContent = plano.faixas[i].faixa_etaria;
                    selFaixa.appendChild(opt);
                }
            }

            selPlano.addEventListener('change', atualizarFaixas);
            atualizarFaixas();

            // Stepper buttons
            var btnsMenos = container.querySelectorAll('.gpp-sim-btn-menos');
            var btnsMais = container.querySelectorAll('.gpp-sim-btn-mais');

            for (var i = 0; i < btnsMenos.length; i++) {
                btnsMenos[i].addEventListener('click', function() {
                    var alvo = this.getAttribute('data-alvo');
                    var input = getInputByAlvo(alvo);
                    if (input && parseInt(input.value) > 0) {
                        input.value = parseInt(input.value) - 1;
                    }
                });
            }

            for (var i = 0; i < btnsMais.length; i++) {
                btnsMais[i].addEventListener('click', function() {
                    var alvo = this.getAttribute('data-alvo');
                    var input = getInputByAlvo(alvo);
                    if (input && parseInt(input.value) < parseInt(input.max)) {
                        input.value = parseInt(input.value) + 1;
                    }
                });
            }

            function getInputByAlvo(alvo) {
                var map = {
                    'consultas': '.gpp-sim-consultas',
                    'exames_simples': '.gpp-sim-exames-simples',
                    'exames_complexos': '.gpp-sim-exames-complexos',
                    'pronto_socorro': '.gpp-sim-pronto-socorro',
                    'internacoes': '.gpp-sim-internacoes',
                    'terapias': '.gpp-sim-terapias'
                };
                return container.querySelector(map[alvo]);
            }

            function formatarMoeda(v) {
                return 'R$ ' + v.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            }

            btnCalc.addEventListener('click', function() {
                var idxPlano = parseInt(selPlano.value);
                var plano = planos[idxPlano];
                var idxFaixa = parseInt(selFaixa.value);
                var vidas = parseInt(inputVidas.value) || 1;

                var valorBase = plano.faixas[idxFaixa].valor;
                var mensalidadeBase = valorBase * vidas;

                var tipoCopart = plano.coparticipacao; // 'total' ou 'parcial'
                var custos = custosCoparticipacao[tipoCopart];

                var uso = {
                    consultas: parseInt(container.querySelector('.gpp-sim-consultas').value) || 0,
                    exames_simples: parseInt(container.querySelector('.gpp-sim-exames-simples').value) || 0,
                    exames_complexos: parseInt(container.querySelector('.gpp-sim-exames-complexos').value) || 0,
                    pronto_socorro: parseInt(container.querySelector('.gpp-sim-pronto-socorro').value) || 0,
                    internacoes: parseInt(container.querySelector('.gpp-sim-internacoes').value) || 0,
                    terapias: parseInt(container.querySelector('.gpp-sim-terapias').value) || 0
                };

                var labels = {
                    consultas: 'Consultas eletivas',
                    exames_simples: 'Exames simples',
                    exames_complexos: 'Exames complexos',
                    pronto_socorro: 'Pronto-socorro',
                    internacoes: 'Internações',
                    terapias: 'Terapias'
                };

                var totalUso = 0;
                var detalhes = [];

                for (var chave in uso) {
                    if (uso[chave] > 0) {
                        var qtdTotal = uso[chave] * vidas;
                        var subtotal = qtdTotal * custos[chave];
                        totalUso += subtotal;
                        detalhes.push({
                            label: labels[chave],
                            qtd: qtdTotal,
                            unitario: custos[chave],
                            subtotal: subtotal
                        });
                    }
                }

                var custoTotal = mensalidadeBase + totalUso;

                // Exibe resultado
                var divResultado = container.querySelector('.gpp-sim-resultado');
                divResultado.style.display = 'block';

                container.querySelector('.gpp-sim-valor-base').textContent = formatarMoeda(mensalidadeBase);
                container.querySelector('.gpp-sim-detalhe-base').textContent = vidas > 1
                    ? vidas + ' vidas × ' + formatarMoeda(valorBase) + '/mês'
                    : formatarMoeda(valorBase) + '/mês por beneficiário';

                container.querySelector('.gpp-sim-valor-uso').textContent = formatarMoeda(totalUso);
                container.querySelector('.gpp-sim-detalhe-uso').textContent = tipoCopart === 'parcial'
                    ? 'Valores de coparticipação parcial'
                    : 'Valores de coparticipação total';

                container.querySelector('.gpp-sim-valor-total').textContent = formatarMoeda(custoTotal);
                container.querySelector('.gpp-sim-detalhe-total').textContent = 'Mensalidade + uso estimado para ' + vidas + (vidas > 1 ? ' beneficiários' : ' beneficiário');

                // Tabela de detalhamento
                var tbody = container.querySelector('.gpp-sim-tbody-detalhe');
                tbody.innerHTML = '';

                if (detalhes.length === 0) {
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td colspan="4" style="text-align:center;color:#888;">Nenhum uso informado — apenas mensalidade base</td>';
                    tbody.appendChild(tr);
                } else {
                    for (var d = 0; d < detalhes.length; d++) {
                        var tr = document.createElement('tr');
                        tr.innerHTML = '<td>' + detalhes[d].label + '</td>' +
                            '<td>' + detalhes[d].qtd + '</td>' +
                            '<td>' + formatarMoeda(detalhes[d].unitario) + '</td>' +
                            '<td><strong>' + formatarMoeda(detalhes[d].subtotal) + '</strong></td>';
                        tbody.appendChild(tr);
                    }
                }

                // Scroll suave até resultado
                divResultado.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Imprime estilos CSS inline
     */
    public function imprimir_estilos() {
        ?>
        <style type="text/css">
        .gpp-simulador-container {
            max-width: 800px;
            margin: 30px auto;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        .gpp-simulador-header {
            background: linear-gradient(135deg, #0054B8, #003d8a);
            color: #fff;
            padding: 25px 30px;
            text-align: center;
        }

        .gpp-simulador-header h3 {
            margin: 0 0 5px 0 !important;
            font-size: 22px !important;
            color: #fff !important;
        }

        .gpp-simulador-subtitulo {
            margin: 0 !important;
            font-size: 14px !important;
            opacity: 0.9;
            color: #e0e8f5 !important;
        }

        .gpp-simulador-form {
            padding: 25px 30px;
            background: #fafbfc;
        }

        .gpp-sim-campo {
            margin-bottom: 18px;
        }

        .gpp-sim-campo label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            color: #333;
            font-size: 14px;
        }

        .gpp-sim-campo select,
        .gpp-sim-campo input[type="number"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 14px;
            background: #fff;
            box-sizing: border-box;
        }

        .gpp-sim-uso-header {
            margin: 25px 0 15px 0;
            padding-top: 18px;
            border-top: 1px solid #e0e0e0;
        }

        .gpp-sim-uso-header h4 {
            margin: 0 0 4px 0 !important;
            font-size: 16px !important;
            color: #0054B8 !important;
        }

        .gpp-sim-uso-desc {
            margin: 0 !important;
            font-size: 13px !important;
            color: #777 !important;
        }

        .gpp-sim-uso-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 20px;
        }

        @media (max-width: 600px) {
            .gpp-sim-uso-grid {
                grid-template-columns: 1fr;
            }
            .gpp-simulador-form {
                padding: 18px 16px;
            }
            .gpp-simulador-header {
                padding: 18px 16px;
            }
        }

        .gpp-sim-campo-uso {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 12px;
        }

        .gpp-sim-campo-uso label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #555;
            margin-bottom: 8px;
        }

        .gpp-sim-stepper {
            display: flex;
            align-items: center;
            gap: 0;
        }

        .gpp-sim-stepper input[type="number"] {
            width: 50px;
            text-align: center;
            border: 1px solid #ddd;
            border-radius: 0;
            padding: 6px 4px;
            font-size: 16px;
            font-weight: bold;
            -moz-appearance: textfield;
        }

        .gpp-sim-stepper input::-webkit-outer-spin-button,
        .gpp-sim-stepper input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .gpp-sim-btn-menos,
        .gpp-sim-btn-mais {
            width: 36px;
            height: 36px;
            border: 1px solid #ddd;
            background: #f5f5f5;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            color: #333;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
        }

        .gpp-sim-btn-menos {
            border-radius: 6px 0 0 6px;
        }

        .gpp-sim-btn-mais {
            border-radius: 0 6px 6px 0;
        }

        .gpp-sim-btn-menos:hover,
        .gpp-sim-btn-mais:hover {
            background: #e0e0e0;
        }

        .gpp-sim-calcular {
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

        .gpp-sim-calcular:hover {
            background: #d64a1a;
            transform: translateY(-1px);
        }

        /* Resultado */
        .gpp-sim-resultado {
            padding: 25px 30px;
            background: #fff;
            border-top: 3px solid #0054B8;
        }

        .gpp-sim-resultado-header h4 {
            margin: 0 0 18px 0 !important;
            font-size: 18px !important;
            color: #0054B8 !important;
            text-align: center;
        }

        .gpp-sim-resultado-cards {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 12px;
            margin-bottom: 20px;
        }

        @media (max-width: 600px) {
            .gpp-sim-resultado-cards {
                grid-template-columns: 1fr;
            }
            .gpp-sim-resultado {
                padding: 18px 16px;
            }
        }

        .gpp-sim-card {
            text-align: center;
            padding: 18px 12px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }

        .gpp-sim-card-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            color: #777;
            margin-bottom: 6px;
        }

        .gpp-sim-card-valor {
            display: block;
            font-size: 22px;
            font-weight: bold;
            margin-bottom: 4px;
        }

        .gpp-sim-card-detalhe {
            display: block;
            font-size: 11px;
            color: #999;
        }

        .gpp-sim-card-base {
            background: #f0f4ff;
        }

        .gpp-sim-card-base .gpp-sim-card-valor {
            color: #0054B8;
        }

        .gpp-sim-card-uso {
            background: #fff8f0;
        }

        .gpp-sim-card-uso .gpp-sim-card-valor {
            color: #F05A22;
        }

        .gpp-sim-card-total {
            background: linear-gradient(135deg, #0054B8, #003d8a);
            color: #fff;
            border-color: #0054B8;
        }

        .gpp-sim-card-total .gpp-sim-card-label {
            color: rgba(255,255,255,0.85);
        }

        .gpp-sim-card-total .gpp-sim-card-valor {
            color: #fff;
            font-size: 26px;
        }

        .gpp-sim-card-total .gpp-sim-card-detalhe {
            color: rgba(255,255,255,0.7);
        }

        .gpp-sim-detalhamento {
            margin-top: 20px;
        }

        .gpp-sim-detalhamento h5 {
            margin: 0 0 10px 0 !important;
            font-size: 14px !important;
            color: #555 !important;
        }

        .gpp-sim-tabela-detalhe {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .gpp-sim-tabela-detalhe th {
            background: #f5f5f5 !important;
            color: #555 !important;
            padding: 8px 10px !important;
            text-align: left !important;
            font-weight: 600 !important;
            border-bottom: 2px solid #ddd !important;
        }

        .gpp-sim-tabela-detalhe td {
            padding: 8px 10px !important;
            border-bottom: 1px solid #eee !important;
            color: #333 !important;
            background: #fff !important;
        }

        .gpp-sim-aviso {
            margin-top: 18px;
            padding: 14px;
            background: #fff3e0;
            border-left: 4px solid #F05A22;
            border-radius: 4px;
            font-size: 12px;
            color: #666;
            line-height: 1.5;
        }
        </style>
        <?php
    }

    /**
     * Imprime scripts (vazio - scripts são inline no shortcode)
     */
    public function imprimir_scripts() {
        // Scripts são emitidos inline com cada instância do shortcode
    }
}

// Inicializa
add_action('plugins_loaded', function() {
    new GPP_Simulador_Custo();
}, 20);
