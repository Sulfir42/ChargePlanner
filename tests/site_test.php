<?php
/*
 * Test d'intégration de la partie « site » (régulation, bornes, pilotage)
 * avec un faux noyau Jeedom :
 *   php tests/site_test.php
 */
date_default_timezone_set('Europe/Paris');

$GLOBALS['CONFIG'] = array();
$GLOBALS['CMDS'] = array();
$GLOBALS['LOG'] = array();
$GLOBALS['EQS'] = array();
$GLOBALS['EXEC'] = array();

function __($s, $f = '') { return $s; }
class log {
    public static function add($p, $l, $m) { $GLOBALS['LOG'][] = "[$l] $m"; }
}
class config {
    public static function byKey($k, $p = 'core', $d = '') { return array_key_exists($k, $GLOBALS['CONFIG']) ? $GLOBALS['CONFIG'][$k] : $d; }
    public static function save($k, $v, $p = 'core') { $GLOBALS['CONFIG'][$k] = $v; }
    public static function remove($k, $p = 'core') { unset($GLOBALS['CONFIG'][$k]); }
}
class jeeObject { public static function byName($n) { return null; } }
class listener {
    public static $instance = null; public $events = array();
    public static function byClassAndFunction($c, $f) { return self::$instance; }
    public function setClass($c) {} public function setFunction($f) {} public function setOption($o) {}
    public function emptyEvent() { $this->events = array(); } public function addEvent($id) { $this->events[] = $id; }
    public function save() { self::$instance = $this; } public function remove() { self::$instance = null; }
}
class scenario {
    public static $list = array();
    public $id; public $active = 1; public $data = array();
    public static function byId($id) { return self::$list[$id] ?? null; }
    public function getData($k) { return $this->data[$k] ?? ''; }
    public function getIsActive() { return $this->active; }
    public function setIsActive($a) { $this->active = $a; }
    public function getName() { return 'Pilotage Wallbox'; }
    public function save() {}
}
class cmd {
    public $id; public $human = ''; public $type = 'info'; public $value = ''; public $date = '';
    public static function byId($id) { return $GLOBALS['CMDS'][$id] ?? null; }
    public static function byString($s) { foreach ($GLOBALS['CMDS'] as $c) { if ($c->human === $s) { return $c; } } throw new Exception('not found ' . $s); }
    public function getType() { return $this->type; }
    public function getId() { return $this->id; }
    public function execCmd($o = array()) {
        if ($this->type === 'action') { $GLOBALS['EXEC'][] = $this->human . (isset($o['slider']) ? '=' . $o['slider'] : ''); return; }
        return $this->value;
    }
    public function getCollectDate() { return $this->date; }
    public function getValueDate() { return $this->date; }
    // création de commandes d'équipement
    public $logicalId; public $configuration = array();
    public function setLogicalId($l) { $this->logicalId = $l; } public function getLogicalId() { return $this->logicalId; }
    public function setEqLogic_id($i) {} public function setName($n) {} public function setIsVisible($v) {} public function setIsHistorized($v) {}
    public function setOrder($o) {} public function setUnite($u) {} public function setType($t) { $this->type = $t; } public function setSubType($t) {}
    public function setConfiguration($k, $v) { $this->configuration[$k] = $v; } public function save() {}
}
class eqLogic {
    public $conf = array(); public $cache = array(); public $cmds = array(); public $logicalId = ''; public $name = ''; public $enable = 1;
    public static function byType($t, $e = false) { return $GLOBALS['EQS']; }
    public static function byLogicalId($id, $t) { foreach ($GLOBALS['EQS'] as $eq) { if ($eq->logicalId === $id) { return $eq; } } return null; }
    public function getConfiguration($k, $d = '') { return array_key_exists($k, $this->conf) ? $this->conf[$k] : $d; }
    public function setConfiguration($k, $v) { $this->conf[$k] = $v; }
    public function getCache($k, $d = '') { return array_key_exists($k, $this->cache) ? $this->cache[$k] : $d; }
    public function setCache($k, $v) { $this->cache[$k] = $v; }
    public function getCmd($t, $l) { return $this->cmds[$l] ?? null; }
    public function checkAndUpdateCmd($l, $v) { if (!isset($this->cmds[$l])) { $this->cmds[$l] = new cmd(); } $this->cmds[$l]->value = $v; }
    public function setEqType_name($n) {} public function setLogicalId($l) { $this->logicalId = $l; } public function setName($n) { $this->name = $n; }
    public function getName() { return $this->name; } public function getHumanName() { return '[Maison][' . $this->name . ']'; }
    public function setObject_id($i) {} public function setIsEnable($e) { $this->enable = $e; } public function setIsVisible($v) {}
    public function getIsEnable() { return $this->enable; } public function getId() { return spl_object_id($this); }
    public function save($direct = false) {
        if (!in_array($this, $GLOBALS['EQS'], true)) { $GLOBALS['EQS'][] = $this; if (method_exists($this, 'postInsert')) { $this->postInsert(); } }
        if (method_exists($this, 'postSave')) { $this->postSave(); }
    }
}
// Les commandes créées par checkAndCreateCommands doivent être rattachées à l'équipement.
require_once __DIR__ . '/../core/class/ChargePlanner.class.php';
class_alias('ChargePlanner', 'ChargePlannerTestAlias');

$failures = 0;
function check($label, $cond, $detail = '') {
    global $failures;
    echo ($cond ? '  OK   ' : '  FAIL ') . $label . ($detail !== '' ? '  [' . $detail . ']' : '') . "\n";
    if (!$cond) { $failures++; }
}
$nextId = 100;
function mk($human, $type = 'info', $value = '') {
    global $nextId;
    $c = new cmd(); $c->id = $nextId++; $c->human = $human; $c->type = $type; $c->value = $value; $c->date = date('Y-m-d H:i:s');
    $GLOBALS['CMDS'][$c->id] = $c; return $c;
}

// Commandes de la maison (valeurs : soleil, batterie pleine, WB1 voiture qui charge en Eco)
$m = array(
    'conso_maison' => mk('#[M][Shelly][C]#', 'info', 3000),
    'conso_edf' => mk('#[M][Shelly][A]#', 'info', -1500),
    'prod_solaire' => mk('#[M][Shelly][B]#', 'info', 4000),
    'soc_batterie' => mk('#[M][Solis][Batterie]#', 'info', 96),
    'p_batterie' => mk('#[M][Batterie][Actuel]#', 'info', 800),
    'prod_pv' => mk('#[M][PV][Actuel]#', 'info', 4200),
    'trigger_cmd' => mk('#[M][Tableau][Actuel]#', 'info', 3000),
    'obs_mode1_source' => mk('#[M][WB1 Mode][Mode]#', 'info', 'Eco'),
    'obs_mode2_source' => mk('#[M][WB2 Mode][Mode]#', 'info', 'Normal'),
    'obs_prio_source' => mk('#[M][Prio][Mode]#', 'info', 'Désactivée'),
);
foreach ($m as $k => $c) { $GLOBALS['CONFIG'][$k] = $c->human; }
foreach (array(1, 2) as $i) {
    $GLOBALS['CONFIG']['borne' . $i . '_name'] = 'Wallbox 0' . $i;
    foreach (array('status' => array('info', $i == 1 ? 194 : 181), 'pince' => array('info', $i == 1 ? 1400 : 0),
                   'slider' => array('action', ''), 'toggle' => array('action', ''), 'refresh' => array('action', ''),
                   'energy' => array('info', 1.2)) as $k => $d) {
        $GLOBALS['CONFIG']['borne' . $i . '_' . $k] = mk('#[M][Wallbox0' . $i . '][' . $k . ']#', $d[0], $d[1])->human;
    }
}
$GLOBALS['CONFIG']['fonctionnement'] = 'observation';
$GLOBALS['CONFIG']['ref_scenario'] = 98;
$GLOBALS['CONFIG']['reg_interval'] = 10;
$sc = new scenario(); $sc->id = 98;
$sc->data = array('v4_init' => 1, 'wb1_cible' => 6, 'wb1_slider' => 6, 'wb1_mode_prec' => 'Eco', 'wb2_cible' => 0, 'wb2_mode_prec' => 'Normal',
    'eco_derniere_montee' => time() - 600, 'wb1_demarrage_ts' => time() - 900);
scenario::$list[98] = $sc;

echo "Création des équipements\n";
ChargePlannerSite::ensureCoreEquipments();
$pil = ChargePlannerSite::pilotageEq(); $b1 = ChargePlannerSite::borneEq(1); $b2 = ChargePlannerSite::borneEq(2);
check('Pilotage + 2 bornes créés', is_object($pil) && is_object($b1) && is_object($b2) && count($GLOBALS['EQS']) === 3);
check('Commandes de borne créées', isset($b1->cmds['mode']) || $b1->cmdValue('mode', '') !== null);
ChargePlannerSite::ensureCoreEquipments();
check('Pas de doublon au second appel', count($GLOBALS['EQS']) === 3);
ChargePlannerSite::setupListener();
check('Écouteur sur le déclencheur', listener::$instance !== null && listener::$instance->events === array($m['trigger_cmd']->id));

echo "Observation\n";
$GLOBALS['EXEC'] = array();
ChargePlannerSite::regulate(true);
check('Aucune commande envoyée aux bornes', count($GLOBALS['EXEC']) === 0, implode(', ', $GLOBALS['EXEC']));
check('Modes recopiés depuis les virtuels', $b1->cmdValue('mode') === 'Eco' && $b2->cmdValue('mode') === 'Normal');
check('Priorisation recopiée', $pil->cmdValue('priorite') === 'Désactivée');
check('Raison publiée et préfixée', strpos($pil->cmdValue('raison'), '[Observation]') === 0, $pil->cmdValue('raison'));
check('Consigne WB1 calculée (montée Eco)', (int) $b1->cmdValue('cible') > 6, (string) $b1->cmdValue('cible'));
check('État du scénario non modifié', $sc->data['wb1_cible'] === 6);
$ferait = array_values(array_filter($GLOBALS['LOG'], function ($l) { return strpos($l, '[Observation] ferait') !== false; }));
check('Log de comparaison plugin / scénario', count($ferait) > 0 && strpos(end($ferait), 'scénario 6 A') !== false, end($ferait));
ChargePlannerSite::regulate();
check('Intervalle minimum respecté (pas de 2e cycle immédiat)', count(array_filter($GLOBALS['LOG'], function ($l) { return strpos($l, '[Observation] ferait') !== false; })) === count($ferait));

echo "Passage en actif\n";
$GLOBALS['CONFIG']['fonctionnement'] = 'actif';
ChargePlanner::postConfig_fonctionnement('actif');
check('Scénario de référence désactivé', $sc->active === 0);
check('État du scénario repris', ($pil->getCache('reg_state')['wb1_cible'] ?? null) === 6);
$GLOBALS['EXEC'] = array();
$pil->setCache('reg_last_end', 0);
$t0 = microtime(true);
ChargePlannerSite::regulate();
$ops = implode(', ', $GLOBALS['EXEC']);
check('Rafraîchissement des deux bornes', strpos($ops, 'Wallbox01][refresh') !== false && strpos($ops, 'Wallbox02][refresh') !== false, $ops);
check('Consigne envoyée à WB1', preg_match('/Wallbox01\]\[slider\]#=(\d+)/', $ops, $mm) && (int) $mm[1] > 6, $ops);
check('État persistant mis à jour', (int) ($pil->getCache('reg_state')['wb1_slider'] ?? 0) > 6);
check('Raison sans préfixe observation', strpos($pil->cmdValue('raison'), '[Observation]') === false);
ChargePlanner::postConfig_fonctionnement('actif');
check('Sauvegarde sans changement : rien ne bouge', $sc->active === 0 && (int) ($pil->getCache('reg_state')['wb1_slider'] ?? 0) > 6);

echo "Modes et priorisation du plugin\n";
$b2->execute(array('action' => 'mode_eco'));
check('Borne 2 passée en Eco par sa commande', $b2->cmdValue('mode') === 'Eco');
$pil->execute(array('action' => 'prio_wb1'));
check('Priorisation WB1', $pil->cmdValue('priorite') === 'WB1');
$m['obs_mode2_source']->value = 'Normal';
$pil->setCache('reg_last_end', 0);
ChargePlannerSite::regulate();
check('En actif, les virtuels ne sont plus recopiés', $b2->cmdValue('mode') === 'Eco');

echo "Retour en observation\n";
$GLOBALS['CONFIG']['fonctionnement'] = 'observation';
ChargePlanner::postConfig_fonctionnement('observation');
check('Scénario de référence réactivé', $sc->active === 1);

echo "Commande manquante\n";
$GLOBALS['CONFIG']['soc_batterie'] = '#[M][Inexistante]#';
$GLOBALS['CONFIG']['fonctionnement'] = 'actif';
ChargePlanner::postConfig_fonctionnement('actif');
$GLOBALS['EXEC'] = array();
$pil->setCache('reg_last_end', 0);
ChargePlannerSite::regulate();
check('SOC illisible : bornes Eco à l\'arrêt (sécurité)', strpos($pil->cmdValue('raison'), 'SOC batterie invalide') !== false, $pil->cmdValue('raison'));
check('Le verrou de cycle est libéré', (int) $pil->getCache('reg_running') === 0);

echo "\n" . ($failures === 0 ? 'Tous les tests passent.' : $failures . ' échec(s).') . "\n";
exit($failures === 0 ? 0 : 1);
