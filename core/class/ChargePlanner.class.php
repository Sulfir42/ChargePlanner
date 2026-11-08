<?php
/* This file is part of Jeedom.
 *
 * Plugin Name: ChargePlanner
 *
 * Un équipement = une voiture. Toutes les 5 minutes, le plugin lit le niveau
 * de batterie, l'état de la borne et les objectifs, demande au moteur s'il
 * faut charger à fond, et bascule la borne entre ses modes « Normal » et
 * « Eco ». Il ne touche jamais aux ampères ni aux limites du compteur : ça
 * reste le travail du scénario de pilotage existant.
 *
 * Règles de cohabitation :
 *  - le plugin ne remet en Eco que ce qu'il a lui-même passé en Normal ;
 *  - si quelqu'un change le mode à la main pendant une charge forcée,
 *    le plugin rend la main pour ce plan et ne le reforce pas ;
 *  - en mode simulation (par défaut à l'installation), il calcule et
 *    affiche tout mais n'envoie aucune commande.
 */

if (!class_exists('eqLogic')) {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
}
require_once __DIR__ . '/ChargePlannerCalendar.class.php';
require_once __DIR__ . '/ChargePlannerEngine.class.php';

class ChargePlanner extends eqLogic {

    const BORNES = array(1, 2);
    const OBJECTIVE_SLOTS = 4;
    const DONE_WAIT = 900;          // 15 min sans demande de la voiture = charge terminée
    const BOOST_MAX = 86400;        // sécurité : un boost ne dure jamais plus de 24 h
    const BORNE_RESET_AFTER = 1800; // borne du jour remise à l'habituelle 30 min après débranchement

    /* ------------------------------------------------------------------ */
    /* Configuration globale                                               */
    /* ------------------------------------------------------------------ */

    /** Horloge, isolée pour pouvoir être figée dans les tests. */
    protected function now() {
        return time();
    }

    public static function globalConfig($key, $default = '') {
        $value = config::byKey($key, 'ChargePlanner', $default);
        return ($value === '' || $value === null) ? $default : $value;
    }

    public static function isSimulation() {
        return (int) self::globalConfig('simulation', 1) === 1;
    }

    public static function buildCalendar() {
        $days = array();
        for ($d = 1; $d <= 7; $d++) {
            $default = in_array($d, array(3, 6, 7), true) ? 1 : 0;
            if ((int) self::globalConfig('offpeak_day_' . $d, $default) === 1) {
                $days[] = $d;
            }
        }
        return new ChargePlannerCalendar(
            $days,
            (int) self::globalConfig('offpeak_holidays', 1) === 1,
            (string) self::globalConfig('offpeak_extra', '')
        );
    }

    public static function borneName($index) {
        return (string) self::globalConfig('borne' . $index . '_name', 'Borne ' . $index);
    }

    /** Résout une commande saisie sous forme « #[Objet][Équipement][Commande]# » ou « #1234# ». */
    public static function resolveCmd($ref) {
        $ref = trim((string) $ref);
        if ($ref === '') {
            return null;
        }
        if (preg_match('/^#?(\d+)#?$/', $ref, $m)) {
            $cmd = cmd::byId((int) $m[1]);
            return is_object($cmd) ? $cmd : null;
        }
        try {
            $cmd = cmd::byString($ref);
            return is_object($cmd) ? $cmd : null;
        } catch (Exception $e) {
            return null;
        }
    }

    private static function readCmd($ref) {
        $cmd = self::resolveCmd($ref);
        if (!is_object($cmd) || $cmd->getType() !== 'info') {
            return null;
        }
        return array('value' => $cmd->execCmd(), 'date' => $cmd->getCollectDate());
    }

    /** Statuts Wallbox où une voiture est branchée (mêmes plages que la tuile). */
    public static function statusConnected($statusId) {
        if ($statusId === null || $statusId === '' || !is_numeric($statusId)) {
            return null;
        }
        $id = (int) $statusId;
        return ($id >= 177 && $id <= 189) || ($id >= 193 && $id <= 196) || $id === 210;
    }

    /* ------------------------------------------------------------------ */
    /* Cycle Jeedom                                                        */
    /* ------------------------------------------------------------------ */

    public static function cron5() {
        foreach (eqLogic::byType('ChargePlanner', true) as $eqLogic) {
            try {
                $eqLogic->evaluate();
            } catch (Exception $e) {
                log::add('ChargePlanner', 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    public function postInsert() {
        $defaults = array(
            'capacity_kwh' => 50, 'efficiency' => 90, 'power_w' => 7200, 'margin_min' => 30,
            'prefer_offpeak' => 1, 'default_borne' => 1,
            'obj1_enabled' => 0, 'obj1_day' => 5, 'obj1_time' => '00:00', 'obj1_target' => 50,
            'obj2_enabled' => 0, 'obj2_day' => 7, 'obj2_time' => '22:00', 'obj2_target' => 100,
        );
        foreach ($defaults as $key => $value) {
            if ($this->getConfiguration($key, '') === '') {
                $this->setConfiguration($key, $value);
            }
        }
        $this->save(true);
    }

    public function postSave() {
        $this->checkAndCreateCommands();
    }

    public function checkAndCreateCommands() {
        $order = 0;
        $commands = array(
            array('decision', __('Décision', __FILE__), 'info', 'string', '', 1, 1),
            array('reason', __('Raison', __FILE__), 'info', 'string', '', 1, 0),
            array('objective', __('Prochain objectif', __FILE__), 'info', 'string', '', 1, 0),
            array('start', __('Départ prévu', __FILE__), 'info', 'string', '', 1, 0),
            array('soc', __('Batterie estimée', __FILE__), 'info', 'numeric', '%', 1, 1),
            array('need', __('Énergie à charger', __FILE__), 'info', 'numeric', 'kWh', 0, 0),
            array('boost', __('Boost', __FILE__), 'info', 'binary', '', 0, 1),
            array('boost_text', __('État boost', __FILE__), 'info', 'string', '', 1, 0),
            array('borne', __('Borne du jour', __FILE__), 'info', 'string', '', 1, 0),
            array('pilot', __('Pilotage auto', __FILE__), 'info', 'binary', '', 0, 1),
            array('boost_on', __('Boost (prochain jour creux)', __FILE__), 'action', 'other', '', 1, 0),
            array('boost_now', __('Boost immédiat', __FILE__), 'action', 'other', '', 1, 0),
            array('boost_off', __('Annuler boost', __FILE__), 'action', 'other', '', 1, 0),
            array('pilot_on', __('Pilotage ON', __FILE__), 'action', 'other', '', 0, 0),
            array('pilot_off', __('Pilotage OFF', __FILE__), 'action', 'other', '', 0, 0),
            array('set_borne', __('Choisir la borne', __FILE__), 'action', 'select', '', 1, 0),
            array('refresh', __('Recalculer', __FILE__), 'action', 'other', '', 1, 0),
        );
        foreach ($commands as $def) {
            list($logicalId, $name, $type, $subType, $unit, $visible, $historized) = $def;
            $cmd = $this->getCmd(null, $logicalId);
            $isNew = !is_object($cmd);
            if ($isNew) {
                $cmd = new ChargePlannerCmd();
                $cmd->setLogicalId($logicalId);
                $cmd->setEqLogic_id($this->getId());
                $cmd->setName($name);
                $cmd->setIsVisible($visible);
                $cmd->setIsHistorized($historized);
                $cmd->setOrder($order);
            }
            $cmd->setType($type);
            $cmd->setSubType($subType);
            if ($unit !== '') {
                $cmd->setUnite($unit);
            }
            if ($logicalId === 'set_borne') {
                $list = array();
                foreach (self::BORNES as $i) {
                    $list[] = $i . '|' . self::borneName($i);
                }
                $cmd->setConfiguration('listValue', implode(';', $list));
            }
            $cmd->save();
            $order++;
        }
        // Valeurs initiales pour que le widget ne soit pas vide avant le premier cycle.
        $pilot = $this->getCmd(null, 'pilot');
        if (is_object($pilot) && $pilot->execCmd() === '') {
            $this->checkAndUpdateCmd('pilot', 1);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Actions                                                             */
    /* ------------------------------------------------------------------ */

    public function execute($_options = array()) {
        $action = $_options['action'] ?? '';
        $now = $this->now();
        switch ($action) {
            case 'boost_on':
                $start = self::buildCalendar()->nextOffPeakStart($now, (string) self::globalConfig('boost_time', '00:05'));
                $this->armBoost($start === null ? $now : $start);
                break;
            case 'boost_now':
                $this->armBoost($now);
                break;
            case 'boost_off':
                $this->clearBoost(__('annulé', __FILE__));
                break;
            case 'pilot_on':
                $this->checkAndUpdateCmd('pilot', 1);
                break;
            case 'pilot_off':
                $this->checkAndUpdateCmd('pilot', 0);
                $this->releaseControl(true, __('pilotage désactivé', __FILE__));
                break;
            case 'set_borne':
                $index = (int) ($_options['select'] ?? 0);
                if (in_array($index, self::BORNES, true) && $index !== $this->currentBorne()) {
                    $this->releaseControl(true, __('changement de borne', __FILE__));
                    $this->setCache('borne_current', $index);
                    $this->setCache('borne_disconnected_since', 0);
                    log::add('ChargePlanner', 'info', $this->getHumanName() . ' : borne du jour = ' . self::borneName($index));
                }
                break;
        }
        $this->evaluate();
    }

    private function armBoost($start) {
        $this->setCache('boost_start', (int) $start);
        $this->setCache('boost_seen_connected', 0);
        $this->setCache('boost_waiting_since', 0);
        log::add('ChargePlanner', 'info', $this->getHumanName() . ' : boost programmé pour ' . date('d/m H:i', (int) $start));
    }

    private function clearBoost($why) {
        if ((int) $this->getCache('boost_start', 0) > 0) {
            log::add('ChargePlanner', 'info', $this->getHumanName() . ' : boost terminé (' . $why . ')');
        }
        $this->setCache('boost_start', 0);
        $this->setCache('boost_seen_connected', 0);
        $this->setCache('boost_waiting_since', 0);
    }

    /* ------------------------------------------------------------------ */
    /* Borne                                                               */
    /* ------------------------------------------------------------------ */

    public function currentBorne() {
        $index = (int) $this->getCache('borne_current', 0);
        if (!in_array($index, self::BORNES, true)) {
            $index = (int) $this->getConfiguration('default_borne', 1);
        }
        return in_array($index, self::BORNES, true) ? $index : 1;
    }

    private function borneCmd($key) {
        return (string) self::globalConfig('borne' . $this->currentBorne() . '_' . $key, '');
    }

    private function readBorneMode() {
        $mode = self::readCmd($this->borneCmd('mode'));
        return $mode === null ? null : trim((string) $mode['value']);
    }

    private function setBorneMode($mode, $why) {
        $key = ($mode === 'Normal') ? 'normal' : 'eco';
        $cmd = self::resolveCmd($this->borneCmd($key));
        if (!is_object($cmd) || $cmd->getType() !== 'action') {
            log::add('ChargePlanner', 'error', $this->getHumanName() . ' : commande « ' . $mode . ' » de ' . self::borneName($this->currentBorne()) . ' non configurée');
            return false;
        }
        if (self::isSimulation()) {
            log::add('ChargePlanner', 'info', '[Simulation] ' . $this->getHumanName() . ' : passerait ' . self::borneName($this->currentBorne()) . ' en ' . $mode . ' (' . $why . ')');
            return true;
        }
        $cmd->execCmd();
        log::add('ChargePlanner', 'info', $this->getHumanName() . ' : ' . self::borneName($this->currentBorne()) . ' → ' . $mode . ' (' . $why . ')');
        return true;
    }

    /**
     * Rend la main : si le plugin avait forcé Normal et que personne n'a
     * touché au mode depuis, remet le mode d'origine.
     */
    private function releaseControl($restore, $why) {
        if ((int) $this->getCache('owned', 0) !== 1) {
            return;
        }
        $current = $this->readBorneMode();
        $setMode = (string) $this->getCache('set_mode', '');
        $restoreMode = (string) $this->getCache('restore_mode', 'Eco');
        if ($restore && $current === $setMode && $restoreMode !== '' && $restoreMode !== $setMode) {
            $this->setBorneMode($restoreMode === 'Normal' ? 'Normal' : 'Eco', $why);
        }
        $this->setCache('owned', 0);
        $this->setCache('set_mode', '');
        $this->setCache('latched', 0);
    }

    /* ------------------------------------------------------------------ */
    /* Batterie                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Niveau de batterie estimé. La valeur du constructeur arrive par à-coups
     * (cloud) : entre deux mises à jour, on ajoute l'énergie comptée par la
     * borne depuis la dernière valeur connue.
     */
    private function estimateSoc($connected) {
        $soc = self::readCmd($this->getConfiguration('soc_cmd'));
        if ($soc === null || !is_numeric($soc['value'])) {
            return null;
        }
        $value = (float) $soc['value'];
        $energy = self::readCmd($this->borneCmd('energy'));
        $energyNow = ($energy !== null && is_numeric($energy['value'])) ? (float) $energy['value'] : null;

        $refKey = $value . '@' . $soc['date'];
        if ($this->getCache('soc_ref_key', '') !== $refKey || $energyNow === null) {
            $this->setCache('soc_ref_key', $refKey);
            $this->setCache('soc_ref_energy', $energyNow);
            return $value;
        }
        $refEnergy = $this->getCache('soc_ref_energy', null);
        if (!$connected || $refEnergy === null || $energyNow < (float) $refEnergy) {
            // Nouvelle session sur la borne : on repart de cette valeur.
            $this->setCache('soc_ref_energy', $energyNow);
            return $value;
        }
        $capacity = max(1.0, (float) $this->getConfiguration('capacity_kwh', 50));
        $efficiency = max(0.5, min(1.0, (float) $this->getConfiguration('efficiency', 90) / 100));
        $added = ($energyNow - (float) $refEnergy) * $efficiency / $capacity * 100;
        return max(0, min(100, round($value + $added, 1)));
    }

    private function objectives() {
        $list = array();
        for ($i = 1; $i <= self::OBJECTIVE_SLOTS; $i++) {
            $list[] = array(
                'enabled' => (int) $this->getConfiguration('obj' . $i . '_enabled', 0),
                'day' => (int) $this->getConfiguration('obj' . $i . '_day', 0),
                'time' => (string) $this->getConfiguration('obj' . $i . '_time', '00:00'),
                'target' => $this->getConfiguration('obj' . $i . '_target', ''),
            );
        }
        return $list;
    }

    /* ------------------------------------------------------------------ */
    /* Évaluation                                                          */
    /* ------------------------------------------------------------------ */

    public function evaluate() {
        $now = $this->now();
        $simulation = self::isSimulation();
        $calendar = self::buildCalendar();
        $borne = $this->currentBorne();

        // État de la borne.
        $status = self::readCmd($this->borneCmd('status'));
        $connected = self::statusConnected($status === null ? null : $status['value']);
        if ($connected === null) {
            $plugged = self::readCmd($this->getConfiguration('plugged_cmd'));
            $connected = ($plugged !== null) ? ((int) $plugged['value'] === 1) : false;
        }
        $statusId = ($status !== null && is_numeric($status['value'])) ? (int) $status['value'] : null;

        // Borne du jour : retour à l'habituelle après un débranchement prolongé.
        $default = (int) $this->getConfiguration('default_borne', 1);
        if ($borne !== $default) {
            if ($connected) {
                $this->setCache('borne_disconnected_since', 0);
            } else {
                $since = (int) $this->getCache('borne_disconnected_since', 0);
                if ($since === 0) {
                    $this->setCache('borne_disconnected_since', $now);
                } elseif ($now - $since > self::BORNE_RESET_AFTER) {
                    $this->releaseControl(false, 'retour borne habituelle');
                    $this->setCache('borne_current', 0);
                    $this->setCache('borne_disconnected_since', 0);
                    log::add('ChargePlanner', 'info', $this->getHumanName() . ' : retour sur ' . self::borneName($default));
                    return $this->evaluate();
                }
            }
        }

        $soc = $this->estimateSoc($connected);

        // Fin du boost : voiture débranchée, charge terminée, ou sécurité 24 h.
        $boostStart = (int) $this->getCache('boost_start', 0);
        if ($boostStart > 0 && $now >= $boostStart) {
            if ($connected) {
                $this->setCache('boost_seen_connected', 1);
            }
            $waiting = ($statusId === 181) || ($soc !== null && $soc >= 100);
            $waitingSince = (int) $this->getCache('boost_waiting_since', 0);
            if ($waiting && $waitingSince === 0) {
                $this->setCache('boost_waiting_since', $now);
            } elseif (!$waiting) {
                $this->setCache('boost_waiting_since', 0);
            }
            if ((int) $this->getCache('boost_seen_connected', 0) === 1 && !$connected) {
                $this->clearBoost('voiture débranchée');
            } elseif ($waiting && $waitingSince > 0 && $now - $waitingSince >= self::DONE_WAIT) {
                $this->clearBoost('charge terminée');
            } elseif ($now - $boostStart > self::BOOST_MAX) {
                $this->clearBoost('durée maximale atteinte');
            }
            $boostStart = (int) $this->getCache('boost_start', 0);
        }

        $engine = new ChargePlannerEngine($calendar);
        $decision = $engine->decide(array(
            'now' => $now,
            'soc' => $soc,
            'capacity_kwh' => (float) $this->getConfiguration('capacity_kwh', 50),
            'efficiency' => (float) $this->getConfiguration('efficiency', 90) / 100,
            'power_w' => (float) $this->getConfiguration('power_w', 7200),
            'margin_min' => (int) $this->getConfiguration('margin_min', 30),
            'prefer_offpeak' => (int) $this->getConfiguration('prefer_offpeak', 1) === 1,
            'latched' => (int) $this->getCache('latched', 0) === 1,
            'objectives' => $this->objectives(),
            'boost_start' => $boostStart > 0 ? $boostStart : null,
        ));

        $pilot = $this->getCmd(null, 'pilot');
        $pilotOn = !is_object($pilot) || (int) $pilot->execCmd() !== 0;
        $reason = $decision['reason'];
        $wanted = $decision['mode'];

        // Plan à ne pas reforcer après une reprise en main manuelle.
        $planKey = $decision['source'] . '@' . ($decision['source'] === 'boost' ? $boostStart : $decision['objective_text']);
        $override = (string) $this->getCache('override', '');
        if ($wanted !== 'Normal') {
            $this->setCache('override', '');
            $override = '';
        }

        $current = $this->readBorneMode();
        if (!$pilotOn) {
            $reason = __('Pilotage automatique désactivé', __FILE__) . ' — ' . $reason;
        } elseif (!$connected) {
            $this->releaseControl(true, 'voiture débranchée');
            $reason = __('Voiture non branchée', __FILE__) . ($reason !== '' ? ' — ' . $reason : '');
            $wanted = 'Eco';
        } elseif ($wanted === 'Normal') {
            if ((int) $this->getCache('owned', 0) === 1 && $current !== null && $current !== (string) $this->getCache('set_mode', '')) {
                // Quelqu'un a changé le mode pendant notre charge : on s'efface.
                $this->setCache('owned', 0);
                $this->setCache('latched', 0);
                $this->setCache('override', $planKey);
                $override = $planKey;
                log::add('ChargePlanner', 'info', $this->getHumanName() . ' : mode changé à la main, le plugin rend la main');
            }
            if ($override === $planKey) {
                $reason = __('Mode choisi à la main, plan suspendu', __FILE__) . ' — ' . $reason;
                $wanted = $current ?? 'Eco';
            } elseif ($current === 'Normal' && (int) $this->getCache('owned', 0) !== 1) {
                // Déjà en Normal par choix de l'utilisateur : on ne s'approprie rien.
            } else {
                if ((int) $this->getCache('owned', 0) !== 1) {
                    $this->setCache('restore_mode', $current ?? 'Eco');
                }
                if ($current !== 'Normal' && $this->setBorneMode('Normal', $reason) && !$simulation) {
                    $this->setCache('owned', 1);
                    $this->setCache('set_mode', 'Normal');
                }
            }
            $this->setCache('latched', $decision['source'] === 'objective' ? 1 : 0);
        } else {
            $this->releaseControl(true, $reason);
            $this->setCache('latched', 0);
        }

        if ($simulation) {
            $reason = '[Simulation] ' . $reason;
        }

        // Mise à jour des infos.
        $this->checkAndUpdateCmd('decision', $wanted);
        $this->checkAndUpdateCmd('reason', $reason);
        $this->checkAndUpdateCmd('objective', $decision['objective_text'] !== '' ? $decision['objective_text'] : '—');
        $this->checkAndUpdateCmd('start', $decision['start_text'] !== '' ? $decision['start_text'] : '—');
        $this->checkAndUpdateCmd('need', $decision['need_kwh']);
        if ($soc !== null) {
            $this->checkAndUpdateCmd('soc', $soc);
        }
        $boostStart = (int) $this->getCache('boost_start', 0);
        $this->checkAndUpdateCmd('boost', $boostStart > 0 ? 1 : 0);
        if ($boostStart === 0) {
            $boostText = __('Aucun', __FILE__);
        } elseif ($now >= $boostStart) {
            $boostText = __('En cours', __FILE__);
        } else {
            $boostText = __('Programmé', __FILE__) . ' ' . ChargePlannerFormat::when($boostStart, $now);
        }
        $this->checkAndUpdateCmd('boost_text', $boostText);
        $this->checkAndUpdateCmd('borne', self::borneName($borne));

        log::add('ChargePlanner', 'debug', $this->getHumanName() . ' : borne=' . $borne . ' branchée=' . ($connected ? 1 : 0)
            . ' statut=' . var_export($statusId, true) . ' soc=' . var_export($soc, true) . ' mode=' . var_export($current, true)
            . ' décision=' . $wanted . ' (' . $decision['source'] . ') ' . $reason);
        return $decision;
    }
}

class ChargePlannerCmd extends cmd {
    public function execute($_options = array()) {
        if ($this->getType() !== 'action') {
            return;
        }
        $eqLogic = $this->getEqLogic();
        if (!is_object($eqLogic)) {
            throw new Exception(__('Équipement ChargePlanner introuvable', __FILE__));
        }
        $options = is_array($_options) ? $_options : array();
        $options['action'] = $this->getLogicalId();
        return $eqLogic->execute($options);
    }
}
