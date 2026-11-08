<?php
/*
 * Test différentiel : le code ORIGINAL du scénario « Pilotage Wallbox » V4.0
 * (tests/reference/scenario98_v4.php, copié tel quel depuis Jeedom) et la
 * classe ChargePlannerRegulator sont exécutés sur les mêmes situations
 * aléatoires. On compare, octet pour octet : les actions envoyées aux bornes
 * (dans l'ordre), l'état persistant, les valeurs publiées et le log.
 *
 *   php tests/regulator_diff_test.php [nombre_de_cas] [graine]
 */

namespace Ref {
    // Environnement simulé pour exécuter le scénario original tel quel.
    class Exception extends \Exception {}
    function time() { return $GLOBALS['SIM']->now; }
    function sleep($s) { $GLOBALS['SIM']->ops[] = 'sleep:' . $s; $GLOBALS['SIM']->phase2(); }
    class cmd {
        private $key;
        public function __construct($key) { $this->key = $key; }
        public static function byString($s) {
            $map = $GLOBALS['SIM']->humanMap;
            if (!isset($map[$s])) { throw new Exception('commande inconnue ' . $s); }
            return new cmd($map[$s]);
        }
        public function execCmd($opt = null) {
            $sim = $GLOBALS['SIM'];
            $k = $this->key;
            if ($k === 'wb1_slider' || $k === 'wb2_slider') { $sim->ops[] = 'slider:' . $k[2] . ':' . $opt['slider']; return; }
            if ($k === 'wb1_toggle' || $k === 'wb2_toggle') { $sim->ops[] = 'toggle:' . $k[2]; return; }
            if ($k === 'prio_off') { $sim->ops[] = 'prioOff'; return; }
            return $sim->read($k);
        }
        public function event($v) { $GLOBALS['SIM']->events[$this->key] = $v; }
        public function getCollectDate() { $ts = $GLOBALS['SIM']->statusTs[$this->key[2]] ?? 0; return $ts ? date('Y-m-d H:i:s', $ts) : ''; }
        public function getValueDate() { return ''; }
    }
    class ScenarioMock {
        public $data; public $logText = '';
        public function __construct(&$data) { $this->data = &$data; }
        public function getData($k) { return $this->data[$k] ?? ''; }
        public function setData($k, $v) { $this->data[$k] = $v; }
        public function setLog($t) { $this->logText = $t; $GLOBALS['SIM']->ops[] = 'log'; }
    }
    function runOriginal($code, $scenario) {
        eval($code);
    }
}

namespace {
    date_default_timezone_set('Europe/Paris');
    require_once __DIR__ . '/../core/class/ChargePlannerRegulator.class.php';

    class Sim {
        public $now; public $values; public $fresh; public $statusTs; public $ops = array(); public $events = array();
        public $humanMap; public $inPhase2 = false;
        public function read($k) { return ($this->inPhase2 && array_key_exists($k, $this->fresh)) ? $this->fresh[$k] : $this->values[$k]; }
        public function phase2() { $this->inPhase2 = true; }
    }

    class SimIO implements ChargePlannerRegulatorIO {
        public $sim; public $logText = '';
        public function __construct($sim) { $this->sim = $sim; }
        public function read($key) { return $this->sim->read($key); }
        public function statusTs($id) { $ts = $this->sim->statusTs[$id] ?? 0; return $ts ? strtotime(date('Y-m-d H:i:s', $ts)) : 0; }
        public function slider($id, $amp) { $this->sim->ops[] = 'slider:' . $id . ':' . $amp; }
        public function toggle($id) { $this->sim->ops[] = 'toggle:' . $id; }
        public function prioOff() { $this->sim->ops[] = 'prioOff'; }
        public function now() { return $this->sim->now; }
        public function sleep($s) { $this->sim->ops[] = 'sleep:' . $s; $this->sim->phase2(); }
        public function log($t) { $this->logText = $t; $this->sim->ops[] = 'log'; }
    }
    class SimState implements ChargePlannerRegulatorState {
        public $data;
        public function __construct($d) { $this->data = $d; }
        public function getData($k) { return $this->data[$k] ?? ''; }
        public function setData($k, $v) { $this->data[$k] = $v; }
    }

    // Code original : remplacement des seules références globales que le
    // namespace de test ne peut pas intercepter (function_exists).
    $original = file_get_contents(__DIR__ . '/reference/scenario98_v4.php');
    $original = "namespace Ref;\n" . preg_replace("/function_exists\\('(ve[A-Za-z]+)'\\)/", "function_exists('Ref\\\\\\\\$1')", $original);

    $humanMap = array(
        '#[Maison][Shelly Pro 3 EM 01][Phase C : Puissance Active]#' => 'conso_maison',
        '#[Maison][Shelly Pro 3 EM 01][Phase A : Puissance Active]#' => 'conso_edf',
        '#[Maison][Shelly Pro 3 EM 01][Phase B : Puissance Active]#' => 'prod_solaire',
        '#[Maison][Solis][Batterie]#' => 'soc_batterie',
        '#[Maison][Mesure - Batterie][Actuel]#' => 'p_batterie',
        '#[Maison][Mesure - Panneaux Solaire][Actuel]#' => 'prod_pv',
        '#[Maison][Wallbox 01 - Mode][Mode]#' => 'wb1_mode',
        '#[Maison][Wallbox01][Statut ID]#' => 'wb1_status',
        '#[Maison][Wallbox01][Puissance maximum]#' => 'wb1_slider',
        '#[Maison][Wallbox01][Contrôle de la charge]#' => 'wb1_toggle',
        '#[Maison][Shelly Pro 3 EM 02][Phase A : Puissance Active]#' => 'wb1_pince',
        '#[Maison][Wallbox 02 - Mode][Mode]#' => 'wb2_mode',
        '#[Maison][Wallbox02][Statut ID]#' => 'wb2_status',
        '#[Maison][Wallbox02][Puissance maximum]#' => 'wb2_slider',
        '#[Maison][Wallbox02][Contrôle de la charge]#' => 'wb2_toggle',
        '#[Maison][Shelly Pro 3 EM 02][Phase B : Puissance Active]#' => 'wb2_pince',
        '#[Maison][VE - Priorisation][Mode]#' => 'priorite',
        '#[Maison][VE - Priorisation][Désactivée]#' => 'prio_off',
        '#[Maison][Pilotage Wallbox 02][Surplus solaire]#' => 'surplus',
        '#[Maison][Pilotage Wallbox 02][Puissance dispo compteur]#' => 'dispo_compteur',
        '#[Maison][Pilotage Wallbox 02][WB1 en charge]#' => 'wb1_charge',
        '#[Maison][Pilotage Wallbox 02][Puissance cible WB2]#' => 'cible_wb2_w',
        '#[Maison][Pilotage Wallbox 02][Ampères calculés]#' => 'amp_wb2',
        '#[Maison][Pilotage Wallbox 02][Autorisation charge]#' => 'autorisation',
        '#[Maison][Pilotage Wallbox 02][Raison blocage]#' => 'raison',
    );

    $n = (int) ($argv[1] ?? 20000);
    mt_srand((int) ($argv[2] ?? 42));
    function pick($a) { return $a[mt_rand(0, count($a) - 1)]; }
    function chance($p) { return mt_rand(0, 999) < $p * 1000; }

    $statuses = array(0, 14, 161, 162, 163, 164, 165, 177, 178, 180, 181, 182, 189, 193, 194, 195, 209, 210);
    $modes = array('Normal', 'Normal', 'Eco', 'Eco', 'Full Green', '', 'bizarre', 'éco', ' normal ');
    $prios = array('WB1', 'WB2', 'Désactivée', 'Off', '', 'E208', 'Wallbox 02', 'n\'importe quoi');

    function randState($now) {
        $s = array();
        if (chance(0.9)) { $s['v4_init'] = 1; }
        foreach (array('1', '2') as $id) {
            $k = 'wb' . $id . '_';
            $amp = pick(array(0, 0, 6, 7, 8, 10, 12, 16, 20, 24, 28, 31, 32));
            if (chance(0.95)) { $s[$k . 'cible'] = $amp; $s[$k . 'slider'] = chance(0.7) ? $amp : pick(array(0, 6, 16, 32)); }
            $s[$k . 'dernier_amp'] = pick(array(0, 6, 16));
            foreach (array('action_amp_ts', 'toggle_ts', 'demarrage_ts', 'inactif_ts', 'faible_depuis', 'dernier_toggle', 'derniere_action_amp') as $t) {
                if (chance(0.7)) { $s[$k . $t] = pick(array(0, $now - 5, $now - 25, $now - 60, $now - 130, $now - 300, $now - 650, $now - 3700)); }
            }
            if (chance(0.8)) { $s[$k . 'mode_prec'] = pick(array('Normal', 'Eco')); }
        }
        foreach (array('eco_soc_ok_depuis', 'eco_derniere_montee', 'prio_reset_ts') as $t) {
            if (chance(0.6)) { $s[$t] = pick(array(0, $now - 10, $now - 40, $now - 200, $now - 700)); }
        }
        return $s;
    }
    function randValues() {
        $v = array(
            'conso_maison' => mt_rand(-500, 14000),
            'conso_edf' => mt_rand(-6000, 13500),
            'prod_solaire' => mt_rand(0, 6000),
            'soc_batterie' => pick(array(0, -1, 101, 20, 85, 89.9, 90, 90.5, 91, 92, 93, 94, 95, 98, 100)),
            'p_batterie' => mt_rand(-3000, 3000),
            'prod_pv' => mt_rand(0, 6000),
            'priorite' => pick($GLOBALS['prios']),
        );
        foreach (array('1', '2') as $id) {
            $v['wb' . $id . '_mode'] = pick($GLOBALS['modes']);
            $v['wb' . $id . '_status'] = pick($GLOBALS['statuses']);
            $v['wb' . $id . '_pince'] = pick(array(0, 0, 50, 400, 600, 1400, 3000, 5500, 7300));
        }
        if (chance(0.15)) { $v['conso_maison'] = (string) $v['conso_maison']; } // valeurs texte
        return $v;
    }
    function randFresh($v) {
        $f = array();
        foreach (array('1', '2') as $id) {
            if (chance(0.3)) { $f['wb' . $id . '_status'] = pick($GLOBALS['statuses']); }
            if (chance(0.3)) { $f['wb' . $id . '_pince'] = pick(array(0, 300, 800, 4000)); }
        }
        return $f;
    }

    $failures = 0;
    $cases = 0;
    $stats = array('slider' => 0, 'toggle' => 0, 'prioOff' => 0);
    for ($i = 0; $i < $n; $i++) {
        $now = 1794100000 + mt_rand(0, 100000);
        $stateA = randState($now);
        $stateB = $stateA;
        $steps = chance(0.3) ? mt_rand(2, 6) : 1; // chaînes de cycles : l'état persistant doit évoluer pareil
        for ($s = 0; $s < $steps; $s++) {
            $values = randValues();
            $fresh = randFresh($values);
            $statusTs = array('1' => pick(array(0, $now - 900, $now - 60, $now + 1)), '2' => pick(array(0, $now - 900, $now - 60, $now + 1)));

            // Original
            $GLOBALS['SIM'] = $simA = new Sim();
            $simA->now = $now; $simA->values = $values; $simA->fresh = $fresh; $simA->statusTs = $statusTs; $simA->humanMap = $humanMap;
            $scenario = new Ref\ScenarioMock($stateA);
            Ref\runOriginal($original, $scenario);
            $stateA = $scenario->data;

            // Plugin
            $GLOBALS['SIM'] = $simB = new Sim();
            $simB->now = $now; $simB->values = $values; $simB->fresh = $fresh; $simB->statusTs = $statusTs;
            $io = new SimIO($simB);
            $st = new SimState($stateB);
            $out = ChargePlannerRegulator::run($io, $st, array());
            $stateB = $st->data;

            $cases++;
            $evB = array();
            foreach (array('surplus', 'dispo_compteur', 'wb1_charge', 'cible_wb2_w', 'amp_wb2', 'autorisation', 'raison') as $k) {
                $evB[$k] = $out[$k];
            }
            $problems = array();
            if ($simA->ops !== $simB->ops) { $problems[] = 'actions ' . json_encode($simA->ops) . ' != ' . json_encode($simB->ops); }
            if ($stateA != $stateB) { $problems[] = 'état ' . json_encode(array_diff_assoc(array_map('strval', $stateA), array_map('strval', $stateB))); }
            if ($simA->events != $evB) { $problems[] = 'sorties ' . json_encode($simA->events) . ' != ' . json_encode($evB); }
            if ($scenario->logText !== $io->logText) { $problems[] = 'log différent'; }
            foreach ($simA->ops as $op) {
                $t = explode(':', $op)[0];
                if (isset($stats[$t])) { $stats[$t]++; }
            }
            if ($problems) {
                $failures++;
                if ($failures <= 5) {
                    echo "ÉCART cas $i étape $s :\n  " . implode("\n  ", $problems) . "\n  entrées " . json_encode($values) . "\n";
                }
                break;
            }
            $now += mt_rand(5, 120);
        }
    }
    echo "$cases cycles comparés, $failures écart(s). Actions rencontrées : " . json_encode($stats) . "\n";
    exit($failures === 0 ? 0 : 1);
}
