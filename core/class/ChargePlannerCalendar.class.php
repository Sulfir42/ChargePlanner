<?php
/* This file is part of Jeedom.
 *
 * Plugin Name: ChargePlanner
 *
 * Calendrier des jours creux : jours de semaine choisis, jours fériés français
 * calculés (y compris ceux qui dépendent de Pâques) et dates ajoutées à la main.
 * Classe pure, sans dépendance à Jeedom : testable en ligne de commande.
 */

class ChargePlannerCalendar {

    /** @var int[] jours ISO (1 = lundi ... 7 = dimanche) */
    private $weekdays;
    /** @var bool */
    private $holidays;
    /** @var array<string,bool> dates Y-m-d ajoutées manuellement */
    private $extraDates = array();

    public function __construct(array $weekdays = array(3, 6, 7), $holidays = true, $extraDates = array()) {
        $this->weekdays = array_map('intval', $weekdays);
        $this->holidays = (bool) $holidays;
        foreach (self::parseDates($extraDates) as $date) {
            $this->extraDates[$date] = true;
        }
    }

    /**
     * Accepte un tableau ou un texte libre (une date par ligne, ou séparées
     * par des virgules / points-virgules), formats AAAA-MM-JJ ou JJ/MM/AAAA.
     */
    public static function parseDates($input) {
        if (!is_array($input)) {
            $input = preg_split('/[\s,;]+/', (string) $input);
        }
        $dates = array();
        foreach ($input as $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
                $dates[] = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
            } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $raw, $m)) {
                $dates[] = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            }
        }
        return $dates;
    }

    /** Date de Pâques (algorithme de Meeus/Jones/Butcher), sans extension calendar. */
    public static function easter($year) {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** Jours fériés de France métropolitaine pour une année : [Y-m-d => libellé]. */
    public static function frenchHolidays($year) {
        $easter = new DateTime(self::easter($year));
        $offset = function ($days) use ($easter) {
            $d = clone $easter;
            $d->modify(($days >= 0 ? '+' : '') . $days . ' days');
            return $d->format('Y-m-d');
        };
        return array(
            sprintf('%04d-01-01', $year) => 'Jour de l\'an',
            $offset(1) => 'Lundi de Pâques',
            sprintf('%04d-05-01', $year) => 'Fête du Travail',
            sprintf('%04d-05-08', $year) => 'Victoire 1945',
            $offset(39) => 'Ascension',
            $offset(50) => 'Lundi de Pentecôte',
            sprintf('%04d-07-14', $year) => 'Fête nationale',
            sprintf('%04d-08-15', $year) => 'Assomption',
            sprintf('%04d-11-01', $year) => 'Toussaint',
            sprintf('%04d-11-11', $year) => 'Armistice 1918',
            sprintf('%04d-12-25', $year) => 'Noël',
        );
    }

    public function isHoliday($timestamp) {
        $date = date('Y-m-d', $timestamp);
        $list = self::frenchHolidays((int) date('Y', $timestamp));
        return isset($list[$date]);
    }

    /** Vrai si la journée calendaire contenant $timestamp est une journée creuse. */
    public function isOffPeakDay($timestamp) {
        if (in_array((int) date('N', $timestamp), $this->weekdays, true)) {
            return true;
        }
        if (isset($this->extraDates[date('Y-m-d', $timestamp)])) {
            return true;
        }
        return $this->holidays && $this->isHoliday($timestamp);
    }

    /** Raison lisible pour laquelle un jour est creux ('' si jour plein). */
    public function offPeakReason($timestamp) {
        $date = date('Y-m-d', $timestamp);
        if ($this->holidays) {
            $list = self::frenchHolidays((int) date('Y', $timestamp));
            if (isset($list[$date])) {
                return $list[$date];
            }
        }
        if (isset($this->extraDates[$date])) {
            return 'Jour ajouté';
        }
        if (in_array((int) date('N', $timestamp), $this->weekdays, true)) {
            return ChargePlannerFormat::dayName((int) date('N', $timestamp));
        }
        return '';
    }

    /**
     * Prochain jour creux à partir de $timestamp (inclus), à l'heure $hhmm.
     * Renvoie le timestamp de ce jour à cette heure. Si on est déjà un jour
     * creux mais après l'heure demandée, renvoie $timestamp lui-même.
     */
    public function nextOffPeakStart($timestamp, $hhmm = '00:05', $maxDays = 14) {
        list($h, $m) = self::parseTime($hhmm);
        if ($this->isOffPeakDay($timestamp)) {
            $todayAt = mktime($h, $m, 0, (int) date('n', $timestamp), (int) date('j', $timestamp), (int) date('Y', $timestamp));
            return max($timestamp, $todayAt);
        }
        for ($i = 1; $i <= $maxDays; $i++) {
            $day = mktime($h, $m, 0, (int) date('n', $timestamp), (int) date('j', $timestamp) + $i, (int) date('Y', $timestamp));
            if ($this->isOffPeakDay($day)) {
                return $day;
            }
        }
        return null;
    }

    public static function parseTime($hhmm) {
        if (preg_match('/^(\d{1,2})[:hH](\d{2})$/', trim((string) $hhmm), $m)) {
            return array(min(23, (int) $m[1]), min(59, (int) $m[2]));
        }
        return array(0, 0);
    }
}

/** Petites aides de mise en forme en français, partagées par le moteur et la classe Jeedom. */
class ChargePlannerFormat {
    const DAYS = array(1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche');
    const DAYS_SHORT = array(1 => 'lun.', 2 => 'mar.', 3 => 'mer.', 4 => 'jeu.', 5 => 'ven.', 6 => 'sam.', 7 => 'dim.');

    public static function dayName($n) {
        return self::DAYS[(int) $n] ?? '';
    }

    /** « 17h30 » si aujourd'hui, « dim. 17h30 » sinon. */
    public static function when($timestamp, $now) {
        $time = date('G', $timestamp) . 'h' . date('i', $timestamp);
        if (date('Y-m-d', $timestamp) === date('Y-m-d', $now)) {
            return $time;
        }
        return self::DAYS_SHORT[(int) date('N', $timestamp)] . ' ' . $time;
    }

    public static function duration($seconds) {
        $minutes = (int) round($seconds / 60);
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        if ($h === 0) {
            return $m . ' min';
        }
        return $h . 'h' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
    }
}
