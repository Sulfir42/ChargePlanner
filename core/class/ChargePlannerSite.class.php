<?php
/* This file is part of Jeedom.
 *
 * Plugin Name: ChargePlanner
 *
 * Partie « site » du plugin : les deux équipements Borne (mode Normal/Eco,
 * consigne, raison), l'équipement Pilotage (priorisation, surplus, budget,
 * raison) et l'exécution de la régulation (ChargePlannerRegulator) avec ses
 * entrées/sorties Jeedom.
 *
 * Fonctionnement :
 *  - « observation » (par défaut) : la régulation est calculée à chaque
 *    déclenchement mais aucune commande n'est envoyée aux bornes. L'état
 *    persistant est emprunté au scénario de référence et les modes /
 *    la priorisation peuvent être recopiés depuis les anciens virtuels, pour
 *    comparer les décisions du plugin à celles du scénario ;
 *  - « actif » : le plugin pilote les bornes. Le scénario de référence est
 *    désactivé automatiquement au passage en actif (et réactivé au retour en
 *    observation) pour qu'il n'y ait jamais deux pilotes à la fois.
 */

require_once __DIR__ . '/ChargePlannerRegulator.class.php';

/** Clés d'état persistant utilisées par la régulation (identiques au scénario V4). */
function chargePlannerStateKeys() {
    $keys = array('v4_init', 'prio_reset_ts', 'eco_soc_ok_depuis', 'eco_derniere_montee');
    foreach (array('1', '2') as $id) {
        foreach (array('dernier_amp', 'cible', 'slider', 'toggle_ts', 'action_amp_ts', 'inactif_ts', 'faible_depuis',
                     'dernier_toggle', 'derniere_action_amp', 'mode_prec', 'demarrage_ts') as $k) {
            $keys[] = 'wb' . $id . '_' . $k;
        }
    }
    return $keys;
}

class ChargePlannerRegState implements ChargePlannerRegulatorState {
    public $data = array();
    public function __construct(array $data) { $this->data = $data; }
    public function getData($key) { return $this->data[$key] ?? ''; }
    public function setData($key, $value) { $this->data[$key] = $value; }
}

class ChargePlannerJeedomIO implements ChargePlannerRegulatorIO {
    public $active;
    public $ops = array();
    public $logText = '';

    public function __construct($active) {
        $this->active = (bool) $active;
    }

    private static function value($ref) {
        $cmd = ChargePlanner::resolveCmd($ref);
        if (!is_object($cmd) || $cmd->getType() !== 'info') {
            return '';
        }
        return $cmd->execCmd();
    }

    public function read($key) {
        if (preg_match('/^wb([12])_(status|pince|mode)$/', $key, $m)) {
            $id = (int) $m[1];
            if ($m[2] === 'mode') {
                $source = ChargePlanner::globalConfig('obs_mode' . $id . '_source', '');
                if (!$this->active && $source !== '') {
                    return self::value($source);
                }
                $borne = ChargePlannerSite::borneEq($id);
                return is_object($borne) ? $borne->cmdValue('mode', 'Eco') : 'Eco';
            }
            return self::value(ChargePlanner::globalConfig('borne' . $id . '_' . $m[2], ''));
        }
        if ($key === 'priorite') {
            $source = ChargePlanner::globalConfig('obs_prio_source', '');
            if (!$this->active && $source !== '') {
                return self::value($source);
            }
            $pilotage = ChargePlannerSite::pilotageEq();
            return is_object($pilotage) ? $pilotage->cmdValue('priorite', 'Désactivée') : 'Désactivée';
        }
        return self::value(ChargePlanner::globalConfig($key, ''));
    }

    public function statusTs($borneId) {
        $cmd = ChargePlanner::resolveCmd(ChargePlanner::globalConfig('borne' . $borneId . '_status', ''));
        if (!is_object($cmd)) {
            return 0;
        }
        $d = $cmd->getCollectDate();
        if ($d == '' || $d === null) {
            $d = $cmd->getValueDate();
        }
        if ($d == '' || $d === null) {
            return 0;
        }
        $t = strtotime($d);
        return ($t === false) ? 0 : $t;
    }

    private function action($ref, $options, $label) {
        $this->ops[] = $label;
        if (!$this->active) {
            return;
        }
        $cmd = ChargePlanner::resolveCmd($ref);
        if (!is_object($cmd) || $cmd->getType() !== 'action') {
            log::add('ChargePlanner', 'error', 'Régulation : commande introuvable pour « ' . $label . ' » (' . $ref . ')');
            return;
        }
        $cmd->execCmd($options);
    }

    public function slider($borneId, $amp) {
        $this->action(ChargePlanner::globalConfig('borne' . $borneId . '_slider', ''), array('slider' => $amp),
            ChargePlanner::borneName($borneId) . ' consigne ' . $amp . ' A');
    }

    public function toggle($borneId) {
        $this->action(ChargePlanner::globalConfig('borne' . $borneId . '_toggle', ''), array(),
            ChargePlanner::borneName($borneId) . ' bascule pause/reprise');
    }

    public function prioOff() {
        $this->ops[] = 'priorisation → Désactivée';
        if (!$this->active) {
            return;
        }
        $pilotage = ChargePlannerSite::pilotageEq();
        if (is_object($pilotage)) {
            $pilotage->checkAndUpdateCmd('priorite', 'Désactivée');
        }
    }

    public function now() {
        return time();
    }

    public function sleep($seconds) {
        if ($this->active) {
            sleep($seconds);
        }
    }

    public function log($text) {
        $this->logText = $text;
    }
}

class ChargePlannerSite {

    const STATE_CACHE = 'reg_state';

    /* ------------------------------------------------------------------ */
    /* Équipements Borne / Pilotage                                        */
    /* ------------------------------------------------------------------ */

    public static function borneEq($id) {
        $eq = eqLogic::byLogicalId('borne' . (int) $id, 'ChargePlanner');
        return is_object($eq) ? $eq : null;
    }

    public static function pilotageEq() {
        $eq = eqLogic::byLogicalId('pilotage', 'ChargePlanner');
        return is_object($eq) ? $eq : null;
    }

    /** Crée les équipements Pilotage et Bornes s'ils n'existent pas. */
    public static function ensureCoreEquipments() {
        // Noms propres au plugin : « Wallbox 01 » existe déjà (plugin myWallBox) dans
        // le même objet, et Jeedom refuse deux équipements de même nom par objet.
        $wanted = array('pilotage' => array('pilotage', __('Pilotage recharge', __FILE__)));
        foreach (ChargePlanner::BORNES as $i) {
            $wanted['borne' . $i] = array('borne', sprintf(__('Recharge borne %d', __FILE__), $i));
        }
        $object = jeeObject::byName('Maison');
        foreach ($wanted as $logicalId => $def) {
            if (is_object(eqLogic::byLogicalId($logicalId, 'ChargePlanner'))) {
                continue;
            }
            try {
                $eq = new ChargePlanner();
                $eq->setEqType_name('ChargePlanner');
                $eq->setLogicalId($logicalId);
                $eq->setName(self::freeName($object, $def[1]));
                $eq->setConfiguration('type', $def[0]);
                if ($def[0] === 'borne') {
                    $eq->setConfiguration('borne_id', (int) substr($logicalId, 5));
                }
                if (is_object($object)) {
                    $eq->setObject_id($object->getId());
                }
                $eq->setIsEnable(1);
                $eq->setIsVisible(1);
                $eq->save();
                log::add('ChargePlanner', 'info', 'Équipement créé : ' . $eq->getName());
            } catch (Exception $e) {
                log::add('ChargePlanner', 'error', 'Création de l\'équipement ' . $def[1] . ' impossible : ' . $e->getMessage());
            }
        }
    }

    /** Nom libre dans l'objet (ajoute « (2) », « (3) »… si besoin). */
    private static function freeName($object, $name) {
        if (!is_object($object) || !method_exists('eqLogic', 'byObjectNameEqLogicName')) {
            return $name;
        }
        $candidate = $name;
        for ($n = 2; $n < 10; $n++) {
            $found = eqLogic::byObjectNameEqLogicName($object->getName(), $candidate);
            if (empty($found)) {
                return $candidate;
            }
            $candidate = $name . ' (' . $n . ')';
        }
        return $candidate;
    }

    public static function commandsFor($type) {
        if ($type === 'borne') {
            return array(
                // logicalId, nom, type, sous-type, unité, visible, historisé
                array('mode', __('Mode', __FILE__), 'info', 'string', '', 1, 1),
                array('mode_normal', 'Normal', 'action', 'other', '', 1, 0),
                array('mode_eco', 'Eco', 'action', 'other', '', 1, 0),
                array('cible', __('Consigne', __FILE__), 'info', 'numeric', 'A', 1, 1),
                array('raison', __('Raison', __FILE__), 'info', 'string', '', 1, 0),
                array('cible_ref', __('Consigne scénario', __FILE__), 'info', 'numeric', 'A', 0, 1),
            );
        }
        if ($type === 'pilotage') {
            return array(
                array('priorite', __('Priorisation', __FILE__), 'info', 'string', '', 1, 1),
                array('prio_wb1', 'WB1', 'action', 'other', '', 1, 0),
                array('prio_wb2', 'WB2', 'action', 'other', '', 1, 0),
                array('prio_off', __('Désactivée', __FILE__), 'action', 'other', '', 1, 0),
                array('surplus', __('Surplus solaire', __FILE__), 'info', 'numeric', 'W', 1, 1),
                array('budget', __('Puissance dispo compteur', __FILE__), 'info', 'numeric', 'W', 1, 1),
                array('autorisation', __('Autorisation charge', __FILE__), 'info', 'binary', '', 0, 1),
                array('urgence', __('Urgence', __FILE__), 'info', 'binary', '', 0, 1),
                array('raison', __('Raison', __FILE__), 'info', 'string', '', 1, 0),
                array('fonctionnement', __('Fonctionnement', __FILE__), 'info', 'string', '', 1, 0),
                array('concordance', __('Concordance avec le scénario', __FILE__), 'info', 'numeric', '%', 0, 1),
                array('ecarts', __('Écarts avec le scénario', __FILE__), 'info', 'numeric', '', 0, 1),
            );
        }
        return array();
    }

    /* ------------------------------------------------------------------ */
    /* Déclenchement                                                       */
    /* ------------------------------------------------------------------ */

    /** (Re)crée l'écouteur sur la commande de déclenchement configurée. */
    public static function setupListener() {
        $listener = listener::byClassAndFunction('ChargePlanner', 'onTrigger');
        $cmd = ChargePlanner::resolveCmd(ChargePlanner::globalConfig('trigger_cmd', ''));
        if (!is_object($cmd)) {
            if (is_object($listener)) {
                $listener->remove();
            }
            return;
        }
        if (!is_object($listener)) {
            $listener = new listener();
            $listener->setClass('ChargePlanner');
            $listener->setFunction('onTrigger');
        }
        $listener->emptyEvent();
        $listener->addEvent($cmd->getId());
        $listener->setOption(array());
        $listener->save();
    }

    /**
     * Lance un cycle si aucun n'est en cours et si l'intervalle minimum est
     * écoulé (le scénario d'origine tournait au plus toutes les ~13 s).
     */
    public static function regulate($force = false) {
        $pilotage = self::pilotageEq();
        if (!is_object($pilotage) || !$pilotage->getIsEnable()) {
            return;
        }
        $now = time();
        $running = (int) $pilotage->getCache('reg_running', 0);
        if ($running > 0 && $now - $running < 120) {
            return;
        }
        $interval = max(5, (int) ChargePlanner::globalConfig('reg_interval', 10));
        if (!$force && $now - (int) $pilotage->getCache('reg_last_end', 0) < $interval) {
            return;
        }
        $pilotage->setCache('reg_running', $now);
        try {
            self::runCycle($pilotage);
        } catch (Throwable $e) {
            log::add('ChargePlanner', 'error', 'Régulation : ' . $e->getMessage());
        }
        $pilotage->setCache('reg_running', 0);
        $pilotage->setCache('reg_last_end', time());
    }

    private static function referenceScenario() {
        $id = (int) ChargePlanner::globalConfig('ref_scenario', 0);
        if ($id <= 0) {
            return null;
        }
        $scenario = scenario::byId($id);
        return is_object($scenario) ? $scenario : null;
    }

    private static function readScenarioState($scenario) {
        $data = array();
        foreach (chargePlannerStateKeys() as $key) {
            $value = $scenario->getData($key);
            if ($value !== '' && $value !== null) {
                $data[$key] = $value;
            }
        }
        return $data;
    }

    /** Paramètres de régulation : valeurs de la configuration, sinon celles du scénario V4. */
    public static function regulatorParams() {
        $p = array();
        foreach (ChargePlannerRegulator::DEFAULTS as $key => $default) {
            $value = ChargePlanner::globalConfig('reg_' . $key, '');
            $p[$key] = ($value === '' || !is_numeric($value)) ? $default : $value + 0;
        }
        foreach (ChargePlanner::BORNES as $i) {
            $pmax = ChargePlanner::globalConfig('borne' . $i . '_pmax', '');
            if ($pmax !== '' && is_numeric($pmax)) {
                $p['P_MAX_WB' . $i] = $pmax + 0;
            }
        }
        return $p;
    }

    private static function runCycle($pilotage) {
        $active = ChargePlanner::isActive();
        $scenario = self::referenceScenario();

        // État persistant : le nôtre en actif ; en observation, celui du
        // scénario de référence (pour calculer « ce que le plugin ferait à sa place »).
        if (!$active && is_object($scenario)) {
            $state = new ChargePlannerRegState(self::readScenarioState($scenario));
        } else {
            $saved = $pilotage->getCache(self::STATE_CACHE, array());
            $state = new ChargePlannerRegState(is_array($saved) ? $saved : array());
        }

        // Comme le scénario : rafraîchir les deux bornes puis attendre 1 s.
        if ($active) {
            foreach (ChargePlanner::BORNES as $i) {
                $refresh = ChargePlanner::resolveCmd(ChargePlanner::globalConfig('borne' . $i . '_refresh', ''));
                if (is_object($refresh) && $refresh->getType() === 'action') {
                    try {
                        $refresh->execCmd();
                    } catch (Exception $e) {
                        log::add('ChargePlanner', 'warning', 'Rafraîchissement ' . ChargePlanner::borneName($i) . ' : ' . $e->getMessage());
                    }
                }
            }
            sleep(1);
        }

        $io = new ChargePlannerJeedomIO($active);
        $out = ChargePlannerRegulator::run($io, $state, self::regulatorParams());

        if ($active || !is_object($scenario)) {
            $pilotage->setCache(self::STATE_CACHE, $state->data);
        }

        // Publication.
        $pilotage->checkAndUpdateCmd('surplus', $out['surplus']);
        $pilotage->checkAndUpdateCmd('budget', $out['budget']);
        $pilotage->checkAndUpdateCmd('autorisation', $out['autorisation']);
        $pilotage->checkAndUpdateCmd('urgence', $out['urgence']);
        $pilotage->checkAndUpdateCmd('raison', ($active ? '' : '[Observation] ') . $out['raison']);
        $pilotage->checkAndUpdateCmd('fonctionnement', $active ? __('Actif', __FILE__) : __('Observation', __FILE__));
        foreach (ChargePlanner::BORNES as $i) {
            $borne = self::borneEq($i);
            if (!is_object($borne)) {
                continue;
            }
            $borne->checkAndUpdateCmd('cible', $out['wb' . $i . '_cible']);
            $borne->checkAndUpdateCmd('raison', $out['wb' . $i . '_raison']);
            if (!$active) {
                // Recopie des anciens virtuels pour que la tuile et les voitures voient les vrais modes.
                $source = ChargePlanner::globalConfig('obs_mode' . $i . '_source', '');
                if ($source !== '') {
                    $value = ChargePlannerRegulator::normMode(ChargePlanner::readCmdValue($source));
                    $borne->checkAndUpdateCmd('mode', $value[0]);
                }
            }
        }
        if (!$active) {
            $source = ChargePlanner::globalConfig('obs_prio_source', '');
            if ($source !== '') {
                $pilotage->checkAndUpdateCmd('priorite', (string) ChargePlanner::readCmdValue($source));
            }
        }

        $actions = count($io->ops) > 0 ? implode(' ; ', $io->ops) : 'aucune action';
        if ($active) {
            log::add('ChargePlanner', count($io->ops) > 0 ? 'info' : 'debug', 'Régulation : ' . $actions . ' | ' . $out['raison']);
        } else {
            $compare = '';
            if (is_object($scenario)) {
                $parts = array();
                foreach (ChargePlanner::BORNES as $i) {
                    $parts[] = ChargePlanner::borneName($i) . ' plugin ' . $out['wb' . $i . '_cible'] . ' A / scénario ' . (int) $scenario->getData('wb' . $i . '_cible') . ' A';
                }
                $compare = ' | ' . implode(', ', $parts);
                self::compareWithScenario($pilotage, $scenario, $out);
            }
            log::add('ChargePlanner', 'debug', '[Observation] ferait : ' . $actions . $compare . ' | ' . $out['raison']);
        }
        log::add('ChargePlanner', 'debug', $io->logText);
    }

    /**
     * Comparaison en observation, enregistrée dans l'historique (pas dans le log,
     * limité en taille).
     *
     * À chaque cycle, le plugin part de l'état laissé par le dernier passage du
     * scénario : sa consigne est donc une prédiction du passage suivant du
     * scénario. Quand l'état du scénario change (il a tourné et modifié quelque
     * chose), on compare sa nouvelle consigne à la dernière prédiction faite
     * avant ce passage. Écart de 2 A ou moins : concordant (les mesures ont
     * bougé de quelques secondes entre les deux calculs).
     */
    private static function compareWithScenario($pilotage, $scenario, array $out) {
        $now = time();
        $data = self::readScenarioState($scenario);
        $hash = md5(json_encode($data));
        $ref = array();
        foreach (ChargePlanner::BORNES as $i) {
            $ref[$i] = (int) ($data['wb' . $i . '_cible'] ?? 0);
            $borne = self::borneEq($i);
            if (is_object($borne)) {
                $borne->checkAndUpdateCmd('cible_ref', $ref[$i]);
            }
        }

        $day = date('Y-m-d', $now);
        $stats = $pilotage->getCache('obs_stats', array());
        if (!is_array($stats) || ($stats['day'] ?? '') !== $day) {
            if (is_array($stats) && !empty($stats['n'])) {
                log::add('ChargePlanner', 'info', sprintf('[Observation] Bilan du %s : %d comparaisons, %d %% concordantes, %d écart(s)', $stats['day'], $stats['n'], round(100 * $stats['ok'] / $stats['n']), $stats['n'] - $stats['ok']));
            }
            $stats = array('day' => $day, 'n' => 0, 'ok' => 0, 'hour' => (int) date('G', $now), 'hn' => 0, 'hok' => 0);
        }

        $pred = $pilotage->getCache('obs_pred', array());
        if (is_array($pred) && isset($pred['hash']) && $pred['hash'] !== $hash && $now - (int) $pred['ts'] < 120) {
            foreach (ChargePlanner::BORNES as $i) {
                $p = (int) $pred['wb' . $i];
                $ok = abs($p - $ref[$i]) <= 2;
                $stats['n']++;
                $stats['hn']++;
                if ($ok) {
                    $stats['ok']++;
                    $stats['hok']++;
                } else {
                    log::add('ChargePlanner', 'info', sprintf('[Observation] Écart %s : plugin %d A, scénario %d A | %s', ChargePlanner::borneName($i), $p, $ref[$i], $pred['raison']));
                }
            }
            $pilotage->checkAndUpdateCmd('concordance', round(100 * $stats['ok'] / $stats['n'], 1));
            $pilotage->checkAndUpdateCmd('ecarts', $stats['n'] - $stats['ok']);
        }

        // Bilan horaire dans le log.
        $hour = (int) date('G', $now);
        if ($hour !== (int) $stats['hour']) {
            if ($stats['hn'] > 0) {
                log::add('ChargePlanner', 'info', sprintf('[Observation] %dh-%dh : %d comparaisons, %d %% concordantes', $stats['hour'], $stats['hour'] + 1, $stats['hn'], round(100 * $stats['hok'] / $stats['hn'])));
            }
            $stats['hour'] = $hour;
            $stats['hn'] = 0;
            $stats['hok'] = 0;
        }
        $pilotage->setCache('obs_stats', $stats);

        $pred = array('hash' => $hash, 'ts' => $now, 'raison' => $out['raison']);
        foreach (ChargePlanner::BORNES as $i) {
            $pred['wb' . $i] = (int) $out['wb' . $i . '_cible'];
        }
        $pilotage->setCache('obs_pred', $pred);
    }

    /* ------------------------------------------------------------------ */
    /* Bascule observation <-> actif                                       */
    /* ------------------------------------------------------------------ */

    public static function switchMode($active) {
        $scenario = self::referenceScenario();
        $pilotage = self::pilotageEq();
        if ($active) {
            if (is_object($pilotage)) {
                // Reprise de l'état du scénario pour ne rien couper au passage.
                if (is_object($scenario)) {
                    $pilotage->setCache(self::STATE_CACHE, self::readScenarioState($scenario));
                }
                // Dernière recopie des modes / de la priorisation.
                foreach (ChargePlanner::BORNES as $i) {
                    $source = ChargePlanner::globalConfig('obs_mode' . $i . '_source', '');
                    $borne = self::borneEq($i);
                    if ($source !== '' && is_object($borne)) {
                        $value = ChargePlannerRegulator::normMode(ChargePlanner::readCmdValue($source));
                        $borne->checkAndUpdateCmd('mode', $value[0]);
                    }
                }
                $source = ChargePlanner::globalConfig('obs_prio_source', '');
                if ($source !== '') {
                    $pilotage->checkAndUpdateCmd('priorite', (string) ChargePlanner::readCmdValue($source));
                }
            }
            if (is_object($scenario) && $scenario->getIsActive()) {
                $scenario->setIsActive(0);
                $scenario->save();
                log::add('ChargePlanner', 'info', 'Passage en ACTIF : scénario « ' . $scenario->getName() . ' » désactivé, le plugin pilote les bornes');
            } else {
                log::add('ChargePlanner', 'info', 'Passage en ACTIF : le plugin pilote les bornes');
            }
        } else {
            if (is_object($scenario) && !$scenario->getIsActive()) {
                $scenario->setIsActive(1);
                $scenario->save();
                log::add('ChargePlanner', 'info', 'Retour en OBSERVATION : scénario « ' . $scenario->getName() . ' » réactivé');
            } else {
                log::add('ChargePlanner', 'info', 'Retour en OBSERVATION');
            }
        }
        if (is_object($pilotage)) {
            $pilotage->checkAndUpdateCmd('fonctionnement', $active ? __('Actif', __FILE__) : __('Observation', __FILE__));
        }
    }
}
