<?php
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/../core/class/ChargePlanner.class.php';

/**
 * Valeurs par défaut de la configuration globale. Le mode simulation est
 * activé à l'installation : le plugin calcule et affiche ses décisions sans
 * envoyer aucune commande aux bornes tant qu'on ne l'a pas désactivé.
 */
function ChargePlanner_defaults() {
    $defaults = array(
        'simulation' => 1,
        'boost_time' => '00:05',
        'offpeak_holidays' => 1,
        'offpeak_extra' => '',
        'borne1_name' => 'Borne 1',
        'borne2_name' => 'Borne 2',
    );
    for ($d = 1; $d <= 7; $d++) {
        $defaults['offpeak_day_' . $d] = in_array($d, array(3, 6, 7), true) ? 1 : 0;
    }
    foreach ($defaults as $key => $value) {
        if (config::byKey($key, 'ChargePlanner', '') === '') {
            config::save($key, $value, 'ChargePlanner');
        }
    }
}

function ChargePlanner_install() {
    ChargePlanner_defaults();
}

function ChargePlanner_update() {
    ChargePlanner_defaults();
    foreach (eqLogic::byType('ChargePlanner') as $eqLogic) {
        try {
            $eqLogic->checkAndCreateCommands();
        } catch (Exception $e) {
            log::add('ChargePlanner', 'warning', 'Mise à jour des commandes impossible pour ' . $eqLogic->getName() . ' : ' . $e->getMessage());
        }
    }
}

function ChargePlanner_remove() {
}
