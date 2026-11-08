<?php
/*
 * Test d'intégration de la classe Jeedom avec un faux noyau minimal :
 *   php tests/plugin_test.php
 * Vérifie la prise et la remise de main sur le mode des bornes, le boost,
 * le mode simulation et la reprise en main manuelle.
 */
date_default_timezone_set('Europe/Paris');

$GLOBALS['NOW'] = strtotime('2026-11-10 21:00'); // mardi soir
$GLOBALS['CONFIG'] = array();
$GLOBALS['CMDS'] = array();
$GLOBALS['LOG'] = array();

function __($s, $f = '') { return $s; }

class log {
    public static function add($plugin, $level, $msg) { $GLOBALS['LOG'][] = "[$level] $msg"; }
}
class config {
    public static function byKey($key, $plugin = 'core', $default = '') {
        return array_key_exists($key, $GLOBALS['CONFIG']) ? $GLOBALS['CONFIG'][$key] : $default;
    }
    public static function save($key, $value, $plugin = 'core') { $GLOBALS['CONFIG'][$key] = $value; }
}
class cmd {
    public $id; public $type = 'info'; public $subType = 'string'; public $value = ''; public $date = '';
    public $onExec = null; public $logicalId = ''; public $eqLogic = null; public $configuration = array();
    public static function byId($id) { return $GLOBALS['CMDS'][$id] ?? null; }
    public static function byString($s) {
        foreach ($GLOBALS['CMDS'] as $c) { if ($c->human === $s) return $c; }
        throw new Exception('not found');
    }
    public $human = '';
    public function getType() { return $this->type; }
    public function setType($t) { $this->type = $t; }
    public function setSubType($t) { $this->subType = $t; }
    public function execCmd($o = array()) {
        if ($this->type === 'action') { $f = $this->onExec; if ($f) { $f(); } $GLOBALS['LOG'][] = 'EXEC ' . $this->human; return; }
        return $this->value;
    }
    public function getCollectDate() { return $this->date; }
    public function setLogicalId($l) { $this->logicalId = $l; }
    public function getLogicalId() { return $this->logicalId; }
    public function setEqLogic_id($id) {}
    public function setName($n) {}
    public function setIsVisible($v) {}
    public function setIsHistorized($v) {}
    public function setOrder($v) {}
    public function setUnite($v) {}
    public function setConfiguration($k, $v) { $this->configuration[$k] = $v; }
    public function save() {}
    public function getEqLogic() { return $this->eqLogic; }
}
class eqLogic {
    public $conf = array(); public $cache = array(); public $cmds = array(); public $infos = array();
    public static function byType($t, $onlyEnable = false) { return $GLOBALS['EQ']; }
    public function getConfiguration($k, $d = '') { return array_key_exists($k, $this->conf) ? $this->conf[$k] : $d; }
    public function setConfiguration($k, $v) { $this->conf[$k] = $v; }
    public function getCache($k, $d = '') { return array_key_exists($k, $this->cache) ? $this->cache[$k] : $d; }
    public function setCache($k, $v) { $this->cache[$k] = $v; }
    public function getCmd($t, $logicalId) {
        if (!isset($this->cmds[$logicalId])) return null;
        return $this->cmds[$logicalId];
    }
    public function checkAndUpdateCmd($logicalId, $value) {
        $this->infos[$logicalId] = $value;
        if (isset($this->cmds[$logicalId])) $this->cmds[$logicalId]->value = $value;
    }
    public function getHumanName() { return '[Garage][E208]'; }
    public function getId() { return 1; }
    public function save($direct = false) {}
}

require_once __DIR__ . '/../core/class/ChargePlanner.class.php';

/* ChargePlanner utilise time() : on le pilote via un namespace-less override */
// time() ne se surcharge pas : on décale la logique en injectant NOW dans les tests via une sous-classe.
class TestPlanner extends ChargePlanner {
    protected function now() { return $GLOBALS['NOW']; }
    public function at($when) { $GLOBALS['NOW'] = strtotime($when); return $this->evaluate(); }
    public function actAt($when, $action, $opt = array()) { $GLOBALS['NOW'] = strtotime($when); return $this->execute(array('action' => $action) + $opt); }
}

function mkcmd($id, $human, $type, $value = '') {
    $c = new cmd(); $c->id = $id; $c->human = $human; $c->type = $type; $c->value = $value; $c->date = '2026-11-10 20:00:00';
    $GLOBALS['CMDS'][$id] = $c; return $c;
}
$mode = mkcmd(10, '#[M][WB1][Mode]#', 'info', 'Eco');
$normal = mkcmd(11, '#[M][WB1][Normal]#', 'action'); $normal->onExec = function () use ($mode) { $mode->value = 'Normal'; };
$eco = mkcmd(12, '#[M][WB1][Eco]#', 'action'); $eco->onExec = function () use ($mode) { $mode->value = 'Eco'; };
$status = mkcmd(13, '#[M][WB1][Statut ID]#', 'info', 182);
$energy = mkcmd(14, '#[M][WB1][Energie]#', 'info', 0);
$soc = mkcmd(20, '#[G][E208][Batterie]#', 'info', 50);

$GLOBALS['CONFIG'] = array(
    'simulation' => 0, 'boost_time' => '00:05', 'offpeak_holidays' => 1,
    'offpeak_day_3' => 1, 'offpeak_day_6' => 1, 'offpeak_day_7' => 1,
    'borne1_name' => 'Wallbox 01',
    'borne1_mode' => '#[M][WB1][Mode]#', 'borne1_normal' => '#[M][WB1][Normal]#', 'borne1_eco' => '#[M][WB1][Eco]#',
    'borne1_status' => '#[M][WB1][Statut ID]#', 'borne1_energy' => '#[M][WB1][Energie]#',
);

$eq = new TestPlanner();
$eq->conf = array('soc_cmd' => '#[G][E208][Batterie]#', 'capacity_kwh' => 52, 'efficiency' => 90, 'power_w' => 7200,
    'margin_min' => 30, 'prefer_offpeak' => 1, 'default_borne' => 1);
$pilot = new cmd(); $pilot->value = 1; $eq->cmds['pilot'] = $pilot;
$GLOBALS['EQ'] = array($eq);

$failures = 0;
function check($label, $cond, $detail = '') {
    global $failures;
    echo ($cond ? '  OK   ' : '  FAIL ') . $label . ($detail !== '' ? '  [' . $detail . ']' : '') . "\n";
    if (!$cond) $failures++;
}

echo "Boost mardi soir pour mercredi 0h05\n";
$eq->actAt('2026-11-10 21:00', 'boost_on');
check('Boost programmé, borne toujours en Eco', $mode->value === 'Eco' && $eq->infos['boost_text'] === 'Programmé mer. 0h05', $eq->infos['boost_text']);
$eq->at('2026-11-11 00:05');
check('0h05 : borne passée en Normal', $mode->value === 'Normal', $eq->infos['reason']);
check('Le plugin détient le mode', $eq->cache['owned'] === 1 && $eq->cache['restore_mode'] === 'Eco');
$status->value = 194;
$eq->at('2026-11-11 03:00');
check('3h en charge : toujours Normal', $mode->value === 'Normal');
$status->value = 181; // voiture pleine, attend une demande
$eq->at('2026-11-11 05:00');
check('Fin détectée, attente 15 min', $mode->value === 'Normal');
$eq->at('2026-11-11 05:20');
check('Charge terminée : retour Eco et boost effacé', $mode->value === 'Eco' && $eq->infos['boost'] === 0, $eq->infos['boost_text']);

echo "Boost immédiat puis débranchement\n";
$status->value = 182;
$eq->actAt('2026-11-12 18:00', 'boost_now');
check('Boost immédiat : Normal', $mode->value === 'Normal');
$status->value = 161; // débranchée
$eq->at('2026-11-12 18:10');
check('Débranchée : retour Eco, boost effacé', $mode->value === 'Eco' && $eq->infos['boost'] === 0);

echo "Reprise en main manuelle\n";
$status->value = 182;
$eq->actAt('2026-11-12 19:00', 'boost_now');
check('Boost : Normal', $mode->value === 'Normal');
$mode->value = 'Eco'; // l'utilisateur clique sur la pastille Eco
$eq->at('2026-11-12 19:05');
check('Le plugin ne reforce pas Normal', $mode->value === 'Eco' && strpos($eq->infos['reason'], 'main') !== false, $eq->infos['reason']);
$eq->actAt('2026-11-12 19:10', 'boost_off');
check('Boost annulé : rien ne bouge', $mode->value === 'Eco' && $eq->infos['boost'] === 0);

echo "Mode Normal choisi par l'utilisateur, sans plan\n";
$mode->value = 'Normal';
$eq->at('2026-11-12 20:00');
check('Le plugin ne remet pas Eco ce qu\'il n\'a pas forcé', $mode->value === 'Normal');
$mode->value = 'Eco';

echo "Objectif dimanche 22h 100 %\n";
$eq->conf += array('obj1_enabled' => 1, 'obj1_day' => 7, 'obj1_time' => '22:00', 'obj1_target' => 100);
$soc->value = 50; $soc->date = '2026-11-15 11:00:00'; $energy->value = 0;
$eq->at('2026-11-15 12:00');
check('Midi : Eco, départ 17h15', $mode->value === 'Eco' && $eq->infos['start'] === '17h15', $eq->infos['start'] . ' / ' . $eq->infos['reason']);
$eq->at('2026-11-15 17:15');
check('17h15 : Normal', $mode->value === 'Normal', $eq->infos['reason']);
$status->value = 194; $energy->value = 10;
$eq->at('2026-11-15 18:45');
check('Estimation SOC avec 10 kWh borne : ~67 %', abs($eq->infos['soc'] - 67.3) < 0.2, (string) $eq->infos['soc']);
check('Toujours Normal (verrou)', $mode->value === 'Normal', $eq->infos['reason']);
$soc->value = 100; $soc->date = '2026-11-15 21:30:00';
$eq->at('2026-11-15 21:35');
check('100 % atteint : retour Eco', $mode->value === 'Eco', $eq->infos['reason']);

echo "Mode simulation\n";
$GLOBALS['CONFIG']['simulation'] = 1;
$soc->value = 40; $soc->date = '2026-11-22 10:00:00'; $status->value = 182; $energy->value = 0;
$eq->at('2026-11-22 20:00');
check('Simulation : décision Normal affichée, borne inchangée', $eq->infos['decision'] === 'Normal' && $mode->value === 'Eco', $eq->infos['reason']);
check('Raison préfixée [Simulation]', strpos($eq->infos['reason'], '[Simulation]') === 0);
$GLOBALS['CONFIG']['simulation'] = 0;

echo "Voiture non branchée\n";
$status->value = 161;
$eq->at('2026-11-22 20:05');
check('Non branchée : pas d\'action, message', $mode->value === 'Eco' && strpos($eq->infos['reason'], 'non branchée') !== false, $eq->infos['reason']);

echo "\n" . ($failures === 0 ? 'Tous les tests passent.' : $failures . ' échec(s).') . "\n";
exit($failures === 0 ? 0 : 1);

