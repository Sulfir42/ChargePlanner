<?php
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/../core/class/ChargePlanner.class.php';

/**
 * Valeurs par défaut de la configuration globale, préremplies avec les
 * commandes de l'installation (plugin privé). Le plugin démarre en
 * fonctionnement « observation » : il calcule tout mais n'envoie rien aux
 * bornes, le scénario de référence reste aux commandes.
 */
function ChargePlanner_defaults() {
    $defaults = array(
        'fonctionnement' => 'observation',
        'ref_scenario' => 98,
        'reg_interval' => 10,
        'trigger_cmd' => '#[Maison][Mesure - Tableau Maison][Actuel]#',
        // Mesures
        'conso_maison' => '#[Maison][Shelly Pro 3 EM 01][Phase C : Puissance Active]#',
        'conso_edf' => '#[Maison][Shelly Pro 3 EM 01][Phase A : Puissance Active]#',
        'prod_solaire' => '#[Maison][Shelly Pro 3 EM 01][Phase B : Puissance Active]#',
        'soc_batterie' => '#[Maison][Solis][Batterie]#',
        'p_batterie' => '#[Maison][Mesure - Batterie][Actuel]#',
        'prod_pv' => '#[Maison][Mesure - Panneaux Solaire][Actuel]#',
        // Observation : recopie des anciens virtuels
        'obs_mode1_source' => '#[Maison][Wallbox 01 - Mode][Mode]#',
        'obs_mode2_source' => '#[Maison][Wallbox 02 - Mode][Mode]#',
        'obs_prio_source' => '#[Maison][VE - Priorisation][Mode]#',
        // Jours creux / boost
        'boost_time' => '00:05',
        'offpeak_holidays' => 1,
        'offpeak_extra' => '',
    );
    $pinces = array(1 => 'A', 2 => 'B');
    foreach (ChargePlanner::BORNES as $i) {
        $wb = 'Wallbox0' . $i;
        $defaults['borne' . $i . '_name'] = 'Wallbox 0' . $i;
        $defaults['borne' . $i . '_status'] = '#[Maison][' . $wb . '][Statut ID]#';
        $defaults['borne' . $i . '_slider'] = '#[Maison][' . $wb . '][Puissance maximum]#';
        $defaults['borne' . $i . '_toggle'] = '#[Maison][' . $wb . '][Contrôle de la charge]#';
        $defaults['borne' . $i . '_pince'] = '#[Maison][Shelly Pro 3 EM 02][Phase ' . $pinces[$i] . ' : Puissance Active]#';
        $defaults['borne' . $i . '_refresh'] = '#[Maison][' . $wb . '][Rafraîchir]#';
        $defaults['borne' . $i . '_energy'] = '#[Maison][' . $wb . '][Énergie consommée]#';
        $defaults['borne' . $i . '_pmax'] = 7400;
    }
    for ($d = 1; $d <= 7; $d++) {
        $defaults['offpeak_day_' . $d] = in_array($d, array(3, 6, 7), true) ? 1 : 0;
    }
    foreach ($defaults as $key => $value) {
        if (config::byKey($key, 'ChargePlanner', '') === '') {
            config::save($key, $value, 'ChargePlanner');
        }
    }
    // Ancienne clé de la 0.1
    config::remove('simulation', 'ChargePlanner');
}

function ChargePlanner_setup() {
    ChargePlanner_defaults();
    ChargePlannerSite::ensureCoreEquipments();
    foreach (eqLogic::byType('ChargePlanner') as $eqLogic) {
        try {
            $eqLogic->checkAndCreateCommands();
        } catch (Exception $e) {
            log::add('ChargePlanner', 'warning', 'Mise à jour des commandes impossible pour ' . $eqLogic->getName() . ' : ' . $e->getMessage());
        }
    }
    ChargePlannerSite::setupListener();
}

function ChargePlanner_install() {
    ChargePlanner_setup();
}

function ChargePlanner_update() {
    ChargePlanner_setup();
}

function ChargePlanner_remove() {
    $listener = listener::byClassAndFunction('ChargePlanner', 'onTrigger');
    if (is_object($listener)) {
        $listener->remove();
    }
    // Si le plugin pilotait, on rend la main au scénario de référence.
    if (ChargePlanner::isActive()) {
        ChargePlannerSite::switchMode(false);
    }
}
