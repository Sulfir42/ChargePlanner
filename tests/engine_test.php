<?php
/*
 * Tests du moteur ChargePlanner, sans Jeedom :
 *   php tests/engine_test.php
 */
date_default_timezone_set('Europe/Paris');
require_once __DIR__ . '/../core/class/ChargePlannerEngine.class.php';

$failures = 0;
function check($label, $cond, $detail = '') {
    global $failures;
    echo ($cond ? '  OK   ' : '  FAIL ') . $label . ($detail !== '' ? '  [' . $detail . ']' : '') . "\n";
    if (!$cond) {
        $failures++;
    }
}
function ts($s) {
    return strtotime($s);
}

echo "Calendrier\n";
check('Pâques 2026 = 5 avril', ChargePlannerCalendar::easter(2026) === '2026-04-05');
check('Pâques 2027 = 28 mars', ChargePlannerCalendar::easter(2027) === '2027-03-28');
$h = ChargePlannerCalendar::frenchHolidays(2026);
check('Ascension 2026 = 14 mai', isset($h['2026-05-14']));
check('Lundi de Pentecôte 2026 = 25 mai', isset($h['2026-05-25']));
$cal = new ChargePlannerCalendar(array(3, 6, 7), true, "2026-12-24\n31/12/2026");
check('Lundi 09/11 plein', !$cal->isOffPeakDay(ts('2026-11-09 12:00')));
check('Mercredi 11/11 creux', $cal->isOffPeakDay(ts('2026-11-11 12:00')));
check('Raison du 11/11 = férié', $cal->offPeakReason(ts('2026-11-11 12:00')) === 'Armistice 1918');
check('Lundi de Pâques creux', $cal->isOffPeakDay(ts('2026-04-06 08:00')));
check('Date ajoutée 24/12 creuse', $cal->isOffPeakDay(ts('2026-12-24 08:00')));
check('Date ajoutée 31/12 (JJ/MM/AAAA) creuse', $cal->isOffPeakDay(ts('2026-12-31 08:00')));
check('Mardi soir -> mercredi 0h05', $cal->nextOffPeakStart(ts('2026-11-10 21:00')) === ts('2026-11-11 00:05'));
check('Jeudi -> samedi 0h05', $cal->nextOffPeakStart(ts('2026-11-12 09:00')) === ts('2026-11-14 00:05'));
check('Samedi 10h -> maintenant', $cal->nextOffPeakStart(ts('2026-11-14 10:00')) === ts('2026-11-14 10:00'));

$engine = new ChargePlannerEngine($cal);
$base = array(
    'soc' => 50, 'capacity_kwh' => 52, 'efficiency' => 0.9, 'power_w' => 7200, 'margin_min' => 30,
    'prefer_offpeak' => true, 'latched' => false, 'boost_start' => null,
);

echo "Échéances\n";
check('Prochain dimanche 22h depuis dimanche 12h = même jour', ChargePlannerEngine::nextDeadline(ts('2026-11-15 12:00'), 7, '22:00') === ts('2026-11-15 22:00'));
check('Prochain dimanche 22h depuis dimanche 23h = +7 j', ChargePlannerEngine::nextDeadline(ts('2026-11-15 23:00'), 7, '22:00') === ts('2026-11-22 22:00'));
check('Vendredi 0h depuis mercredi', ChargePlannerEngine::nextDeadline(ts('2026-11-11 14:00'), 5, '00:00') === ts('2026-11-13 00:00'));

echo "Objectif dimanche 22h 100 % (50 %, 52 kWh)\n";
$p = $base + array('objectives' => array(array('enabled' => 1, 'day' => 7, 'time' => '22:00', 'target' => 100)));
$p['now'] = ts('2026-11-15 12:00');
$d = $engine->decide($p);
check('A midi : Eco', $d['mode'] === 'Eco', $d['reason']);
check('Départ prévu 17h15', $d['start'] === ts('2026-11-15 17:15'), date('D H:i', (int) $d['start']));
check('Énergie ~28.9 kWh', abs($d['need_kwh'] - 28.9) < 0.1, (string) $d['need_kwh']);
$p['now'] = ts('2026-11-15 17:20');
$d = $engine->decide($p);
check('A 17h20 : Normal (objectif)', $d['mode'] === 'Normal' && $d['source'] === 'objective', $d['reason']);
$p['now'] = ts('2026-11-15 12:00');
$p['soc'] = 80;
$d = $engine->decide($p);
check('Si le solaire a monté à 80 % : départ décalé à 19h45', $d['start'] === ts('2026-11-15 19:45'), date('H:i', (int) $d['start']));
$p['soc'] = 100;
$d = $engine->decide($p);
check('A 100 % : Eco, objectif atteint', $d['mode'] === 'Eco' && strpos($d['reason'], 'atteint') !== false, $d['reason']);

echo "Objectif vendredi 0h 50 % (30 %), préférence jour creux\n";
$p = $base + array('objectives' => array(array('enabled' => 1, 'day' => 5, 'time' => '00:00', 'target' => 50)));
$p['soc'] = 30;
$p['now'] = ts('2026-11-18 14:00'); // mercredi (jour creux), jeudi plein
$d = $engine->decide($p);
check('Mercredi 14h : Eco, départ mercredi soir', $d['mode'] === 'Eco' && date('Y-m-d', (int) $d['start']) === '2026-11-18', date('D H:i', (int) $d['start']) . ' / ' . $d['reason']);
check('Départ mercredi 21h45', $d['start'] === ts('2026-11-18 21:45'), date('H:i', (int) $d['start']));
$p['now'] = ts('2026-11-18 22:00');
$d = $engine->decide($p);
check('Mercredi 22h (cron en retard) : Normal sur les heures creuses restantes', $d['mode'] === 'Normal', $d['reason']);
$p['now'] = ts('2026-11-18 21:50');
$p['latched'] = true;
$p['soc'] = 35;
$d = $engine->decide($p);
check('Mercredi 21h50, verrou + 35 % : reste en Normal', $d['mode'] === 'Normal', $d['reason']);
$p['latched'] = false;
$p['soc'] = 30;
$p['now'] = ts('2026-11-19 10:00'); // jeudi : plus de jour creux avant l'échéance
$d = $engine->decide($p);
check('Jeudi 10h sans charge : repli, départ jeudi 21h45', $d['mode'] === 'Eco' && $d['start'] === ts('2026-11-19 21:45'), date('D H:i', (int) $d['start']));
$p['latched'] = true;
$d = $engine->decide($p);
check('Jeudi 10h, verrou actif : pas de charge en heure pleine avant 21h45', $d['mode'] === 'Eco', $d['reason']);
$p['prefer_offpeak'] = false;
$p['latched'] = false;
$p['now'] = ts('2026-11-18 14:00');
$d = $engine->decide($p);
check('Sans préférence creux : départ jeudi 21h45', $d['start'] === ts('2026-11-19 21:45'), date('D H:i', (int) $d['start']));

echo "Plusieurs objectifs\n";
$p = $base + array('objectives' => array(
    array('enabled' => 1, 'day' => 5, 'time' => '00:00', 'target' => 50),
    array('enabled' => 1, 'day' => 7, 'time' => '22:00', 'target' => 100),
    array('enabled' => 0, 'day' => 1, 'time' => '07:00', 'target' => 100),
));
$p['soc'] = 60;
$p['now'] = ts('2026-11-18 14:00');
$d = $engine->decide($p);
check('Vendredi déjà OK : on affiche dimanche', strpos($d['objective_text'], 'dim.') === 0, $d['objective_text']);

echo "Retard\n";
$p = $base + array('objectives' => array(array('enabled' => 1, 'day' => 7, 'time' => '22:00', 'target' => 100)));
$p['soc'] = 10;
$p['now'] = ts('2026-11-15 20:00');
$d = $engine->decide($p);
check('Trop tard : Normal immédiat', $d['mode'] === 'Normal' && strpos($d['reason'], 'retard') !== false, $d['reason']);

echo "Boost\n";
$p = $base + array('objectives' => array());
$p['boost_start'] = ts('2026-11-11 00:05');
$p['now'] = ts('2026-11-10 21:00');
$d = $engine->decide($p);
check('Boost programmé : Eco en attendant', $d['mode'] === 'Eco' && strpos($d['reason'], 'mer. 0h05') !== false, $d['reason']);
$p['now'] = ts('2026-11-11 00:10');
$d = $engine->decide($p);
check('Boost actif à 0h10 : Normal', $d['mode'] === 'Normal' && $d['source'] === 'boost', $d['reason']);
$p['soc'] = null;
$d = $engine->decide($p);
check('Boost actif même sans % batterie', $d['mode'] === 'Normal');

echo "Batterie inconnue\n";
$p = $base + array('objectives' => array(array('enabled' => 1, 'day' => 7, 'time' => '22:00', 'target' => 100)));
$p['soc'] = null;
$p['now'] = ts('2026-11-15 12:00');
$d = $engine->decide($p);
check('Sans % : Eco et message', $d['mode'] === 'Eco' && strpos($d['reason'], 'inconnu') !== false, $d['reason']);

echo "Changement d'heure (dimanche 29/03/2026)\n";
$p = $base + array('objectives' => array(array('enabled' => 1, 'day' => 7, 'time' => '22:00', 'target' => 100)));
$p['now'] = ts('2026-03-29 01:00');
$d = $engine->decide($p);
check('Départ calculé le jour du changement d\'heure', $d['start'] === ts('2026-03-29 17:15'), date('Y-m-d H:i', (int) $d['start']));

echo "\n" . ($failures === 0 ? 'Tous les tests passent.' : $failures . ' échec(s).') . "\n";
exit($failures === 0 ? 0 : 1);
