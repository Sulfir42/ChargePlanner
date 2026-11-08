// ======================================================
// SCENARIO JEEDOM - PILOTAGE VE (WB1 + WB2) - V4.0
//
// NOUVEAUTES V4 :
// - Un mode PAR BORNE : 'Wallbox 01 - Mode' et 'Wallbox 02 - Mode'
//   (Normal / Eco). 'Full Green' est traite comme Eco. Un mode vide ou
//   inconnu est traite comme Eco, avec une alerte dans la raison.
// - Bornes dans le MEME mode : la priorisation s'applique (comme V3.1).
// - Bornes en modes DIFFERENTS : la priorisation est remise sur Off.
//   La borne Normal est servie en premier sur le budget 12 kW ; la borne
//   Eco reste regulee sur la batterie et ne charge que s'il reste un
//   excedent reel.
// - CORRECTION BUG RECHARGE PLANIFIEE (Tesla a 4 h) : une borne en Normal
//   dont la voiture ne tire rien garde une consigne de veille et n'est
//   JAMAIS mise en pause pour ce motif. Le flag 'voiture inactive' ne
//   concerne plus que l'Eco, et il expire au bout d'1 h (plus de blocage
//   definitif d'une borne).
// - Toggles securises ('Controle de la charge' est une BASCULE) :
//     * pause uniquement si la pince mesure un vrai tirage,
//     * reprise uniquement si la pince ne mesure rien,
//     * aucun toggle tant que le statut n'a pas ete rafraichi depuis le
//       toggle precedent (evite d'inverser l'effet sur un statut perime).
// - Eco : demarrage refuse si la batterie est deja en decharge (nuit,
//   nuage, autre borne en Normal qui tire sur la batterie).
// - Consignes : les baisses d'amperage sont envoyees AVANT les hausses.
//
// PREREQUIS :
// - Power Boost / compteur DESACTIVE sur les deux bornes dans myWallbox.
// - Declenchement : toutes les minutes (cron * * * * *).
// - Adapter $CMD_PRIORITE_OFF au nom reel de la commande action 'Off'
//   (ou 'Desactivee') du virtuel 'VE - Priorisation'.
//
// Convention puissance batterie : positif = charge, negatif = decharge.
// Convention conso EDF : positif = import, negatif = injection.
// ======================================================


// ======================================================
// PARAMETRES GENERAUX
// ======================================================

$P_COMPTEUR_MAX = 12000;     // Limite compteur EDF en W
$P_MAISON_MAX = 12000;       // Limite conso maison / tableau en W
$P_MAX_WB1 = 7400;           // Puissance max borne 01 en W
$P_MAX_WB2 = 7400;           // Puissance max borne 02 en W
$U = 230;

$AMP_MIN = 6;
$AMP_MAX = 32;
$P_MIN_CHARGE_VE = $AMP_MIN * $U; // 1380 W

$MARGE_SECURITE = 500;       // Marge de planification sous les limites
$MARGE_URGENCE = 300;        // Mesure reelle > limite - 300 W => urgence

// ------------------------------------------------------
// Regulation batterie (mode Eco) - inchangee par rapport a V3.1
// ------------------------------------------------------
$SOC_START = 91;
$SOC_START_IMMEDIAT = 93;
$SOC_STOP = 90;
$SOC_CIBLE_BASSE = 90;
$SOC_CIBLE_HAUTE = 95;
$P_BAT_CIBLE_MAX = 600;
$P_BAT_CIBLE_MIN = -600;

$DEADBAND = 300;
$DELAI_DEMARRAGE_ECO = 180;
$DELAI_MONTEE_ECO = 30;
$PAS_MAX_MONTEE = 4;
$PAS_MAX_BAISSE = 6;

// ------------------------------------------------------
// Anti-yoyo mode Normal
// ------------------------------------------------------
$DELAI_MONTEE_AMP = 30;
$DELAI_BAISSE_AMP = 30;
$ECART_MIN_MONTEE = 2;
$SEUIL_BAISSE_IMMEDIATE = 3;

// ------------------------------------------------------
// Toggles et detection voiture
// ------------------------------------------------------
$DELAI_MIN_TOGGLE = 120;      // Delai mini entre deux toggles par borne
$DELAI_STATUT_MAX = 600;      // Attente max d'un rafraichissement du statut apres un toggle
$SEUIL_VE_ACTIF = 500;        // Pince > 500 W = la voiture absorbe reellement
$DELAI_VE_INACTIF = 600;      // Eco : 10 min sans absorber => borne ecartee
$DELAI_RETEST_INACTIF = 3600; // Eco : une borne ecartee est retentee au bout d'1 h
$DELAI_GRACE_DEMARRAGE = 240; // Delai de grace apres demarrage
$DELAI_RETRY_PRIO_OFF = 600;  // Modes mixtes : nouvel essai de remise a Off toutes les 10 min

// ------------------------------------------------------
// Statuts Wallbox
// ------------------------------------------------------
$ST_PAUSE = array(178, 182);
$ST_PILOTABLE = array(161, 162, 178, 180, 181, 182, 193, 194, 195, 209, 210);


// ======================================================
// FONCTIONS
// ======================================================

if (!function_exists('veStatusText')) {
    function veStatusText($status) {
        switch ($status) {
            case 0: return 'Disconnected';
            case 14: case 15: return 'Error';
            case 161: case 162: return 'Ready';
            case 163: return 'Disconnected';
            case 164: return 'Waiting - Remove plug';
            case 165: return 'Locked';
            case 166: return 'Updating';
            case 177: case 179: return 'Scheduled';
            case 178: case 182: return 'Paused';
            case 180: case 181: return 'Waiting for car demand';
            case 183: case 184: return 'Waiting in queue by Power Sharing';
            case 185: case 186: return 'Waiting in queue by Power Boost';
            case 187: return 'Waiting MID failed';
            case 188: return 'Waiting MID safety margin exceeded';
            case 189: return 'Waiting in queue by Eco-Smart';
            case 193: case 194: case 195: return 'Charging';
            case 196: return 'Discharging';
            case 209: return 'Locked';
            case 210: return 'Locked - Car connected';
            default: return 'Unknown';
        }
    }
}

// Normalise la valeur d'un virtuel de mode : array(mode, note)
if (!function_exists('veNormMode')) {
    function veNormMode($v) {
        $n = mb_strtolower(trim(strval($v)));
        if ($n == 'normal') {
            return array('Normal', '');
        }
        if ($n == 'eco' || $n == 'éco') {
            return array('Eco', '');
        }
        if (strpos($n, 'full') !== false || strpos($n, 'green') !== false) {
            return array('Eco', 'Full Green traité comme Eco');
        }
        return array('Eco', 'MODE NON RECONNU ("' . $v . '") : Eco appliqué par défaut');
    }
}

// Horodatage de la derniere collecte d'une commande info (0 si inconnu)
if (!function_exists('veCmdTs')) {
    function veCmdTs($str) {
        try {
            $c = cmd::byString($str);
            $d = $c->getCollectDate();
            if ($d == '' || $d === null) {
                $d = $c->getValueDate();
            }
            if ($d == '' || $d === null) {
                return 0;
            }
            $t = strtotime($d);
            return ($t === false) ? 0 : $t;
        } catch (Exception $e) {
            return 0;
        }
    }
}


// ======================================================
// COMMANDES A ADAPTER
// ======================================================

// Mesures maison / reseau (Shelly Pro 3 EM 01)
$consoMaison = cmd::byString('#[Maison][Shelly Pro 3 EM 01][Phase C : Puissance Active]#')->execCmd();
$consoEDF = cmd::byString('#[Maison][Shelly Pro 3 EM 01][Phase A : Puissance Active]#')->execCmd();
$prodSolaire = cmd::byString('#[Maison][Shelly Pro 3 EM 01][Phase B : Puissance Active]#')->execCmd();

// Solis (batterie)
$socBatterie = cmd::byString('#[Maison][Solis][Batterie]#')->execCmd();
$pBatterie = cmd::byString('#[Maison][Mesure - Batterie][Actuel]#')->execCmd();
$prodPV = cmd::byString('#[Maison][Mesure - Panneaux Solaire][Actuel]#')->execCmd();

// Commandes par borne
$cmdWB = array(
    '1' => array(
        'mode'   => '#[Maison][Wallbox 01 - Mode][Mode]#',
        'status' => '#[Maison][Wallbox01][Statut ID]#',
        'slider' => '#[Maison][Wallbox01][Puissance maximum]#',
        'toggle' => '#[Maison][Wallbox01][Contrôle de la charge]#',
        'pince'  => '#[Maison][Shelly Pro 3 EM 02][Phase A : Puissance Active]#',
        'pmax'   => $P_MAX_WB1
    ),
    '2' => array(
        'mode'   => '#[Maison][Wallbox 02 - Mode][Mode]#',
        'status' => '#[Maison][Wallbox02][Statut ID]#',
        'slider' => '#[Maison][Wallbox02][Puissance maximum]#',
        'toggle' => '#[Maison][Wallbox02][Contrôle de la charge]#',
        'pince'  => '#[Maison][Shelly Pro 3 EM 02][Phase B : Puissance Active]#',
        'pmax'   => $P_MAX_WB2
    )
);

// Priorisation
$CMD_PRIORITE = '#[Maison][VE - Priorisation][Mode]#';
$CMD_PRIORITE_OFF = '#[Maison][VE - Priorisation][Désactivée]#';   // A ADAPTER au nom reel de l'action
$priorite = cmd::byString($CMD_PRIORITE)->execCmd();

// Virtuel de suivi (commandes existantes)
$virtuelSurplus = '#[Maison][Pilotage Wallbox 02][Surplus solaire]#';
$virtuelDispoCompteur = '#[Maison][Pilotage Wallbox 02][Puissance dispo compteur]#';
$virtuelWB1Charge = '#[Maison][Pilotage Wallbox 02][WB1 en charge]#';
$virtuelCibleWB2 = '#[Maison][Pilotage Wallbox 02][Puissance cible WB2]#';
$virtuelAmpWB2 = '#[Maison][Pilotage Wallbox 02][Ampères calculés]#';
$virtuelAutorisation = '#[Maison][Pilotage Wallbox 02][Autorisation charge]#';
$virtuelRaison = '#[Maison][Pilotage Wallbox 02][Raison blocage]#';


// ======================================================
// SECURISATION DES VALEURS
// ======================================================

$consoMaison = floatval($consoMaison);
$consoEDF = floatval($consoEDF);
$prodSolaire = floatval($prodSolaire);
$socBatterie = floatval($socBatterie);
$pBatterie = floatval($pBatterie);
$prodPV = floatval($prodPV);

$maintenant = time();
$alertes = array();


// ======================================================
// MIGRATION V3.1 -> V4 (une seule fois)
// Reprend les consignes en cours pour ne rien couper au passage.
// ======================================================

if (intval($scenario->getData('v4_init')) == 0) {
    foreach (array('1', '2') as $id) {
        $ancien = intval($scenario->getData('wb' . $id . '_dernier_amp'));
        $scenario->setData('wb' . $id . '_cible', $ancien);
        $scenario->setData('wb' . $id . '_slider', $ancien);
        $scenario->setData('wb' . $id . '_toggle_ts', intval($scenario->getData('wb' . $id . '_dernier_toggle')));
        $scenario->setData('wb' . $id . '_action_amp_ts', intval($scenario->getData('wb' . $id . '_derniere_action_amp')));
        $scenario->setData('wb' . $id . '_inactif_ts', 0);
        $scenario->setData('wb' . $id . '_faible_depuis', 0);
    }
    $scenario->setData('v4_init', 1);
}


// ======================================================
// ETAT DES DEUX BORNES
// ======================================================

$wb = array();

foreach (array('1', '2') as $id) {

    $k = 'wb' . $id . '_';
    $b = array();
    $b['nom'] = 'WB' . $id;
    $b['status'] = intval(cmd::byString($cmdWB[$id]['status'])->execCmd());
    $b['puissance'] = max(0, floatval(cmd::byString($cmdWB[$id]['pince'])->execCmd()));
    $b['pmax'] = $cmdWB[$id]['pmax'];

    // Mode de la borne
    $modeBrut = cmd::byString($cmdWB[$id]['mode'])->execCmd();
    $nm = veNormMode($modeBrut);
    $b['mode'] = $nm[0];
    $b['noteMode'] = $nm[1];
    if (strpos($nm[1], 'NON RECONNU') !== false) {
        $alertes[] = $b['nom'] . ' : ' . $nm[1];
    }

    $modePrec = strval($scenario->getData($k . 'mode_prec'));
    $b['modeChange'] = ($modePrec != '' && $modePrec != $b['mode']) ? 1 : 0;
    $scenario->setData($k . 'mode_prec', $b['mode']);

    $b['pilotable'] = in_array($b['status'], $ST_PILOTABLE) ? 1 : 0;
    $b['enPause'] = in_array($b['status'], $ST_PAUSE) ? 1 : 0;
    $b['absorbe'] = ($b['puissance'] > $SEUIL_VE_ACTIF) ? 1 : 0;

    // Donnees persistantes
    $b['ciblePrec'] = intval($scenario->getData($k . 'cible'));
    if ($b['pilotable'] == 0) {
        $b['ciblePrec'] = 0;
    }
    $b['slider'] = intval($scenario->getData($k . 'slider'));
    $b['actionAmpTs'] = intval($scenario->getData($k . 'action_amp_ts'));
    $b['toggleTs'] = intval($scenario->getData($k . 'toggle_ts'));
    $b['demarrageTs'] = intval($scenario->getData($k . 'demarrage_ts'));
    $b['recent'] = ($b['demarrageTs'] > 0 && ($maintenant - $b['demarrageTs']) < $DELAI_GRACE_DEMARRAGE) ? 1 : 0;

    // La voiture suit-elle sa consigne ? (sinon inutile de lui pousser plus d'amperes)
    $b['suit'] = ($b['slider'] > 0 && $b['puissance'] < (($b['slider'] - 2) * $U)) ? 0 : 1;

    // Detection 'voiture n'absorbe pas' (utilisee en Eco uniquement).
    // Le flag expire au bout de DELAI_RETEST_INACTIF : il ne peut plus
    // condamner une borne indefiniment.
    $inactifTs = intval($scenario->getData($k . 'inactif_ts'));
    $faibleDepuis = intval($scenario->getData($k . 'faible_depuis'));

    if ($inactifTs > 0 && ($maintenant - $inactifTs) >= $DELAI_RETEST_INACTIF) {
        $inactifTs = 0;
        $faibleDepuis = 0;
    }
    if ($b['pilotable'] == 0 || $b['absorbe'] == 1 || $b['modeChange'] == 1) {
        $inactifTs = 0;
        $faibleDepuis = 0;
    } elseif ($b['ciblePrec'] >= $AMP_MIN && $b['recent'] == 0 && $b['enPause'] == 0) {
        if ($faibleDepuis <= 0) {
            $faibleDepuis = $maintenant;
        }
        if ($inactifTs == 0 && ($maintenant - $faibleDepuis) >= $DELAI_VE_INACTIF) {
            $inactifTs = $maintenant;
        }
    } else {
        $faibleDepuis = 0;
    }
    $scenario->setData($k . 'inactif_ts', $inactifTs);
    $scenario->setData($k . 'faible_depuis', $faibleDepuis);
    $b['inactif'] = ($inactifTs > 0) ? 1 : 0;

    // Amperages max
    $b['maxAmp'] = ($b['pilotable'] == 1) ? min($AMP_MAX, floor($b['pmax'] / $U)) : 0;
    $b['maxAmpEco'] = ($b['inactif'] == 1) ? 0 : $b['maxAmp'];

    $b['cible'] = 0;
    $b['coupeBudget'] = 0;   // Normal : 1 si la consigne 0 vient d'un manque de budget
    $b['enAttente'] = 0;     // Normal : voiture branchee qui ne tire rien (planif, pleine)
    $b['raison'] = '';

    $wb[$id] = $b;
}


// ======================================================
// BUDGET DE PUISSANCE VE (limites 12 kW)
// On retire la puissance reelle des bornes (pinces) du bilan.
// ======================================================

$pVE = $wb['1']['puissance'] + $wb['2']['puissance'];
$consoMaisonHorsVE = max(0, $consoMaison - $pVE);
$importEDFHorsVE = max(0, $consoEDF - $pVE);

$dispoVEMaison = max(0, $P_MAISON_MAX - $consoMaisonHorsVE - $MARGE_SECURITE);
$dispoVEEDF = max(0, $P_COMPTEUR_MAX - $importEDFHorsVE - $MARGE_SECURITE);
$budgetVE = min($dispoVEMaison, $dispoVEEDF);

$surplusExporte = max(0, -$consoEDF);


// ======================================================
// DETECTION URGENCE (mesure reelle proche d'une limite)
// ======================================================

$urgence = 0;
$depassement = 0;

if ($consoEDF > ($P_COMPTEUR_MAX - $MARGE_URGENCE)) {
    $depassement = max($depassement, $consoEDF - ($P_COMPTEUR_MAX - $MARGE_SECURITE));
}
if ($consoMaison > ($P_MAISON_MAX - $MARGE_URGENCE)) {
    $depassement = max($depassement, $consoMaison - ($P_MAISON_MAX - $MARGE_SECURITE));
}
if ($depassement > 0) {
    $urgence = 1;
}


// ======================================================
// PRIORISATION
// ======================================================

$prioritePartage = 0;
$prioOffLike = 0;
$alertePriorite = '';
$notePrio = '';
$prioriteNorm = mb_strtolower(trim(strval($priorite)));

if (strpos($prioriteNorm, 'wb1') !== false || strpos($prioriteNorm, 'wallbox 01') !== false || strpos($prioriteNorm, 'e208') !== false) {
    $ordrePrio = array('1', '2');
} elseif (strpos($prioriteNorm, 'wb2') !== false || strpos($prioriteNorm, 'wallbox 02') !== false || strpos($prioriteNorm, 'e3008') !== false) {
    $ordrePrio = array('2', '1');
} else {
    // Partage : les montees vont a la borne la moins chargee.
    $prioritePartage = 1;
    $ordrePrio = ($wb['1']['ciblePrec'] <= $wb['2']['ciblePrec']) ? array('1', '2') : array('2', '1');
    if (strpos($prioriteNorm, 'sactiv') !== false || $prioriteNorm == 'off' || $prioriteNorm == '') {
        $prioOffLike = 1;
    } else {
        $alertePriorite = 'PRIORISATION NON RECONNUE ("' . $priorite . '") : partage appliqué';
    }
}

$modeMixte = ($wb['1']['mode'] != $wb['2']['mode']) ? 1 : 0;

if ($modeMixte == 1) {
    // La priorisation n'a pas de sens entre une borne Normal et une Eco :
    // on la remet sur Off (nouvel essai toutes les 10 min si elle n'a pas pris).
    $alertePriorite = '';
    if ($prioOffLike == 0) {
        $dernierReset = intval($scenario->getData('prio_reset_ts'));
        if (($maintenant - $dernierReset) >= $DELAI_RETRY_PRIO_OFF) {
            try {
                cmd::byString($CMD_PRIORITE_OFF)->execCmd();
                $notePrio = 'Modes mixtes : priorisation remise sur Off';
            } catch (Exception $e) {
                $notePrio = 'Modes mixtes : remise sur Off IMPOSSIBLE (vérifier $CMD_PRIORITE_OFF)';
            }
            $scenario->setData('prio_reset_ts', $maintenant);
        } else {
            $notePrio = 'Modes mixtes : priorisation ignorée';
        }
    }
}

// Repartition des bornes par mode, dans l'ordre de priorite
$idsNormal = array();
$idsEco = array();
foreach ($ordrePrio as $id) {
    if ($wb[$id]['mode'] == 'Normal') {
        $idsNormal[] = $id;
    } else {
        $idsEco[] = $id;
    }
}


// ======================================================
// MODE NORMAL (bornes concernees)
// 1. Bornes dont la voiture tire (ou vient de demarrer) : servies selon
//    la priorite, dans la limite du budget.
// 2. Bornes en attente (voiture planifiee ou pleine) : recoivent le reste
//    du budget comme consigne de veille. JAMAIS mises en pause pour ce
//    motif : a l'heure planifiee la voiture demarre d'elle-meme.
// ======================================================

$budgetRestant = $budgetVE;

if (count($idsNormal) > 0) {

    $actifsN = array();
    $attenteN = array();

    foreach ($idsNormal as $id) {
        if ($wb[$id]['maxAmp'] < $AMP_MIN) {
            $wb[$id]['raison'] = 'indisponible (' . veStatusText($wb[$id]['status']) . ')';
            continue;
        }
        if ($wb[$id]['absorbe'] == 1 || $wb[$id]['recent'] == 1) {
            $actifsN[] = $id;
        } else {
            $attenteN[] = $id;
        }
    }

    // --- 1. Bornes actives ---
    if (count($actifsN) == 2 && $prioritePartage == 1 && $modeMixte == 0) {

        $x = $actifsN[0];
        $y = $actifsN[1];
        $ax = min($wb[$x]['maxAmp'], floor(($budgetRestant / 2) / $U));
        $ay = min($wb[$y]['maxAmp'], floor(($budgetRestant - $ax * $U) / $U));
        $ax = min($wb[$x]['maxAmp'], floor(($budgetRestant - $ay * $U) / $U));
        if ($ax < $AMP_MIN) { $ax = 0; }
        if ($ay < $AMP_MIN) { $ay = 0; }
        if ($ax == 0 && $ay > 0) {
            $ay = min($wb[$y]['maxAmp'], floor($budgetRestant / $U));
        }
        if ($ay == 0 && $ax > 0) {
            $ax = min($wb[$x]['maxAmp'], floor($budgetRestant / $U));
        }
        foreach (array($x => $ax, $y => $ay) as $id => $a) {
            $wb[$id]['cible'] = $a;
            if ($a == 0) {
                $wb[$id]['coupeBudget'] = 1;
                $wb[$id]['raison'] = 'budget insuffisant';
            } else {
                $wb[$id]['raison'] = 'charge ' . $a . ' A (partage)';
            }
            $budgetRestant = max(0, $budgetRestant - $a * $U);
        }

    } else {

        foreach ($actifsN as $id) {
            $a = min($wb[$id]['maxAmp'], floor($budgetRestant / $U));
            if ($a < $AMP_MIN) {
                $a = 0;
                $wb[$id]['coupeBudget'] = 1;
                $wb[$id]['raison'] = 'budget insuffisant';
            } else {
                $wb[$id]['raison'] = 'charge ' . $a . ' A';
            }
            $wb[$id]['cible'] = $a;
            $budgetRestant = max(0, $budgetRestant - $a * $U);
        }
    }

    // --- 2. Bornes en attente ---
    foreach ($attenteN as $id) {
        $wb[$id]['enAttente'] = 1;
        $a = min($wb[$id]['maxAmp'], floor($budgetRestant / $U));

        if ($wb[$id]['enPause'] == 1) {
            // Borne en pause (urgence passee, ancienne pause Eco, ...) :
            // on ne la relance qu'avec une petite marge pour eviter le pompage.
            if ($a < ($AMP_MIN + 1)) {
                $a = 0;
                $wb[$id]['coupeBudget'] = 1;
                $wb[$id]['raison'] = 'en pause, budget insuffisant pour reprendre';
            } else {
                $wb[$id]['raison'] = 'en pause, reprise à ' . $a . ' A';
            }
        } elseif ($a < $AMP_MIN) {
            // Veille a 6 A meme si l'autre borne a tout pris : si la voiture
            // demarre, la regulation reequilibrera au cycle suivant.
            if ($budgetVE >= $P_MIN_CHARGE_VE) {
                $a = $AMP_MIN;
                $wb[$id]['raison'] = 'voiture en attente, veille ' . $AMP_MIN . ' A';
            } else {
                $a = 0;
                $wb[$id]['coupeBudget'] = 1;
                $wb[$id]['raison'] = 'voiture en attente, budget nul';
            }
        } else {
            $wb[$id]['raison'] = 'voiture en attente (planif/pleine), veille ' . $a . ' A';
        }

        $wb[$id]['cible'] = $a;
        $budgetRestant = max(0, $budgetRestant - $a * $U);
    }
}


// ======================================================
// MODE ECO (bornes concernees) - regulation sur batterie (logique V3.1)
// ======================================================

$cibleBat = 0;
$erreur = 0;
$raisonEco = '';
$raisonTransfert = '';

if (count($idsEco) > 0) {

    $ordreMonteeEco = $idsEco;
    $ordreBaisseEco = array_reverse($idsEco);
    $deuxEco = (count($idsEco) == 2) ? 1 : 0;

    // Continuite : on part des consignes precedentes.
    // Une borne qui vient de passer en Eco repart au plus a 6 A.
    foreach ($idsEco as $id) {
        $c = $wb[$id]['ciblePrec'];
        if ($wb[$id]['modeChange'] == 1) {
            $c = min($c, $AMP_MIN);
        }
        if ($c > 0) {
            $c = min($c, $wb[$id]['maxAmpEco']);
            if ($c < $AMP_MIN) {
                $c = 0;
            }
        }
        $wb[$id]['cible'] = $c;
    }

    if ($socBatterie <= 0 || $socBatterie > 100) {

        foreach ($idsEco as $id) { $wb[$id]['cible'] = 0; }
        $scenario->setData('eco_soc_ok_depuis', 0);
        $raisonEco = 'SOC batterie invalide (' . $socBatterie . ') - arrêt sécurité';

    } elseif ($socBatterie < $SOC_STOP) {

        foreach ($idsEco as $id) { $wb[$id]['cible'] = 0; }
        $scenario->setData('eco_soc_ok_depuis', 0);
        $raisonEco = 'SOC ' . $socBatterie . '% < ' . $SOC_STOP . '% - arrêt';

    } else {

        $pente = ($P_BAT_CIBLE_MAX - $P_BAT_CIBLE_MIN) / ($SOC_CIBLE_HAUTE - $SOC_CIBLE_BASSE);
        $cibleBat = $P_BAT_CIBLE_MAX - ($socBatterie - $SOC_CIBLE_BASSE) * $pente;
        $cibleBat = max($P_BAT_CIBLE_MIN, min($P_BAT_CIBLE_MAX, $cibleBat));

        // erreur > 0 : energie disponible -> monter ; erreur < 0 : baisser.
        $erreur = ($pBatterie - $cibleBat) + $surplusExporte;

        $active = 0;
        foreach ($idsEco as $id) {
            if ($wb[$id]['cible'] > 0) { $active = 1; }
        }

        if ($active == 0) {

            // ------ DEMARRAGE ------
            if ($socBatterie >= $SOC_START) {

                $socOkDepuis = intval($scenario->getData('eco_soc_ok_depuis'));
                if ($socOkDepuis <= 0) {
                    $socOkDepuis = $maintenant;
                    $scenario->setData('eco_soc_ok_depuis', $socOkDepuis);
                }

                if ($socBatterie >= $SOC_START_IMMEDIAT || ($maintenant - $socOkDepuis) >= $DELAI_DEMARRAGE_ECO) {

                    if (($pBatterie + $surplusExporte) < -$DEADBAND) {
                        // La batterie se vide deja (nuit, nuage, borne Normal qui tire) :
                        // demarrer ne ferait que transvaser la batterie dans la voiture.
                        $raisonEco = 'SOC ' . $socBatterie . '% mais batterie en décharge (' . round($pBatterie) . ' W) - pas de démarrage';
                    } elseif ($budgetRestant < $P_MIN_CHARGE_VE) {
                        $raisonEco = 'SOC OK mais budget 12 kW insuffisant';
                    } else {
                        $demarree = 0;
                        foreach ($ordreMonteeEco as $id) {
                            if ($wb[$id]['maxAmpEco'] >= $AMP_MIN) {
                                $wb[$id]['cible'] = $AMP_MIN;
                                $raisonEco = 'démarrage ' . $wb[$id]['nom'] . ' à ' . $AMP_MIN . ' A (SOC ' . $socBatterie . '%)';
                                $demarree = 1;
                                break;
                            }
                        }
                        if ($demarree == 0) {
                            $raisonEco = 'SOC OK mais aucune borne Eco disponible';
                        }
                    }

                } else {
                    $raisonEco = 'SOC ' . $socBatterie . '% - attente stabilité (' . ($maintenant - $socOkDepuis) . 's/' . $DELAI_DEMARRAGE_ECO . 's)';
                }

            } else {
                $scenario->setData('eco_soc_ok_depuis', 0);
                $raisonEco = 'SOC ' . $socBatterie . '% < ' . $SOC_START . '% - attente batterie';
            }

        } else {

            // ------ REGULATION ------
            $scenario->setData('eco_soc_ok_depuis', 0);

            // Equilibrage (2 bornes Eco, priorisation Off)
            if ($deuxEco == 1 && $prioritePartage == 1) {
                foreach (array('1', '2') as $id) {
                    $autre = ($id == '1') ? '2' : '1';
                    if ($wb[$id]['cible'] >= (2 * $AMP_MIN) && $wb[$autre]['cible'] == 0 && $wb[$autre]['maxAmpEco'] >= $AMP_MIN) {
                        $wb[$autre]['cible'] = $AMP_MIN;
                        $scenario->setData('eco_derniere_montee', $maintenant);
                        $raisonTransfert = 'Équilibrage : ' . $wb[$autre]['nom'] . ' démarrée à ' . $AMP_MIN . ' A';
                        break;
                    }
                }
            }

            // Consolidation (2 bornes Eco, priorite WB1 ou WB2)
            if ($deuxEco == 1 && $prioritePartage == 0 && $erreur >= -$DEADBAND) {

                $idPrio = $ordreMonteeEco[0];
                $idSec = $ordreMonteeEco[1];
                $derniereMonteeCons = intval($scenario->getData('eco_derniere_montee'));

                if ($wb[$idSec]['cible'] > 0 && ($maintenant - $derniereMonteeCons) >= $DELAI_MONTEE_ECO) {

                    if ($wb[$idPrio]['cible'] >= $AMP_MIN && $wb[$idPrio]['cible'] < $wb[$idPrio]['maxAmpEco']
                        && ($wb[$idPrio]['recent'] == 1 || ($wb[$idPrio]['absorbe'] == 1 && $wb[$idPrio]['suit'] == 1))) {

                        $t = min($PAS_MAX_MONTEE, $wb[$idPrio]['maxAmpEco'] - $wb[$idPrio]['cible'], $wb[$idSec]['cible']);
                        if (($wb[$idSec]['cible'] - $t) < $AMP_MIN) {
                            $t = min($wb[$idSec]['cible'], $wb[$idPrio]['maxAmpEco'] - $wb[$idPrio]['cible']);
                            $wb[$idSec]['cible'] = 0;
                        } else {
                            $wb[$idSec]['cible'] = $wb[$idSec]['cible'] - $t;
                        }
                        $wb[$idPrio]['cible'] = $wb[$idPrio]['cible'] + $t;
                        $scenario->setData('eco_derniere_montee', $maintenant);
                        $raisonTransfert = 'Consolidation : +' . $t . ' A sur ' . $wb[$idPrio]['nom'] . ', repris sur ' . $wb[$idSec]['nom'] . ($wb[$idSec]['cible'] == 0 ? ' (coupée)' : ' (' . $wb[$idSec]['cible'] . ' A)');

                    } elseif ($wb[$idPrio]['cible'] == 0 && $wb[$idPrio]['maxAmpEco'] >= $AMP_MIN) {

                        if ($wb[$idSec]['cible'] >= (2 * $AMP_MIN)) {
                            $wb[$idPrio]['cible'] = $AMP_MIN;
                            $wb[$idSec]['cible'] = $wb[$idSec]['cible'] - $AMP_MIN;
                            $raisonTransfert = 'Consolidation : démarrage ' . $wb[$idPrio]['nom'] . ' à ' . $AMP_MIN . ' A, ' . $wb[$idSec]['nom'] . ' à ' . $wb[$idSec]['cible'] . ' A';
                        } else {
                            $wb[$idPrio]['cible'] = $AMP_MIN;
                            $wb[$idSec]['cible'] = 0;
                            $raisonTransfert = 'Consolidation : bascule sur ' . $wb[$idPrio]['nom'] . ', ' . $wb[$idSec]['nom'] . ' coupée';
                        }
                        $scenario->setData('eco_derniere_montee', $maintenant);
                    }
                }
            }

            $derniereMontee = intval($scenario->getData('eco_derniere_montee'));

            if ($erreur > $DEADBAND) {

                if (($maintenant - $derniereMontee) >= $DELAI_MONTEE_ECO) {

                    $step = min($PAS_MAX_MONTEE, max(1, floor($erreur / $U)));
                    $fait = 0;

                    // 1. Monter une borne Eco active qui absorbe reellement
                    foreach ($ordreMonteeEco as $id) {
                        if ($wb[$id]['cible'] >= $AMP_MIN && $wb[$id]['cible'] < $wb[$id]['maxAmpEco']
                            && ($wb[$id]['recent'] == 1 || ($wb[$id]['absorbe'] == 1 && $wb[$id]['suit'] == 1))) {
                            $wb[$id]['cible'] = min($wb[$id]['maxAmpEco'], $wb[$id]['cible'] + $step);
                            $raisonEco = 'montée +' . $step . ' A sur ' . $wb[$id]['nom'] . ' (erreur +' . round($erreur) . ' W)';
                            $fait = 1;
                            break;
                        }
                    }

                    // 2. Sinon demarrer l'autre borne Eco si l'excedent couvre ses 6 A
                    if ($fait == 0 && $erreur >= ($P_MIN_CHARGE_VE + $DEADBAND)) {
                        foreach ($ordreMonteeEco as $id) {
                            if ($wb[$id]['cible'] == 0 && $wb[$id]['maxAmpEco'] >= $AMP_MIN) {
                                $wb[$id]['cible'] = $AMP_MIN;
                                $raisonEco = 'démarrage 2e borne ' . $wb[$id]['nom'] . ' (erreur +' . round($erreur) . ' W)';
                                $fait = 1;
                                break;
                            }
                        }
                    }

                    if ($fait == 1) {
                        $scenario->setData('eco_derniere_montee', $maintenant);
                    } else {
                        $raisonEco = 'excédent +' . round($erreur) . ' W mais montée impossible (max atteint ou VE n\'absorbe pas)';
                    }

                } else {
                    $raisonEco = 'excédent +' . round($erreur) . ' W - montée temporisée';
                }

            } elseif ($erreur < -$DEADBAND) {

                $besoin = min($PAS_MAX_BAISSE, ceil(abs($erreur) / $U));
                $besoinInitial = $besoin;

                foreach ($ordreBaisseEco as $id) {
                    if ($besoin <= 0) {
                        break;
                    }
                    if ($wb[$id]['cible'] > $AMP_MIN) {
                        $red = min($besoin, $wb[$id]['cible'] - $AMP_MIN);
                        $wb[$id]['cible'] = $wb[$id]['cible'] - $red;
                        $besoin = $besoin - $red;
                    }
                    if ($besoin > 0 && $wb[$id]['cible'] == $AMP_MIN && abs($erreur) >= $P_MIN_CHARGE_VE) {
                        $autresActives = 0;
                        foreach ($idsEco as $autre) {
                            if ($autre != $id && $wb[$autre]['cible'] > 0) { $autresActives = 1; }
                        }
                        // La derniere borne Eco n'est coupee que sous SOC_START,
                        // sinon la batterie encaisse et SOC_STOP tranchera.
                        if ($autresActives == 1 || $socBatterie < $SOC_START) {
                            $wb[$id]['cible'] = 0;
                            $besoin = max(0, $besoin - $AMP_MIN);
                        }
                    }
                }

                $raisonEco = 'baisse -' . $besoinInitial . ' A (erreur ' . round($erreur) . ' W, SOC ' . $socBatterie . '%)';

            } else {
                $raisonEco = 'équilibre (erreur ' . round($erreur) . ' W, SOC ' . $socBatterie . '%, cible bat ' . round($cibleBat) . ' W)';
            }
        }
    }

    if ($raisonTransfert != '') {
        $raisonEco = $raisonTransfert . ' | ' . $raisonEco;
    }
    foreach ($idsEco as $id) {
        $wb[$id]['raison'] = ($wb[$id]['cible'] > 0) ? ($wb[$id]['cible'] . ' A') : 'arrêt';
    }
} else {
    $scenario->setData('eco_soc_ok_depuis', 0);
}


// ======================================================
// SECURITES FINALES ET PLAFOND GLOBAL 12 kW
// Les bornes Normal en attente (voiture qui ne tire pas) ne comptent pas :
// leur consigne de veille ne doit pas brider la borne qui charge.
// Ordre de reduction : bornes Eco d'abord, puis Normal (priorite inverse).
// ======================================================

foreach ($wb as $id => $b) {
    $c = max(0, min($b['cible'], $b['maxAmp'], $AMP_MAX));
    if ($c > 0 && $c < $AMP_MIN) {
        $c = 0;
    }
    $wb[$id]['cible'] = $c;
}

$ordreBaisseGlobal = array_merge(array_reverse($idsEco), array_reverse($idsNormal));

$totalVEW = 0;
foreach ($wb as $id => $b) {
    if ($b['enAttente'] == 0) {
        $totalVEW = $totalVEW + $b['cible'] * $U;
    }
}

$retrait = 0;
if ($totalVEW > $budgetVE) {
    $retrait = ceil(($totalVEW - $budgetVE) / $U);
}
if ($urgence == 1) {
    $retrait = max($retrait, ceil($depassement / $U) + 1);
}

$notePlafond = '';
if ($retrait > 0) {
    foreach ($ordreBaisseGlobal as $id) {
        if ($retrait <= 0) {
            break;
        }
        // Une borne en attente ne tire rien : la reduire ne soulagerait rien.
        // Si sa voiture demarre, elle repassera en 'active' au cycle suivant.
        if ($wb[$id]['enAttente'] == 1) {
            continue;
        }
        if ($wb[$id]['cible'] > 0) {
            $red = min($retrait, $wb[$id]['cible'] - $AMP_MIN);
            if ($red > 0) {
                $wb[$id]['cible'] = $wb[$id]['cible'] - $red;
                $retrait = $retrait - $red;
            }
            if ($retrait > 0 && $wb[$id]['cible'] <= $AMP_MIN) {
                $retrait = $retrait - $wb[$id]['cible'];
                $wb[$id]['cible'] = 0;
                $wb[$id]['coupeBudget'] = 1;
            }
        }
    }
    $notePlafond = ($urgence == 1) ? ('URGENCE : réduction immédiate (dépassement ' . round($depassement) . ' W)') : 'Plafond 12 kW : réduction appliquée';
}


// ======================================================
// ANTI-YOYO (bornes Normal uniquement, hors urgence)
// ======================================================

if ($urgence == 0) {
    foreach ($idsNormal as $id) {
        $b = $wb[$id];
        if ($b['cible'] > 0 && $b['ciblePrec'] > 0) {
            $ecart = $b['cible'] - $b['ciblePrec'];
            if ($ecart > 0) {
                if ($ecart < $ECART_MIN_MONTEE || ($maintenant - $b['actionAmpTs']) < $DELAI_MONTEE_AMP) {
                    $wb[$id]['cible'] = $b['ciblePrec'];
                }
            } elseif ($ecart < 0) {
                if (abs($ecart) < $SEUIL_BAISSE_IMMEDIATE && ($maintenant - $b['actionAmpTs']) < $DELAI_BAISSE_AMP) {
                    $wb[$id]['cible'] = $b['ciblePrec'];
                }
            }
        }
    }
}


// ======================================================
// ACTIONNEMENT - PHASE 1 : CONSIGNES D'AMPERAGE
// Baisses d'abord, hausses ensuite (jamais de pic transitoire).
// ======================================================

$actions = '';

foreach (array('baisse', 'hausse') as $passe) {
    foreach (array('1', '2') as $id) {
        $b = $wb[$id];
        if ($b['cible'] <= 0 || $b['cible'] == $b['slider']) {
            continue;
        }
        $estBaisse = ($b['cible'] < $b['slider']) ? 1 : 0;
        if (($passe == 'baisse' && $estBaisse == 0) || ($passe == 'hausse' && $estBaisse == 1)) {
            continue;
        }
        cmd::byString($cmdWB[$id]['slider'])->execCmd(array('slider' => $b['cible']));
        $scenario->setData('wb' . $id . '_slider', $b['cible']);
        $scenario->setData('wb' . $id . '_action_amp_ts', $maintenant);
        $wb[$id]['slider'] = $b['cible'];
        $actions = $actions . $b['nom'] . ' consigne ' . $b['cible'] . ' A. ';
    }
}

foreach (array('1', '2') as $id) {
    if ($wb[$id]['ciblePrec'] == 0 && $wb[$id]['cible'] > 0) {
        $scenario->setData('wb' . $id . '_demarrage_ts', $maintenant);
    }
}

sleep(2);


// ======================================================
// ACTIONNEMENT - PHASE 2 : PAUSE / REPRISE
// 'Controle de la charge' est une bascule : on ne l'actionne que si
// la pince confirme l'etat reel ET si le statut a ete rafraichi depuis
// le dernier toggle. Sinon on attend le cycle suivant.
// ======================================================

foreach (array('1', '2') as $id) {

    $b = $wb[$id];
    $k = 'wb' . $id . '_';

    $statusFrais = intval(cmd::byString($cmdWB[$id]['status'])->execCmd());
    $pFrais = max(0, floatval(cmd::byString($cmdWB[$id]['pince'])->execCmd()));
    $statusTs = veCmdTs($cmdWB[$id]['status']);
    $depuisToggle = $maintenant - $b['toggleTs'];
    $statutAJour = ($b['toggleTs'] == 0 || $statusTs > $b['toggleTs'] || $depuisToggle >= $DELAI_STATUT_MAX) ? 1 : 0;
    $pauseFraiche = in_array($statusFrais, $ST_PAUSE) ? 1 : 0;

    $cibleFinale = $b['cible'];

    if ($b['cible'] > 0) {

        // Reprise : statut 'Paused' ET la pince confirme que rien ne passe.
        if ($pauseFraiche == 1 && $pFrais < $SEUIL_VE_ACTIF) {
            if ($statutAJour == 0) {
                $actions = $actions . $b['nom'] . ' reprise en attente (statut pas encore rafraîchi). ';
            } elseif ($depuisToggle < $DELAI_MIN_TOGGLE) {
                $actions = $actions . $b['nom'] . ' reprise différée (anti pause/reprise). ';
            } else {
                cmd::byString($cmdWB[$id]['toggle'])->execCmd();
                $scenario->setData($k . 'toggle_ts', $maintenant);
                $scenario->setData($k . 'demarrage_ts', $maintenant);
                $actions = $actions . $b['nom'] . ' reprise charge. ';
            }
        }

    } else {

        // Pause : uniquement si la voiture tire REELLEMENT, et en Normal
        // uniquement pour un motif de puissance (budget, plafond, urgence).
        $motifPause = ($b['mode'] == 'Eco' || $b['coupeBudget'] == 1 || $urgence == 1) ? 1 : 0;

        if ($pFrais > $SEUIL_VE_ACTIF && $pauseFraiche == 0 && $motifPause == 1) {

            // La voiture tire : le flag 'inactif' n'a plus lieu d'etre.
            $scenario->setData($k . 'inactif_ts', 0);
            $scenario->setData($k . 'faible_depuis', 0);

            $toggleAutorise = ($statutAJour == 1) && ($depuisToggle >= $DELAI_MIN_TOGGLE || ($urgence == 1 && $depuisToggle >= 60));

            if ($toggleAutorise) {
                cmd::byString($cmdWB[$id]['toggle'])->execCmd();
                $scenario->setData($k . 'toggle_ts', $maintenant);
                $actions = $actions . $b['nom'] . (($urgence == 1) ? ' pause IMMEDIATE (urgence). ' : ' mise en pause. ');
            } else {
                // En attendant de pouvoir mettre en pause : reduction au minimum.
                if ($b['slider'] != $AMP_MIN) {
                    cmd::byString($cmdWB[$id]['slider'])->execCmd(array('slider' => $AMP_MIN));
                    $scenario->setData($k . 'slider', $AMP_MIN);
                    $scenario->setData($k . 'action_amp_ts', $maintenant);
                    $wb[$id]['slider'] = $AMP_MIN;
                }
                $actions = $actions . $b['nom'] . ' pause différée, réduite à ' . $AMP_MIN . ' A. ';
            }

        } elseif ($pFrais > $SEUIL_VE_ACTIF && $motifPause == 0) {
            // Normal, borne non allouee (statut non pilotable au debut du cycle)
            // mais la voiture tire : on ne coupe pas, le cycle suivant l'allouera.
            $actions = $actions . $b['nom'] . ' tire sans consigne : allocation au prochain cycle. ';
        }
    }

    $scenario->setData($k . 'cible', $cibleFinale);
}

if ($actions == '') {
    $actions = 'Aucune action';
}


// ======================================================
// RAISON
// ======================================================

$morceaux = array();
foreach ($alertes as $a) {
    $morceaux[] = $a;
}
if ($alertePriorite != '') {
    $morceaux[] = $alertePriorite;
}
if ($notePrio != '') {
    $morceaux[] = $notePrio;
}
foreach (array('1', '2') as $id) {
    $morceaux[] = $wb[$id]['nom'] . ' [' . $wb[$id]['mode'] . '] : ' . $wb[$id]['raison'];
}
if (count($idsEco) > 0 && $raisonEco != '') {
    $morceaux[] = 'Eco : ' . $raisonEco;
}
if ($notePlafond != '') {
    $morceaux[] = $notePlafond;
}
$raison = implode(' | ', $morceaux);


// ======================================================
// MISE A JOUR DU VIRTUEL
// ======================================================

cmd::byString($virtuelSurplus)->event(round($erreur));
cmd::byString($virtuelDispoCompteur)->event($budgetVE);
cmd::byString($virtuelWB1Charge)->event($wb['1']['absorbe']);
cmd::byString($virtuelCibleWB2)->event($wb['2']['cible'] * $U);
cmd::byString($virtuelAmpWB2)->event($wb['2']['cible']);
cmd::byString($virtuelAutorisation)->event((($wb['1']['cible'] > 0 || $wb['2']['cible'] > 0)) ? 1 : 0);
cmd::byString($virtuelRaison)->event($raison);


// ======================================================
// LOG JEEDOM
// ======================================================

$logBornes = '';
foreach (array('1', '2') as $id) {
    $b = $wb[$id];
    $logBornes = $logBornes .
        $b['nom'] . " mode=" . $b['mode'] . ($b['noteMode'] != '' ? " (" . $b['noteMode'] . ")" : "") . ($b['modeChange'] == 1 ? " [CHANGEMENT]" : "") . "\n" .
        $b['nom'] . " status=" . $b['status'] . " (" . veStatusText($b['status']) . ") / pilotable=" . $b['pilotable'] . "\n" .
        $b['nom'] . " puissance=" . $b['puissance'] . " W / absorbe=" . $b['absorbe'] . " / suit=" . $b['suit'] . " / inactif=" . $b['inactif'] . " / attente=" . $b['enAttente'] . "\n" .
        $b['nom'] . " cible=" . $b['cible'] . " A (préc=" . $b['ciblePrec'] . " A, slider=" . $b['slider'] . " A, coupeBudget=" . $b['coupeBudget'] . ")\n";
}

$scenario->setLog(
    "Modes : WB1=" . $wb['1']['mode'] . " / WB2=" . $wb['2']['mode'] . ($modeMixte == 1 ? " (MIXTE)" : "") . "\n" .
    "Priorisation=" . $priorite . " (normalisée: '" . $prioriteNorm . "', ordre: WB" . implode(" puis WB", $ordrePrio) . ", partage=" . $prioritePartage . ")\n" .
    "Urgence=" . $urgence . " / Dépassement=" . round($depassement) . " W\n" .
    "---------------------------\n" .
    "Conso maison=" . $consoMaison . " W / hors VE=" . $consoMaisonHorsVE . " W\n" .
    "Conso EDF=" . $consoEDF . " W / import hors VE=" . $importEDFHorsVE . " W / export=" . $surplusExporte . " W\n" .
    "Prod PV (Solis)=" . $prodPV . " W / Prod (Shelly)=" . $prodSolaire . " W\n" .
    "SOC=" . $socBatterie . " % / P batterie=" . $pBatterie . " W / cible bat=" . round($cibleBat) . " W / erreur=" . round($erreur) . " W\n" .
    "Budget VE=" . $budgetVE . " W (maison " . $dispoVEMaison . " / EDF " . $dispoVEEDF . ")\n" .
    "---------------------------\n" .
    $logBornes .
    "---------------------------\n" .
    "Actions=" . $actions . "\n" .
    "Raison=" . $raison
);