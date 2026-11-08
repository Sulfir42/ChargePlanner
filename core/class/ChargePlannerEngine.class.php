<?php
/* This file is part of Jeedom.
 *
 * Plugin Name: ChargePlanner
 *
 * Moteur de décision : à partir de l'état de la voiture, des objectifs et du
 * calendrier des jours creux, dit si la borne doit être en mode « Normal »
 * (charge à pleine puissance) ou laissée en « Eco » (surplus solaire).
 *
 * Classe pure, sans dépendance à Jeedom : toute la logique est testable en
 * ligne de commande (voir tests/engine_test.php).
 */

require_once __DIR__ . '/ChargePlannerCalendar.class.php';

class ChargePlannerEngine {

    const SLOT = 900; // créneaux de 15 minutes
    const HORIZON_DAYS = 7;

    /** @var ChargePlannerCalendar */
    private $calendar;

    public function __construct(ChargePlannerCalendar $calendar) {
        $this->calendar = $calendar;
    }

    /**
     * Prochaine échéance d'un objectif (jour ISO + heure), strictement après $now.
     */
    public static function nextDeadline($now, $isoDay, $hhmm) {
        list($h, $m) = ChargePlannerCalendar::parseTime($hhmm);
        $today = (int) date('N', $now);
        $delta = ((int) $isoDay - $today + 7) % 7;
        $ts = mktime($h, $m, 0, (int) date('n', $now), (int) date('j', $now) + $delta, (int) date('Y', $now));
        if ($ts <= $now) {
            $ts = mktime($h, $m, 0, (int) date('n', $now), (int) date('j', $now) + $delta + 7, (int) date('Y', $now));
        }
        return $ts;
    }

    /** Énergie à tirer du réseau (kWh), pertes comprises, pour passer de $soc à $target. */
    public static function energyNeeded($soc, $target, $capacityKwh, $efficiency) {
        if ($soc === null || $target <= $soc) {
            return 0.0;
        }
        $efficiency = ($efficiency > 0 && $efficiency <= 1) ? $efficiency : 0.9;
        return (($target - $soc) / 100.0) * $capacityKwh / $efficiency;
    }

    /**
     * Planifie un objectif.
     *
     * @param array $p now, soc, capacity_kwh, efficiency, power_w, margin_min,
     *                 prefer_offpeak, latched
     * @param array $objective day (1-7), time (HH:MM), target (%)
     * @return array deadline, need_kwh, duration, slots (timestamps choisis),
     *               start, normal_now, offpeak_plan, late
     */
    public function planObjective(array $p, array $objective) {
        $now = (int) $p['now'];
        $deadline = self::nextDeadline($now, $objective['day'], $objective['time']);
        $target = max(0, min(100, (float) $objective['target']));
        $need = self::energyNeeded($p['soc'], $target, (float) $p['capacity_kwh'], (float) $p['efficiency']);
        $result = array(
            'deadline' => $deadline,
            'target' => $target,
            'need_kwh' => $need,
            'duration' => 0,
            'start' => null,
            'normal_now' => false,
            'offpeak_plan' => false,
            'late' => false,
        );
        if ($need <= 0) {
            return $result;
        }

        $powerKw = max(1.0, (float) $p['power_w'] / 1000.0);
        $duration = ($need / $powerKw) * 3600 + max(0, (int) $p['margin_min']) * 60;
        $result['duration'] = $duration;
        $needed = (int) ceil($duration / self::SLOT);

        // Créneaux de 15 min entre maintenant et l'échéance.
        $first = $now - ($now % self::SLOT);
        $slots = array();
        $offpeak = array();
        for ($t = $first; $t < $deadline; $t += self::SLOT) {
            $slots[] = $t;
            if ($this->calendar->isOffPeakDay($t)) {
                $offpeak[] = $t;
            }
        }

        if (!empty($p['prefer_offpeak']) && count($offpeak) >= $needed) {
            $chosen = array_slice($offpeak, -$needed);
            $result['offpeak_plan'] = true;
        } elseif (!empty($p['prefer_offpeak']) && count($offpeak) > 0 && count($slots) >= $needed) {
            // Pas assez d'heures creuses : on prend toutes celles qui restent,
            // et on complète avec les derniers créneaux pleins avant l'échéance.
            $full = array_values(array_diff($slots, $offpeak));
            $chosen = array_merge($offpeak, array_slice($full, -($needed - count($offpeak))));
            sort($chosen);
            $result['offpeak_plan'] = true;
        } else {
            $chosen = array_slice($slots, -$needed);
            if (count($slots) < $needed) {
                $result['late'] = true; // même en chargeant tout de suite, on n'y arrivera pas
            }
        }
        $result['start'] = count($chosen) > 0 ? $chosen[0] : null;
        if ($result['start'] !== null && $result['start'] < $now) {
            $result['start'] = $now;
        }

        $inPlan = in_array($first, $chosen, true);
        // Verrou : une fois la charge lancée pour cet objectif, on ne la coupe
        // pas à chaque mise à jour du % (qui arrive par à-coups), sauf pour
        // éviter de charger en heure pleine quand le plan vise les heures creuses.
        $latched = !empty($p['latched'])
            && (empty($p['prefer_offpeak']) || $inPlan || $this->calendar->isOffPeakDay($now));
        $result['normal_now'] = $inPlan || $latched || $result['late'];
        return $result;
    }

    /**
     * Décision globale pour une voiture.
     *
     * @param array $p now, soc, capacity_kwh, efficiency, power_w, margin_min,
     *                 prefer_offpeak, latched, objectives[], boost_start (ts|null)
     * @return array mode (Normal|Eco), source (boost|objective|none), reason,
     *               objective_text, start, start_text, need_kwh
     */
    public function decide(array $p) {
        $now = (int) $p['now'];
        $out = array(
            'mode' => 'Eco',
            'source' => 'none',
            'reason' => '',
            'objective_text' => '',
            'start' => null,
            'start_text' => '',
            'need_kwh' => 0.0,
        );

        // 1. Boost : prioritaire sur tout le reste.
        if (!empty($p['boost_start'])) {
            if ($now >= (int) $p['boost_start']) {
                $out['mode'] = 'Normal';
                $out['source'] = 'boost';
                $out['reason'] = 'Boost en cours jusqu\'à la fin de la charge';
            } else {
                $out['start'] = (int) $p['boost_start'];
                $out['start_text'] = ChargePlannerFormat::when($out['start'], $now);
                $out['reason'] = 'Boost programmé ' . $out['start_text'];
            }
        }

        // 2. Objectifs.
        $objectives = array();
        foreach ((array) ($p['objectives'] ?? array()) as $o) {
            if (!empty($o['enabled']) && !empty($o['day']) && isset($o['target']) && $o['target'] !== '') {
                $objectives[] = $o;
            }
        }
        if (count($objectives) === 0) {
            if ($out['source'] === 'none' && $out['reason'] === '') {
                $out['reason'] = 'Aucun objectif actif';
            }
            return $out;
        }
        if ($p['soc'] === null) {
            if ($out['source'] === 'none' && $out['reason'] === '') {
                $out['reason'] = 'Niveau de batterie inconnu : objectifs en attente';
            }
            return $out;
        }

        $plans = array();
        foreach ($objectives as $o) {
            $plans[] = $this->planObjective($p, $o);
        }
        usort($plans, function ($a, $b) {
            return $a['deadline'] <=> $b['deadline'];
        });

        // Objectif affiché : le plus proche qui demande encore de l'énergie,
        // sinon le plus proche tout court.
        $shown = $plans[0];
        foreach ($plans as $plan) {
            if ($plan['need_kwh'] > 0) {
                $shown = $plan;
                break;
            }
        }
        $out['objective_text'] = ChargePlannerFormat::when($shown['deadline'], $now) . ' → ' . round($shown['target']) . ' %';
        $out['need_kwh'] = round($shown['need_kwh'], 1);

        $driving = null;
        foreach ($plans as $plan) {
            if ($plan['normal_now']) {
                $driving = $plan;
                break;
            }
        }

        if ($out['source'] === 'boost') {
            return $out;
        }

        if ($driving !== null) {
            $out['mode'] = 'Normal';
            $out['source'] = 'objective';
            $out['start'] = $driving['start'];
            $label = ChargePlannerFormat::when($driving['deadline'], $now) . ' ' . round($driving['target']) . ' %';
            $out['reason'] = $driving['late']
                ? 'Objectif ' . $label . ' : en retard, charge immédiate'
                : 'Objectif ' . $label . ' : charge en cours (' . round($driving['need_kwh'], 1) . ' kWh restants)';
            $out['start_text'] = 'en cours';
            return $out;
        }

        if ($shown['need_kwh'] <= 0) {
            if ($out['reason'] === '') {
                $out['reason'] = 'Objectif ' . $out['objective_text'] . ' déjà atteint';
            }
            return $out;
        }

        if ($out['start'] === null || ($shown['start'] !== null && $shown['start'] < $out['start'])) {
            $out['start'] = $shown['start'];
            $out['start_text'] = ChargePlannerFormat::when($shown['start'], $now);
        }
        if ($out['reason'] === '') {
            $out['reason'] = 'Eco jusqu\'à ' . ChargePlannerFormat::when($shown['start'], $now)
                . ' (' . round($shown['need_kwh'], 1) . ' kWh, ' . ChargePlannerFormat::duration($shown['duration']) . ' de charge'
                . ($shown['offpeak_plan'] ? ', en jour creux' : '') . ')';
        }
        return $out;
    }
}
